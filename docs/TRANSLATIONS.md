# Plugin translations

The settings page follows the language selected in Unraid. French is included, along with every other language offered by the official Unraid language repositories at the time of this release: 27 languages including English. There is no separate plugin language setting. After changing language or updating the plugin, reload the settings page; use Ctrl+F5 if the browser retains an older page.

Supported locales: Arabic (`ar_AR`), Bengali (`bn_BN`), Catalan (`ca_CA`), Chinese Simplified (`zh_CN`), Chinese Traditional (`zh_TW`), Croatian (`hr_HR`), Czech (`cs_CZ`), Danish (`da_DA`), Dutch (`nl_NL`), English (`en_US`), French (`fr_FR`), German (`de_DE`), Greek (`el_GR`), Hungarian (`hu_HU`), Italian (`it_IT`), Japanese (`ja_JA`), Korean (`ko_KO`), Latvian (`lv_LV`), Norwegian (`no_NO`), Polish (`pl_PL`), Portuguese Brazil (`pt_BR`), Portuguese Portugal (`pt_PT`), Romanian (`ro_RO`), Russian (`ru_RU`), Spanish (`es_ES`), Swedish (`sv_SE`) and Ukrainian (`uk_UA`). Arabic uses right-to-left layout within the plugin panel; technical values and time inputs retain their reading direction.

Translated content includes settings, help, weekdays, schedule/display status, color categories, buttons and common API errors. Server time remains the actual Unraid server time in its configured timezone. Logs, diagnostics, installation/maintenance commands, hardware names and protocol identifiers remain in English. Locale changes do not change configuration keys or saved values. Project documentation uses English; `README.fr.md` preserves the earlier French README.

## Implementation

`plugin/i18n.php` reads the native page locale, then the native session locale, then `[display] locale` in `/boot/config/plugins/dynamix/dynamix.cfg` when no native locale was supplied. An explicit empty locale means English, as in Unraid. Only canonical locales in `plugin/lang/languages.json` become catalog filenames. Common standard spellings are mapped to Unraid's locale codes. Unsupported languages fall back to English.

Each `plugin/lang/<locale>.json` maps the English source message to plain translated text. `en_US.json` is the English master, shared by PHP-rendered HTML and JavaScript messages. Missing, empty, malformed or placeholder-incompatible entries fall back to the English source. The plugin also honors valid translations already loaded by Unraid's native `_()` function. It never writes into core language packs, modifies their caches or replaces native translation functions.

PHP escapes translated HTML text and attributes. The inline JSON uses hexadecimal escaping for HTML-sensitive characters. JavaScript inserts translations, server messages and substituted values through text nodes. Named placeholders are substituted without evaluation. Selecting a language does not write settings, change stored RGB/schedule values or alter CSRF validation.

## Contribute a correction

1. Edit the relevant JSON catalog in `plugin/lang/`. Keep the English keys unchanged, including punctuation and case.
2. Preserve every named placeholder exactly: for example, `{model}`, `{status}`, `{zones}`, `{time}` and `{timezone}`. A translation may move placeholders within a sentence.
3. Use plain text, with no HTML or JavaScript. JSON must remain valid UTF-8 and contain no duplicate keys.
4. Add new UI source messages to `en_US.json` and every catalog. A new locale also needs its native name and direction in `languages.json`.
5. Run `python3 tests/test_translations.py` and `node tests/test_ui.js`. The full build also checks PHP/FPM rendering, English fallback, escaping, unchanged settings and packaged catalogs. See [CONTRIBUTING.md](../CONTRIBUTING.md) for the isolated test environment.

The initial translations have not all been reviewed by native speakers. Automated checks validate coverage, placeholders, safe rendering and behavior; they do not certify linguistic quality. Corrections from native speakers are welcome through issues and pull requests.

Native integration references: [Unraid multi-language documentation](https://docs.unraid.net/go/multi-language/), [official language repositories](https://github.com/orgs/unraid/repositories?q=lang-), and [Unraid translation implementation](https://github.com/unraid/webgui/blob/master/emhttp/plugins/dynamix/include/Translations.php). The supported locale list is pinned to this release and checked in tests; new official languages need an added catalog in a future update.
