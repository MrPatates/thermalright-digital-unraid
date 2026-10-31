# Architecture et choix vérifiés le 31 octobre 2026

Le plugin a été écrit après lecture de ces sources primaires, dont les empreintes sont conservées dans `source-evidence.json` :

- [Dynamix System Temp, manifeste maintenu par Unraid](https://github.com/unraid/dynamix/blob/master/unRAIDv6/dynamix.system.temp.plg) : PLUGIN, FILE/INLINE, launch, méthode remove.
- [StaXX, manifeste maintenu](https://github.com/quadcom/Staxx/blob/main/staxx.plg) : manifeste natif, paquets versionnés et empreintes.
- [Gestionnaire officiel des plugins](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.plugin.manager/scripts/plugin) : installation d’un fichier local, suivi dans `/var/log/plugins`, préservation de l’entrée installée si le hook remove échoue. L’option `force` est un argument final.
- [Page officielle Installer un Plugin](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.plugin.manager/PluginInstall.page) : l’interface accepte explicitement URL **ou chemin local**.
- [Événements officiels emhttp](https://github.com/unraid/webgui/blob/master/sbin/emhttp_event) : les événements sont synchrones. Les scripts de démarrage ne font donc que lancer un superviseur détaché ; ils n’attendent pas le chargement de l’image. L’arrêt est borné.
- [Formulaire natif avec jeton CSRF](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.docker.manager/include/CreateDocker.php) et [authentification native](https://github.com/unraid/webgui/blob/master/emhttp/auth-request.php).
- [Prétraitement natif des requêtes PHP](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix/include/local_prepend.php) : après comparaison du jeton natif, Unraid retire `$_POST['csrf_token']`. Depuis la version .2, le navigateur envoie aussi le même jeton dans `X-Thermalright-CSRF-Token`. L’API compare cet en-tête indépendant au jeton natif conservé dans var.ini ; le champ natif reste envoyé pour le contrôle préalable d’Unraid. La protection ne repose jamais sur la seule présence d’un jeton déjà stocké côté serveur.

Le manifeste public annonce la dernière release stable GitHub via `pluginURL`. Les annexes restent épinglées à la version et à leurs empreintes ; le mode de packaging local sans URL reste disponible. Il vérifie le payload txz et les métadonnées par SHA-256 avant extraction. Le gestionnaire gère l’enregistrement du plugin, tandis que l’installateur gère le remplacement transactionnel des fichiers. Il n’utilise pas installpkg/upgradepkg car le retour automatique doit conserver ensemble le code et les réglages ; le txz est un payload privé vérifié, sans scripts post-installation cachés.

## Pilote et commande envisagée

Source retenue : [TRCC v9.11.2](https://github.com/Lexonight1/thermalright-trcc-linux/tree/v9.11.2), version GPL-3.0-or-later, Python ≥ 3.10. L’archive source PyPI et le wheel ont été vérifiés contre les SHA-256 des [métadonnées PyPI 9.11.2](https://pypi.org/pypi/trcc-linux/9.11.2/json), et les modules utilisés ont été confrontés au tag GitHub.

La commande suivante a réellement été lancée dans un conteneur temporaire muni de l’ensemble des dépendances officielles :

```bash
TRCC_DAEMON=0 QT_QPA_PLATFORM=offscreen trcc -v led play 0416:8001 --interval 1
```

L’aide affiche `trcc led play [OPTIONS] {key}` et l’option interval. La commande accepte les arguments, tente ConnectDevice/InitializeLed puis termine avec le code 1 attendu car aucun écran USB n’est délégué. Ce test valide la CLI et ses imports, pas le matériel. Le log complet avec les dépendances résolues est dans `evidence/cli-validation.log`. L’option TRCC_DAEMON=0 évite la création du daemon partagé ; le ticker reste dans le processus appelant.

**Cette commande n’est pas le processus de production du plugin.** RenderLed lit les sondes du contexte du pilote et plusieurs valeurs absentes deviennent 0. Le plugin réutilise directement les modules TRCC non modifiés `Led`, `PyUsbBulkTransport`, `LedPayload` et les rendus de segments. L’adaptateur reçoit exclusivement le JSON échantillonné sur l’hôte et enlève les groupes indisponibles avant de transmettre la trame. Le handshake et le remappage de l’ordre physique restent ceux du pilote. Le style est celui du PM/SUB reçu, avec refus explicite des dispositions non gérées.

L’image de production installe le wheel TRCC avec `--no-deps` et seulement PyUSB 1.3.1 : ce sont les dépendances nécessaires au sous-ensemble utilisé, dont les imports et le cycle du worker sont testés. La CLI générale, Qt, les thèmes, l’API réseau et leurs dépendances ne sont pas installés dans cette image. Il faut utiliser les commandes du plugin, pas `docker exec ... trcc`. L’environnement complet de validation CLI était temporaire et ne fait pas partie du backend livré.

Base Python 3.12.12 slim-bookworm épinglée par digest multiarchitecture, construction amd64 vérifiée. Libusb 1.0.26-1 et PyUSB 1.3.1 sont épinglés ; PyUSB est vérifié par hash pip et le wheel TRCC par SHA-256 explicite. APT authentifie son index et le paquet libusb. La livraison fige aussi l’ID réel de l’image, son archive et chacune de ses couches. Le digest de base, les versions et les empreintes figurent dans les fichiers de construction et l’inventaire de l’image.

## Isolation, ressources et ordre

Depuis .5, `settings.php` normalise les anciens réglages en mémoire et valide les nouveaux paramètres RGB, la source de puissance et le mode de fréquence. `metrics.php` lit uniquement les ABI du noyau hôte : [cpufreq](https://docs.kernel.org/admin-guide/pm/cpufreq.html), [hwmon](https://docs.kernel.org/hwmon/sysfs-interface.html) et [powercap](https://docs.kernel.org/power/powercap/powercap.html). Les politiques cpufreq sont dédupliquées et la provenance mesurée/demandée est conservée. Les packages RAPL ne sont pas additionnés à leurs sous-domaines ni aux fournisseurs dupliqués. Le cache d’énergie utilise temps monotone, identité de source et boot_id en RAM ; les deltas invalides produisent `null`. Depuis .6, le contrôle RAPL `enabled` n’est pas assimilé à la disponibilité de son compteur d’énergie : il décrit la limitation de puissance. Aucun module ni registre MSR n’est chargé ou écrit.

Le superviseur échantillonne l’hôte chaque seconde. Son JSON contient aussi les réglages RGB enregistrés ; le worker le relit à 10 Hz et applique les effets sans redémarrage. Le lissage exponentiel concerne exclusivement les couleurs. Depuis .7, `rgb.temperature_zones` sélectionne les catégories qui suivent la température, en intersection avec la cible globale ; les autres utilisent leurs couleurs fixes par zone. Les anciens réglages conservent les cinq catégories actives par défaut. Les masques et unités W/MHz ne sont activés que sur les dispositions compatibles ; une mesure absente ou hors capacité numérique est masquée, sans altérer sa valeur réelle dans le statut. Les métriques hôte périmées après 10 secondes éteignent l’affichage.

L’action POST `display-test` exige un superviseur vivant, un statut prêt récent et un conteneur actif portant les deux labels d’appartenance. Elle écrit un marqueur RAM de 8 secondes, jamais les réglages. Le worker borne aussi la durée, suit une échéance monotone et signale `test_active` avec phase/temps restant. La séquence rouge/vert/bleu/segments remplace seulement les trames : les mesures du statut restent réelles. L’arrêt retire le marqueur et envoie une extinction.

| Ressource | Emplacement / preuve | Nettoyage |
|---|---|---|
| Réglages, inventaire, paquets, versions précédentes | `/boot/config/plugins/thermalright-digital/` ; owner + instance aléatoire ; hashes des bundles | suppression après vérification ; inconnu conservé et échec annoncé |
| UI et événements | `/usr/local/emhttp/plugins/thermalright-digital/` ; marqueur du payload | retrait complet |
| Transaction | dossiers cachés `.../.thermalright-digital-stage` / `...-backup` ; marqueur | récupération lors de la réinstallation, retrait lors du nettoyage |
| Mesures, cache handshake, logs, PID/locks | `/var/run/thermalright-digital/` en RAM | retrait complet |
| Conteneur backend | nom dédié ; labels `io.local.thermalright-digital.owner` ET `.instance` | stop puis rm sans force globale |
| Image importée | ID fixé, label propriétaire, inventaire écrit avant load | rm seulement si créée par ce plugin et non partagée |
| Manifeste installé / lien | gestionnaire Unraid | méthode remove native ; finaliseur SHA-256 pour son archive |
| Volumes/réseaux/tâches/cron/go | aucun créé | aucune modification |

Le conteneur n’a que le device USB actuellement résolu, en rw sans droit mknod. Pas de `--privileged`, de règle USB globale, de socket Docker, de `/boot`, de `/mnt`, de PID/network host ni d’accès aux autres nœuds USB. Le sysfs en lecture seule fourni normalement par Docker peut exposer leurs métadonnées, sans déléguer leur accès. Les sondes ne sont même pas montées : l’hôte en transmet un échantillon JSON par bind read-only. L’unique bind inscriptible est le cache/journal privé en RAM. Racine read-only, toutes capacités supprimées, no-new-privileges, réseau none, 64 PID, 192 Mio, 0,5 CPU. Aucun volume anonyme n’est déclaré par l’image.

Les journaux privés tournent sur deux fichiers de 256 Kio pour chaque composant. Les échantillons/statuts sont remplacés en RAM. Aucune écriture périodique n’a lieu sur la clé : réglages, changements de version, première interaction Docker et nouvel ID d’image seulement. L’archive Docker d’environ 79 Mio reste sur la clé pour permettre la réinstallation après recréation de docker.img. C’est un compromis explicite pour un bundle autonome disponible avant le montage des pools.

Les verrous de contrôle et du superviseur empêchent les doublons. Le superviseur a son propre groupe de processus setsid ; le PID, les arguments et le compteur de démarrage /proc sont vérifiés avant terminaison. Une commande Docker a un délai maximal ; l’arrêt termine le groupe si une commande en attente bloque. Les événements suivants peuvent réessayer le démarrage, sans créer de seconde instance.

Le nettoyage n’utilise jamais system prune, rm forcé d’une image partagée, ni accès aux fichiers internes de Docker. Si Docker est injoignable après utilisation, l’inventaire reste présent et le hook retourne un échec. L’interface de retrait native peut donc conserver le plugin au lieu d’annoncer un succès.
