# Legacy installation and old releases

Run cleanup after installing the plugin, with Docker available. The active version, settings, container and image are preserved. Removing old packages removes the ability to roll back to those versions.

Audit from the Unraid terminal:

```bash
php /usr/local/emhttp/plugins/thermalright-digital/cleanup.php --audit
```

Remove verified candidates:

```bash
php /usr/local/emhttp/plugins/thermalright-digital/cleanup.php --apply
```

The standalone `cleanup-thermalright.php` release asset can also run through PHP. It uses the installed controller and the same checks. Verify it with `cleanup-thermalright.sha256`.

The tool handles:

- Packages other than the active release, after checking inventory, manifest hash, metadata and archives. Known rollback manifests and unused exclusive plugin images can be removed. Verified bundle copies directly under recognized `/mnt/<name>/thermalright-digital/` storage paths can also be removed.
- The legacy `thermalright-screen` container with exactly `trcc.setup=unraid-thermalright-0416-8001`, its proven image, and appdata identified by that container's mount at recognized thermalright-screen appdata paths. Labeled legacy volumes/networks are removed only if unshared. Identified Docker templates and only the thermalright-screen entry in Unraid's autostart metadata are removed.

Old ZIP/update downloads under `/tmp` or storage roots are removed only if their SHA-256 matches a known published archive; names alone are insufficient. System logs remain intact.

Names without labels, directories without mount evidence, modified/unknown files and shared resources are marked **KEPT**. References in `go`, User Scripts, cron and unidentified templates are marked **REVIEW** and preserved. The tool does not claim to find every manual command. It never runs a global prune or alters internal Docker layers; the autostart text file is native Unraid manager metadata.

The private `maintenance.json` journal retains ownership evidence across interruptions between container and image/appdata removal. It is removed when no verified pending resource remains. Cleanup can be rerun without removing the active version.

Exit codes: 0 = completed; 1 = error prevents confirmed cleanup; 2 = remaining or preserved items require review, including an audit finding remnants. Exit code 2 does not mean everything was removed.
