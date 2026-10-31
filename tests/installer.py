"""Execute the actual .plg INLINE installer in a disposable fake Unraid root."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import unittest
import xml.etree.ElementTree as ET
import functools
import http.server
import threading

spec=importlib.util.spec_from_file_location('package','/work/scripts/package.py')
pack=importlib.util.module_from_spec(spec);spec.loader.exec_module(pack)


class InstallerTests(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory(prefix='td-installer-',dir='/tmp')
        self.root=Path(self.temp.name)
        self.env=dict(os.environ,TD_TEST_ROOT=str(self.root),
                      TD_TEST_DOCKER='/work/tests/fake-docker.php',TD_FAKE_STATE=str(self.root/'docker.json'))
        self.write('etc/unraid-version','version="7.2.0"\n')
        self.write('var/local/emhttp/var.ini','mdState="STARTED"\n')
        self.write('docker.json',json.dumps(dict(online=True,containers={},images={},volumes={},networks={})))
        image=self.root/'input.xz';image.write_bytes(b'fake image export')
        self.bundles={}
        for version in ('2026.10.31.1','2026.10.31.2'):
            out=self.root/('build-'+version)
            b=pack.package(out,image,'sha256:'+'a'*64,version)
            target=self.root/'boot/config/plugins/thermalright-digital/packages'/version
            shutil.copytree(b,target)
            self.bundles[version]=target
        self.active=self.root/'usr/local/emhttp/plugins/thermalright-digital'

    def tearDown(self):
        if (self.active/'control.php').exists():
            subprocess.run(['php',str(self.active/'control.php'),'stop'],env=self.env,capture_output=True,timeout=30)
        self.temp.cleanup()

    def write(self,name,text):
        path=self.root/name;path.parent.mkdir(parents=True,exist_ok=True);path.write_text(text)

    def install(self,version='2026.10.31.1',extra=None,ok=True):
        plg=self.bundles[version]/'thermalright-digital.plg'
        xml=ET.parse(plg)
        code=xml.find("./FILE[@Method='install']/INLINE").text
        script=self.root/'inline.sh';script.write_text(code)
        r=subprocess.run(['bash',str(script)],env=dict(self.env,**(extra or {})),text=True,capture_output=True,timeout=60)
        if ok:self.assertEqual(r.returncode,0,r.stdout+r.stderr)
        else:self.assertNotEqual(r.returncode,0,r.stdout+r.stderr)
        return r

    def current(self):
        return json.loads((self.active/'release.json').read_text())['version']

    def test_install_reinstall_update_and_explicit_rollback(self):
        self.install();self.assertEqual(self.current(),'2026.10.31.1')
        cfg=self.root/'boot/config/plugins/thermalright-digital/settings.json'
        settings=dict(autostart=False,interval=5,sensor='auto');cfg.write_text(json.dumps(settings))
        inventory=self.root/'boot/config/plugins/thermalright-digital/inventory.json'
        instance=json.loads(inventory.read_text())['instance']
        self.install();self.assertEqual(json.loads(inventory.read_text())['instance'],instance)
        self.install('2026.10.31.2');self.assertEqual(self.current(),'2026.10.31.2')
        self.assertEqual(json.loads(cfg.read_text()),settings)
        self.install('2026.10.31.1');self.assertEqual(self.current(),'2026.10.31.1')
        self.assertEqual(json.loads(cfg.read_text()),settings)

    def test_failed_update_restores_old_code_and_settings(self):
        self.install()
        self.install('2026.10.31.2',{'TD_TEST_FAIL':'after_activate'},ok=False)
        self.assertEqual(self.current(),'2026.10.31.1')

    def test_backend_failure_rolls_back_running_installation(self):
        self.install()
        for f,v in dict(idVendor='0416',idProduct='8001',busnum='1',devnum='5').items():
            self.write('sys/bus/usb/devices/1-2/'+f,v)
        self.write('dev/bus/usb/001/005','')
        self.write('proc/stat','cpu 100 0 100 800 0 0 0 0\n')
        r=subprocess.run(['php',str(self.active/'control.php'),'start'],env=self.env,text=True,capture_output=True)
        self.assertEqual(r.returncode,0,r.stderr)
        status=self.root/'var/run/thermalright-digital/driver/status.json'
        for _ in range(50):
            if status.exists():break
            time.sleep(.1)
        self.assertTrue(status.exists())
        fake=json.loads((self.root/'docker.json').read_text())
        fake['not_ready_version']='2026.10.31.2'
        (self.root/'docker.json').write_text(json.dumps(fake))
        self.install('2026.10.31.2',{'TD_TEST_READY_TIMEOUT':'3'},ok=False)
        self.assertEqual(self.current(),'2026.10.31.1')
        for _ in range(50):
            if status.exists() and json.loads(status.read_text()).get('ready'):break
            time.sleep(.1)
        self.assertTrue(status.exists())

    def test_sigkill_interruption_is_recoverable(self):
        self.install()
        result=self.install('2026.10.31.2',{'TD_TEST_INTERRUPT':'after_activate'},ok=False)
        self.assertEqual(result.returncode,-9)
        self.install('2026.10.31.1')
        self.assertEqual(self.current(),'2026.10.31.1')
        self.assertFalse((self.active.parent/'.thermalright-digital-backup').exists())

    def test_failed_first_install_cleans_created_files(self):
        self.install(extra={'TD_TEST_FAIL':'after_activate'},ok=False)
        self.assertFalse(self.active.exists())
        self.assertFalse((self.root/'var/run/thermalright-digital').exists())
        self.assertFalse((self.root/'boot/config/plugins/thermalright-digital/inventory.json').exists())

    def test_corrupt_payload_is_refused_without_activation(self):
        (self.bundles['2026.10.31.1']/'payload.txz').write_bytes(b'corrupt')
        self.install(ok=False);self.assertFalse(self.active.exists())

    def test_preexisting_unmarked_ui_folder_is_preserved(self):
        self.active.mkdir(parents=True);(self.active/'keep.txt').write_text('existing')
        self.install(ok=False);self.assertEqual((self.active/'keep.txt').read_text(),'existing')

    def test_preexisting_configuration_folder_is_preserved(self):
        p=self.root/'boot/config/plugins/thermalright-digital/keep.txt';p.write_text('existing')
        self.install(ok=False);self.assertEqual(p.read_text(),'existing')

    def test_unsupported_unraid_is_refused(self):
        self.write('etc/unraid-version','version="7.4.0"\n')
        self.install(ok=False);self.assertFalse(self.active.exists())

    def url_fixture(self):
        public=self.root/'http';public.mkdir()
        handler=functools.partial(http.server.SimpleHTTPRequestHandler,directory=str(public))
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),handler)
        thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        version='2026.10.31.3'
        base=f'http://127.0.0.1:{server.server_port}/thermalright-digital/packages/{version}'
        bundle=pack.package(public,self.root/'input.xz','sha256:'+'a'*64,version,download_base_url=base)
        native=self.root/'tmp/plugins/thermalright-digital.plg';native.parent.mkdir(parents=True)
        shutil.copyfile(bundle/'thermalright-digital.plg',native)
        target=self.root/'boot/config/plugins/thermalright-digital/packages'/version
        self.bundles[version]=target
        script=self.root/'url-inline.sh';script.write_text(ET.parse(native).find("./FILE[@Method='install']/INLINE").text)
        return server,bundle,target,script

    def test_url_fetches_missing_files_and_boots_offline(self):
        server,bundle,target,script=self.url_fixture()
        shutil.rmtree(self.root/'boot/config/plugins/thermalright-digital/packages')
        try:
            r=subprocess.run(['bash',str(script)],env=self.env,text=True,capture_output=True,timeout=30)
            self.assertEqual(r.returncode,0,r.stdout+r.stderr)
            for name in ['payload.txz','release.json','backend-image.tar.xz','thermalright-digital.plg']:
                self.assertEqual((target/name).read_bytes(),(bundle/name).read_bytes())
        finally:server.shutdown();server.server_close()
        self.install('2026.10.31.3')
        self.assertEqual(self.current(),'2026.10.31.3')

    def test_url_corrupt_download_preserves_current_install(self):
        self.install()
        server,bundle,target,script=self.url_fixture()
        (bundle/'payload.txz').write_bytes(b'corrupt')
        try:
            r=subprocess.run(['bash',str(script)],env=self.env,text=True,capture_output=True,timeout=30)
            self.assertNotEqual(r.returncode,0)
            self.assertFalse(target.exists())
            self.assertEqual(self.current(),'2026.10.31.1')
        finally:server.shutdown();server.server_close()

    def test_url_failed_activation_removes_downloaded_package(self):
        self.install()
        server,bundle,target,script=self.url_fixture()
        try:
            r=subprocess.run(['bash',str(script)],env=dict(self.env,TD_TEST_FAIL='after_activate'),text=True,capture_output=True,timeout=30)
            self.assertNotEqual(r.returncode,0)
            self.assertFalse(target.exists())
            self.assertEqual(self.current(),'2026.10.31.1')
        finally:server.shutdown();server.server_close()


if __name__=='__main__':unittest.main(verbosity=2)
