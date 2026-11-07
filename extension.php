<?php

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
require_once __DIR__ . '/lib/RuleLoader.php';
require_once __DIR__ . '/lib/SelectorExtractor.php';

use FiveFilters\Readability\Readability;
use FiveFilters\Readability\Configuration;

final class NativeFullContentExtension extends Minz_Extension {

    private ?RuleLoader $ruleLoader = null;
    /** @var array<string, string> Cache catégorie feedId => categoryId */
    private array $feedCategoryCache = [];
    /** @var array<string, int> Compteur d'articles traités par flux lors d'une session cron */
    private array $feedProcessedCounter = [];

    #[\Override]
    public function init(): void {
        $this->ruleLoader = new RuleLoader(__DIR__ . '/rules');

        $this->registerHook('entry_before_insert', [$this, 'parseArticleBeforeInsert']);
        $this->registerHook('entry_before_display', [$this, 'injectEntryButton']);

        $this->registerController('nativeFullContent');

        Minz_View::appendScript($this->getFileUrl('script.js', true));
        Minz_View::appendStyle($this->getFileUrl('style.css', true));
    }

    public function getRuleLoader(): RuleLoader {
        if ($this->ruleLoader === null) {
            $this->ruleLoader = new RuleLoader(__DIR__ . '/rules');
        }
        return $this->ruleLoader;
    }

