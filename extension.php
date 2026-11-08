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

        Minz_View::appendScript($this->getFileUrl('script.js', 'js'));
        Minz_View::appendStyle($this->getFileUrl('style.css', 'css'));
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
            $op = (string) Minz_Request::param('op', '');

            if ($op === 'test') {
                $testUrl = trim((string) Minz_Request::param('test_url', ''));
                header('Content-Type: text/html; charset=UTF-8');

                echo '<!DOCTYPE html><html><head><meta charset="utf-8">';
                echo '<style>body{font-family:sans-serif;font-size:13px;margin:10px;color:#24292f;}'
                   . '.card-ok{background:#f0fff4;border:1px solid #48bb78;border-radius:5px;padding:12px;color:#22543d;}'
                   . '.card-ko{background:#fff5f5;border:1px solid #f56565;border-radius:5px;padding:12px;color:#742a2a;}'
                   . '.preview{margin-top:10px;max-height:280px;overflow-y:auto;background:#fff;border:1px solid #cbd5e0;padding:10px;border-radius:4px;}</style>';
                echo '</head><body>';

                if ($testUrl !== '' && filter_var($testUrl, FILTER_VALIDATE_URL)) {
                    $debugInfo = [
                        'host' => parse_url($testUrl, PHP_URL_HOST),
                        'engine' => 'Aucun',
                    ];

                    $content = $this->extractArticleContent($testUrl, $debugInfo);

                    if ($content !== null && trim($content) !== '') {
                        echo '<div class="card-ok">';
                        echo '<strong>✅ Extraction réussie !</strong><br>';
                        echo 'Hôte : ' . htmlspecialchars($debugInfo['host'] ?? '', ENT_QUOTES) . '<br>';
                        echo 'Moteur : ' . htmlspecialchars($debugInfo['engine'] ?? '', ENT_QUOTES) . '<br>';
                        if (!empty($debugInfo['selector'])) {
                            echo 'Sélecteur : <code>' . htmlspecialchars($debugInfo['selector'], ENT_QUOTES) . '</code><br>';
                        }
                        echo 'Longueur : ' . mb_strlen($content) . ' caractères.';
                        echo '<details style="margin-top:8px;"><summary style="cursor:pointer;font-weight:bold;">Voir le texte extrait</summary>';
                        echo '<div class="preview">' . $content . '</div>';
                        echo '</details></div>';
                    } else {
                        echo '<div class="card-ko">';
                        echo '<strong>❌ Échec de l\'analyse :</strong> ' . htmlspecialchars($debugInfo['error'] ?? 'Aucun contenu significatif extrait.', ENT_QUOTES);
                        echo '</div>';
                    }
                } else {
                    echo '<div class="card-ko"><strong>❌ Erreur :</strong> Veuillez saisir une URL valide (ex: https://...).</div>';
                }

                echo '</body></html>';
                exit;
            }

            if ($op === 'save') {
                $scopeMode = (string) Minz_Request::param('scope_mode', 'feed');
                if (!in_array($scopeMode, ['all', 'category', 'feed'], true)) {
                    $scopeMode = 'feed';
                }

                $enabledCategories = Minz_Request::param('enabled_categories', []);
                $enabledFeeds = Minz_Request::param('enabled_feeds', []);
                $userAgent = (string) Minz_Request::param('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0');
                $timeout = (int) Minz_Request::param('timeout', 15);
                $maxPerFeed = (int) Minz_Request::param('max_per_feed', 5);

                $this->setUserConfigurationValue('scope_mode', $scopeMode);
                $this->setUserConfigurationValue('enabled_categories', is_array($enabledCategories) ? array_map('strval', $enabledCategories) : []);
                $this->setUserConfigurationValue('enabled_feeds', is_array($enabledFeeds) ? array_map('strval', $enabledFeeds) : []);
                $this->setUserConfigurationValue('user_agent', $userAgent);
                $this->setUserConfigurationValue('timeout', $timeout > 0 ? $timeout : 15);
                $this->setUserConfigurationValue('max_per_feed', $maxPerFeed > 0 ? $maxPerFeed : 5);
            }
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

        if (str_contains($entry->content(), 'native-fullcontent-header-bar')) {
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
        $cleanUrl = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = $this->fetchUrl($cleanUrl, $debugInfo);

        // Fallback immédiat si le serveur distant renvoie 403, 429 ou un refus HTML
        if ($html === '') {
            $wpContent = $this->fetchViaWordPressApi($cleanUrl, $debugInfo);
            if ($wpContent !== null) {
                return $wpContent;
            }
            return null;
        }

        // 1. Règles spécifiques par domaine (rules/*.json)
        $rule = $this->getRuleLoader()->getRuleForUrl($cleanUrl);
        if ($rule !== null) {
            $customContent = SelectorExtractor::extract(
                $html,
                $cleanUrl,
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

        // 2. Détection dynamique de Readability
        $readabilityClass = null;
        $configClass = null;

        if (class_exists('fivefilters\Readability\Readability')) {
            $readabilityClass = 'fivefilters\Readability\Readability';
            $configClass = 'fivefilters\Readability\Configuration';
        } elseif (class_exists('FiveFilters\Readability\Readability')) {
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
                    $config->setOriginalURL($cleanUrl);
                }

                $readability = new $readabilityClass($config);
                if ($readability->parse($html)) {
                    $content = $readability->getContent();
                    $content = SelectorExtractor::cleanAndNormalize($content, $cleanUrl);

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
        }

        // 3. Fallback secondaire via API WordPress si Readability échoue sur le HTML
        $wpContent = $this->fetchViaWordPressApi($cleanUrl, $debugInfo);
        if ($wpContent !== null) {
            return $wpContent;
        }

        return null;
    }

    /**
     * Tente de récupérer l'article via l'API REST WordPress (Automattic / VIP / REST local)
     */
    public function fetchViaWordPressApi(string $url, ?array &$debugInfo = null): ?string {
        $parsed = parse_url($url);
        $host = (string) ($parsed['host'] ?? '');
        $path = trim((string) ($parsed['path'] ?? ''), '/');
        $slug = basename($path);

        if ($host === '' || $slug === '' || $slug === $host) {
            return null;
        }

        $endpoints = [
            'https://public-api.wordpress.com/wp/v2/sites/' . urlencode($host) . '/posts?slug=' . urlencode($slug),
            'https://' . $host . '/wp-json/wp/v2/posts?slug=' . urlencode($slug),
        ];

        foreach ($endpoints as $apiUrl) {
            $ch = curl_init($apiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_HTTPHEADER     => [
                    'Accept: application/json',
                ],
            ]);

            $jsonStr = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && is_string($jsonStr) && $jsonStr !== '') {
                $data = json_decode($jsonStr, true);
                if (is_array($data) && !empty($data[0]['content']['rendered'])) {
                    $rawHtml = (string) $data[0]['content']['rendered'];
                    $content = SelectorExtractor::cleanAndNormalize($rawHtml, $url);

                    if (mb_strlen($content) > 100) {
                        if ($debugInfo !== null) {
                            $isWpCom = str_contains($apiUrl, 'public-api.wordpress.com');
                            $debugInfo['engine'] = 'API WordPress REST (' . ($isWpCom ? 'WordPress.com' : 'Locale') . ')';
                            if (!empty($data[0]['title']['rendered'])) {
                                $debugInfo['title'] = html_entity_decode((string) $data[0]['title']['rendered'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            }
                            unset($debugInfo['error']);
                        }
                        return $content;
                    }
                }
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

    public function fetchUrl(string $url, ?array &$debugInfo = null): string {
        $defaultUa = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:125.0) Gecko/20100101 Firefox/125.0';
        $userAgent = (string) ($this->getUserConfigurationValue('user_agent') ?: $defaultUa);
        $timeout = (int) ($this->getUserConfigurationValue('timeout') ?: 15);

        @set_time_limit($timeout + 5);

        $parsed = parse_url($url);
        $host = $parsed['host'] ?? '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '', // Gzip, deflate, br
            CURLOPT_COOKIEFILE => '', // Active le moteur de cookies mémoire pour les redirections
            CURLOPT_AUTOREFERER => true,
            CURLOPT_REFERER => 'https://www.google.com/',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                'Accept-Language: fr-FR,fr;q=0.9,en-US;q=0.8,en;q=0.7',
                'Sec-Fetch-Dest: document',
                'Sec-Fetch-Mode: navigate',
                'Sec-Fetch-Site: cross-site',
                'Sec-Fetch-User: ?1',
                'Upgrade-Insecure-Requests: 1',
                'Cache-Control: max-age=0'
            ]
        ]);

        $content = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($content === false || $httpCode < 200 || $httpCode >= 400) {
            if ($debugInfo !== null) {
                if ($curlError !== '') {
                    $debugInfo['error'] = 'Erreur réseau cURL : ' . $curlError;
                } else {
                    $debugInfo['error'] = 'Le serveur distant a renvoyé un code HTTP ' . $httpCode . ' (' . $host . ')';
                }
            }
            return '';
        }

        return (string) $content;
    }
}