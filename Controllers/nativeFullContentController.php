<?php

final class FreshExtension_nativeFullContent_Controller extends Minz_ActionController {

    public function firstAction() {
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
        }
    }

    public function fetchAction() {
        header('Content-Type: application/json; charset=UTF-8');

        $entryId = (int) Minz_Request::param('id', 0);
        if ($entryId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Identifiant d\'article invalide.']);
            exit;
        }

        $extension = Minz_ExtensionManager::findExtension('NativeFullContent')
                  ?: Minz_ExtensionManager::findExtension('xExtension-NativeFullContent');

        if ($extension === null) {
            echo json_encode(['success' => false, 'error' => 'Extension introuvable dans Minz_ExtensionManager.']);
            exit;
        }

        $entryDAO = FreshRSS_Factory::createEntryDao();
        $entry = $entryDAO->searchById($entryId);

        if ($entry === null) {
            echo json_encode(['success' => false, 'error' => 'Article introuvable en base.']);
            exit;
        }

        $url = $entry->link();
        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => 'Aucune URL valide sur cet article.']);
            exit;
        }

        $debugInfo = [];
        $fullContent = $extension->extractArticleContent($url, $debugInfo);

        if ($fullContent === null || trim($fullContent) === '') {
            echo json_encode(['success' => false, 'error' => $debugInfo['error'] ?? 'Impossible d\'extraire le contenu distant.']);
            exit;
        }

        $buttonHtml = '<div class="native-fullcontent-header-bar" style="margin-bottom:12px;padding:6px 10px;background:#f7f9fa;border-left:3px solid #0066cc;" data-entry-id="' . $entryId . '">'
                    . '<span class="native-badge-success" style="color:#137333;font-weight:bold;">✅ Texte intégral récupéré (' . mb_strlen($fullContent) . ' caractères)</span>'
                    . '</div>';

        $savedContent = $buttonHtml . $fullContent;
        $entry->_content($savedContent);

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