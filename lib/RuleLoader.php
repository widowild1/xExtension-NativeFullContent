<?php

final class RuleLoader {

    private string $rulesDir;
    /** @var array<string, array|null> Cache mémoire */
    private array $resolvedCache = [];

    public function __construct(string $rulesDir) {
        $this->rulesDir = rtrim($rulesDir, '/\\');
    }

    public function getRuleForUrl(string $url): ?array {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return null;
        }

        return $this->getRuleForHost($host);
    }

    public function getRuleForHost(string $host): ?array {
        $host = strtolower(trim($host));

        if (array_key_exists($host, $this->resolvedCache)) {
            return $this->resolvedCache[$host];
        }

        $candidates = [];
        $candidates[] = $host;

        if (str_starts_with($host, 'www.')) {
            $candidates[] = substr($host, 4);
        }

        $parts = explode('.', $host);
        while (count($parts) > 2) {
            array_shift($parts);
            $candidates[] = implode('.', $parts);
        }

        $candidates = array_unique($candidates);

        foreach ($candidates as $candidate) {
            $file = $this->rulesDir . '/' . $candidate . '.json';
            if (is_file($file) && is_readable($file)) {
                $raw = file_get_contents($file);
                $rule = json_decode($raw, true);

                if (is_array($rule) && !empty($rule['content'])) {
                    $rule['_matched_domain'] = $candidate;
                    $this->resolvedCache[$host] = $rule;
                    return $rule;
                }
            }
        }

        $this->resolvedCache[$host] = null;
        return null;
    }

    public function getAllRules(): array {
        $rules = [];
        if (!is_dir($this->rulesDir)) {
            return $rules;
        }

        $files = glob($this->rulesDir . '/*.json');
        if ($files === false) {
            return $rules;
        }

        foreach ($files as $file) {
            $domain = basename($file, '.json');
            $raw = file_get_contents($file);
            $rule = json_decode($raw, true);
            if (is_array($rule)) {
                $rules[$domain] = $rule;
            }
        }

        ksort($rules);
        return $rules;
    }
}