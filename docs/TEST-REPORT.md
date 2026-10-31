# Rapport de validation — 31 octobre 2026

Livraison publique **2026.10.31.8**, avec le code fonctionnel de la .7 inchangé. Tests locaux sur Fedora 44 x86_64 avec Podman, PHP CLI/FPM 8.3.36 et Python 3.12.12. L’utilisateur confirme le fonctionnement sur Unraid 7.3.4 avec USB 0416:8001 et profil LF12, incluant les watts CPU après correction .6 et le fonctionnement de la nouvelle interface et du ciblage thermique en .7. Les diagnostics privés ne sont pas publiés. Les autres profils nécessitent leur validation physique.

## Réellement exécuté

- Construction de l’image amd64 avec le wheel TRCC 9.11.2 vérifié, PyUSB 1.3.1 à hash pip et libusb épinglé ; imports du sous-ensemble utilisé réussis.
- **40 tests Python** sur les vrais modules TRCC : masques CPU, valeurs absentes/GPU, watts/MHz sur les profils compatibles, zéro réellement mesuré, dépassements numériques, RGB fixe/zones/température/respiration/arc-en-ciel, luminosité, ciblage, lissage monotone, test visuel borné, fraîcheur des échantillons, cadence et cycle du worker avec transport simulé, extinction après erreur USB, refus des dispositions non CPU.
- **36 contrôles PHP de cycle de vie**, dans des répertoires temporaires avec un **faux Docker** : installation logique, réinitialisation, versions acceptées/refusées, calcul CPU connu, sonde absente, validation des réglages, autostart, labels, délégation d’un seul nœud USB, absence de socket/privileged/réseau, verrou de superviseur, adresse USB modifiée, arrêt, USB/Docker absents, inventaire conservé en cas de retrait incomplet, anciennes ressources préservées, image empruntée, conflit de nom, fichiers inconnus, retrait répété, absence de répertoires privés et finalisation de l’archive native du manifeste.
- **3 contrôles HTTP/PHP** : méthode GET refusée 405, mauvais jeton CSRF refusé 403, action inconnue refusée 400. L’authentification nginx native et les sessions Unraid ne peuvent pas être validées hors Unraid.
- **7 contrôles CSRF d’intégration**, en exécutant le fichier officiel Unraid `local_prepend.php` non modifié : requête valide acceptée APRÈS consommation du jeton natif, mauvais jeton natif refusé même avec en-tête valide, absence de jeton natif refusée, en-tête indépendant mauvais ou absent refusé, action inconnue refusée, rendu de page avec jeton natif sans dépendre de `$var`. L’ancien api.php reproduit effectivement le HTTP 403 sur la première requête valide ; ce même cas passe en HTTP 200 après correction. Le fichier de test officiel reste sous GPL-2.0-only, sa licence est jointe.
- **15 scénarios JavaScript** avec DOM/fetch simulés : jeton natif et en-tête, erreurs vides/HTML/JSON/CSRF/login et reprise ; anciens réglages migrés, RGB restitué exactement, sources et capacités, watts zéro/indisponibles et fréquence demandée ; brouillon conservé pendant le polling ; test sans sauvegarde et erreurs d’action conservées. Le rendu a été inspecté dans Firefox sur ordinateur et mobile, en thèmes sombre et clair, avec un état simulé ; la .7 est ensuite confirmée fonctionnelle par l’utilisateur.
- **12 tests de l’installateur effectivement extrait du `.plg`** : installation/réinstallation, mise à jour et retour explicite, réglages conservés, restauration après échec injecté, nouveau backend simulé non prêt et retour automatique, SIGKILL après activation puis récupération, échec de première installation sans fichiers créés résiduels, payload corrompu refusé, dossiers préexistants préservés, version hors cible refusée. Les noms de versions des fixtures sont indépendants des livraisons réelles.
- **20 contrôles de nettoyage** : audit sans suppression, anciennes versions retirées, version active/réglages préservés, ancien conteneur/image/appdata vérifiés, volumes/réseaux étiquetés, autostart et modèles, homonymes, ressources partagées, fichier inconnu, absence Docker et relance idempotente. Les opérations Docker sont simulées.
- **3 scénarios URL additionnels** inclus dans les 12 tests installateur : serveur HTTP réel local, téléchargement des fichiers absents et relance hors ligne ; corruption refusée avec version active intacte ; échec d’activation restaurant l’ancienne version et nettoyant le paquet récupéré.
- **47 contrôles de réglages** : migration des anciens champs, défauts en mémoire sans écriture flash, cinq modes RGB, bornes/énumérations/couleurs/palettes/seuils, sauvegarde, compatibilité ancien client, refus d’une source inconnue et d’un test sans backend prêt.
- **42 contrôles de métriques hôte** : politiques uniques, moyenne/maximum, fréquence mesurée/demandée, hwmon CPU et unités, rails individuels, compteurs énergie, premier échantillon, temps monotone, compteur circulaire, reset/reboot/source remplacée, cache corrompu, sources indisponibles, absence de double comptage MSR/MMIO/sous-domaines et totalité multi-socket sans somme partielle.
- **11 tests avec PHP-FPM réel** et le prétraitement natif Unraid : état 7.3.4, journaux, diagnostic, sauvegarde/validation des réglages historiques et RGB, sources JSON, démarrage/arrêt sans array, test visuel refusé sans backend puis accepté avec backend appartenant au plugin, absence de persistance du test, refus CSRF/méthode, erreur de configuration, erreur fatale et log non UTF-8. Échanges FastCGI réels ; Docker simulé, aucun USB exposé.
- Contrôles syntaxiques PHP et Bash ; XML du manifeste réellement généré analysé.
- CLI officielle testée avec ses dépendances complètes dans un conteneur temporaire : aide et syntaxe de `TRCC_DAEMON=0 QT_QPA_PLATFORM=offscreen trcc -v led play 0416:8001 --interval 1` valides ; code 1 « Failed to open led transport » attendu sans écran.
- Démarrage **réel** de l’image finale sans USB sous les restrictions de production : sortie 1, statut `ready=false` et erreur exploitable, sans installation globale de dépendances.
- Export **réel** au format docker-archive, compression xz ; vérification du SHA-256 de l’archive, du digest de configuration correspondant à l’ID annoncé, du tag, de l’architecture, des labels et des **9 couches** contre les diff_ids du manifeste Docker.

