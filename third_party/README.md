# Sources tierces

Les archives de sources sont téléchargées par `python3 scripts/fetch-dependencies.py` et vérifiées contre `dependencies.lock.json`. Elles restent ignorées par Git et sont incluses dans le paquet source distribué avec les releases, afin d’accompagner le backend binaire avec ses sources et licences.
