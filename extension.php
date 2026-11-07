<?php

// Chargement de l'autoloader embarqué de Composer
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use FiveFilters\Readability\Readability;
use FiveFilters\Readability\Configuration;

final class NativeFullContentExtension extends Minz_Extension {

    #[\Override]
    public function init(): void {
        $this->registerHook('entry_before_insert', [$this, 'parseArticleBeforeInsert']);
    }

    #[\Override]
    public function handleConfigureAction(): void {
        $this->registerTranslates();

        if (Minz_Request::isPost()) {
            $enabledFeeds = Minz_Request::paramArray('enabled_feeds', []);
            $userAgent = Minz_Request::paramString('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
            $timeout = (int) Minz_Request::param('timeout', 15);

            $this->setUserConfigurationValue('enabled_feeds', $enabledFeeds);
            $this->setUserConfigurationValue('user_agent', $userAgent);
            $this->setUserConfigurationValue('timeout', $timeout > 0 ? $timeout : 15);
        }
    }

    public function parseArticleBeforeInsert(?FreshRSS_Entry $entry): ?FreshRSS_Entry {
        if ($entry === null) {
            return null;
        }

        // Vérification si la classe FiveFilters est disponible
        if (!class_exists('FiveFilters\Readability\Readability')) {
            return $entry;
        }

        $enabledFeeds = $this->getUserConfigurationValue('enabled_feeds');
        if (!is_array($enabledFeeds)) {
            $enabledFeeds = [];
        }

        $feedId = (string) $entry->feedId();
        if (!in_array($feedId, $enabledFeeds, true)) {
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

        try {
            $config = new Configuration();
            $config->setFixRelativeURLs(true);
            $config->setOriginalURL($url);

            $readability = new Readability($config);
            $parsed = $readability->parse($html);

            if ($parsed) {
                $fullContent = $readability->getContent();

                // Post-traitement du HTML pour réparer le lazy-loading résiduel
                $fullContent = $this->fixLazyLoadingImages($fullContent, $url);

                if (mb_strlen($fullContent) > mb_strlen($entry->content())) {
                    $entry->_content($fullContent);
                }
            }
        } catch (\Throwable $e) {
            // En cas d'erreur de parsing, on laisse l'article original intact
            return $entry;
        }

        return $entry;
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
            CURLOPT_ENCODING => '', // Active la décompression automatique gzip/deflate
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

    protected function fixLazyLoadingImages(string $html, string $baseUrl): string {
        if (trim($html) === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $encodedHtml = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $images = $dom->getElementsByTagName('img');
        $modified = false;

        foreach ($images as $img) {
            if ($img->hasAttribute('data-src')) {
                $img->setAttribute('src', $img->getAttribute('data-src'));
                $modified = true;
            } elseif ($img->hasAttribute('data-original')) {
                $img->setAttribute('src', $img->getAttribute('data-original'));
                $modified = true;
            } elseif ($img->hasAttribute('data-lazy-src')) {
                $img->setAttribute('src', $img->getAttribute('data-lazy-src'));
                $modified = true;
            }
        }

        if (!$modified) {
            return $html;
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $output = '';
            foreach ($body->childNodes as $child) {
                $output .= $dom->saveHTML($child);
            }
            return trim($output);
        }

        return $dom->saveHTML();
    }
}