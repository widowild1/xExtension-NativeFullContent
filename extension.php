<?php

$autoloadCandidates = [
    __DIR__ . '/vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
];
foreach ($autoloadCandidates as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

require_once __DIR__ . '/lib/RuleLoader.php';
require_once __DIR__ . '/lib/SelectorExtractor.php';

final class NativeFullContentExtension extends Minz_Extension {

    public static ?array $testResult = null;
    public static ?string $testError = null;
    public static string $testUrl = '';

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

        if (Minz_Request::isPost()) {
            $op = (string) Minz_Request::param('op', 'save');

            // 1. Traitement du banc d'essai (100% PHP natif)
            if ($op === 'test') {
                self::$testUrl = trim((string) Minz_Request::param('test_url', ''));
                if (self::$testUrl !== '' && filter_var(self::$testUrl, FILTER_VALIDATE_URL)) {
                    $debugInfo = [
                        'host' => parse_url(self::$testUrl, PHP_URL_HOST),
                        'engine' => 'Aucun',
                    ];

                    $content = $this->extractArticleContent(self::$testUrl, $debugInfo);

                    if ($content !== null && trim($content) !== '') {
                        self::$testResult = [
                            'html' => $content,
                            'length' => mb_strlen($content),
                            'debug' => $debugInfo,
                        ];
                    } else {
                        self::$testError = $debugInfo['error'] ?? 'Aucun contenu significatif n\'a pu être extrait de cette URL.';
                        self::$testResult = null;
                    }
                } else {
                    self::$testError = 'Veuillez saisir une URL valide (commençant par http:// ou https://).';
                    self::$testResult = null;
                }
                return;
            }

            // 2. Sauvegarde des options
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
        $buttonHtml = '<div class="native-fullcontent-header-bar" style="margin-bottom:12px;padding:6px 10px;background:#f7f9fa;border-left:3px solid #0066cc;">'
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
                $debugInfo['error'] = 'Échec de la connexion HTTP (serveur distant inaccessible, code d\'erreur HTTP >= 400 ou timeout).';
            }
            return null;
        }

        // 1. Règles par domaine (rules/*.json)
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

        // 2. Détection de la classe Readability
        $readabilityClass = null;
        $configClass = null;

        if (class_exists('FiveFilters\Readability\Readability')) {
            $readabilityClass = 'FiveFilters\Readability\Readability';
            $configClass = 'FiveFilters\Readability\Configuration';
        } elseif (class_exists('andreskrey\Readability\Readability')) {
            $readabilityClass = 'andreskrey\Readability\Readability';
            $configClass = 'andreskrey\Readability\Configuration';
        }

        if ($readabilityClass !== null && $configClass !== null) {
            try {
                $config = new $configClass();
                if (method_exists($config, 'setFixRelativeURLs')) {
                    $config->setFixRelativeURLs(true);
                }
                if (method_exists($config, 'setOriginalURL')) {
                    $config->setOriginalURL($url);
                }

                $readability = new $readabilityClass($config);
                if ($readability->parse($html)) {
                    $content = $readability->getContent();
                    $content = SelectorExtractor::extract($content, $url, 'body');

                    if ($debugInfo !== null) {
                        $debugInfo['engine'] = 'Readability (' . $readabilityClass . ')';
                        if (method_exists($readability, 'getTitle')) {
                            $debugInfo['title'] = $readability->getTitle();
                        }
                    }
                    return $content;
                }
            } catch (\Throwable $e) {
                if ($debugInfo !== null) {
                    $debugInfo['error'] = 'Erreur Readability : ' . $e->getMessage();
                }
            }
        } else {
            if ($debugInfo !== null) {
                $debugInfo['error'] = 'Bibliothèque Readability absente. Exécutez composer install dans le dossier de l\'extension.';
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