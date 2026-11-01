THERMALRIGHT DIGITAL for Unraid — 2026.11.01.1.

- Shortened Docker instance name to TR-DIGITAL so it fits beside the native Docker icon.
- Added weekly scheduled display off: choose starting weekdays and off/on times, including overnight intervals, or switch off for entire selected days.
- Uses the Unraid server timezone and daylight saving rules; the server clock is displayed in Settings.
- Blanks all display digits and decorations with the global display switch off, while preserving real metrics and the USB connection. Wakes automatically without a container restart.
- Scheduled off takes priority over visual tests. A running test is canceled and a new one is refused during the off period.
- Schedule disabled by default. Existing settings, paths and ownership are preserved; older owned container names are removed by ownership labels.

After updating, reload Settings with Ctrl+F5. Under Scheduled display off, select days/times and click Save. For Monday 23:00–07:00, select Monday; the screen wakes Tuesday at 07:00. Entire-day mode uses midnight to midnight. Disable the schedule and save to restore output early.

The new schedule and compact Docker layout still require physical verification on Unraid. Automated checks cover host time boundaries/DST, settings, output frames, UI and installation. Core display functionality was previously confirmed on Unraid 7.3.4 / LF12. See docs/TEST-REPORT.md and docs/VALIDATION-UNRAID.md.

Install thermalright-digital.plg through Plugins → Install Plugin. Downloads are version-pinned and SHA-256 verified. Keep the manifest and annexes from the same build together. The complete ZIP supports offline installation; corresponding sources and third-party licenses are included.
