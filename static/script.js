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
                    // Recherche du conteneur principal du corps de l'article dans FreshRSS
                    var contentTarget = btn.closest('.content');
                    if (!contentTarget) {
                        var flux = btn.closest('.flux_content') || btn.closest('.flux');
                        if (flux) {
                            contentTarget = flux.querySelector('.content') || flux.querySelector('.text');
                        }
                    }

                    if (contentTarget) {
                        contentTarget.innerHTML = data.newContent;
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