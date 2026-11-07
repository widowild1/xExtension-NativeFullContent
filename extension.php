<?php
require_once __DIR__ . '/lib/RuleLoader.php';

$extension = Minz_ExtensionManager::findExtension('NativeFullContent') 
          ?: Minz_ExtensionManager::findExtension('xExtension-NativeFullContent');

$extName = $extension !== null ? $extension->getName() : 'NativeFullContent';

$scopeMode = 'feed';
$enabledCategories = [];
$enabledFeeds = [];
$userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
$timeout = 12;
$maxPerFeed = 5;

if ($extension !== null) {
    $scopeMode = (string) ($extension->getUserConfigurationValue('scope_mode') ?: 'feed');
    $rawCats = $extension->getUserConfigurationValue('enabled_categories');
    $enabledCategories = is_array($rawCats) ? array_map('strval', $rawCats) : [];

    $rawFeeds = $extension->getUserConfigurationValue('enabled_feeds');
    $enabledFeeds = is_array($rawFeeds) ? array_map('strval', $rawFeeds) : [];

    $userAgent = (string) ($extension->getUserConfigurationValue('user_agent') ?: $userAgent);
    $timeout = (int) ($extension->getUserConfigurationValue('timeout') ?: 12);
    $maxPerFeed = (int) ($extension->getUserConfigurationValue('max_per_feed') ?: 5);
}

$categoryDAO = FreshRSS_Factory::createCategoryDao();
$categories = $categoryDAO->listCategories(true);
if (!is_array($categories)) {
    $categories = [];
}

$rulesDir = __DIR__ . '/rules';
$installedRules = [];
if (class_exists('RuleLoader') && is_dir($rulesDir)) {
    $ruleLoader = new RuleLoader($rulesDir);
    $installedRules = $ruleLoader->getAllRules();
}

$csrfToken = FreshRSS_Auth::csrfToken();

// Lecture des résultats persistés en session
$testResult = $_SESSION['native_test_result'] ?? null;
$testError = $_SESSION['native_test_error'] ?? null;
$testUrl = $_SESSION['native_test_url'] ?? '';
$savedNotice = $_SESSION['native_save_notice'] ?? false;

// Nettoyage après affichage
unset($_SESSION['native_test_result'], $_SESSION['native_test_error'], $_SESSION['native_test_url'], $_SESSION['native_save_notice']);
?>

<h2>Configuration de FullText Native</h2>

<?php if ($savedNotice): ?>
    <div class="box" style="background: #dafbe1; border: 1px solid #4ac26b; border-radius: 5px; padding: 12px; margin-bottom: 20px; color: #1a7f37;">
        <strong>✅ Paramètres enregistrés avec succès !</strong>
    </div>
<?php endif; ?>

