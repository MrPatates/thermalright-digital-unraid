THERMALRIGHT DIGITAL for Unraid — 2026.10.31.9.

- English interface, diagnostics, maintenance output, documentation and GitHub contribution templates.
- Previous French README preserved as `README.fr.md`, linked from the English README.
- Original icon shared by the plugin and its Docker instance; uppercase visible plugin name and THERMALRIGHT-DIGITAL-BACKEND container name.
- Community Applications profile and plugin wrapper added, with real support/install links and compatibility limits. Catalog inclusion still requires submission and manual review.
- Both READMEs and all branding/catalog files included in source packages.

Existing settings, plugin identifiers and ownership inventory are preserved. An older owned lowercase backend is removed when the new instance starts. After updating, reload the page with Ctrl+F5; the Docker icon appears when the backend is recreated.

Core functionality, including CPU watts/MHz and temperature targeting by category, was confirmed by the user with .7 on Unraid 7.3.4 and an LF12 display. This release adds English text and branding; physical verification of .9, other layouts, reboot/replug behavior and uninstall remains part of the validation procedure. See docs/TEST-REPORT.md and docs/VALIDATION-UNRAID.md.

Install the `thermalright-digital.plg` asset through Plugins → Install Plugin. Versioned annexes are fetched and verified using SHA-256. The complete ZIP supports offline installation. Keep files from the same build together. Full sources and third-party license notices are included.
