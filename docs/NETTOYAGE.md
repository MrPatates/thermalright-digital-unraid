# Ancienne installation et anciennes versions

Utiliser cet outil après installation du plugin, avec Docker actif. Il conserve la version active, ses paramètres, son conteneur et son image. Le retrait des anciens paquets supprime la possibilité de revenir à ces versions.

Audit depuis le terminal Unraid :

```bash
php /usr/local/emhttp/plugins/thermalright-digital/cleanup.php --audit
```

Suppression des candidats vérifiés :

```bash
php /usr/local/emhttp/plugins/thermalright-digital/cleanup.php --apply
```

La copie autonome `cleanup-thermalright.php` peut aussi être téléchargée depuis les fichiers joints à la release et exécutée par PHP ; elle utilise les mêmes vérifications et le contrôleur installé. Son SHA-256 est fourni dans `cleanup-thermalright.sha256`.

L’outil traite les deux catégories demandées :

- Paquets des versions autres que la version active, après vérification de l’inventaire, de l’empreinte du manifeste, des métadonnées et des archives. Les anciens manifests de retour arrière sont retirés si leur hash est connu. Les anciennes images propres au plugin sont retirées si elles sont inutilisées et exclusives. Les copies vérifiées des bundles dans les dossiers `thermalright-digital` situés directement sous un stockage `/mnt/<nom>/` reconnu sont aussi retirées.
- Conteneur `thermalright-screen` portant exactement `trcc.setup=unraid-thermalright-0416-8001`, image reliée à ce conteneur ou portant le même label, appdata prouvé par un montage de ce conteneur et limité aux chemins appdata thermalright-screen connus. Les anciens volumes/réseaux ayant ce label sont retirés s’ils ne sont pas partagés. Les modèles Docker identifiés sont retirés ; seule la ligne thermalright-screen est retirée du fichier d’autostart Unraid, les autres lignes sont conservées.

Les anciens ZIP/update téléchargés sous /tmp ou à la racine des stockages détectés sont supprimés uniquement si leur SHA-256 correspond exactement aux anciennes archives publiées. Les noms seuls ne suffisent pas. Les logs système restent intacts.

Un homonyme sans label, un dossier sans preuve de montage, un fichier modifié/inconnu ou une ressource partagée est signalé **CONSERVÉ**. Les références à thermalright-screen dans `go`, User Scripts, cron ou des modèles non identifiés sont signalées **À EXAMINER** et restent en place. L’outil ne promet pas de supprimer des commandes manuelles inconnues. Il ne lance aucun prune global et ne touche pas aux couches internes de Docker ; le fichier texte d’autostart est une métadonnée du gestionnaire Unraid.

Le journal privé `maintenance.json` conserve les preuves nécessaires si le nettoyage est interrompu après suppression du conteneur mais avant celle de son image/dossier. Il est retiré quand il n’y a plus de ressource vérifiée en attente. Le contrôle peut être relancé sans supprimer la version active.

Codes de sortie : 0 = traitement terminé ; 1 = erreur empêchant de confirmer le nettoyage ; 2 = éléments encore présents ou conservés à examiner (également normal pendant un audit qui trouve des restes). Un code 2 ne signifie pas que tout a été effacé.