Les tests/contrôles automatisés ci-dessus sont regroupés dans `tests/`. Les journaux finaux sont dans `docs/evidence/`. Les sorties d’échec attendues des tests négatifs et la reproduction volontaire de l’ancien défaut sont normales ; la fin des suites corrigées indique leur réussite. Les archives de livraison et leur fichier SHA256SUMS ont également été revérifiés après packaging.

## Nécessite le serveur et l’écran

| Vérification | État |
|---|---|
| Installation et watts CPU sur Unraid 7.3.4, profil LF12 | confirmés par l’utilisateur sur .7 |
| Retrait via le gestionnaire Unraid réel | à exécuter |
| Profil du backend existant | LF12 prêt et fonctionnement .7 confirmés par l’utilisateur |
| Accès USB du backend .7 | fonctionnement confirmé par l’utilisateur ; relevé détaillé du contrat Docker à conserver |
| Lecture CPU k10temp/Tctl | confirmée en CLI utilisateur ; choix et rendu à comparer |
| Watts RAPL package-0 et MHz cpufreq, LF12 | fonctionnement confirmé par l’utilisateur après .6 |
| Nouvelle interface et ciblage thermique par catégorie .7 | fonctionnement confirmé par l’utilisateur ; chaque combinaison de réglages reste à vérifier séparément |
| Redémarrage du serveur, de l’array et de Docker avec autostart | à exécuter |
| Déconnexion/rebranchement physique et réénumération USB | à exécuter si le connecteur permet une manipulation sûre |
| Restauration après panne d’un futur vrai backend de version différente | à exécuter avant usage sans surveillance |
| Absence de ressources privées après retrait sur ce serveur | à exécuter avec l’audit livré |

Le format de l’archive est compatible Docker ; aucun daemon Docker réel n’est disponible sur le poste de construction. La création/suppression Docker du cycle de vie a été simulée, et l’exécution réelle de l’image a utilisé Podman. Les plages Unraid annoncées sont les gardes de compatibilité du code, pas des versions certifiées par ces tests.

La procédure concrète est dans [VALIDATION-UNRAID.md](VALIDATION-UNRAID.md). Une réussite locale ne garantit pas la réponse du firmware, la sélection correcte de la sonde ou la disposition physique de votre écran.

