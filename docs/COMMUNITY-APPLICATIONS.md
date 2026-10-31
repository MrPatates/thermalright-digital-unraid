# Community Applications submission

This repository is prepared for a plugin submission. It is not listed merely because these files exist; the Community Apps portal must validate and scan it, and a moderator must review the plugin.

## Files and links

- Root `ca_profile.xml`: repository/maintainer description, canonical project link and shared icon.
- `plugins/thermalright-digital.xml`: uppercase display name, English overview, Utilities category, supported Unraid range, icon, GitHub Issues support and latest stable `.plg` installation URL.
- `icon.svg` and `icon.png`: original project artwork; the 256 × 256 PNG is also embedded in the plugin payload for native UI and Docker use.
- Root LICENSE, English README and preserved French README.

The Docker backend is managed by the plugin; it is not a separate Community Apps Docker application and requires no public image registry or separate container template. Install the plugin to create the isolated backend with the correct USB permissions.

## Submit

1. Make sure the source commit is public and the stable release is published with every asset.
2. Open [Community Apps submissions](https://ca.unraid.net/submit) and select **Start Submission**.
3. Enter `https://github.com/MrPatates/thermalright-digital-unraid`.
4. Run **Validate**, then **Scan**; inspect the preview and address any reported problems.
5. Submit for review after the checks pass. Plugin submissions always require manual review.

The support URL is the real GitHub Issues page; no forum thread is claimed. If a support thread is created later, update the wrapper/profile with its real URL.

Local tests verify metadata consistency, packaging and artwork inclusion. They do not substitute for the portal's scan or moderator approval. Hardware validation is documented in [TEST-REPORT.md](TEST-REPORT.md): LF12 on Unraid 7.3.4 was confirmed with .7; other accepted layouts/versions still need hardware checks.

References: [Builder Guide](https://ca.unraid.net/submit/help/builders), [Repository Information XML](https://ca.unraid.net/submit/help/repository-info-xml), [Plugin wrapper format](https://ca.unraid.net/submit/help/repository-xml), [XML field reference](https://ca.unraid.net/submit/help/xml-field-reference).
