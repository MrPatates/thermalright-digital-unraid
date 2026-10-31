# Validation on the server and physical display

These checks require your Unraid server. Only the user-confirmed checks listed in [TEST-REPORT.md](TEST-REPORT.md) have hardware confirmation. Never run manual TRCC alongside the plugin.

1. Record the environment before installation:

   ```bash
   cat /etc/unraid-version
   uname -m
   lsusb -d 0416:8001
   docker version
   docker ps -a --filter name=thermalright-screen
   docker images thermalright-screen
   test ! -d /mnt/user/appdata/thermalright-screen || ls -ld /mnt/user/appdata/thermalright-screen
   ```

2. Install following the README. Check the Plugins entry, logo and **Settings → THERMALRIGHT DIGITAL** page. Start with autostart disabled and choose an identifiable CPU sensor. Compare its reading to the host:

   ```bash
   for h in /sys/class/hwmon/hwmon*; do
     echo "$h $(cat "$h/name" 2>/dev/null)"
     for t in "$h"/temp*_input; do
       test -f "$t" && echo "$t $(cat "$t")"
     done
   done
   php /usr/local/emhttp/plugins/thermalright-digital/control.php status
   ```

3. Click **Start**, wait for the initial image load, then inspect the real Docker contract:

   ```bash
   docker inspect THERMALRIGHT-DIGITAL-BACKEND --format '{{json .HostConfig.Devices}}'
   docker inspect THERMALRIGHT-DIGITAL-BACKEND --format '{{json .Mounts}}'
   docker inspect THERMALRIGHT-DIGITAL-BACKEND --format '{{json .Config.Labels}}'
   docker inspect THERMALRIGHT-DIGITAL-BACKEND --format '{{.HostConfig.Privileged}} {{.HostConfig.NetworkMode}} {{.HostConfig.ReadonlyRootfs}} {{json .HostConfig.CapDrop}}'
   cat /var/run/thermalright-digital/driver/status.json
   tail -n 60 /var/run/thermalright-digital/driver/driver.log
   ```

   Expect one `/dev/bus/usb/BBB/DDD` node, two private binds, no boot flash/socket/appdata mounts, and false / none / true / ALL. Check the uppercase name, dedicated Docker icon and WebUI link. After upgrade, no older owned lowercase backend should remain. The log should identify the handshake PM and model. Export diagnostics for unsupported style or USB permission errors; do not bypass isolation with privileged mode.

4. **Observe the physical display.** Temperature should match the selected sensor and load should change with host activity. “Frames sent” confirms transfers, not physical rendering. Uninstrumented GPU areas should stay blank. Stop should release USB. Restart may reuse this boot's handshake cache without assuming unlimited firmware handshake replies.

   Under **Display colors**, try red/green/blue fixed colors, brightness at 25/100%, category colors, breathing and rainbow. Check digits/decorations targeting against reported capabilities. In temperature mode, click **Temperature only**: °C digits should follow the palette while W/MHz/% and decorations retain fixed colors. Then test multiple categories and the global scope filter. Compare colors with thresholds and the displayed sensor; smoothing must affect color only.

   Click **Test colors and segments**: expect an 8-second red/green/blue/segment sequence, an active-test message and automatic return. An unsaved settings draft must remain unsaved. Page readings must remain real.

   Inspect MHz/watt sources read-only:

   ```bash
   for f in /sys/devices/system/cpu/cpufreq/policy*/{affected_cpus,cpuinfo_cur_freq,scaling_cur_freq}; do
     [ -f "$f" ] && printf '%s = %s\n' "$f" "$(cat "$f")"
   done
   for f in /sys/class/hwmon/hwmon*/{name,power*_average,power*_input,power*_label} /sys/class/powercap/*/{name,energy_uj,max_energy_range_uj} /sys/class/powercap/*/*/{name,energy_uj,max_energy_range_uj}; do
     [ -f "$f" ] && printf '%s = %s\n' "$f" "$(cat "$f")"
   done
   cat /var/run/thermalright-digital/feed/metrics.json
   cat /var/run/thermalright-digital/driver/status.json
   ```

   cpufreq uses kHz, hwmon power uses µW and powercap provides energy counters in µJ. The first energy sample is unavailable. RAPL `enabled=0` controls power limiting and does not invalidate a readable advancing counter. `scaling_cur_freq` is labeled requested frequency. Without a supported CPU power source, **Unavailable** and blank W digits are expected. An individually selected rail is not a whole-package measurement. Check `capabilities.power` / `frequency`: host readings may exist without corresponding physical display groups.

5. Enable autostart and reboot during a maintenance window. After the array and Docker start, verify one backend and one supervisor. Stop/restart Docker through Unraid and check again. Test with the array stopped: bounded waiting and no premature backend.

6. For USB absence/reconnection, use a connection designed for safe hot plugging; do not manipulate an internal motherboard connector live. Disconnecting before startup already tests absence. Record old/new USB addresses and inspect delegated devices after reconnecting. Allow 30 seconds between retries. After 180 seconds of absence, click Start once the device returns.

7. Reinstall the same bundle with Force Install and check settings retention. For a future different backend, test updates, explicit rollback with `force` and automatic rollback after backend failure. Automatic rollback is simulated locally but still needs a real differing backend test before unattended reliance.

8. Export diagnostics before removal and keep `audit-residuals.sh` under `/tmp` or on your workstation. Remove the plugin with Docker available. After 10 seconds, run the audit: no private files or exclusively owned container/image should remain. Verify any unrelated legacy container/image/appdata survived. Native UI caches and system logs are managed separately by Unraid.

9. To test removal with Docker unavailable, use a maintenance window and native UI controls. Stop Docker, request plugin removal, confirm failure and retained inventory, then enable Docker and retry. Do not delete inventory to force success.

Local tests already simulate interrupted installations. Do not cut server power to reproduce them; boot flash and array failure behavior extend beyond this plugin's scope.
