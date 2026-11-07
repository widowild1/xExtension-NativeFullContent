<?php

final class FreshExtension_nativeFullContent_Controller extends Minz_ActionController {

    #[\Override]
    public function firstAction(): void {
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
        }
    }

    /**
     * Action AJAX : Banc de test d'URL depuis la configuration
     */
    public function testAction(): void {
        header('Content-Type: application/json; charset=UTF-8');

        $url = trim((string) Minz_Request::param('url', ''));
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['success' => false, 'error' => 'URL invalide ou absente.']);
            exit;
        }

        /** @var NativeFullContentExtension $extension */
        $extension = Minz_ExtensionManager::findExtension('NativeFullContent');
        if ($extension === null) {
            echo json_encode(['success' => false, 'error' => 'Extension introuvable.']);
            exit;
        }

        $debugInfo = [
            'host' => parse_url($url, PHP_URL_HOST),
            'engine' => 'Aucun',
        ];

        $content = $extension->extractArticleContent($url, $debugInfo);

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

    /**
     * Action AJAX : Récupération à la volée sur un article ouvert
     */
    public function fetchAction(): void {
        header('Content-Type: application/json; charset=UTF-8');

        $entryId = (int) Minz_Request::param('id', 0);
        if ($entryId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Identifiant d\'article invalide.']);
            exit;
        }

        /** @var NativeFullContentExtension $extension */
        $extension = Minz_ExtensionManager::findExtension('NativeFullContent');
        if ($extension === null) {
            echo json_encode(['success' => false, 'error' => 'Extension introuvable.']);
            exit;
        }

        $entryDAO = FreshRSS_Factory::createEntryDao();
        $entry = $entryDAO->searchById($entryId);

        if ($entry === null) {
            echo json_encode(['success' => false, 'error' => 'Article introuvable en base de données.']);
            exit;
        }

        $url = $entry->link();
        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => 'L\'article ne comporte aucune URL valide.']);
            exit;
        }

        $debugInfo = [];
        $fullContent = $extension->extractArticleContent($url, $debugInfo);

        if ($fullContent === null || trim($fullContent) === '') {
            echo json_encode(['success' => false, 'error' => 'Échec de l\'aspiration de l\'article distant.']);
            exit;
        }

        // Réinjection de la barre d'action au sommet
        $buttonHtml = '<div class="native-fullcontent-header-bar" data-entry-id="' . $entryId . '">'
                    . '<span class="native-badge-success">✅ Texte intégral récupéré (' . mb_strlen($fullContent) . ' caractères)</span>'
                    . '</div>';

        $savedContent = $buttonHtml . $fullContent;
        $entry->_content($savedContent);

        // Sauvegarde immédiate en base de données
        if (method_exists($entryDAO, 'updateContent')) {
            $entryDAO->updateContent($entryId, $savedContent);
        } elseif (method_exists($entryDAO, 'updateEntry')) {
            $entryDAO->updateEntry($entry);
        }

        echo json_encode([
            'success' => true,
            'entryId' => $entryId,
            'newContent' => $savedContent
        ]);
        exit;
    }
}