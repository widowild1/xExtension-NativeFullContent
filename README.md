# xExtension-NativeFullContent

**xExtension-NativeFullContent** est une extension pour [FreshRSS](https://freshrss.org/) qui permet de récupérer le contenu intégral des articles RSS directement depuis leur source, en utilisant la bibliothèque [Readability.php](https://github.com/fivefilters/readability.php).

Cette extension est conçue pour être déployée dans un environnement automatisé via Forgejo CI/CD, avec des contrôles de sécurité stricts pour éviter les fuites de métadonnées d'infrastructure lors de la synchronisation vers un miroir public (comme GitHub).

## Fonctionnalités

- Récupération automatique du contenu complet des articles tronqués.
- Nettoyage et formatage natif via `fivefilters/readability.php`.
- Intégration transparente dans l'interface FreshRSS.
- Pipeline CI/CD incluse pour la mise à jour automatique des dépendances.

## Prérequis

- Une instance **FreshRSS** fonctionnelle.
- **PHP** (cli, curl, xml, mbstring, zip) installé sur le serveur hébergeant FreshRSS.
- **Composer** installé sur le serveur pour la gestion des dépendances.

## Installation Manuelle

1. Accédez au répertoire des extensions de votre installation FreshRSS :
   ```bash
   cd /chemin/vers/freshrss/extensions/
   ```

2. Clonez ce dépôt :
   ```bash
   git clone https://github.com/widowild/xExtension-NativeFullContent.git
   ```

3. Accédez au répertoire de l'extension :
   ```bash
   cd xExtension-NativeFullContent
   ```

4. Installez les dépendances via Composer (indispensable pour télécharger `readability.php`) :
   ```bash
   composer install --no-dev --optimize-autoloader
   ```

5. Attribuez les droits appropriés au dossier (adaptez selon l'utilisateur de votre serveur web) :
   ```bash
   chown -R www-data:www-data .
   ```

6. Connectez-vous à votre instance FreshRSS avec un compte administrateur.
7. Allez dans **Configuration** > **Extensions**.
8. Activez **Native Full Content**.

## Déploiement Automatisé (CI/CD Forgejo)

Ce dépôt inclut des workflows `.forgejo/workflows/` conçus pour automatiser la maintenance et le déploiement sur une infrastructure Proxmox LXC.

### Architecture requise

- Une forge **Forgejo** privée.
- Un runner Forgejo (`runs-on: native`) configuré sur le même réseau.
- FreshRSS hébergé dans un conteneur LXC accessible en SSH par le runner.

### Configuration des Secrets

Pour faire fonctionner le déploiement automatique, configurez les secrets suivants dans votre dépôt Forgejo (**Paramètres > Actions > Secrets**) :

- `LXC_HOST` : L'adresse IP ou nom d'hôte de votre conteneur FreshRSS (ex: `<IP_LXC_FRESHRSS>`).
- `LXC_SSH_KEY` : La clé privée SSH (Ed25519) autorisée à se connecter en `root` sur le conteneur FreshRSS.

### Workflows inclus

1. **Mise à jour Readability (`update-readability.yaml`)** :
   - S'exécute tous les lundis à 04:00 UTC (ou sur déclenchement manuel).
   - Met à jour `fivefilters/readability.php` via Composer.
   - Effectue un *lint* de la syntaxe PHP.
   - Vérifie par regex l'absence de fuites de données privées d'infrastructure.
   - Commite et pousse les modifications si une mise à jour est disponible.

2. **Déploiement sur Proxmox LXC (`deploy.yml`)** :
   - S'exécute à chaque *push* sur la branche `main`.
   - Se connecte en SSH natif au conteneur FreshRSS.
   - Tire les dernières modifications (`git pull origin main`).
   - Réapplique les permissions web (`chown -R www-data:www-data`).

## Sécurité et Audit

Si vous modifiez le code localement, activez le hook de *pre-commit* pour interdire tout commit contenant des informations d'infrastructure (domaines internes, IP locales, clés privées) :

```bash
chmod +x .git/hooks/pre-commit
```

## Licence

Ce projet est sous licence MIT. La bibliothèque `fivefilters/readability.php` possède sa propre licence.
