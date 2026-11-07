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
    /** @var array<string, string> Cache catégorie */
    private array $feedCategoryCache = [];

    #[\Override]
    public function init(): void {
        $this->ruleLoader = new RuleLoader(__DIR__ . '/rules');
        $this->registerHook('entry_before_insert', [$this, 'parseArticleBeforeInsert']);
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
            $scopeMode = Minz_Request::paramString('scope_mode', 'feed');
            if (!in_array($scopeMode, ['all', 'category', 'feed'], true)) {
                $scopeMode = 'feed';
            }

            $enabledCategories = Minz_Request::paramArray('enabled_categories', []);
            $enabledFeeds = Minz_Request::paramArray('enabled_feeds', []);
            $userAgent = Minz_Request::paramString('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            $timeout = (int) Minz_Request::param('timeout', 15);

            $this->setUserConfigurationValue('scope_mode', $scopeMode);
            $this->setUserConfigurationValue('enabled_categories', $enabledCategories);
            $this->setUserConfigurationValue('enabled_feeds', $enabledFeeds);
            $this->setUserConfigurationValue('user_agent', $userAgent);
            $this->setUserConfigurationValue('timeout', $timeout > 0 ? $timeout : 15);
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

        $url = $entry->link();
        if (empty($url)) {
            return $entry;
        }

        $html = $this->fetchUrl($url);
        if ($html === '') {
            return $entry;
        }

        // 1. Détection automatique du nom de domaine et chargement de la règle
        $rule = $this->getRuleLoader()->getRuleForUrl($url);
        if ($rule !== null) {
            $customContent = SelectorExtractor::extract(
                $html,
                $url,
                $rule['content'],
                $rule['strip'] ?? ''
            );

            if (mb_strlen($customContent) > 100) {
                $entry->_content($customContent);
                return $entry;
            }
        }

        // 2. Repli automatique (fallback) sur FiveFilters Readability
        if (class_exists('FiveFilters\Readability\Readability')) {
            try {
                $config = new Configuration();
                $config->setFixRelativeURLs(true);
                $config->setOriginalURL($url);

                $readability = new Readability($config);
                if ($readability->parse($html)) {
                    $content = $readability->getContent();
                    $content = SelectorExtractor::extract($content, $url, 'body');

                    if (mb_strlen($content) > mb_strlen($entry->content())) {
                        $entry->_content($content);
                    }
                }
            } catch (\Throwable $e) {
                return $entry;
            }
        }

        return $entry;
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
            return $catId !== null && in_array($catId, $enabledCategories, true);
        }

        $enabledFeeds = $this->getUserConfigurationValue('enabled_feeds');
        if (!is_array($enabledFeeds)) {
            return false;
        }

        return in_array($feedId, $enabledFeeds, true);
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
            // Ignorer l'erreur
        }

        return null;
    }

    protected function fetchUrl(string $url): string {
        $userAgent = $this->getUserConfigurationValue('user_agent') ?: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
        $timeout = (int) ($this->getUserConfigurationValue('timeout') ?: 15);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
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