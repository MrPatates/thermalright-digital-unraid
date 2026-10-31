# Validation sur le serveur et l’écran

Ces opérations nécessitent votre Unraid ; elles n’ont pas été exécutées ici. Remplacer uniquement l’adresse du serveur pour la copie du README. Ne pas lancer le programme manuel TRCC en même temps que le plugin.

1. Avant installation, relever :

   ```bash
   cat /etc/unraid-version
   uname -m
   lsusb -d 0416:8001
   docker version
   docker ps -a --filter name=thermalright-screen
   docker images thermalright-screen
   test ! -d /mnt/user/appdata/thermalright-screen || ls -ld /mnt/user/appdata/thermalright-screen
   ```

2. Installer le bundle comme décrit dans le README. Vérifier l’entrée dans Plugins et la page Paramètres. Commencer sans autostart ; choisir une sonde CPU identifiable. Comparer sa valeur à l’hôte :

   ```bash
   for h in /sys/class/hwmon/hwmon*; do
     echo "$h $(cat "$h/name" 2>/dev/null)"
     for t in "$h"/temp*_input; do
       test -f "$t" && echo "$t $(cat "$t")"
     done
   done
   php /usr/local/emhttp/plugins/thermalright-digital/control.php status
   ```

3. Démarrer depuis la page. Attendre le chargement initial. Inspecter le contrat réel du conteneur :

   ```bash
   docker inspect thermalright-digital-backend --format '{{json .HostConfig.Devices}}'
   docker inspect thermalright-digital-backend --format '{{json .Mounts}}'
   docker inspect thermalright-digital-backend --format '{{json .Config.Labels}}'
   docker inspect thermalright-digital-backend --format '{{.HostConfig.Privileged}} {{.HostConfig.NetworkMode}} {{.HostConfig.ReadonlyRootfs}} {{json .HostConfig.CapDrop}}'
   cat /var/run/thermalright-digital/driver/status.json
   tail -n 60 /var/run/thermalright-digital/driver/driver.log
   ```

   Attendu : un seul nœud `/dev/bus/usb/BBB/DDD`, deux binds privés, aucune clé de démarrage/socket/appdata monté ; false / none / true / ALL. Le journal doit annoncer le PM et le modèle issus du handshake. Si le style est inconnu ou la permission USB refusée, exporter le diagnostic ; ne pas activer privileged pour contourner le problème.

4. **Observer physiquement l’écran.** La température doit correspondre à la sonde choisie, la charge doit varier avec l’activité du serveur. L’indicateur « trames transmises » ne prouve pas le rendu physique. Les régions GPU non instrumentées doivent rester vides. Arrêter doit libérer l’USB ; relancer doit réutiliser le cache de handshake du même démarrage, pas supposer que le firmware répond indéfiniment.

   Dans **Couleurs de l’écran**, essayer couleur fixe rouge/vert/bleu, luminosité 25/100 %, couleurs par zone puis respiration/arc-en-ciel. Vérifier la cible chiffres/décorations selon les capacités annoncées. Pour le mode température, sélectionner « Température uniquement » : les chiffres °C doivent suivre la palette, tandis que W/MHz/% et décorations gardent leurs couleurs fixes par zone. Vérifier ensuite une combinaison de catégories et le filtre chiffres/décorations. Comparer la couleur aux seuils et à la sonde affichée ; le lissage doit agir sur la couleur, pas sur la mesure.

   Cliquer **Tester les couleurs et les segments** : séquence rouge/vert/bleu/segments pendant 8 secondes, indication de test dans la page puis retour automatique. Le test ne doit pas enregistrer un brouillon de paramètres. Les mesures de la page restent réelles.

   Pour les MHz et watts, contrôler les sources en lecture seule :

   ```bash
   for f in /sys/devices/system/cpu/cpufreq/policy*/{affected_cpus,cpuinfo_cur_freq,scaling_cur_freq}; do
     [ -f "$f" ] && printf '%s = %s\n' "$f" "$(cat "$f")"
   done
   for f in /sys/class/hwmon/hwmon*/{name,power*_average,power*_input,power*_label} /sys/class/powercap/*/{name,energy_uj,max_energy_range_uj} /sys/class/powercap/*/*/{name,energy_uj,max_energy_range_uj}; do
     [ -f "$f" ] && printf '%s = %s\n' "$f" "$(cat "$f")"
   done
   cat /var/run/thermalright-digital/feed/metrics.json
   cat /var/run/thermalright-digital/driver/status.json
   ```

   cpufreq est en kHz, hwmon power en µW ; powercap est un compteur d’énergie en µJ, pas des watts instantanés. Le premier échantillon d’énergie reste indisponible. `enabled=0` dans une zone RAPL décrit la limitation de puissance et ne bloque pas un compteur lisible qui progresse ; la version .6 corrige ce cas. `scaling_cur_freq` est signalé comme fréquence demandée. Sur un noyau sans source CPU de puissance, **Indisponible** et chiffres W éteints sont attendus. La puissance d’un rail explicitement choisi n’est pas celle du package complet. Vérifier `capabilities.power` / `frequency` : une mesure hôte peut exister sans zone physique correspondante.

5. Activer l’autostart et redémarrer lors d’une fenêtre de maintenance. Vérifier après démarrage de l’array et Docker qu’il existe un seul conteneur et un seul superviseur. Arrêter puis redémarrer Docker depuis Unraid et vérifier de nouveau. Tester aussi un démarrage array arrêté : attente bornée et absence de conteneur prématuré.

6. Test de déconnexion : **ne pas manipuler un connecteur interne de carte mère sous tension s’il n’est pas prévu pour cela.** Un écran débranché avant le démarrage permet déjà le test d’absence. Avec une liaison USB permettant un rebranchement sûr, noter l’ancien lsusb, débrancher/rebrancher puis vérifier la nouvelle adresse et les devices inspectés. Laisser jusqu’à 30 secondes entre tentatives. Après 180 secondes d’absence, cliquer Démarrer une fois l’écran revenu.

7. Mise à jour/réinstallation : utiliser Force Install avec le même bundle ; contrôler la conservation des paramètres. Pour un futur bundle de version différente, vérifier la mise à jour et le retour au manifeste précédent avec `force`. Le retour automatique en cas de panne est testé avec un faux Docker local, mais doit être vérifié sur une future vraie version avant usage sans surveillance.

8. Exporter le diagnostic avant retrait et conserver `audit-residuals.sh` sous `/tmp` ou sur le poste. Supprimer le plugin depuis Plugins avec Docker actif. Après 10 secondes, lancer l’audit. Aucun fichier privé/conteneur/image exclusivement créée pour lui ne doit rester. Vérifier que l’ancien conteneur/image/appdata éventuel est intact. Les logs système peuvent rappeler l’opération.

9. Pour vérifier le chemin d’échec Docker sans casser un serveur en production, utiliser une fenêtre de maintenance : arrêter Docker avec les commandes natives de l’interface, demander le retrait du plugin, confirmer le message d’échec et la présence de l’inventaire ; réactiver Docker et relancer Supprimer. Ne pas effacer l’inventaire pour forcer le résultat.

La suite locale simule déjà les interruptions d’installation. Éviter de couper l’alimentation de votre serveur pour reproduire ce test : le comportement de la clé FAT et de l’array dépasse le périmètre du plugin.
