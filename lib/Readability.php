<?php

class StandaloneReadability {
    protected string $html;
    protected string $url;
    protected ?DOMDocument $dom = null;

    protected array $badTags = [
        'script', 'style', 'noscript', 'iframe', 'svg', 
        'header', 'footer', 'nav', 'aside', 'form'
    ];

    protected string $unlikelyRegex = '/combx|comment|community|disqus|extra|foot|header|menu|remark|rss|shoutbox|sidebar|sponsor|ad-break|agegate|pagination|pager|popup/i';
    protected string $maybeCandidateRegex = '/and|article|body|column|main|shadow|content|entry|post|text|story/i';

    public function __construct(string $html, string $url) {
        $this->html = $html;
        $this->url = $url;
    }

    public function getContent(): string {
        if (trim($this->html) === '') {
            return '';
        }

        libxml_use_internal_errors(true);
        $this->dom = new DOMDocument('1.0', 'UTF-8');
        
        $encodedHtml = mb_convert_encoding($this->html, 'HTML-ENTITIES', 'UTF-8');
        $this->dom->loadHTML('<?xml encoding="UTF-8">' . $encodedHtml, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $this->removeBadTags();
        $this->fixImagesAndLinks();

        $xpath = new DOMXPath($this->dom);
        $articles = $xpath->query('//article');
        
        $targetNode = null;
        if ($articles !== false && $articles->length > 0) {
            $targetNode = $articles->item(0);
        } else {
            $mainNodes = $xpath->query('//*[@id="main" or @id="content" or @class="post-content" or @class="entry-content" or contains(@class, "article")]');
            if ($mainNodes !== false && $mainNodes->length > 0) {
                $targetNode = $mainNodes->item(0);
            }
        }

        if ($targetNode === null) {
            $targetNode = $this->dom->getElementsByTagName('body')->item(0);
        }

        if ($targetNode === null) {
            return '';
        }

        $result = '';
        foreach ($targetNode->childNodes as $child) {
            $result .= $this->dom->saveHTML($child);
        }

        return trim($result);
    }

    protected function removeBadTags(): void {
        foreach ($this->badTags as $tag) {
            $nodes = $this->dom->getElementsByTagName($tag);
            while ($node = $nodes->item(0)) {
                $node->parentNode->removeChild($node);
            }
        }

        $xpath = new DOMXPath($this->dom);
        $allElements = $xpath->query('//*');
        if ($allElements !== false) {
            $toRemove = [];
            foreach ($allElements as $element) {
                $classAndId = ($element->getAttribute('class') ?? '') . ' ' . ($element->getAttribute('id') ?? '');
                if (preg_match($this->unlikelyRegex, $classAndId) && !preg_match($this->maybeCandidateRegex, $classAndId)) {
                    if ($element->nodeName !== 'body' && $element->nodeName !== 'html') {
                        $toRemove[] = $element;
                    }
                }
            }
            foreach ($toRemove as $el) {
                if ($el->parentNode) {
                    $el->parentNode->removeChild($el);
                }
            }
        }
    }

    protected function fixImagesAndLinks(): void {
        $images = $this->dom->getElementsByTagName('img');
        foreach ($images as $img) {
            // Résolution Lazy-load
            if ($img->hasAttribute('data-src')) {
                $img->setAttribute('src', $img->getAttribute('data-src'));
            } elseif ($img->hasAttribute('data-original')) {
                $img->setAttribute('src', $img->getAttribute('data-original'));
            } elseif ($img->hasAttribute('data-lazy-src')) {
                $img->setAttribute('src', $img->getAttribute('data-lazy-src'));
            }

            // Normalisation URL absolue
            $src = $img->getAttribute('src');
            if ($src !== '') {
                $img->setAttribute('src', $this->makeAbsoluteUrl($src, $this->url));
            }
        }

        $links = $this->dom->getElementsByTagName('a');
        foreach ($links as $a) {
            $href = $a->getAttribute('href');
            if ($href !== '' && !str_starts_with($href, '#') && !str_starts_with($href, 'javascript:')) {
                $a->setAttribute('href', $this->makeAbsoluteUrl($href, $this->url));
            }
        }
    }

    protected function makeAbsoluteUrl(string $relativeUrl, string $baseUrl): string {
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