Pour relancer les suites sur un poste de développement après construction de l’image :

```bash
CONTAINER_ENGINE=podman bash scripts/check.sh localhost/thermalright-digital:2026.10.31.8
```

Les outils PHP et leurs fixtures restent dans des conteneurs éphémères ; aucune connexion au socket Docker de l’hôte n’est montée. Les versions/digests d’images de test sont verrouillés dans `scripts/check.sh`.

La capture utilisateur de .3 montre une réponse Web vide, alors que le CLI retourne un état valide sous Unraid 7.3.4, avec USB 0416:8001 et sonde k10temp/Tctl. La cause a été reproduite en vrai HTTP : le shebang du contrôleur, toléré en CLI, provoquait une erreur de compilation stricte lors de son inclusion Web. Le correctif .4 retire ce shebang ; tous les appels CLI utilisent déjà explicitement PHP. La reproduction est dans evidence/http-before-fix.log et les nouveaux tests dans evidence/http-fpm-tests.log. Le fonctionnement de la .7 est ensuite confirmé par l’utilisateur.

## Correctif RAPL .6

Le retour utilisateur de .5 confirme `cpu_watts=null`, un cache énergie vide et un backend LF12 prêt avec `capabilities.power=true`. Le package RAPL expose `enabled=0`, mais son compteur passe de 41 777 528 224 à 41 838 690 375 µJ après une attente de deux secondes. Le collecteur écartait ce compteur à tort : `enabled` décrit les contrôles de limitation, pas la disponibilité de `energy_uj` ([documentation Linux](https://docs.kernel.org/power/powercap/powercap.html), [implémentation RAPL](https://github.com/torvalds/linux/blob/master/drivers/powercap/intel_rapl_common.c)).

Le défaut est reproduit avant correction dans `evidence/rapl-before-fix.log`. Cinq contrôles supplémentaires vérifient la disponibilité du compteur malgré `enabled=0`, le premier échantillon en RAM, un delta énergétique valide, les contrôles RAPL conservés et le refus d’un compteur devenu illisible. Aucune écriture dans sysfs n’est ajoutée. Les suites PHP/FPM sont relancées après correction ; les tests du worker restent ceux de l’image .5 inchangée. L’utilisateur a confirmé ensuite le fonctionnement des watts CPU avec .6.

## Préparation de la publication .7

Le ciblage thermique par catégorie ajoute des vérifications pour LF12 (température seule à 42 °C, couleurs fixes distinctes, luminosité, filtre global), plusieurs catégories, absence de température, alternance des petits profils et modes existants conservés. Les anciens réglages reçoivent les cinq catégories par défaut ; un ancien formulaire ne supprime pas une sélection déjà enregistrée.

Quatre contrôles supplémentaires de nettoyage couvrent un pool quelconque, l’exclusion des racines et parents appdata symboliques et la conservation de leur cible. Cinq tests de publication vérifient les URL d’annexes versionnées, pluginURL, le mode hors ligne, les métadonnées rejetées avant écriture, les dépendances à SHA-256 et la conservation d’un cache modifié, ainsi que l’exclusion des binaires du dépôt source. Les quatre archives tierces ont aussi été téléchargées dans un dossier neuf et vérifiées par leur SHA-256. Les workflows sont validés localement avec actionlint 1.7.12 ; leur exécution sur GitHub est assurée par les workflows du dépôt, dont les résultats sont consultables dans l’onglet Actions.

Après installation de .7 via le serveur temporaire, l’utilisateur confirme que tout fonctionne sur son Unraid. Cette confirmation complète les captures locales simulées ; les tests de redémarrage, retrait et autres profils restent à exécuter explicitement.

## Revue finale pour .8

La confirmation utilisateur de la .7 est enregistrée dans ce rapport et les notes de release. Les sources et archives publiques excluent les diagnostics privés et adresses LAN. Les workflows incluent explicitement les journaux de `.build/logs/`, dossier caché ignoré par défaut par upload-artifact. Les workflows sont à nouveau contrôlés avec actionlint. La version publique .8 sépare les nouveaux paquets de publication des paquets .7 locaux déjà installés, car l’ID Docker d’une reconstruction GitHub peut différer.
