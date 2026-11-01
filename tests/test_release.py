"""Publication guards: pinned URLs, safe packaging and reproducible inputs."""
import hashlib
import importlib.util
import json
from pathlib import Path
import struct
import tarfile
import tempfile
import unittest
import xml.etree.ElementTree as ET

BASE = Path(__file__).resolve().parents[1]

def module(name, script):
    spec = importlib.util.spec_from_file_location(name, BASE / 'scripts' / script)
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value

pack = module('package', 'package.py')
fetcher = module('fetcher', 'fetch-dependencies.py')
exporter = module('exporter', 'prepare-github.py')

class ReleaseTests(unittest.TestCase):
    def test_community_apps_metadata_and_source_export_are_consistent(self):
        repository = json.loads((BASE / 'project.json').read_text())['repository']
        project = 'https://github.com/' + repository
        icon = f'https://raw.githubusercontent.com/{repository}/main/icon.png'
        profile = ET.parse(BASE / 'ca_profile.xml').getroot()
        self.assertEqual(profile.tag, 'CommunityApplications')
        self.assertTrue(profile.findtext('Profile', '').strip())
        self.assertEqual(profile.findtext('WebPage'), project)
        self.assertEqual(profile.findtext('Icon'), icon)
        wrapper = ET.parse(BASE / 'plugins/thermalright-digital.xml').getroot()
        self.assertEqual(wrapper.tag, 'Plugin')
        self.assertEqual(wrapper.findtext('Name'), 'THERMALRIGHT DIGITAL')
        self.assertEqual(wrapper.findtext('PluginURL'), project + '/releases/latest/download/thermalright-digital.plg')
        self.assertEqual(wrapper.findtext('Support'), project + '/issues')
        self.assertEqual(wrapper.findtext('Project'), project)
        self.assertEqual(wrapper.findtext('Icon'), icon)
        self.assertEqual(wrapper.findtext('MinVer'), '6.12.0')
        self.assertEqual(wrapper.findtext('MaxVer'), '7.3.999')
        self.assertIn('LF12', wrapper.findtext('Overview'))
        files = {str(p.relative_to(BASE)) for p in exporter.source_files()}
        self.assertTrue({'README.md', 'README.fr.md', 'ca_profile.xml', 'plugins/thermalright-digital.xml', 'icon.svg', 'icon.png', 'plugin/README.md'} <= files)
        self.assertIn('(README.fr.md)', (BASE / 'README.md').read_text())

    def test_native_plugin_identity_and_logo_are_packaged_offline(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); image = root / 'image.xz'; image.write_bytes(b'test image')
            package = pack.package(root / 'dist', image, 'sha256:' + 'a' * 64, '2026.10.31.9')
            manifest = ET.parse(package / 'thermalright-digital.plg').getroot()
            self.assertEqual(manifest.attrib['name'], 'thermalright-digital')
            self.assertEqual(manifest.attrib['icon'], 'logo.png')
            with tarfile.open(package / 'payload.txz', 'r:xz') as archive:
                icon = archive.extractfile(manifest.attrib['icon']).read()
                self.assertEqual(icon, (BASE / 'icon.png').read_bytes())
                self.assertEqual(archive.extractfile('icons/logo.png').read(), icon)
                self.assertEqual(icon[:8], b'\x89PNG\r\n\x1a\n')
                self.assertEqual(struct.unpack('>II', icon[16:24]), (256, 256))
                self.assertIn(b'**THERMALRIGHT DIGITAL**', archive.extractfile('README.md').read())
                self.assertIn(b'file:///usr/local/emhttp/plugins/', archive.extractfile('control.php').read())

    def test_all_language_catalogs_and_helper_are_readable_in_offline_payload(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp); image=root/'image.xz'; image.write_bytes(b'test image')
            bundle=pack.package(root/'dist',image,'sha256:'+'a'*64,'2026.11.01.2')
            languages=json.loads((BASE/'plugin/lang/languages.json').read_text())
            exported={str(p.relative_to(BASE)) for p in exporter.source_files()}
            with tarfile.open(bundle/'payload.txz','r:xz') as archive:
                self.assertEqual(archive.getmember('lang').mode,0o755)
                for name in ['i18n.php','lang/languages.json',*[f'lang/{code}.json' for code in languages]]:
                    self.assertEqual(archive.extractfile(name).read(),(BASE/'plugin'/name).read_bytes())
                    self.assertEqual(archive.getmember(name).mode,0o644)
                    self.assertIn('plugin/'+name,exported)

    def test_github_manifest_pins_annexes_and_updates_from_latest(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); image = root / 'image.xz'; image.write_bytes(b'test image')
            package = pack.package(root / 'dist', image, 'sha256:' + 'a' * 64,
                                   '2026.10.31.7', repository='example/plugin')
            manifest = (package / 'thermalright-digital.plg').read_text()
            xml = ET.fromstring(manifest)
            self.assertEqual(xml.attrib['author'], 'example')
            self.assertEqual(xml.attrib['pluginURL'], 'https://github.com/example/plugin/releases/latest/download/thermalright-digital.plg')
            self.assertIn('https://github.com/example/plugin/releases/download/v2026.10.31.7', manifest)
            self.assertIn(hashlib.sha256((package / 'payload.txz').read_bytes()).hexdigest(), manifest)

    def test_offline_manifest_remains_available(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); image = root / 'image.xz'; image.write_bytes(b'test image')
            package = pack.package(root / 'dist', image, 'sha256:' + 'a' * 64, '2026.10.31.7')
            self.assertNotIn('pluginURL', ET.parse(package / 'thermalright-digital.plg').getroot().attrib)

    def test_invalid_publication_metadata_is_rejected_before_writes(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); image = root / 'image.xz'; image.write_bytes(b'test image')
            for options in [dict(version='../escape'), dict(image_id='bad'), dict(repository='owner/repo\"'), dict(download_base_url='https://host/$(shell)')]:
                args = dict(output=root / 'dist', image=image, image_id='sha256:' + 'a' * 64, version='2026.10.31.7')
                args.update(options)
                with self.subTest(options=options), self.assertRaises(ValueError):
                    pack.package(**args)
            self.assertFalse((root / 'dist').exists())

    def test_dependency_integrity_and_modified_cache_preserved(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); source = root / 'upstream'; source.write_bytes(b'locked archive')
            item = dict(path='backend/test.whl', url=source.as_uri(), sha256=hashlib.sha256(source.read_bytes()).hexdigest())
            (root / 'dependencies.lock.json').write_text(json.dumps(dict(files=[item])))
            fetcher.fetch(root)
            target = root / item['path']; self.assertEqual(target.read_bytes(), source.read_bytes())
            target.write_bytes(b'modified local file')
            with self.assertRaises(RuntimeError): fetcher.fetch(root)
            self.assertEqual(target.read_bytes(), b'modified local file')

    def test_source_export_excludes_binary_dependencies(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); (root / 'backend').mkdir(); (root / 'third_party').mkdir()
            (root / 'README.md').write_text('Public source')
            (root / 'backend/worker.py').write_text('pass\n')
            (root / 'backend/vendor.whl').write_bytes(b'ignored')
            (root / 'third_party/vendor.tar.gz').write_bytes(b'ignored')
            (root / 'third_party/README.md').write_text('Sources via lockfile')
            files = [str(p.relative_to(root)) for p in exporter.source_files(root)]
            self.assertIn('backend/worker.py', files)
            self.assertNotIn('backend/vendor.whl', files)
            self.assertNotIn('third_party/vendor.tar.gz', files)

if __name__ == '__main__':
    unittest.main()
