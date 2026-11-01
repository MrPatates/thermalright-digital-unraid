<?php
declare(strict_types=1);
require '/work/plugin/control.php';
$checks=0;
function check(bool $ok,string $message): void {global $checks; if(!$ok)throw new RuntimeException('FAIL: '.$message);$checks++;echo 'PASS '.$message."\n";}
function put(string $path,string $text): void {if(!is_dir(dirname($path)))mkdir(dirname($path),0700,true);file_put_contents($path,$text);}
function fs_state(): array {return json_read(getenv('TD_FAKE_STATE'));}
function fs_save(array $s): void {json_write(getenv('TD_FAKE_STATE'),$s);}
function fixture(): void {
 $root='/tmp/td-test-'.bin2hex(random_bytes(4));mkdir($root);putenv('TD_TEST_ROOT='.$root);
 putenv('TD_TEST_DOCKER=/work/tests/fake-docker.php');putenv('TD_FAKE_STATE='.$root.'/docker.json');
 put($root.'/etc/unraid-version',"version=\"7.2.0\"\n");put($root.'/var/local/emhttp/var.ini',"mdState=\"STARTED\"\ncsrf_token=\"test\"\n");
 put($root.'/proc/stat',"cpu  100 0 100 800 0 0 0 0 0 0\n");
 foreach(['idVendor'=>'0416','idProduct'=>'8001','busnum'=>'1','devnum'=>'5','product'=>'Thermalright'] as $f=>$v)put($root.'/sys/bus/usb/devices/1-2/'.$f,$v);
 put($root.'/dev/bus/usb/001/005','');
 foreach(['name'=>'k10temp','temp1_label'=>'Tctl','temp1_input'=>'55000'] as $f=>$v)put($root.'/sys/class/hwmon/hwmon0/'.$f,$v);
 fs_save(['online'=>true,'containers'=>[],'images'=>[],'volumes'=>[],'networks'=>[]]);
 mkdir(ui_dir(),0700,true);
 foreach(glob('/work/plugin/*') as $p)copy($p,ui_dir().'/'.basename($p));
 put(ui_dir().'/.td-owner',TD_NAME);
 $r=['owner'=>TD_NAME,'version'=>'test.1','image_id'=>'sha256:'.str_repeat('a',64),'image_tag'=>'localhost/thermalright-digital:test.1','image_sha256'=>hash('sha256','fake image'),'payload_sha256'=>hash('sha256','fake payload')];
 put(ui_dir().'/release.json',json_encode($r));
 $pkg=state_dir().'/packages/test.1';
 put($pkg.'/backend-image.tar.xz','fake image');put($pkg.'/payload.txz','fake payload');put($pkg.'/release.json',json_encode($r));
 check(cli(['ctl','initialize'])===0,'native initialization');
}
function drop_fixture(): void { $root=getenv('TD_TEST_ROOT'); if(is_file(run_dir().'/desired')) stop_service();erase_tree($root); }

