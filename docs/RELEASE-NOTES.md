Première release publique de Thermalright Digital pour Unraid, version 2026.10.31.8.

Elle reprend le code fonctionnel de la .7 confirmée sur le serveur de l’utilisateur. Le nouveau numéro évite les collisions avec les paquets .7 de validation locale.

- Interface native française, température et charge CPU hôte.
- Watts CPU par hwmon/RAPL et MHz par cpufreq ; correction de RAPL enabled=0.
- Interface compacte : cartes de mesures, sources repliables et réglages regroupés.
- RGB fixe, zones, température, respiration, arc-en-ciel et test visuel de 8 secondes.
- Ciblage thermique par catégorie : les chiffres °C peuvent suivre la température seuls, avec couleurs fixes pour W/MHz/%.
- Backend Docker isolé, installation et mises à jour par URL GitHub.
- Réglages persistants, retour arrière et nettoyage des ressources vérifiées.

Fonctionnement confirmé par l’utilisateur sur Unraid 7.3.4, USB 0416:8001, profil LF12, incluant les watts CPU, la nouvelle interface et le ciblage thermique par catégorie en version .7. Les autres dispositions restent à valider physiquement. Les tests locaux utilisent Podman et un Docker simulé pour les opérations de cycle de vie ; ils ne certifient pas toutes les versions Unraid acceptées.

Installer `thermalright-digital.plg` depuis cette release, ou utiliser le ZIP complet pour une installation hors ligne. `SHA256SUMS` vérifie les fichiers joints. `thermalright-digital-source.tar.xz` contient les sources du plugin et des composants tiers.