<!-- 1. BANC DE TEST EN DIRECT -->
<div class="box" style="background: #fdfdfd; border: 1px solid #d0d7de; padding: 15px; border-radius: 6px; margin-bottom: 25px;">
    <h3 style="margin-top: 0;">🔬 Banc d'essai d'extraction (Test en direct)</h3>
    <p><em>Testez l'extraction d'un article en direct via le serveur (sans altérer votre base de données).</em></p>
    
    <form action="<?php echo _url('extension', 'configure', 'e', $extName); ?>" method="post">
        <input type="hidden" name="_csrf" value="<?php echo $csrfToken; ?>" />
        <input type="hidden" name="op" value="test" />
        <div style="display: flex; gap: 10px;">
            <input type="url" name="test_url" value="<?php echo htmlspecialchars($testUrl, ENT_QUOTES, 'UTF-8'); ?>" placeholder="https://exemple.fr/mon-article..." style="flex: 1; padding: 6px 10px;" required />
            <button type="submit" class="btn btn-important">Tester l'extraction</button>
        </div>
    </form>

    <?php if ($testResult !== null): ?>
        <div class="box" style="background: #ffffff; border: 1px solid #2da44e; border-radius: 5px; padding: 14px; margin-top: 15px;">
            <h4 style="margin-top: 0; color: #1a7f37;">✅ Extraction réussie</h4>
            <ul style="margin: 5px 0 10px 20px;">
                <li><strong>Hôte :</strong> <?php echo htmlspecialchars($testResult['debug']['host'] ?? '', ENT_QUOTES, 'UTF-8'); ?></li>
                <li><strong>Moteur utilisé :</strong> <?php echo htmlspecialchars($testResult['debug']['engine'] ?? '', ENT_QUOTES, 'UTF-8'); ?></li>
                <?php if (!empty($testResult['debug']['selector'])): ?>
                    <li><strong>Sélecteur appliqué :</strong> <code><?php echo htmlspecialchars($testResult['debug']['selector'], ENT_QUOTES, 'UTF-8'); ?></code></li>
                <?php endif; ?>
                <li><strong>Longueur du texte :</strong> <?php echo (int)$testResult['length']; ?> caractères</li>
            </ul>
            <details open style="margin-top: 10px;">
                <summary style="cursor: pointer; font-weight: bold; padding: 5px 0;">Aperçu du texte extrait</summary>
                <div style="margin-top: 10px; max-height: 400px; overflow-y: auto; background: #f6f8fa; border: 1px solid #d0d7de; padding: 15px; border-radius: 4px;">
                    <?php echo $testResult['html']; ?>
                </div>
            </details>
        </div>
    <?php elseif ($testError !== null): ?>
        <div class="box" style="background: #ffebe9; border: 1px solid #ff8182; border-radius: 5px; padding: 14px; margin-top: 15px; color: #cf222e;">
            <h4 style="margin-top: 0;">❌ Échec de l'extraction</h4>
            <p style="margin: 0;"><strong>Détail :</strong> <?php echo htmlspecialchars($testError, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
    <?php endif; ?>
</div>

<!-- 2. FORMULAIRE PRINCIPAL DE CONFIGURATION -->
<form action="<?php echo _url('extension', 'configure', 'e', $extName); ?>" method="post">
    <input type="hidden" name="_csrf" value="<?php echo $csrfToken; ?>" />
    <input type="hidden" name="op" value="save" />

    <h3>Paramètres réseau & Anti-Timeout Cron</h3>
    <div class="form-group">
        <label class="group-name" for="user_agent">User-Agent HTTP :</label>
        <div class="group-controls">
            <input type="text" id="user_agent" name="user_agent" class="text" style="width: 100%;" value="<?php echo htmlspecialchars($userAgent, ENT_QUOTES, 'UTF-8'); ?>" />
        </div>
    </div>

    <div class="form-group">
        <label class="group-name" for="timeout">Timeout par requête (sec) :</label>
        <div class="group-controls">
            <input type="number" id="timeout" name="timeout" min="2" max="60" value="<?php echo (int) $timeout; ?>" />
        </div>
    </div>

    <div class="form-group">
        <label class="group-name" for="max_per_feed">Plafond d'articles par flux lors du Cron :</label>
        <div class="group-controls">
            <input type="number" id="max_per_feed" name="max_per_feed" min="1" max="50" value="<?php echo (int) $maxPerFeed; ?>" />
            <small><em>Limite le nombre d'articles scrapés à chaque passe pour protéger la tâche cron.</em></small>
        </div>
    </div>

    <hr />

    <h3>Portée de l'extraction automatique</h3>
    <div class="form-group">
        <div class="group-controls">
            <label class="radio">
                <input type="radio" name="scope_mode" value="all" <?php echo $scopeMode === 'all' ? 'checked' : ''; ?> />
                <strong>La totalité des flux</strong> (traiter automatiquement tous les flux)
            </label><br />
            <label class="radio">
                <input type="radio" name="scope_mode" value="category" <?php echo $scopeMode === 'category' ? 'checked' : ''; ?> />
                <strong>Par catégorie</strong> (traiter les catégories cochées ci-dessous)
            </label><br />
            <label class="radio">
                <input type="radio" name="scope_mode" value="feed" <?php echo $scopeMode === 'feed' ? 'checked' : ''; ?> />
                <strong>À l'unité</strong> (traiter les flux individuels cochés ci-dessous)
            </label>
        </div>
    </div>

    <!-- Panel Catégories -->
    <details <?php echo ($scopeMode === 'category' || empty($scopeMode)) ? 'open' : ''; ?> style="margin-top: 15px; border: 1px solid #d0d7de; border-radius: 6px; padding: 10px; background: #fafafa;">
        <summary style="cursor: pointer; font-weight: bold; padding: 4px 0;">📁 Sélection des catégories (mode "Par catégorie")</summary>
        <div style="max-height: 250px; overflow-y: auto; padding: 10px 0;">
            <?php foreach ($categories as $category): ?>
                <?php $feedList = is_array($category->feeds()) ? $category->feeds() : []; ?>
                <label class="checkbox" style="display: block; margin-bottom: 4px;">
                    <input type="checkbox" name="enabled_categories[]" value="<?php echo (string)$category->id(); ?>" <?php echo in_array((string)$category->id(), $enabledCategories, true) ? 'checked' : ''; ?> />
                    <strong><?php echo htmlspecialchars($category->name(), ENT_QUOTES, 'UTF-8'); ?></strong>
                    <small style="color: #666;">(<?php echo count($feedList); ?> flux)</small>
                </label>
            <?php endforeach; ?>
        </div>
    </details>

    <!-- Panel Flux individuels -->
    <details <?php echo $scopeMode === 'feed' ? 'open' : ''; ?> style="margin-top: 15px; border: 1px solid #d0d7de; border-radius: 6px; padding: 10px; background: #fafafa;">
        <summary style="cursor: pointer; font-weight: bold; padding: 4px 0;">📰 Sélection des flux individuels (mode "À l'unité")</summary>
        <div style="max-height: 350px; overflow-y: auto; padding: 10px 0;">
            <?php foreach ($categories as $category): ?>
                <?php $feedList = is_array($category->feeds()) ? $category->feeds() : []; ?>
                <h5 style="margin: 10px 0 4px 0; border-bottom: 1px solid #ddd; padding-bottom: 2px;">
                    📁 <?php echo htmlspecialchars($category->name(), ENT_QUOTES, 'UTF-8'); ?>
                </h5>
                <div style="margin-left: 15px; margin-bottom: 8px;">
                    <?php if (empty($feedList)): ?>
                        <em style="color: #888; font-size: 0.9em;">Aucun flux dans cette catégorie.</em><br />
                    <?php else: ?>
                        <?php foreach ($feedList as $feed): ?>
                            <label class="checkbox" style="display: block; margin-bottom: 3px;">
                                <input type="checkbox" name="enabled_feeds[]" value="<?php echo (string)$feed->id(); ?>" <?php echo in_array((string)$feed->id(), $enabledFeeds, true) ? 'checked' : ''; ?> />
                                <?php echo htmlspecialchars($feed->name(), ENT_QUOTES, 'UTF-8'); ?>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </details>

    <hr />

    <h3>Règles par domaine actives (<code>rules/*.json</code>)</h3>
    <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9em;">
        <thead>
            <tr style="background: #f0f0f0; border-bottom: 2px solid #ccc;">
                <th style="padding: 6px;">Domaine</th>
                <th style="padding: 6px;">Sélecteur de contenu</th>
                <th style="padding: 6px;">Éléments exclus</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($installedRules)): ?>
                <tr>
                    <td colspan="3" style="padding: 10px; color: #777;">Aucun fichier JSON dans <code>rules/</code>. Repli 100% FiveFilters Readability.</td>
                </tr>
            <?php else: ?>
                <?php foreach ($installedRules as $domain => $rule): ?>
                    <tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 6px;"><strong><?php echo htmlspecialchars($domain, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                        <td style="padding: 6px;"><code><?php echo htmlspecialchars($rule['content'] ?? '', ENT_QUOTES, 'UTF-8'); ?></code></td>
                        <td style="padding: 6px; color: #888;"><code><?php echo htmlspecialchars($rule['strip'] ?? '-', ENT_QUOTES, 'UTF-8'); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="form-actions" style="margin-top: 20px;">
        <button type="submit" class="btn btn-important">Enregistrer les options</button>
    </div>
</form>