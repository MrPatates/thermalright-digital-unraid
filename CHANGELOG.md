# Historique

## 2026.10.31.8 — première publication GitHub

- Code fonctionnel de la .7 conservé, désormais confirmé sur Unraid 7.3.4 / LF12.
- Numéro public distinct des paquets .7 locaux pour une mise à jour sans collision.
- Journaux de validation inclus explicitement dans les résultats GitHub Actions.
- Documentation et archives vérifiées pour la publication.

## 2026.10.31.7 — version locale validée

- Interface plus compacte et ciblage thermique par catégorie, dont « Température uniquement ».
- Installation et recherche de mises à jour depuis les releases GitHub.
- Dépendances verrouillées, récupérées avec vérification SHA-256.
- Construction, tests et création d’une release en brouillon via GitHub Actions.
- Nettoyage indépendant des noms des pools locaux, avec les mêmes preuves d’appartenance.
- Documentation publique, licences et sources complètes ; diagnostics privés exclus.

## 2026.10.31.6

- Correction des watts CPU avec un compteur RAPL lisible lorsque `enabled=0`.
- Fonctionnement des watts confirmé par l’utilisateur sur Unraid 7.3.4, profil LF12.

## 2026.10.31.5

- RGB fixe, zones, température, respiration et arc-en-ciel ; luminosité et lissage.
- Watts CPU, fréquence MHz et test visuel temporaire de 8 secondes.

## 2026.10.31.1 à 2026.10.31.4

- Plugin natif Unraid, backend Docker isolé, cycle de vie et nettoyage par inventaire.
- Corrections CSRF et réponses JSON PHP-FPM ; installation par URL.
