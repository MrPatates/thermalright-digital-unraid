# Contribuer

Ouvrir une issue avec la version du plugin, d’Unraid et le profil détecté. Retirer les identifiants, adresses et chemins privés d’un diagnostic avant de le partager.

Les sources PHP collectent les mesures sur l’hôte Unraid. Le worker Python utilise exclusivement le flux JSON reçu dans son conteneur isolé. Conserver les garanties d’appartenance des ressources, les valeurs absentes à `null`, les opérations bornées et la compatibilité des anciens réglages.

## Construire et vérifier

Prérequis sur le poste de développement : Linux x86_64, Python ≥ 3.11, Node.js ≥ 18, xz, Docker ou Podman. Aucun de ces outils de développement n’est installé globalement sur Unraid par le plugin.

```bash
python3 scripts/fetch-dependencies.py
CONTAINER_ENGINE=docker bash scripts/build.sh
# Alternative : CONTAINER_ENGINE=podman bash scripts/build.sh
```

La construction lance les tests PHP CLI, PHP-FPM, JavaScript, worker et publication, puis vérifie image et bundle. Les tests PHP écrivent dans des chemins natifs de leurs conteneurs éphémères : ne pas lancer `tests/run.sh` ou `tests/test_http_fpm.py` directement sur un serveur réel. Docker est simulé dans les tests de cycle de vie ; l’écran physique nécessite la procédure de [validation](docs/VALIDATION-UNRAID.md).

Les résultats de la construction sont dans `.build/logs/`, les livraisons dans `dist/`. Les archives tierces locales sont ignorées par Git et leurs versions/empreintes sont dans `dependencies.lock.json`.

Pour une contribution, décrire le comportement corrigé et les vérifications réalisées. Conserver LF, les notices de licence et les versions verrouillées ; éviter les fichiers générés dans le commit.
