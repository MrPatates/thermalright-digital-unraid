# Test report and validation limits

## Automated coverage

Release 2026.11.01.2 covers 410 automated tests/checks, including 59 schedule checks and multilingual PHP, PHP-FPM, UI, catalog and packaging checks. The build runs the following suites against actual pinned PHP/TRCC dependencies. Docker lifecycle fixtures simulate the Docker API; they do not access a production daemon or USB device.

- Worker tests: TRCC layouts and masks, unavailable/zero/overflow readings, RGB modes, brightness, thermal category selection, smoothing, stale feeds, visual-test lifetime and error paths.
- PHP lifecycle tests: ownership, version guard, host sampling, single supervisor, USB address change, container isolation, stop/uninstall, unavailable Docker, pre-existing/shared resources and exact native manifest cleanup.
- HTTP security and native CSRF integration: request method, action validation, native preprocessing and the independent token header.
- Cleanup tests: inventory/hash proofs, legacy resources, active release preservation, shared resources, retries, arbitrary pool names and symbolic-link exclusions.
- Settings tests: old settings migration without periodic flash writes, full RGB validation, thermal categories, legacy clients, unknown sensor rejection and visual-test readiness.
- Host metrics tests: unique policies, maximum/average, measured/requested frequency, CPU-only hwmon sources/units, explicitly selected rails, energy deltas and first sample, monotonic timing, wrap/reset/reboot/source identity, corrupt cache, unavailable counters, provider/subdomain deduplication and complete multi-socket totals.
- Real PHP-FPM/FastCGI tests with native Unraid preprocessing: status/logs/diagnostics, settings and RGB, source metadata, start/stop, visual-test readiness/ownership, CSRF/method rejection, configuration/fatal errors and invalid UTF-8 logs.
- Installer tests: failed/interrupted installation, rollback, ownership conflicts and actual HTTP downloads of pinned annexes.
- UI tests: token handling, unavailable readings, malformed/empty/login responses, recovery, real metrics/provenance/capabilities, RGB draft retention, thermal category targeting and visual-test status/errors.
- Release tests: pinned release/update URLs, offline packaging, invalid metadata rejected before writes, dependency hashes and modified-cache preservation, source allowlists, catalog metadata and embedded branding.

PHP/Bash syntax and generated manifest XML are checked. The offline ZIP CRC, all checksums, source package, payload, manifests and executable events are verified. The actual docker-archive export is checked against its advertised configuration ID, tag, architecture, labels and all nine layer diff_ids.

The official TRCC CLI was exercised in a temporary full-dependency container; without USB it exited 1 with an expected transport error. A real production image was also run without USB under the production restrictions, producing `ready=false` and an actionable error rather than installing dependencies on the host. These checks do not validate firmware behavior.

Local logs are in `.build/logs/`; release evidence is copied into `docs/evidence/`. Historical reproductions and original logs retain their original wording, including French output. Negative tests intentionally produce errors before their final passing result. Run current suites rather than interpreting an archived failure reproduction as a current failure.

The first public .8 release passed both GitHub workflows and anonymous asset verification. Its published files were checked against the tag. Version .9 added English text, branding and catalog packaging. Current runs are recorded in Actions and bundled evidence rather than treated as additional physical confirmation.

## Hardware checks

| Check | Status |
|---|---|
| Installation and core display behavior on Unraid 7.3.4 / LF12 | User confirmed with .7 |
| CPU k10temp/Tctl detection | Confirmed in user CLI output |
| RAPL package-0 watts and cpufreq MHz on LF12 | User confirmed after .6 |
| Compact UI and temperature colors by category | User confirmed with .7; individual combinations still need separate checks |
| Scheduled display off/wake and compact Docker name | Automated checks; physical server verification still required |
| Real Unraid uninstall and residual audit | Still required |
| Server/array/Docker restart with autostart | Still required |
| Physical USB unplug/replug and re-enumeration | Still required with a safe connector |
| Rollback after failure of a future different real backend | Still required |
| Other accepted Unraid releases and TRCC layouts | Still required |

