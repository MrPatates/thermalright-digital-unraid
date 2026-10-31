# Publier sur GitHub

Dépôt : **MrPatates/thermalright-digital-unraid**, première version publique **2026.10.31.8**. Cette procédure décrit l’import initial et la publication des versions suivantes ; adapter le compte et le dépôt dans `project.json` pour un fork.

## Déposer les sources

1. Créer sur [GitHub](https://github.com/new) un dépôt public vide nommé `thermalright-digital-unraid` dans le compte `MrPatates`, sans README ni licence générés.
2. Extraire `dist/thermalright-digital-github.zip` dans un dossier vide. L’archive contient les fichiers cachés `.github`, `.gitignore`, `.gitattributes` et `.editorconfig` ; les conserver.
3. Depuis ce dossier :

   ```bash
   git init -b main
   git add .
   git commit -m "Prepare Thermalright Digital for Unraid"
   git remote add origin https://github.com/MrPatates/thermalright-digital-unraid.git
   git push -u origin main
   ```

L’authentification GitHub est celle de votre client Git. Si Git demande une identité avant le commit, configurer votre nom et l’adresse de commit associée à GitHub. Pour l’accès HTTPS, utiliser l’authentification d’un gestionnaire d’identifiants ou un jeton GitHub, et non le mot de passe du compte ; voir [l’authentification Git officielle](https://docs.github.com/en/get-started/git-basics/caching-your-github-credentials-in-git). Aucun jeton n’est fourni dans les sources. Le workflow **Validation** construit le backend et lance les suites ; les logs sont téléchargeables dans Actions.

## Préparer et publier la release

Après réussite du workflow, pousser le tag correspondant exactement au fichier VERSION :

```bash
git tag v2026.10.31.8
git push origin v2026.10.31.8
```

Le workflow **Release en brouillon** reconstruit et vérifie l’image, puis crée un brouillon et joint les fichiers de `dist/release/`. Ouvrir **Releases**, contrôler le brouillon et choisir **Set as latest release** si proposé, laisser **This is a pre-release** décoché, puis cliquer **Publish release** pour le rendre public. Il doit être une release stable afin que `releases/latest` la sélectionne.

Le manifeste utilise les URL versionnées du dépôt réellement exécutant le workflow ; son attribut `pluginURL` utilise le lien de dernière release stable. Le lien d’installation devient accessible après publication :

```text
https://github.com/MrPatates/thermalright-digital-unraid/releases/latest/download/thermalright-digital.plg
```

Les annexes obligatoires sont `payload.txz`, `release.json` et `backend-image.tar.xz` à côté du manifeste. Joindre aussi le ZIP hors ligne, le paquet source et `SHA256SUMS`. Les liens d’une release en brouillon nécessitent une authentification et ne permettent pas l’installation publique depuis Unraid.

## Construction manuelle

```bash
CONTAINER_ENGINE=podman bash scripts/build.sh
cd dist/release
sha256sum -c SHA256SUMS
```

Créer ensuite la release portant le tag exact et déposer **tous** les fichiers de `dist/release/`. Éviter d’assembler des annexes provenant de constructions différentes, car chaque manifeste fige leurs SHA-256.

Pour une future version, modifier VERSION, CHANGELOG et les notes de release, construire et vérifier, puis pousser le nouveau tag. Les actions officielles sont épinglées par commit. Les archives binaires et paquets sources sont distribués comme assets de release ; le dépôt Git contient les sources légères et les dépendances verrouillées. Voir [la documentation GitHub sur les gros fichiers](https://docs.github.com/en/repositories/working-with-files/managing-large-files/about-large-files-on-github).

## Contrôler la première publication

Le tag doit pointer sur le commit vérifié. Après publication, ouvrir le lien d’installation public ci-dessus : il doit télécharger un fichier `.plg`. Les annexes de la même release doivent être visibles dans Assets. La publication des seules sources ne suffit pas pour installer le plugin.

Les sommes du manifeste et de l’image construits par GitHub Actions peuvent différer de celles du bundle local testé, car chaque construction fige son propre ID Docker et ses annexes. Installer les fichiers d’une même construction ensemble. La première version publique porte le numéro .8 pour mettre à jour les installations locales .7 sans collision de paquets. Pour les versions suivantes, publier un nouveau tag et conserver les fichiers de chaque release ensemble ; elles se détectent via le `pluginURL` GitHub.
