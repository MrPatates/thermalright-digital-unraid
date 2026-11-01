THERMALRIGHT DIGITAL for Unraid — 2026.11.01.2.

- Restored French and added translations for all 27 languages offered by Unraid, including English.
- The plugin follows the language selected in Unraid automatically. No separate language setting is required.
- Settings, help, status messages, schedule weekdays, color categories and common errors are translated. Arabic has right-to-left layout within the plugin panel.
- Missing or invalid translations fall back to English. Native Unraid language-pack translations are honored when available; no core language files are modified.
- Stored settings and protocol identifiers stay unchanged. Server time still follows the Unraid server timezone. Technical logs, diagnostics and maintenance commands remain in English.

After updating through Plugins, reload Settings with Ctrl+F5. To use French, select French in Unraid's language settings and reload the plugin page. The permanent installation URL stays unchanged.

Automated checks cover all catalogs, locale selection, placeholders, safe HTML/JSON, real PHP-FPM rendering, UI behavior, settings retention and offline packaging. The initial translations are open to native-speaker corrections; automated tests do not certify linguistic quality. Core hardware behavior was previously confirmed on Unraid 7.3.4 / LF12. Scheduled off/wake still needs the physical checks in docs/VALIDATION-UNRAID.md.

Install thermalright-digital.plg through Plugins → Install Plugin. Downloads are version-pinned and SHA-256 verified. Keep each build's manifest and annexes together. Offline bundle, corresponding source and third-party licenses are included.
