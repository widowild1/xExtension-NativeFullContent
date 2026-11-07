<?php

declare(strict_types=1);

final class FreshExtension_nativeFullContent_Controller extends Minz_ActionController {

    #[\Override]
    public function firstAction(): void {
        $this->view->_layout(null);
        if (!FreshRSS_Auth::hasAccess()) {
            Minz_Error::error(403);
        }
    }

    public function fetchAction(): void {
        $this->view->_layout(null);
        header('Content-Type: application/json; charset=UTF-8');

        $entryId = trim((string) Minz_Request::param('id', ''));
        if ($entryId === '') {
            echo json_encode(['success' => false, 'error' => 'Identifiant d\'article invalide.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $extension = Minz_ExtensionManager::findExtension('NativeFullContent')
                  ?: Minz_ExtensionManager::findExtension('xExtension-NativeFullContent');

        if ($extension === null) {
            echo json_encode(['success' => false, 'error' => 'Extension introuvable dans Minz_ExtensionManager.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $entryDAO = FreshRSS_Factory::createEntryDao();
        $entry = $entryDAO->searchById($entryId);

        if ($entry === null) {
            echo json_encode(['success' => false, 'error' => 'Article introuvable en base.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $url = $entry->link();
        if (empty($url)) {
            echo json_encode(['success' => false, 'error' => 'Aucune URL valide sur cet article.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $debugInfo = [];
        $fullContent = $extension->extractArticleContent($url, $debugInfo);

        if ($fullContent === null || trim($fullContent) === '') {
            echo json_encode(['success' => false, 'error' => $debugInfo['error'] ?? 'Impossible d\'extraire le contenu distant.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $badgeHtml = '<div class="native-fullcontent-header-bar" data-entry-id="' . htmlspecialchars($entryId, ENT_QUOTES, 'UTF-8') . '" style="margin-bottom:12px;padding:6px 10px;background:#f0fff4;border-left:3px solid #38a169;">'
                    . '<span class="native-badge-success" style="color:#22543d;font-weight:bold;">✅ Texte intégral récupéré (' . mb_strlen($fullContent) . ' caractères)</span>'
                    . '</div>';

        $savedContent = $badgeHtml . $fullContent;
        $entry->_content($savedContent);
        $entry->_lastModified(time());

        $updated = $entryDAO->updateEntry($entry->toArray());

        if (!$updated) {
            echo json_encode(['success' => false, 'error' => 'Échec de la sauvegarde en base de données.'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        echo json_encode([
            'success' => true,
            'entryId' => $entryId,
            'newContent' => $savedContent
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}