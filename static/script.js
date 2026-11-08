function nativeRunTest() {
    var urlInput = document.getElementById('native_test_url');
    var url = urlInput ? urlInput.value.trim() : '';
    var resultContainer = document.getElementById('native_test_output');
    var btnTest = document.getElementById('native_btn_run_test');

    if (!url) {
        alert('Veuillez saisir une URL complète (ex: https://...).');
        return;
    }

    if (btnTest) {
        btnTest.disabled = true;
        btnTest.innerHTML = 'Analyse en cours...';
    }
    if (resultContainer) {
        resultContainer.style.display = 'block';
        resultContainer.innerHTML = '<em>Connexion et extraction du document...</em>';
    }

    var testUrl = './?c=nativeFullContent&a=test';
    var hiddenEndpoint = document.getElementById('native_test_endpoint');
    if (hiddenEndpoint && hiddenEndpoint.value) {
        testUrl = hiddenEndpoint.value;
    }

    var csrfInput = document.querySelector('input[name="_csrf"]');
    var csrfToken = csrfInput ? csrfInput.value : '';

    var formData = new FormData();
    formData.append('url', url);
    formData.append('_csrf', csrfToken);

    fetch(testUrl, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(function (res) {
        return res.text().then(function (text) {
            try {
                return JSON.parse(text);
            } catch (e) {
                throw new Error('Réponse serveur inattendue (code ' + res.status + ') : ' + text.substring(0, 250));
            }
        });
    })
    .then(function (data) {
        if (btnTest) {
            btnTest.disabled = false;
            btnTest.innerHTML = 'Tester l\'extraction';
        }
        if (!resultContainer) return;

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
                        '<summary style="cursor: pointer; font-weight: bold;">Aperçu du texte extrait</summary>' +
                        '<div class="native-preview-box">' + data.html + '</div>' +
                    '</details>' +
                '</div>';
        } else {
            resultContainer.innerHTML = 
                '<div class="native-test-card error">' +
                    '<strong>Échec de l\'analyse :</strong> ' + (data.error || 'Erreur inconnue') +
                '</div>';
        }
    })
    .catch(function (err) {
        if (btnTest) {
            btnTest.disabled = false;
            btnTest.innerHTML = 'Tester l\'extraction';
        }
        if (resultContainer) {
            resultContainer.innerHTML = '<div class="native-test-card error">' + err.message + '</div>';
        }
    });
}

(function () {
    function initNativeFullContent() {
        if (window._nativeFullContentInitialized) return;
        window._nativeFullContentInitialized = true;

        document.body.addEventListener('click', function (e) {
            var btn = e.target.closest('.native-btn-fetch');
            if (!btn) return;

            e.preventDefault();
            var entryId = btn.getAttribute('data-id');
            if (!entryId) return;

            var originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '⏳ Extraction en cours...';

            var formData = new FormData();
            formData.append('id', entryId);

            var csrfToken = (window.context && window.context.csrf)
                ? window.context.csrf
                : (document.querySelector('input[name="_csrf"]')?.value || '');
            if (csrfToken) {
                formData.append('_csrf', csrfToken);
            }

            fetch('./?c=nativeFullContent&a=fetch', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function (res) {
                return res.text().then(function (text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        throw new Error('Réponse serveur (code ' + res.status + ') : ' + text.substring(0, 200));
                    }
                });
            })
            .then(function (data) {
                if (data.success) {
                    var contentTarget = btn.closest('.content');
                    if (!contentTarget) {
                        var flux = btn.closest('.flux_content') || btn.closest('.flux');
                        if (flux) {
                            contentTarget = flux.querySelector('.content') || flux.querySelector('.text');
                        }
                    }

                    if (contentTarget) {
                        // 1. Sauvegarder et détacher temporairement les barres d'outils tierces (xExtension-AITranslator)
                        var preservedToolbars = [];
                        var existingToolbars = contentTarget.querySelectorAll('.aitranslator-toolbar');
                        existingToolbars.forEach(function (toolbar) {
                            preservedToolbars.push(toolbar);
                            if (toolbar.parentNode) {
                                toolbar.parentNode.removeChild(toolbar);
                            }
                        });

                        // 2. Remplacer le contenu par le texte intégral et son badge de statut
                        contentTarget.innerHTML = data.newContent;

                        // 3. Réinjecter la barre d'outils AITranslator au sommet de l'article
                        preservedToolbars.forEach(function (toolbar) {
                            // Réinitialiser l'état du bouton Traduire si l'extrait court avait déjà été traduit
                            var transBtn = toolbar.querySelector('.aitranslator-btn-translate');
                            if (transBtn && transBtn.classList.contains('badge-translated')) {
                                transBtn.classList.remove('badge-translated');
                                transBtn.classList.add('badge-pending');
                                var defaultLabel = transBtn.getAttribute('data-label-default');
                                if (defaultLabel) {
                                    transBtn.innerHTML = defaultLabel;
                                }
                                transBtn.title = '';
                            }

                            var tldrBtn = toolbar.querySelector('.aitranslator-btn-tldr');
                            if (tldrBtn) {
                                tldrBtn.disabled = false;
                            }

                            contentTarget.insertBefore(toolbar, contentTarget.firstChild);
                        });
                    } else {
                        btn.outerHTML = '<span class="native-badge-success" style="color:#137333;font-weight:bold;">✅ Contenu mis à jour !</span>';
                    }
                } else {
                    alert('Erreur : ' + (data.error || 'Impossible d\'extraire l\'article.'));
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
            })
            .catch(function (err) {
                alert('Erreur réseau ou serveur : ' + err.message);
                btn.disabled = false;
                btn.innerHTML = originalText;
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initNativeFullContent);
    } else {
        initNativeFullContent();
    }
})();