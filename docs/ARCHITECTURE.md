# Architecture and verified design

Primary sources reviewed on 31 October 2026 are recorded in `evidence/source-evidence.json`:

- [Dynamix System Temp manifest](https://github.com/unraid/dynamix/blob/master/unRAIDv6/dynamix.system.temp.plg): native PLUGIN, FILE/INLINE, launch and removal contract.
- [StaXX manifest](https://github.com/quadcom/Staxx/blob/main/staxx.plg): versioned packages and hashes.
- [Official plugin manager](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.plugin.manager/scripts/plugin): local installation, `/var/log/plugins` tracking and preservation when removal fails. `force` is a final argument.
- [Install Plugin page](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.plugin.manager/PluginInstall.page): accepts URLs and local paths.
- [emhttp events](https://github.com/unraid/webgui/blob/master/sbin/emhttp_event): synchronous hooks. Startup hooks launch a detached supervisor instead of waiting for the image to load; shutdown is bounded.
- [Native CSRF form](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix.docker.manager/include/CreateDocker.php), [authentication](https://github.com/unraid/webgui/blob/master/emhttp/auth-request.php) and [PHP request preprocessing](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix/include/local_prepend.php): Unraid validates and removes `$_POST['csrf_token']`. The browser also sends that native token through `X-Thermalright-CSRF-Token`. The API compares the independent header against var.ini using a constant-time comparison; the native form field is still sent for Unraid's own check. Merely finding a stored token never grants access.

The public manifest advertises the latest stable GitHub release using `pluginURL`; annex URLs and hashes remain version-pinned. Offline packaging is also supported. The installer verifies txz and metadata SHA-256 before extraction. Unraid tracks the plugin; the installer replaces files transactionally. The private txz has no hidden post-install scripts. `installpkg`/`upgradepkg` are not used because automatic rollback must retain matching code and settings.

## Driver and production adapter

[TRCC v9.11.2](https://github.com/Lexonight1/thermalright-trcc-linux/tree/v9.11.2) is GPL-3.0-or-later and requires Python ≥ 3.10. Its wheel and source archive were verified against [PyPI metadata](https://pypi.org/pypi/trcc-linux/9.11.2/json); used modules were compared with the GitHub tag.

This command was executed in a temporary container with the full official dependencies:

```bash
TRCC_DAEMON=0 QT_QPA_PLATFORM=offscreen trcc -v led play 0416:8001 --interval 1
```

The CLI accepts the arguments, attempts ConnectDevice/InitializeLed and exits with expected code 1 when no USB display is delegated. This checks CLI imports and syntax, not hardware; see `evidence/cli-validation.log`. `TRCC_DAEMON=0` avoids a shared daemon and keeps the ticker in the calling process.

Production uses an adapter rather than that CLI command. TRCC RenderLed reads the driver's own sensor context and can substitute zero for missing values. The plugin instead uses unmodified `Led`, `PyUsbBulkTransport`, `LedPayload` and segment renderers, receives only host-sampled JSON and masks unavailable groups before transmission. Handshake and physical order remapping remain upstream behavior. PM/SUB selects the style; unsupported layouts are refused explicitly.

The production image installs TRCC with `--no-deps` plus PyUSB 1.3.1, the subset needed by the adapter. Worker imports and lifecycle are tested. General CLI, Qt, themes, network API and their dependencies are not installed. Use plugin commands, not `docker exec ... trcc`; the full CLI validation environment was temporary.

Python 3.12.12 slim-bookworm is pinned by multiarchitecture digest; the build is amd64. Libusb 1.0.26-1 and PyUSB 1.3.1 are pinned. Pip verifies PyUSB's hash and the build verifies the TRCC wheel SHA-256. APT authenticates its index and libusb package. Packaging also pins the actual image ID, archive and every layer. Build files and image inventory record the relevant digests and versions.

## Host metrics and display state

`settings.php` normalizes older settings in memory and validates RGB, power source and frequency mode. `metrics.php` only reads host kernel ABIs: [cpufreq](https://docs.kernel.org/admin-guide/pm/cpufreq.html), [hwmon](https://docs.kernel.org/hwmon/sysfs-interface.html) and [powercap](https://docs.kernel.org/power/powercap/powercap.html). Unique cpufreq policies retain measured/requested provenance. RAPL packages are not added to subdomains or duplicate providers. Energy baselines use monotonic time, source identity and boot_id in RAM; invalid deltas become `null`. RAPL `enabled` controls power limiting, not readable energy availability. No kernel modules or MSR registers are loaded or written.

The supervisor samples the host every second and includes saved RGB settings in the feed. The worker rereads at 10 Hz and applies effects without restart. Exponential smoothing affects colors only. `rgb.temperature_zones` intersects selected categories with the global target; unselected categories use fixed zone colors. Older settings default to all five categories. Unsupported, unavailable or overflowing W/MHz groups are masked without altering actual status values. A feed older than 10 seconds blanks the display.

POST `display-test` requires a live supervisor, recent ready status and a running container with both ownership labels. It writes an 8-second RAM marker, never settings. The worker also bounds duration, uses a monotonic deadline and reports `test_active`, phase and remaining time. Red/green/blue/segment frames replace only the display output; status readings remain real. Stop removes the marker and sends a blank frame.

## Resources, isolation and lifecycle

| Resource | Location / ownership evidence | Cleanup |
|---|---|---|
| Settings, inventory, packages, previous releases | `/boot/config/plugins/thermalright-digital/`; owner, random instance and bundle hashes | Verified removal; unknown files preserved with failure reported |
| UI and native events | `/usr/local/emhttp/plugins/thermalright-digital/`; payload marker | Complete removal |
| Transaction files | hidden `.thermalright-digital-stage` / `-backup` directories; ownership marker | Recovered on reinstall, removed on cleanup |
| Metrics, energy/handshake cache, logs, PID/locks | `/var/run/thermalright-digital/` in RAM | Complete removal |
| Backend container | THERMALRIGHT-DIGITAL-BACKEND; both `io.local.thermalright-digital.owner` and `.instance` | Stop then remove; no global force |
| Imported image | Pinned ID, owner label, write-ahead inventory | Remove only when created by this plugin and unshared |
| Native manifest/link | Unraid plugin manager | Native removal hook and exact-hash archive finalizer |
| Volumes/networks/cron/go tasks | None created | None modified |

Only the resolved USB device is passed rw, without mknod. No privileged mode, global USB rule, Docker socket, boot flash, appdata, host PID/network or other USB nodes are delegated. Docker's normal read-only sysfs may expose metadata without device access. Sensors are not mounted: a read-only bind carries host JSON. The only writable bind is private RAM state/logs. Limits are read-only root, all capabilities dropped, no-new-privileges, no network, 64 PIDs, 192 MiB and 0.5 CPU. The image declares no anonymous volume.

Private logs rotate across two 256 KiB files per component. Status/samples are replaced in RAM. Boot flash is written only for settings changes, release changes, first Docker use or a new image ID. The approximately 79 MiB Docker archive remains on flash to support reinstall after docker.img recreation and before pools mount.

Control/supervisor locks prevent duplicates. The supervisor has a dedicated setsid process group; PID, command arguments and `/proc` start ticks are checked before termination. Docker calls have deadlines and shutdown can terminate the group including a blocked child. Later events can retry without duplicating the supervisor.

Cleanup never runs system prune, forcibly removes shared images or accesses Docker's internal layers. If Docker is unreachable after use, inventory is retained and the remove hook fails so Unraid can preserve the plugin for retry.

## Branding and catalog metadata

The `.plg` internal name and all persistent identifiers remain lowercase for compatibility. Its `icon="logo.png"` resolves inside the installed plugin directory. `plugin/README.md` supplies the uppercase visible name in the native plugin list. The Settings title and Community Apps wrapper also use THERMALRIGHT DIGITAL.

The backend name is uppercase. Existing owned containers, including the old lowercase name, are discovered and removed by instance/owner labels before the new backend starts. `net.unraid.docker.icon` uses a local `file://` PNG URL read by Unraid's manager, so the backend needs no network access and no remote logo download. The native Docker manager handles its own icon cache. A manager label identifies plugin control, and the WebUI label links to the native Settings page.

The same original icon and real project/support links are included in `ca_profile.xml` and `plugins/thermalright-digital.xml`. Catalog preparation does not imply Community Apps approval; see [submission instructions](COMMUNITY-APPLICATIONS.md).
