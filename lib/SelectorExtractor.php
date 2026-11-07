<?php

final class SelectorExtractor {

    public static function extract(string $html, string $url, string $contentSelector, string $stripSelector = ''): string {
        if (trim($html) === '' || trim($contentSelector) === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $encodedHtml = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8">' . $encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // 1. Suppression des éléments inutiles
        if ($stripSelector !== '') {
            $stripXPath = self::cssToXPath($stripSelector);
            if ($stripXPath !== '') {
                $nodesToRemove = $xpath->query($stripXPath);
                if ($nodesToRemove !== false) {
                    $elements = [];
                    foreach ($nodesToRemove as $node) {
                        $elements[] = $node;
                    }
                    foreach ($elements as $el) {
                        if ($el->parentNode) {
                            $el->parentNode->removeChild($el);
                        }
                    }
                }
            }
        }

        // 2. Extraction du conteneur d'article
        $contentXPath = self::cssToXPath($contentSelector);
        if ($contentXPath === '') {
            return '';
        }

        $targets = $xpath->query($contentXPath);
        if ($targets === false || $targets->length === 0) {
            return '';
        }

        $extractedHtml = '';
        foreach ($targets as $target) {
            foreach ($target->childNodes as $child) {
                $extractedHtml .= $dom->saveHTML($child);
            }
        }

        if (trim($extractedHtml) === '') {
            return '';
        }

        // 3. Normalisation des images et liens relatifs
        return self::normalizeFragment($extractedHtml, $url);
    }

    public static function cssToXPath(string $selector): string {
        $selector = trim($selector);
        if ($selector === '') {
            return '';
        }

        if (str_starts_with($selector, '/') || str_starts_with($selector, './')) {
            return $selector;
        }

        $groups = array_map('trim', explode(',', $selector));
        $xpathExpressions = [];

        foreach ($groups as $group) {
            $tokens = preg_split('/\s+/', $group);
            $subXPath = '';
            foreach ($tokens as $token) {
                $subXPath .= '//' . self::translateToken($token);
            }
            if ($subXPath !== '') {
                $xpathExpressions[] = $subXPath;
            }
        }

        return implode(' | ', $xpathExpressions);
    }

    private static function translateToken(string $token): string {
        if (preg_match('/^([a-zA-Z0-9*_-]+)?(#([a-zA-Z0-9_-]+))?(\.([a-zA-Z0-9_-]+))?$/', $token, $matches)) {
            $element = !empty($matches[1]) ? $matches[1] : '*';
            $conditions = [];

            if (!empty($matches[3])) {
                $id = $matches[3];
                $conditions[] = "@id='{$id}'";
            }
            if (!empty($matches[5])) {
                $class = $matches[5];
                $conditions[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')";
            }

            if (!empty($conditions)) {
                return $element . '[' . implode(' and ', $conditions) . ']';
            }
            return $element;
        }

        return '*';
    }

    private static function normalizeFragment(string $fragmentHtml, string $baseUrl): string {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $encodedHtml = mb_convert_encoding($fragmentHtml, 'HTML-ENTITIES', 'UTF-8');
        $dom->loadHTML('<?xml encoding="UTF-8"><body>' . $encodedHtml . '</body>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        // Résolution du lazy loading et images
        $images = $dom->getElementsByTagName('img');
        foreach ($images as $img) {
            if ($img->hasAttribute('data-src')) {
                $img->setAttribute('src', $img->getAttribute('data-src'));
            } elseif ($img->hasAttribute('data-original')) {
                $img->setAttribute('src', $img->getAttribute('data-original'));
            } elseif ($img->hasAttribute('data-lazy-src')) {
                $img->setAttribute('src', $img->getAttribute('data-lazy-src'));
            }

            $src = $img->getAttribute('src');
            if ($src !== '') {
                $img->setAttribute('src', self::makeAbsoluteUrl($src, $baseUrl));
            }
        }

        // Liens relatifs
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $a) {
            $href = $a->getAttribute('href');
            if ($href !== '' && !str_starts_with($href, '#') && !str_starts_with($href, 'javascript:')) {
                $a->setAttribute('href', self::makeAbsoluteUrl($href, $baseUrl));
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body !== null) {
            $result = '';
            foreach ($body->childNodes as $child) {
                $result .= $dom->saveHTML($child);
            }
            return trim($result);
        }

        return $fragmentHtml;
    }

    public static function makeAbsoluteUrl(string $relativeUrl, string $baseUrl): string {
        if (parse_url($relativeUrl, PHP_URL_SCHEME) != '') {
            return $relativeUrl;
        }

        $baseParts = parse_url($baseUrl);
        $scheme = $baseParts['scheme'] ?? 'http';
        $host = $baseParts['host'] ?? '';
        $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

        if (str_starts_with($relativeUrl, '//')) {
            return $scheme . ':' . $relativeUrl;
        }

        if (str_starts_with($relativeUrl, '/')) {
            return $scheme . '://' . $host . $port . $relativeUrl;
        }

        $path = $baseParts['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '', $path);
        return $scheme . '://' . $host . $port . $dir . '/' . $relativeUrl;
    }
}