document.addEventListener('DOMContentLoaded', function () {

    // 1. Gestion du bouton "Récupérer le texte complet" à la volée sur un article
    document.body.addEventListener('click', function (e) {
        var btn = e.target.closest('.native-btn-fetch');
        if (!btn) return;

        e.preventDefault();
        var entryId = btn.getAttribute('data-id');
        var originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '⏳ Extraction en cours...';

        var formData = new FormData();
        formData.append('id', entryId);

        fetch('./?c=nativeFullContent&a=fetch', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.success) {
                // Recherche du conteneur de contenu de l'article dans l'interface FreshRSS
                var container = btn.closest('.flux_content') || btn.closest('.content') || btn.closest('[data-entry-id="' + entryId + '"]');
                if (container) {
                    var contentTarget = container.querySelector('.text') || container.querySelector('.content') || container;
                    contentTarget.innerHTML = data.newContent;
                } else {
                    btn.outerHTML = '<span class="native-badge-success">✅ Contenu mis à jour !</span>';
                }
            } else {
                alert('Erreur lors de l\'extraction : ' + (data.error || 'Erreur inconnue'));
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        })
        .catch(function (err) {
            alert('Erreur de communication réseau.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        });
    });

    // 2. Gestion du banc d'essai dans la page de configuration
    var btnTest = document.getElementById('native_btn_run_test');
    if (btnTest) {
        btnTest.addEventListener('click', function () {
            var urlInput = document.getElementById('native_test_url');
            var url = urlInput ? urlInput.value.trim() : '';
            var resultContainer = document.getElementById('native_test_output');

            if (!url) {
                alert('Veuillez saisir une URL complète.');
                return;
            }

            btnTest.disabled = true;
            btnTest.innerHTML = 'Analyse en cours...';
            resultContainer.style.display = 'block';
            resultContainer.innerHTML = '<em>Connexion et extraction du document...</em>';

            var formData = new FormData();
            formData.append('url', url);

            fetch('./?c=nativeFullContent&a=test', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                btnTest.disabled = false;
                btnTest.innerHTML = 'Tester l\'extraction';

                if (data.success) {
                    resultContainer.innerHTML = 
                        '<div class="native-test-card">' +
                            '<h4>Résultat de l\'analyse</h4>' +
                            '<ul>' +
                                '<li><strong>Hôte :</strong> ' + data.debug.host + '</li>' +
                                '<li><strong>Moteur utilisé :</strong> ' + data.debug.engine + '</li>' +
                                '<li><strong>Longueur extraite :</strong> ' + data.length + ' caractères</li>' +
                            '</ul>' +
                            '<details style="margin-top: 10px;" open>' +
                                '<summary style="cursor: pointer; font-weight: bold;">Aperçu du rendu</summary>' +
                                '<div class="native-preview-box">' + data.html + '</div>' +
                            '</details>' +
                        '</div>';
                } else {
                    resultContainer.innerHTML = 
                        '<div class="native-test-card error">' +
                            '<strong>Échec de l\'analyse :</strong> ' + data.error +
                        '</div>';
                }
            })
            .catch(function (err) {
                btnTest.disabled = false;
                btnTest.innerHTML = 'Tester l\'extraction';
                resultContainer.innerHTML = '<div class="native-test-card error">Erreur réseau lors du test.</div>';
            });
        });
    }
});