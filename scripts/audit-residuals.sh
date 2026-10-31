#!/bin/bash
# Standalone, read-only. Keep this copy on your workstation or under /tmp.
set -u
name=thermalright-digital
result=0
echo 'Private paths still present :'
for p in "/boot/config/plugins/$name" "/boot/config/plugins/$name.plg" "/boot/config/plugins-removed/$name.plg" "/var/log/plugins/$name.plg" "/var/run/$name" "/usr/local/emhttp/plugins/$name" "/usr/local/emhttp/plugins/.$name-stage" "/usr/local/emhttp/plugins/.$name-backup"; do
  if [[ -e "$p" || -L "$p" ]]; then printf '  %s\n' "$p"; result=1; fi
done
echo 'Private processes still running :'
for file in /proc/[0-9]*/cmdline; do
  [[ -r "$file" ]] || continue
  process=$(tr '\0' ' ' < "$file" 2>/dev/null) || continue
  case "$process" in
    *'/usr/local/emhttp/plugins/thermalright-digital/control.php supervise'*|*'td-finalize-manifest'*)
      pid=${file#/proc/}; pid=${pid%/cmdline}
      printf '  PID %s : %s\n' "$pid" "$process"; result=1 ;;
  esac
done
if ! command -v docker >/dev/null || ! timeout 5 docker info >/dev/null 2>&1; then
  echo 'Docker unavailable : Docker check incomplete.'; exit 2
fi
for kind in container image volume network; do
  printf '\nDocker %s with ownership label :\n' "$kind"
  ids=$(timeout 10 docker "$kind" ls --quiet --filter "label=io.local.thermalright-digital.owner=$name") || exit 2
  [[ -z "$ids" ]] || { printf '%s\n' "$ids"; result=1; }
done
echo 'Labeled IDs require review: pre-existing or shared images are intentionally preserved. System logs are kept.'
exit "$result"
