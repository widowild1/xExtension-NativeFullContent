<?php

require_once __DIR__ . '/lib/Readability.php';

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

        $readability = new StandaloneReadability($html, $url);
        $fullContent = $readability->getContent();

        if (mb_strlen($fullContent) > mb_strlen($entry->content())) {
            $entry->_content($fullContent);
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