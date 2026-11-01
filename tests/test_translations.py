"""Catalog integrity and UI coverage. Linguistic review is a separate human task."""
import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
LANG = ROOT / 'plugin/lang'
OFFICIAL = set('ar_AR bn_BN ca_CA cs_CZ da_DA de_DE el_GR en_US es_ES fr_FR hr_HR hu_HU it_IT ja_JA ko_KO lv_LV nl_NL no_NO pl_PL pt_BR pt_PT ro_RO ru_RU sv_SE uk_UA zh_CN zh_TW'.split())


def unique_object(pairs):
    result = {}
    for key, value in pairs:
        if key in result:
            raise ValueError('Duplicate translation key: ' + key)
        result[key] = value
    return result


def load(path):
    return json.loads(path.read_text(encoding='utf-8'), object_pairs_hook=unique_object)


class TranslationTests(unittest.TestCase):
    def test_all_official_language_codes_have_catalogs(self):
        languages = load(LANG / 'languages.json')
        self.assertEqual(set(languages), OFFICIAL)
        self.assertEqual({p.stem for p in LANG.glob('*.json')} - {'languages'}, OFFICIAL)
        for code, info in languages.items():
            self.assertTrue(info['name'].strip())
            self.assertEqual(info['direction'], 'rtl' if code == 'ar_AR' else 'ltr')

    def test_english_is_the_complete_plain_text_master(self):
        english = load(LANG / 'en_US.json')
        self.assertGreater(len(english), 150)
        self.assertTrue(all(key == value for key, value in english.items()))
        for key in english:
            self.assertNotRegex(key, r'<[^>]*>')

    def test_every_language_covers_every_message_without_empty_values(self):
        english = load(LANG / 'en_US.json')
        for code in OFFICIAL:
            with self.subTest(locale=code):
                catalog = load(LANG / (code + '.json'))
                self.assertEqual(catalog.keys(), english.keys())
                self.assertTrue(all(isinstance(v, str) and v.strip() for v in catalog.values()))
                if code != 'en_US':
                    self.assertNotEqual(catalog['Save'], 'Save')
                    self.assertNotEqual(catalog['Scheduled display off'], 'Scheduled display off')

    def test_named_parameters_are_preserved_exactly(self):
        for code in OFFICIAL:
            for source, translated in load(LANG / (code + '.json')).items():
                with self.subTest(locale=code, source=source):
                    self.assertEqual(set(re.findall(r'\{[A-Za-z0-9_]+\}', source)), set(re.findall(r'\{[A-Za-z0-9_]+\}', translated)))

    def test_catalogs_are_plain_text_and_contain_no_script_or_html(self):
        for code in OFFICIAL:
            for value in load(LANG / (code + '.json')).values():
                self.assertNotRegex(value, r'<[^>]*>')
                self.assertNotIn('\x00', value)

    def test_static_and_dynamic_ui_messages_have_master_entries(self):
        page = (ROOT / 'plugin/ThermalrightDigital.page').read_text()
        english = load(LANG / 'en_US.json')
        used = {s.replace("\\'", "'").replace('\\\\', '\\') for s in re.findall(r"\b(?:td_h|t)\('((?:\\.|[^'\\])*)'", page)}
        self.assertGreater(len(used), 120)
        self.assertEqual(used - english.keys(), set())
        self.assertNotIn('innerHTML', page)

    def test_translation_support_never_writes_core_language_files(self):
        helper = (ROOT / 'plugin/i18n.php').read_text()
        self.assertNotRegex(helper, r'\b(?:file_put_contents|fopen|mkdir|unlink|parse_plugin)\s*\(')
        self.assertIn("PHP_SAPI==='cli'", helper)


if __name__ == '__main__':
    unittest.main(verbosity=2)