    #[\Override]
    public function handleConfigureAction(): void {
        $this->registerTranslates();

        // 1. Prise en charge directe de l'action AJAX du banc d'essai
        if (Minz_Request::isPost() && Minz_Request::param('ajax_action') === 'test') {
            header('Content-Type: application/json; charset=UTF-8');

            $url = trim((string) Minz_Request::param('url', ''));
            if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                echo json_encode(['success' => false, 'error' => 'URL invalide ou absente.']);
                exit;
            }

            $debugInfo = [
                'host' => parse_url($url, PHP_URL_HOST),
                'engine' => 'Aucun',
            ];

            $content = $this->extractArticleContent($url, $debugInfo);

            if ($content === null || trim($content) === '') {
                echo json_encode([
                    'success' => false,
                    'error' => $debugInfo['error'] ?? 'Aucun contenu significatif n\'a pu être extrait.',
                    'debug' => $debugInfo
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'debug' => $debugInfo,
                'length' => mb_strlen($content),
                'html' => $content
            ]);
            exit;
        }

        // 2. Enregistrement classique des paramètres de configuration
        if (Minz_Request::isPost()) {
            $scopeMode = (string) Minz_Request::param('scope_mode', 'feed');
            if (!in_array($scopeMode, ['all', 'category', 'feed'], true)) {
                $scopeMode = 'feed';
            }

            $enabledCategories = Minz_Request::param('enabled_categories', []);
            $enabledFeeds = Minz_Request::param('enabled_feeds', []);
            $userAgent = (string) Minz_Request::param('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            $timeout = (int) Minz_Request::param('timeout', 12);
            $maxPerFeed = (int) Minz_Request::param('max_per_feed', 5);

            $this->setUserConfigurationValue('scope_mode', $scopeMode);
            $this->setUserConfigurationValue('enabled_categories', is_array($enabledCategories) ? array_map('strval', $enabledCategories) : []);
            $this->setUserConfigurationValue('enabled_feeds', is_array($enabledFeeds) ? array_map('strval', $enabledFeeds) : []);
            $this->setUserConfigurationValue('user_agent', $userAgent);
            $this->setUserConfigurationValue('timeout', $timeout > 0 ? $timeout : 12);
            $this->setUserConfigurationValue('max_per_feed', $maxPerFeed > 0 ? $maxPerFeed : 5);
        }
    }

    public function parseArticleBeforeInsert(?FreshRSS_Entry $entry): ?FreshRSS_Entry {
        if ($entry === null) {
            return null;
        }

        $feedId = (string) $entry->feedId();
        if (!$this->isFeedEligible($feedId)) {
            return $entry;
        }

        $maxPerFeed = (int) ($this->getUserConfigurationValue('max_per_feed') ?: 5);
        $currentCount = $this->feedProcessedCounter[$feedId] ?? 0;
        if ($currentCount >= $maxPerFeed) {
            return $entry;
        }

        $url = $entry->link();
        if (empty($url)) {
            return $entry;
        }

        $fullContent = $this->extractArticleContent($url);
        if ($fullContent !== null && mb_strlen($fullContent) > mb_strlen($entry->content())) {
            $entry->_content($fullContent);
            $this->feedProcessedCounter[$feedId] = $currentCount + 1;
        }

        return $entry;
    }

    public function injectEntryButton(?FreshRSS_Entry $entry): ?FreshRSS_Entry {
        if ($entry === null) {
            return null;
        }

        $url = $entry->link();
        if (empty($url)) {
            return $entry;
        }

        $id = (string) $entry->id();
        $buttonHtml = '<div class="native-fullcontent-header-bar" data-entry-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '">'
                    . '<button type="button" class="btn btn-secondary native-btn-fetch" '
                    . 'data-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '" '
                    . 'data-url="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
                    . '📄 Récupérer le texte complet'
                    . '</button>'
                    . '</div>';

        $entry->_content($buttonHtml . $entry->content());
        return $entry;
    }

    public function extractArticleContent(string $url, ?array &$debugInfo = null): ?string {
        $html = $this->fetchUrl($url);
        if ($html === '') {
            if ($debugInfo !== null) {
                $debugInfo['error'] = 'Échec de la requête HTTP (connexion bloquée, code HTTP >= 400 ou timeout).';
            }
            return null;
        }

        // 1. Analyse par règle spécifique de domaine
        $rule = $this->getRuleLoader()->getRuleForUrl($url);
        if ($rule !== null) {
            $customContent = SelectorExtractor::extract(
                $html,
                $url,
                $rule['content'],
                $rule['strip'] ?? ''
            );

            if (mb_strlen($customContent) > 100) {
                if ($debugInfo !== null) {
                    $debugInfo['engine'] = 'Règle de domaine (' . $rule['_matched_domain'] . '.json)';
                    $debugInfo['selector'] = $rule['content'];
                }
                return $customContent;
            }
        }

        // 2. Repli sur FiveFilters Readability
        if (class_exists('FiveFilters\Readability\Readability')) {
            try {
                $config = new Configuration();
                $config->setFixRelativeURLs(true);
                $config->setOriginalURL($url);

                $readability = new Readability($config);
                if ($readability->parse($html)) {
                    $content = $readability->getContent();
                    $content = SelectorExtractor::extract($content, $url, 'body');

                    if ($debugInfo !== null) {
                        $debugInfo['engine'] = 'FiveFilters Readability (Algorithme heuristique)';
                        $debugInfo['title'] = $readability->getTitle();
                    }
                    return $content;
                }
            } catch (\Throwable $e) {
                if ($debugInfo !== null) {
                    $debugInfo['error'] = 'Erreur d\'exécution Readability : ' . $e->getMessage();
                }
            }
        } else {
            if ($debugInfo !== null) {
                $debugInfo['error'] = 'Le dossier vendor/ est manquant sur le serveur. Lancez "composer require fivefilters/readability.php" dans le dossier de l\'extension.';
            }
        }

        return null;
    }

    protected function isFeedEligible(string $feedId): bool {
        $scopeMode = (string) ($this->getUserConfigurationValue('scope_mode') ?: 'feed');

        if ($scopeMode === 'all') {
            return true;
        }

        if ($scopeMode === 'category') {
            $enabledCategories = $this->getUserConfigurationValue('enabled_categories');
            if (!is_array($enabledCategories) || empty($enabledCategories)) {
                return false;
            }

            $catId = $this->getFeedCategoryId($feedId);
            return $catId !== null && in_array((string)$catId, array_map('strval', $enabledCategories), true);
        }

        $enabledFeeds = $this->getUserConfigurationValue('enabled_feeds');
        if (!is_array($enabledFeeds) || empty($enabledFeeds)) {
            return false;
        }

        return in_array($feedId, array_map('strval', $enabledFeeds), true);
    }

    protected function getFeedCategoryId(string $feedId): ?string {
        if (isset($this->feedCategoryCache[$feedId])) {
            return $this->feedCategoryCache[$feedId];
        }

        try {
            $feedDAO = FreshRSS_Factory::createFeedDao();
            $feed = $feedDAO->searchById((int) $feedId);
            if ($feed !== null) {
                $catId = (string) $feed->category();
                $this->feedCategoryCache[$feedId] = $catId;
                return $catId;
            }
        } catch (\Throwable $e) {
            // Ignorer
        }

        return null;
    }

    public function fetchUrl(string $url): string {
        $userAgent = (string) ($this->getUserConfigurationValue('user_agent') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        $timeout = (int) ($this->getUserConfigurationValue('timeout') ?: 12);

        @set_time_limit($timeout + 5);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: fr,fr-FR;q=0.9,en;q=0.8',
                'Cache-Control: max-age=0'
            ]
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content === false || $httpCode < 200 || $httpCode >= 400) {
            return '';
        }

        return (string) $content;
    }
}