try {
 fixture();$i=inventory();$id=$i['instance'];
 check(cli(['ctl','initialize'])===0 && inventory()['instance']===$id,'reinstall preserves instance and settings');
 check(version_check()==='7.2.0','Unraid version detection');
 put(root_path('/etc/unraid-version'),'version="8.0.0"');
 try{version_check();check(false,'version rejection');}catch(RuntimeException $e){check(true,'unsupported version rejected');}
 put(root_path('/etc/unraid-version'),'version="7.2.0"');
 init_run();$s=sample(config());check($s['cpu_temp']===55.0 && $s['cpu_load']===null,'real temperature, first load unavailable');
 put(root_path('/proc/stat'),"cpu  120 0 120 860 0 0 0 0 0 0\n");$s=sample(config());check(abs($s['cpu_load']-40)<0.01,'load calculated from host /proc/stat');
 unlink(root_path('/sys/class/hwmon/hwmon0/temp1_input'));check(sample(config())['cpu_temp']===null,'missing temperature stays null');
 try{save_settings(['autostart'=>true,'interval'=>0,'sensor'=>'auto']);check(false,'validation');}catch(RuntimeException $e){check(true,'invalid interval rejected');}
 try{save_settings(['autostart'=>true,'interval'=>1,'sensor'=>'../../etc/shadow']);check(false,'validation');}catch(RuntimeException $e){check(true,'injected sensor path rejected');}
 save_settings(['autostart'=>true,'interval'=>1,'sensor'=>'auto']);
 check(cli(['ctl','event-start'])===0,'autostart launches supervisor');
 for($n=0;$n<60 && !inspect('container',container_name());$n++)usleep(100000);
 $c=inspect('container',container_name());check($c!==null && own_container($c,inventory()),'container has instance and owner labels');
 $args=$c['args'];check(in_array('/dev/bus/usb/001/005:/dev/bus/usb/001/005:rw',$args,true),'exactly one USB node delegated');
 check(!str_contains(implode(' ',$args),'docker.sock') && !in_array('--privileged',$args,true) && in_array('--network=none',$args,true),'isolation without socket, privileged mode or network');
 $pid=json_read(run_dir().'/supervisor.json')['pid'];start_service();check(json_read(run_dir().'/supervisor.json')['pid']===$pid,'repeated start does not duplicate instance');
 put(root_path('/sys/bus/usb/devices/1-2/devnum'),'9');put(root_path('/dev/bus/usb/001/009'),'');
 // Address changes are rebuilt promptly when retry window expires.
 stop_service();start_service();
 for($n=0;$n<60;$n++){usleep(100000);$c=inspect('container',container_name());if($c && in_array('/dev/bus/usb/001/009:/dev/bus/usb/001/009:rw',$c['args'],true))break;}
 check($c!==null && in_array('/dev/bus/usb/001/009:/dev/bus/usb/001/009:rw',$c['args'],true),'new USB address resolved after restart');
 stop_service();check(!supervisor_alive() && inspect('container',container_name())===null,'stop releases process and container');
 unlink(root_path('/sys/bus/usb/devices/1-2/idVendor'));start_service();usleep(1500000);check(inspect('container',container_name())===null,'no backend without USB');stop_service();
 $s=fs_state();$s['online']=false;fs_save($s);start_service();usleep(1500000);check(supervisor_alive(),'Docker absent: supervised waiting');
 @unlink(run_dir().'/desired');for($n=0;$n<30 && supervisor_alive();$n++)usleep(100000);
 check(cli(['ctl','uninstall'])===1 && is_file(state_dir().'/inventory.json') && is_file(state_dir().'/uninstall-pending.json'),'unavailable Docker blocks uninstall and preserves inventory');
 $s=fs_state();$s['online']=true;fs_save($s);
 // Create an unrelated container/image/path; removal must preserve all three.
 $s=fs_state();$s['containers']['foreign']=['Id'=>'foreign','Name'=>'thermalright-screen','Image'=>'foreign-image','Config'=>['Labels'=>['trcc.setup'=>'unraid-thermalright-0416-8001']],'State'=>['Running'=>true]];
 $s['images']['foreign-image']=['Id'=>'foreign-image','RepoTags'=>['thermalright-screen'],'Config'=>['Labels'=>[]]];fs_save($s);
 put(root_path('/mnt/user/appdata/thermalright-screen/keep.txt'),'existing');
 check(legacy()['container'] && legacy()['appdata'],'legacy installation detected without taking ownership');
 check(cli(['ctl','uninstall'])===0,'uninstall succeeds after Docker returns');
 check(!is_dir(state_dir()) && !is_dir(run_dir()) && !is_dir(ui_dir()),'no private residual files');
 $s=fs_state();check(count($s['containers'])===1 && isset($s['containers']['foreign']) && count($s['images'])===1 && is_file(root_path('/mnt/user/appdata/thermalright-screen/keep.txt')),'pre-existing resources preserved');
 check(cli(['ctl','uninstall'])===0 && !is_dir(run_dir()),'repeated uninstall is idempotent');
 drop_fixture();

 fixture();$i=inventory();$rel=release();$s=fs_state();$s['images'][$rel['image_id']]=['Id'=>$rel['image_id'],'RepoTags'=>[$rel['image_tag']],'Config'=>['Labels'=>[TD_LABEL.'.owner'=>TD_NAME]]];fs_save($s);
 ensure_image($i);check($i['images'][$rel['image_id']]['created']===false,'identical pre-existing image treated as borrowed');
 check(cli(['ctl','uninstall'])===0 && isset(fs_state()['images'][$rel['image_id']]),'pre-existing image preserved');drop_fixture();

 fixture();$s=fs_state();$s['containers']['conflict']=['Id'=>'conflict','Name'=>container_name(),'Config'=>['Labels'=>[]],'State'=>['Running'=>false]];fs_save($s);
 try{$i=inventory();create_backend($i,usb_devices()[0],config());check(false,'conflict');}catch(RuntimeException $e){check(isset(fs_state()['containers']['conflict']),'pre-existing container name refused and preserved');}
 check(cli(['ctl','uninstall'])===0 && isset(fs_state()['containers']['conflict']),'uninstall preserves unrelated same-name container');drop_fixture();

 foreach (['thermalright-digital-backend','THERMALRIGHT-DIGITAL-BACKEND'] as $oldName) {
 fixture();$i=inventory();$i['resources']['container']=$oldName;inv_save($i);
 check(cli(['ctl','initialize'])===0 && inventory()['resources']['container']==='TR-DIGITAL' && inventory()['instance']===$i['instance'],'upgrade refreshes container inventory without changing ownership');
 $s=fs_state();$s['containers']['owned-old']=['Id'=>'owned-old','Name'=>$oldName,'Config'=>['Labels'=>[TD_LABEL.'.owner'=>TD_NAME,TD_LABEL.'.instance'=>$i['instance']]],'State'=>['Running'=>true]];fs_save($s);
 create_backend($i,usb_devices()[0],config());$s=fs_state();$c=inspect('container',container_name());
 check(!isset($s['containers']['owned-old']) && count($s['containers'])===1 && $c['Name']==='TR-DIGITAL','upgrade replaces an owned old backend with one compact-name instance');
 check(labels($c)['net.unraid.docker.icon']==='file:///usr/local/emhttp/plugins/thermalright-digital/logo.png' && labels($c)['net.unraid.docker.managed']===TD_NAME,'native Docker branding uses an offline local icon and plugin manager label');
 check(labels($c)['net.unraid.docker.webui']==='/Settings/ThermalrightDigital','Docker WebUI links to native plugin settings');
 check(cli(['ctl','uninstall'])===0 && !fs_state()['containers'],'compact-name instance is removed through the ownership inventory');drop_fixture();
 }

 fixture();put(state_dir().'/unknown.txt','existing');check(cli(['ctl','uninstall'])===1 && is_file(state_dir().'/unknown.txt'),'unknown file preserved and incomplete cleanup reported');unlink(state_dir().'/unknown.txt');check(cli(['ctl','uninstall'])===0,'cleanup can be retried');drop_fixture();
 fixture();
 $manifest='<PLUGIN name="thermalright-digital" version="test.1"/>';
 $native=root_path('/boot/config/plugins/'.TD_NAME.'.plg');put($native,$manifest);
 $i=inventory();$i['bundles']['test.1']=['plg_sha256'=>hash('sha256',$manifest)];inv_save($i);
 check(cli(['ctl','uninstall'])===0,'uninstall finalizes native manifest');
 $archived=root_path('/boot/config/plugins-removed/'.TD_NAME.'.plg');mkdir(dirname($archived),0700,true);rename($native,$archived);
 for($n=0;$n<50;$n++){clearstatcache(true,$archived);if(!is_file($archived))break;usleep(100000);}
 clearstatcache(true,$archived);
 check(!is_file($archived),'exact native archive removed after Unraid moves it');drop_fixture();
 echo "TOTAL $checks checks passed\n";
} catch(Throwable $e) {
 fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");
 try{if(is_file(run_dir().'/desired'))stop_service();}catch(Throwable $ignored){}
 exit(1);
}
