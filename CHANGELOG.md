# Changelog

## 2026.10.31.9 — English interface and Community Apps preparation

- English interface, errors, maintenance output, documentation and GitHub templates; previous French README preserved as `README.fr.md`.
- Original screen/thermometer logo shared by the plugin, Docker instance and catalog metadata.
- Uppercase display name THERMALRIGHT DIGITAL and backend container name THERMALRIGHT-DIGITAL-BACKEND, with ownership-based removal of an older lowercase instance.
- Community Applications repository profile and plugin wrapper with real installation/support links, compatibility limits and hardware validation context.
- Both READMEs, branding and catalog metadata included in complete source and lightweight GitHub exports.

## 2026.10.31.8 — first GitHub release

- Preserved functional code from .7, confirmed on Unraid 7.3.4 / LF12.
- Separate public version number to update local .7 installations without package collisions.
- Validation logs explicitly included in GitHub Actions artifacts.
- Documentation and archives checked for publication.

## 2026.10.31.7 — validated local release

- Compact interface and temperature targeting by category, including Temperature only.
- Installation and update checks from GitHub releases.
- Pinned dependencies retrieved with SHA-256 verification.
- Build, test and draft-release workflows in GitHub Actions.
- Cleanup independent of local pool names, preserving ownership checks.
- Public documentation, licenses and complete sources; private diagnostics excluded.

## 2026.10.31.6

- Fixed CPU watts with a readable RAPL energy counter when `enabled=0`.
- User confirmed working watts on Unraid 7.3.4, LF12 profile.

## 2026.10.31.5

- Static RGB, zones, temperature, breathing and rainbow, with brightness and smoothing.
- CPU watts, MHz and a temporary 8-second visual test.

## 2026.10.31.1–2026.10.31.4

- Native Unraid plugin, isolated Docker backend, lifecycle and inventory-based cleanup.
- CSRF and PHP-FPM JSON fixes; installation by URL.
