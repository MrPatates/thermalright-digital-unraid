"""Real PHP-FPM/FastCGI requests with the unchanged native Unraid prepend.

Run only in the disposable PHP-FPM test container; never on the host/Unraid.
No actual Docker socket or USB node is passed to that container.
"""
import json
import os
from pathlib import Path
import shutil
import socket
import struct
import subprocess
import tempfile
import time
import unittest
from urllib.parse import urlencode

BASE=Path('/work')
UI=Path('/usr/local/emhttp/plugins/thermalright-digital')
STATE=Path('/boot/config/plugins/thermalright-digital')
RUN=Path('/var/run/thermalright-digital')


def record(kind,data=b''):
    return struct.pack('!BBHHBB',1,kind,1,len(data),0,0)+data


def length(n):
    return bytes([n]) if n<128 else struct.pack('!I',n|0x80000000)


def exact(sock,n):
    result=b''
    while len(result)<n:
        part=sock.recv(n-len(result))
        if not part:
            raise RuntimeError('FastCGI connection closed before response completed')
        result+=part
    return result


class FpmTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if not Path('/.dockerenv').exists() and not Path('/run/.containerenv').exists():
            raise RuntimeError('Disposable container required')
        cls.temp=tempfile.TemporaryDirectory(prefix='td-fpm-')
        cls.tmp=Path(cls.temp.name)
        for p in [UI,STATE,RUN,Path('/var/local/emhttp')]:p.mkdir(parents=True,exist_ok=True)
        link=Path('/usr/local/emhttp/state')
        if not link.exists():link.symlink_to('/var/local/emhttp')
        timezone=Path('/etc/localtime')
        if timezone.exists() or timezone.is_symlink():timezone.unlink()
        timezone.symlink_to('/usr/share/zoneinfo/UTC')
        Path('/etc/unraid-version').write_text('version="7.3.4"\n')
        Path('/var/local/emhttp/var.ini').write_text('csrf_token="native-token"\nmdState="STOPPED"\n')
        if not Path('/usr/bin/php').exists():Path('/usr/bin/php').symlink_to('/usr/local/bin/php')
        shutil.copyfile(BASE/'tests/fake-docker.php','/usr/bin/docker')
        Path('/usr/bin/docker').chmod(0o755)
        cls.fake=cls.tmp/'docker.json'
        with socket.socket() as s:
            s.bind(('127.0.0.1',0));cls.port=s.getsockname()[1]
        config=cls.tmp/'fpm.conf'
        config.write_text(f'''[global]
daemonize = no
error_log = {cls.tmp}/fpm.log
[www]
user = root
group = root
listen = 127.0.0.1:{cls.port}
pm = static
pm.max_children = 1
clear_env = no
catch_workers_output = yes
php_admin_flag[display_errors] = off
php_admin_flag[log_errors] = on
php_admin_flag[short_open_tag] = on
php_admin_flag[opcache.enable] = off
php_admin_value[error_log] = {cls.tmp}/php.log
php_admin_value[auto_prepend_file] = /work/tests/fixtures/unraid-local-prepend.php
''')
        cls.process=subprocess.Popen(['php-fpm','-R','-F','-y',str(config)],
                                     env=dict(os.environ,TD_FAKE_STATE=str(cls.fake)),
                                     stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        for _ in range(50):
            try:
                with socket.create_connection(('127.0.0.1',cls.port),timeout=.2):break
            except OSError:time.sleep(.1)
        else:raise RuntimeError((cls.tmp/'fpm.log').read_text())

    @classmethod
    def tearDownClass(cls):
        cls.process.terminate();cls.process.wait(timeout=10)
        cls.temp.cleanup()

    def setUp(self):
        shutil.copytree(BASE/'plugin/lang',UI/'lang',dirs_exist_ok=True)
        for p in (BASE/'plugin').iterdir():
            if p.is_file():shutil.copyfile(p,UI/p.name)
        STATE.mkdir(exist_ok=True);RUN.mkdir(exist_ok=True)
        (RUN/'.owner').write_text('thermalright-digital')
        for folder in ('feed','driver'):(RUN/folder).mkdir(exist_ok=True)
        (UI/'release.json').write_text(json.dumps(dict(owner='thermalright-digital',version='2026.10.31.5')))
        (STATE/'inventory.json').write_text(json.dumps(dict(owner='thermalright-digital',instance='b'*32,images=[],docker_touched=False)))
        self.settings=dict(autostart=False,interval=1,sensor='auto')
        (STATE/'settings.json').write_text(json.dumps(self.settings))
        (RUN/'feed/metrics.json').write_text(json.dumps(dict(timestamp=time.time(),cpu_temp=38.75,cpu_load=2,sensor='k10temp — Tctl')))
        for name in ('service.log','service.log.1','driver/driver.log','driver/driver.log.1'):
            (RUN/name).unlink(missing_ok=True)
        self.fake.write_text(json.dumps(dict(online=True,containers={},images={},volumes={},networks={})))

    def tearDown(self):
        shutil.copyfile(BASE/'plugin/control.php',UI/'control.php')
        subprocess.run(['php',str(UI/'control.php'),'stop'],env=dict(os.environ,TD_FAKE_STATE=str(self.fake)),
                       stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL,timeout=20)

    def request(self,action='status',*,token='native-token',header='native-token',method='POST',script='api.php',expect_json=True,**fields):
        data=dict(action=action,**fields)
        if token is not None:data['csrf_token']=token
        body=urlencode(data).encode() if method=='POST' else b''
        params=dict(SCRIPT_FILENAME=str(UI/script),SCRIPT_NAME='/plugins/thermalright-digital/'+script,
                    REQUEST_URI='/plugins/thermalright-digital/'+script,REQUEST_METHOD=method,
                    CONTENT_TYPE='application/x-www-form-urlencoded',CONTENT_LENGTH=str(len(body)),
                    SERVER_PROTOCOL='HTTP/1.1',SERVER_NAME='localhost',HTTP_HOST='localhost',
                    SERVER_PORT='80',REMOTE_ADDR='127.0.0.1',GATEWAY_INTERFACE='CGI/1.1',DOCUMENT_ROOT='/usr/local/emhttp')
        if header is not None:params['HTTP_X_THERMALRIGHT_CSRF_TOKEN']=header
        encoded=b''
        for key,value in params.items():
            k=key.encode();v=value.encode();encoded+=length(len(k))+length(len(v))+k+v
        stdout=b'';stderr=b''
        with socket.create_connection(('127.0.0.1',self.port),timeout=20) as s:
            s.sendall(record(1,struct.pack('!HB5x',1,0))+record(4,encoded)+record(4)+record(5,body)+record(5))
            while True:
                _,kind,_,size,padding,_=struct.unpack('!BBHHBB',exact(s,8))
                chunk=exact(s,size)
                if padding:exact(s,padding)
                if kind==6:stdout+=chunk
                if kind==7:stderr+=chunk
                if kind==3:break
        head,separator,payload=stdout.partition(b'\r\n\r\n')
        self.assertTrue(separator,stdout+stderr)
        headers=dict(line.decode().split(': ',1) for line in head.split(b'\r\n') if b': ' in line)
        code=int(headers.get('Status','200').split()[0])
        self.assertTrue(payload.strip(),f'Empty body, HTTP {code}, stderr={stderr!r}')
        if not expect_json:
            self.assertEqual(stderr,b'')
            return code,payload.decode()
        self.assertIn('application/json',headers.get('Content-Type',''))
        return code,json.loads(payload)

    def page(self,setup):
        # The native router supplies locale in this include scope; no public locale parameter.
        # Read/evaluate the body like Unraid, so __DIR__ is the router, not the plugin.
        router=Path('/usr/local/emhttp/test-page.php')
        router.write_text('<?php $docroot="/usr/local/emhttp"; '+setup+' eval("?>".explode("\\n---\\n",file_get_contents("'+str(UI/'ThermalrightDigital.page')+'"),2)[1]);')
        (UI/'test-page.php').write_text('<?php include "/usr/local/emhttp/test-page.php";')
        return self.request(method='GET',script='test-page.php',expect_json=False)

    def test_all_localized_pages_render_under_real_fpm(self):
        languages=json.loads((UI/'lang/languages.json').read_text())
        for locale,metadata in languages.items():
            with self.subTest(locale=locale):
                code,html=self.page('$locale='+json.dumps(locale)+';')
                self.assertEqual(code,200)
                self.assertIn('lang="'+locale.replace('_','-')+'"',html)
                self.assertIn('dir="'+metadata['direction']+'"',html)
                self.assertIn('value="native-token"',html)
                catalog=json.loads((UI/f'lang/{locale}.json').read_text())
                self.assertIn(catalog['Scheduled display off'],html)
                embedded=html.split('<script type="application/json" id="td-i18n">',1)[1].split('</script>',1)[0]
                self.assertEqual(json.loads(embedded)['messages'],catalog)

    def test_native_empty_locale_restores_english_after_french(self):
        native=Path('/boot/config/plugins/dynamix');native.mkdir(parents=True,exist_ok=True)
        (native/'dynamix.cfg').write_text('[display]\nlocale="fr_FR"\n')
        code,french=self.page('')
        self.assertEqual(code,200);self.assertIn('Enregistrer',french)
        code,english=self.page('$locale="";')
        self.assertEqual(code,200);self.assertIn('lang="en-US"',english);self.assertIn('>Save</button>',english)

    def test_catalog_html_is_escaped_in_page_and_inline_json(self):
        path=UI/'lang/fr_FR.json';catalog=json.loads(path.read_text())
        catalog['Save']='</script><script>alert("bad")</script>&'
        path.write_text(json.dumps(catalog))
        code,html=self.page('$locale="fr_FR";')
        self.assertEqual(code,200)
        self.assertNotIn(catalog['Save'],html)
        self.assertIn('&lt;/script&gt;',html)
        embedded=html.split('<script type="application/json" id="td-i18n">',1)[1].split('</script>',1)[0]
        self.assertEqual(json.loads(embedded)['messages']['Save'],catalog['Save'])

    def test_status_under_real_fpm(self):
        code,data=self.request()
        self.assertEqual(code,200);self.assertEqual(data['unraid'],'7.3.4')
        self.assertEqual(data['metrics']['cpu_temp'],38.75)

    def test_logs_under_real_fpm(self):
        code,data=self.request('logs');self.assertEqual((code,data),(200,{'logs':''}))

    def test_diagnostic_under_real_fpm(self):
        code,data=self.request('diagnostic');self.assertEqual(code,200)
        self.assertIn('status',data);self.assertIn('inventory',data)

    def test_settings_save_and_reject_invalid_interval(self):
        code,_=self.request('settings',settings=json.dumps(dict(self.settings,interval=2)))
        self.assertEqual(code,200);self.assertEqual(json.loads((STATE/'settings.json').read_text())['interval'],2)
        code,data=self.request('settings',settings=json.dumps(dict(self.settings,interval=0)))
        self.assertEqual(code,409);self.assertIn('error',data)

    def test_rgb_settings_persist_and_metrics_sources_are_json(self):
        code,data=self.request();self.assertEqual(code,200)
        self.assertIn('metric_sources',data)
        settings=dict(data['settings']);settings['rgb']['mode']='temperature'
        settings['rgb']['brightness']=45
        settings['rgb']['temperature_zones']=['temperature']
        settings['frequency_mode']='average';settings['power_sensor']='off'
        code,data=self.request('settings',settings=json.dumps(settings));self.assertEqual(code,200,data)
        saved=json.loads((STATE/'settings.json').read_text());self.assertEqual(saved['rgb']['brightness'],45)
        self.assertEqual(saved['rgb']['mode'],'temperature')
        self.assertEqual(saved['rgb']['temperature_zones'],['temperature'])
        legacy=dict(settings,rgb=dict(settings['rgb']));legacy['rgb'].pop('temperature_zones')
        code,data=self.request('settings',settings=json.dumps(legacy));self.assertEqual(code,200,data)
        self.assertEqual(json.loads((STATE/'settings.json').read_text())['rgb']['temperature_zones'],['temperature'])
        code,data=self.request('settings',settings=json.dumps(dict(settings,rgb=dict(settings['rgb'],thresholds=[80,60,40]))))
        self.assertEqual(code,409);self.assertIn('error',data)

    def test_visual_test_is_explicit_transient_and_requires_ready_backend(self):
        code,data=self.request('display-test');self.assertEqual(code,409)
        self.assertFalse((RUN/'display-test.json').exists())
        code,data=self.request('start');self.assertEqual(code,200,data)
        fake=json.loads(self.fake.read_text())
        fake['containers']['test-current']={'Id':'test-current','Name':'TR-DIGITAL','Image':'fake',
          'Config':{'Labels':{'io.local.thermalright-digital.owner':'thermalright-digital','io.local.thermalright-digital.instance':'b'*32}},'State':{'Running':True}}
        self.fake.write_text(json.dumps(fake))
        (RUN/'driver/status.json').write_text(json.dumps(dict(ready=True,timestamp=time.time(),model='TEST ONLY')))
        before=(STATE/'settings.json').read_bytes()
        code,data=self.request('display-test');self.assertEqual(code,200,data)
        marker=json.loads((RUN/'display-test.json').read_text());self.assertEqual(marker['duration'],8)
        self.assertEqual((STATE/'settings.json').read_bytes(),before)
        self.request('stop');self.assertFalse((RUN/'display-test.json').exists())

    def test_start_stop_under_real_fpm_without_array(self):
        code,data=self.request('start');self.assertEqual(code,200,data)
        self.assertTrue((RUN/'supervisor.json').exists())
        code,data=self.request('stop');self.assertEqual(code,200,data)
        self.assertFalse((RUN/'supervisor.json').exists())

    def test_schedule_persists_and_old_client_preserves_it(self):
        code,data=self.request();self.assertEqual(code,200)
        settings=dict(data['settings'])
        settings['quiet_hours']=dict(enabled=True,days=[1,2,3,4,5,6,7],all_day=True,start='23:00',end='07:00')
        code,data=self.request('settings',settings=json.dumps(settings));self.assertEqual(code,200,data)
        code,data=self.request();self.assertEqual(code,200)
        self.assertTrue(data['display']['off']);self.assertEqual(data['display']['reason'],'schedule')
        self.assertEqual(data['display']['timezone'],'UTC')
        self.assertTrue(data['settings']['quiet_hours']['enabled'])
        code,data=self.request('settings',settings=json.dumps(self.settings));self.assertEqual(code,200,data)
        self.assertEqual(json.loads((STATE/'settings.json').read_text())['quiet_hours'],settings['quiet_hours'])
        before=(STATE/'settings.json').read_bytes()
        settings['quiet_hours']['days']=[]
        code,data=self.request('settings',settings=json.dumps(settings));self.assertEqual(code,409)
        self.assertEqual((STATE/'settings.json').read_bytes(),before)

    def test_visual_test_refuses_scheduled_off_without_creating_marker(self):
        code,data=self.request();settings=data['settings']
        settings['quiet_hours']['enabled']=True;settings['quiet_hours']['all_day']=True
        code,data=self.request('settings',settings=json.dumps(settings));self.assertEqual(code,200,data)
        (RUN/'display-test.json').unlink(missing_ok=True)
        code,data=self.request('display-test');self.assertEqual(code,409)
        self.assertIn('off by schedule',data['error'])
        self.assertFalse((RUN/'display-test.json').exists())

    def test_csrf_and_method_checks_survive_fpm(self):
        for options,expected in [({'token':'wrong'},403),({'token':None},403),({'header':'wrong'},403),({'header':None},403),({'method':'GET'},405)]:
            with self.subTest(options=options):
                code,data=self.request(**options);self.assertEqual(code,expected);self.assertIn('error',data)

    def test_invalid_config_returns_json_error(self):
        (STATE/'settings.json').write_text('{broken')
        code,data=self.request();self.assertEqual(code,500);self.assertIn('error',data)

    def test_compile_fatal_returns_json_instead_of_empty_body(self):
        source=(BASE/'plugin/control.php').read_bytes()
        (UI/'control.php').write_bytes(b'#!/usr/bin/php\n'+source)
        code,data=self.request('logs');self.assertEqual(code,500)
        self.assertIn('control.php',data['error'])

    def test_non_utf8_log_is_still_valid_json(self):
        (RUN/'service.log').write_bytes(b'USB log with invalid byte \xff\n')
        code,data=self.request('logs');self.assertEqual(code,200);self.assertIn('\ufffd',data['logs'])


if __name__=='__main__':unittest.main(verbosity=2)
