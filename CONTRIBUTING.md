# Contributing

Open an issue with the plugin version, Unraid version and detected display profile. Remove identifiers, addresses, tokens and private paths from diagnostics before sharing them.

PHP collects metrics on the Unraid host. The Python worker uses only the JSON feed received in its isolated container. Preserve resource ownership checks, unavailable values as `null`, bounded operations and compatibility with existing settings.

## Build and verify

Development prerequisites: Linux x86_64, Python ≥ 3.11, Node.js ≥ 18, xz, and Docker or Podman. The plugin does not install these development tools globally on Unraid.

```bash
python3 scripts/fetch-dependencies.py
CONTAINER_ENGINE=docker bash scripts/build.sh
# Alternative: CONTAINER_ENGINE=podman bash scripts/build.sh
```

The build runs PHP CLI, PHP-FPM, JavaScript, worker and release tests, then verifies the image and bundle. PHP tests write native paths inside disposable containers: do not run `tests/run.sh` or `tests/test_http_fpm.py` directly on a real server. Lifecycle tests simulate Docker. Physical display tests follow the [validation guide](docs/VALIDATION-UNRAID.md).

Build logs are in `.build/logs/` and deliverables in `dist/`. Local third-party archives are ignored by Git; `dependencies.lock.json` records their versions and hashes.

Use English for interface source messages, technical errors/logs, documentation and contribution templates. The settings page uses bundled translations for all 27 Unraid languages; see [TRANSLATIONS.md](docs/TRANSLATIONS.md) to contribute a correction. Do not translate configuration keys or identifiers. `README.fr.md` preserves the previous French README. Historical evidence logs retain their original wording. The original SVG logo is in `icon.svg`; `icon.png` is its 256 × 256 export, used in the plugin payload and Community Apps. Regenerate it with `magick -background none icon.svg -strip PNG32:icon.png` after changing the SVG.

Describe the behavior changed and the checks performed in each contribution. Preserve LF line endings, license notices and pinned dependency versions; exclude generated files from commits. New catalog metadata must be included in both source export allowlists.
