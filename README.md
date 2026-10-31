# Thermalright Digital pour Unraid

Plugin communautaire pour piloter l’écran USB d’un Thermalright Peerless Assassin 140 Digital depuis les paramètres Unraid. Interface française, mesures CPU de l’hôte et pilote TRCC 9.11.2 dans un conteneur Docker isolé. Aucun Python, pip, User Scripts ou systemd n’est ajouté à Unraid.

Version : **2026.10.31.8**, sous **GPL-3.0-or-later**. Le fonctionnement de la .7, incluant les watts CPU et le ciblage thermique par catégorie, est confirmé par l’utilisateur sur **Unraid 7.3.4**, USB **0416:8001**, profil **LF12**. Les autres profils et versions acceptés restent à valider sur matériel.

## Fonctions

- Température CPU par hwmon, charge par `/proc/stat`, fréquence MHz par cpufreq et puissance CPU par hwmon ou compteur RAPL package.
- RGB fixe, couleurs par zone, température, respiration, arc-en-ciel, luminosité et lissage.
- Choix des catégories affectées par la température : uniquement les chiffres °C, ou une combinaison avec charge, watts, MHz et décorations ; les autres gardent leurs couleurs fixes.
- Test visuel de 8 secondes, démarrage automatique facultatif après l’array et Docker, reconnexion USB par VID/PID.
- Installation et recherche de mises à jour via les releases GitHub, bundle hors ligne, réglages persistants et retour arrière.
- Nettoyage explicite des anciennes versions et d’une installation manuelle identifiée, avec conservation des ressources étrangères ou partagées.

## Installer une release publiée

Dans **Plugins → Installer un Plugin**, saisir :

```text
https://github.com/MrPatates/thermalright-digital-unraid/releases/latest/download/thermalright-digital.plg
```

Ce lien devient disponible après publication d’une release stable publique. Les brouillons ne sont pas accessibles depuis Unraid. Les annexes versionnées sont téléchargées et vérifiées par SHA-256 ; une image identique déjà présente est réutilisée. Les redémarrages suivants fonctionnent hors ligne.

Ouvrir **Paramètres → Écran Thermalright Digital**, choisir la sonde CPU puis **Démarrer**, avec l’array et Docker actifs. L’autostart est désactivé par défaut. Après mise à jour, recharger la page avec **Ctrl+F5**. Les réglages sont conservés. Les mises à jour publiques se vérifient dans l’onglet Plugins.

Compatibilité visée : Unraid x86_64 stable **6.12.x et 7.0.x à 7.3.x**. Les versions hors de cette plage et les préversions sont refusées ; cette garde ne constitue pas une certification de chaque version.

### Installation hors ligne

Télécharger le ZIP complet de la release, vérifier `SHA256SUMS` puis copier **le dossier `thermalright-digital` entier** dans `/boot/config/plugins/`. Dans l’installateur Unraid, choisir :

```text
/boot/config/plugins/thermalright-digital/packages/2026.10.31.8/thermalright-digital.plg
```

Alternative terminal :

```bash
plugin install /boot/config/plugins/thermalright-digital/packages/2026.10.31.8/thermalright-digital.plg
```

Prévoir environ 85 Mio sur la clé et 250 Mio dans le stockage Docker. Le plugin ne crée pas d’appdata, de volume ou de réseau privé.

## Réglages et mesures

Dans les couleurs, choisir **Couleur selon la température CPU** et sélectionner **Température** seule pour que les chiffres comme **42 °C** suivent la palette, tandis que W/MHz/% conservent leurs couleurs par zone. Les seuils, les quatre couleurs et le lissage sont réglables. Le lissage affecte uniquement la couleur ; les chiffres gardent leur mesure réelle. Les effets sont appliqués en direct à l’écran USB. L’ARGB de la carte mère et des ventilateurs relève de leur propre contrôleur.

Les mesures indisponibles restent `null` dans le statut et leurs chiffres/unités sont éteints. Une vraie mesure zéro reste affichable. Les capacités W/MHz sont annoncées après le handshake : LF8/LF12/LF15 les possèdent, AK120 possède les watts. Les valeurs dépassant le nombre de chiffres disponibles sont masquées.

La fréquence correspond au maximum des politiques cpufreq actives uniques, ou à leur moyenne arithmétique sans pondération par nombre de cœurs. Le repli `scaling_cur_freq` est signalé comme **fréquence demandée** ; il peut différer de la fréquence physique.

Les watts concernent la source CPU sélectionnée, jamais la consommation totale à la prise. RAPL utilise les deltas d’énergie du package et un temps monotone ; les sous-domaines et fournisseurs dupliqués ne sont pas additionnés. Le premier échantillon, un compteur illisible ou un delta invalide restent indisponibles. `enabled=0` décrit les contrôles de limitation RAPL et ne bloque pas un compteur d’énergie lisible. Les rails individuels se sélectionnent explicitement avec leur libellé. Aucune puissance n’est estimée depuis le TDP ou la charge. Les mesures GPU ne sont pas fournies.

Les animations tournent à 10 Hz. L’intervalle de 1 à 60 secondes règle l’alternance des petits écrans. Le test couleurs/segments utilise des chiffres artificiels pendant 8 secondes, conserve les mesures du statut et revient automatiquement à l’affichage normal.

## Mise à jour, retrait et dépannage

Une mise à jour prépare le code en RAM, vérifie les paquets et conserve réglages/inventaire. Si un backend actif ne redevient pas prêt, l’ancien code est restauré. Les anciens paquets permettent un retour explicite avec **Force Install**. Ne lancer qu’un pilote à la fois sur l’écran.

Pour désinstaller : **Plugins → Supprimer**, avec Docker disponible, ou `plugin remove thermalright-digital.plg`. Les ressources privées vérifiées sont retirées ; les ressources partagées et préexistantes sont conservées. Si Docker est indisponible, l’inventaire est maintenu et le retrait doit être repris. Voir [le nettoyage](docs/NETTOYAGE.md), [la validation Unraid](docs/VALIDATION-UNRAID.md) et [le rapport de tests](docs/TEST-REPORT.md).

En cas de problème, exporter le diagnostic depuis la page. Il peut contenir des chemins et identifiants privés : les retirer avant de le joindre à une issue.

## Développement et publication

```bash
python3 scripts/fetch-dependencies.py
CONTAINER_ENGINE=podman bash scripts/build.sh
# Ou : CONTAINER_ENGINE=docker bash scripts/build.sh
```

Prérequis et fonctionnement des tests : [CONTRIBUTING.md](CONTRIBUTING.md). La construction vérifie les dépendances, exécute les suites, exporte une vraie image Docker et produit le ZIP hors ligne, les sources complètes et `dist/release/`. Les fichiers générés sont ignorés par Git.

Pour préparer le dépôt et publier la release : [GITHUB-PUBLICATION.md](docs/GITHUB-PUBLICATION.md). Les workflows créent des releases **en brouillon** ; leur publication reste une action explicite dans GitHub.

Le paquet source comprend les sources du plugin et des composants tiers. Voir [l’architecture](docs/ARCHITECTURE.md), [les licences](licenses/THIRD-PARTY-NOTICES.md) et [l’historique](CHANGELOG.md). Projet sans affiliation avec Thermalright, Unraid ou le mainteneur TRCC.