The original workstation used Podman for actual image execution and simulated Docker for lifecycle checks. GitHub Actions also builds and tests with Docker. The accepted Unraid ranges are code guards, not certified releases. Follow [VALIDATION-UNRAID.md](VALIDATION-UNRAID.md) for real hardware checks.

To rerun after building:

```bash
CONTAINER_ENGINE=podman bash scripts/check.sh localhost/thermalright-digital:2026.11.01.2
```

PHP fixtures remain in disposable containers with no host Docker socket. Their image versions/digests are pinned in `scripts/check.sh`.

## Historical fixes

Version .3 returned valid CLI JSON on Unraid 7.3.4 but an empty Web response. Real HTTP reproduction identified a controller shebang before PHP strict_types, tolerated in CLI but fatal when included through FPM. Version .4 removed it; CLI calls already invoke PHP explicitly. See `evidence/http-before-fix.log` and `evidence/http-fpm-tests.log`.

Version .5 reported `cpu_watts=null` despite ready LF12 power capabilities. RAPL exposed `enabled=0` while energy advanced from 41,777,528,224 to 41,838,690,375 µJ after a two-second wait. The collector incorrectly rejected it: enabled controls power limiting, not `energy_uj` availability ([Linux documentation](https://docs.kernel.org/power/powercap/powercap.html), [RAPL implementation](https://github.com/torvalds/linux/blob/master/drivers/powercap/intel_rapl_common.c)). Version .6 fixed this without sysfs writes; regression checks cover availability, RAM baseline, valid delta, unchanged controls and unreadable counters. The user confirmed working watts. Original reproduction: `evidence/rapl-before-fix.log`.

Version .7 added per-category thermal targeting and compact UI; legacy forms preserve saved category selections. Cleanup gained arbitrary-pool/symlink checks. Dependency archives were downloaded fresh and verified. Actionlint checked the workflows. The user confirmed the complete .7 installation worked.

Version .8 separated the first public GitHub build from local .7 packages to avoid same-version image/archive collisions, included hidden `.build/logs/` artifacts and excluded private diagnostics from public exports.

## Community Applications scope

Local metadata tests check XML structure, real project/support/install destinations, version bounds and shared artwork. They cannot guarantee the portal's scan result or moderator acceptance. No catalog listing is claimed until the submission is reviewed; see [COMMUNITY-APPLICATIONS.md](COMMUNITY-APPLICATIONS.md).

## Scheduled off release

Tests cover strict schedule validation and legacy migration, same-day/overnight/entire-day boundaries, Sunday-to-Monday wrap, multiple selected days, spring/autumn DST, non-browser timezone handling, timezone changes, RAM feed, visual-test cancellation and no persistent sampling writes. Driver tests check global-off frames for all LEDs, real metric retention, test suppression, automatic wake without USB reconnect and invalid state handling. HTTP tests check persistence, legacy clients, invalid input and refused tests during off. UI checks cover server clock, actual worker state, draft retention, weekdays/times/entire-day controls and validation. Lifecycle tests cover migration from both previous container names and updated inventory ownership.

The schedule and compact Docker layout still need the physical checks in VALIDATION-UNRAID.md. Earlier hardware confirmation does not certify the new off/wake behavior.

## Multilingual interface release

All 27 official Unraid locales are bundled, including English. Seven catalog checks cover exact key coverage, nonempty UTF-8 plain text, preserved named placeholders, UI source coverage and no writes to core language packs. Forty-nine PHP checks cover native/session/config selection, explicit English, aliases, allowlisted paths, missing/corrupt entries, safe HTML/JSON, actual French/Arabic page rendering and native overrides. The UI suite includes one behavior check per locale covering state, server clock, errors, RGB targeting and unchanged serialized settings. Three additional real PHP-FPM tests render every locale, verify English restoration and test HTML/script escaping. Release checks verify all catalogs and the helper are exported and readable in the payload.

This verifies integration and catalog integrity, not native-speaker fluency. Translations and long-text layout remain open to community review. Physical testing with Unraid's installed language packs remains on the manual validation list. Technical logs and diagnostics intentionally remain English.
