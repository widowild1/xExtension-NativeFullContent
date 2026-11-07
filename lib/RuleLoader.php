<?php

final class RuleLoader {

    private string $rulesDir;
    /** @var array<string, array|null> Cache en mémoire pour la session */
    private array $resolvedCache = [];

    public function __construct(string $rulesDir) {
        $this->rulesDir = rtrim($rulesDir, '/\\');
    }

    /**
     * Détecte le domaine d'une URL et renvoie la règle correspondante
     */
    public function getRuleForUrl(string $url): ?array {
        $host = parse_url($url, PHP_URL_HOST);
        if (empty($host)) {
            return null;
        }

        return $this->getRuleForHost($host);
    }

    /**
     * Recherche une règle pour un hôte en résolvant les sous-domaines (ex: blog.lemonde.fr -> lemonde.fr)
     */
    public function getRuleForHost(string $host): ?array {
        $host = strtolower(trim($host));

        if (array_key_exists($host, $this->resolvedCache)) {
            return $this->resolvedCache[$host];
        }

        // 1. Liste des candidats : host exact, sans 'www.', puis domaines parents
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

        // 2. Vérification sur le disque
        foreach ($candidates as $candidate) {
            $file = $this->rulesDir . '/' . $candidate . '.json';
            if (is_file($file) && is_readable($file)) {
                $raw = file_get_contents($file);
                $rule = json_decode($raw, true);

                if (is_array($rule) && !empty($rule['content'])) {
                    $this->resolvedCache[$host] = $rule;
                    return $rule;
                }
            }
        }

        $this->resolvedCache[$host] = null;
        return null;
    }

    /**
     * Liste toutes les règles présentes sur le disque (pour l'UI de configuration)
     */
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