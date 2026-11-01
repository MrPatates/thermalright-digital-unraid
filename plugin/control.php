<?php
/* Thermalright Digital for Unraid. GPL-3.0-or-later. No host Python required. */
declare(strict_types=1);
const TD_NAME = 'thermalright-digital';
const TD_LABEL = 'io.local.thermalright-digital';

function root_path(string $p): string {
    $test = PHP_SAPI === 'cli' ? getenv('TD_TEST_ROOT') : false;
    return ($test ?: '').$p;
}
function state_dir(): string { return root_path('/boot/config/plugins/'.TD_NAME); }
function run_dir(): string { return root_path('/var/run/'.TD_NAME); }
function ui_dir(): string { return root_path('/usr/local/emhttp/plugins/'.TD_NAME); }
require_once __DIR__.'/settings.php';
require_once __DIR__.'/schedule.php';
require_once __DIR__.'/metrics.php';
function fail(string $s): never { throw new RuntimeException($s); }
function json_read(string $p, array $default=[]): array {
    clearstatcache(true,$p);
    if (!is_file($p)) return $default;
    $d = json_decode((string)file_get_contents($p), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($d)) fail('Invalid JSON : '.$p);
    return $d;
}
function atomic(string $p, string $data): void {
    if (is_link($p) || is_link(dirname($p))) fail('Symbolic link refused : '.$p);
    $t = $p.'.tmp.'.getmypid();
    if (file_put_contents($t, $data, LOCK_EX) === false) fail('Cannot write : '.$t);
    chmod($t, 0600);
    if (!rename($t, $p)) { unlink($t); fail('Cannot rename : '.$p); }
}
function json_write(string $p, array $d): void {
    atomic($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
}
function config(): array {
    return td_settings_normalize(json_read(state_dir().'/settings.json',td_settings_defaults()));
}
function inventory(): array { return json_read(state_dir().'/inventory.json'); }
function inv_save(array $i): void { json_write(state_dir().'/inventory.json', $i); }
function owned_state(): array {
    $i = inventory();
    if (($i['owner'] ?? '') !== TD_NAME || !preg_match('/^[a-f0-9]{32}$/D', $i['instance'] ?? '')) fail('Ownership inventory missing or invalid');
    return $i;
}
function init_run(): void {
    $r = run_dir();
    if (is_link($r)) fail('RAM directory replaced by a symbolic link');
    if (is_dir($r) && !is_file($r.'/.owner')) fail('Pre-existing RAM directory has no ownership marker');
    if (!is_dir($r)) { mkdir($r,0700,true); file_put_contents($r.'/.owner', TD_NAME); }
    if (trim((string)file_get_contents($r.'/.owner')) !== TD_NAME) fail('Invalid RAM ownership marker');
    foreach (['feed','driver'] as $sub) if (!is_dir($r.'/'.$sub)) mkdir($r.'/'.$sub,0700);
}
function version_check(): string {
    $text = (string)@file_get_contents(root_path('/etc/unraid-version'));
    if (!preg_match('/version="?([0-9]+\.[0-9]+\.[0-9]+)"?\s*$/m', trim($text), $m)) fail('Cannot determine stable Unraid version');
    if (!preg_match('/^(6\.12\.[0-9]+|7\.[0-3]\.[0-9]+)$/D', $m[1])) fail('Unsupported Unraid version : '.$m[1]);
    if (php_uname('m') !== 'x86_64') fail('x86_64 architecture required');
    return $m[1];
}
function command(array $args, int $seconds=10): array {
    $pipes=[];
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
    if (!is_resource($p)) fail('Cannot start command');
    fclose($pipes[0]);
    stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
    $out=''; $err=''; $until=microtime(true)+$seconds; $code=-1;
    do {
        $out.=stream_get_contents($pipes[1]); $err.=stream_get_contents($pipes[2]);
        if (strlen($out)+strlen($err)>2097152) { proc_terminate($p,9); fail('Command output too large'); }
        $s=proc_get_status($p);
        if (!$s['running']) { $code=$s['exitcode']; break; }
        if (microtime(true)>$until) { proc_terminate($p,15); usleep(100000); proc_terminate($p,9); $code=124; break; }
        usleep(20000);
    } while (true);
    $out.=stream_get_contents($pipes[1]); $err.=stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
    return [$code,trim($out),trim($err)];
}
function docker(array $args,int $timeout=10): array {
    $bin=(PHP_SAPI==='cli' && getenv('TD_TEST_ROOT')) ? (getenv('TD_TEST_DOCKER') ?: '/usr/bin/docker') : '/usr/bin/docker';
    return command(array_merge([$bin],$args),$timeout);
}
function docker_ok(): bool {
    try { return docker(['info','--format','{{.ServerVersion}}'],3)[0]===0; } catch (Throwable $e) { return false; }
}
function inspect(string $kind,string $id): ?array {
    [$code,$out,$err]=docker([$kind,'inspect',$id]);
    if ($code!==0) {
        if ($code===1 && preg_match('/No such (object|image|container)/i',$err)) return null;
        fail('Cannot inspect Docker resource : '.$err);
    }
    return json_decode($out,true,64,JSON_THROW_ON_ERROR)[0];
}
function labels(array $object): array { return $object['Config']['Labels'] ?? []; }
function container_name(): string { return 'TR-DIGITAL'; }
function own_container(array $c,array $i): bool {
    $l=labels($c);
    return ($l[TD_LABEL.'.owner']??'')===TD_NAME && ($l[TD_LABEL.'.instance']??'')===$i['instance'];
}
function event_log(string $s): void {
    $p=run_dir().'/service.log';
    if (is_file($p) && filesize($p)>262144) { @unlink($p.'.1'); rename($p,$p.'.1'); }
    file_put_contents($p,date('c').' '.$s."\n",FILE_APPEND|LOCK_EX);
}
function array_ready(): bool {
    $v=@parse_ini_file(root_path('/var/local/emhttp/var.ini')) ?: [];
    return ($v['mdState']??'')==='STARTED';
}
function usb_devices(): array {
    $found=[];
    foreach (glob(root_path('/sys/bus/usb/devices/*/idVendor')) ?: [] as $v) {
        $p=dirname($v);
        if (trim((string)@file_get_contents($v))!=='0416' || trim((string)@file_get_contents($p.'/idProduct'))!=='8001') continue;
        $bus=trim((string)@file_get_contents($p.'/busnum')); $dev=trim((string)@file_get_contents($p.'/devnum'));
        if (!ctype_digit($bus) || !ctype_digit($dev)) continue;
        $node=sprintf('/dev/bus/usb/%03d/%03d',(int)$bus,(int)$dev);
        $found[]=['id'=>'0416:8001','port'=>basename($p),'node'=>$node,'product'=>trim((string)@file_get_contents($p.'/product'))];
    }
    return $found;
}
function sensors(): array {
    $out=[];
    foreach (glob(root_path('/sys/class/hwmon/hwmon*/temp*_input')) ?: [] as $p) {
        $name=trim((string)@file_get_contents(dirname($p).'/name'));
        $label=trim((string)@file_get_contents(str_replace('_input','_label',$p))) ?: basename($p,'_input');
        $real=realpath(dirname($p));
        if ($real===false) continue;
        $stable=preg_replace('~/hwmon/hwmon[0-9]+$~','',$real);
        $id=substr(hash('sha256',$stable.'|'.$name.'|'.basename($p)),0,24);
        $raw=trim((string)@file_get_contents($p));
        $value=is_numeric($raw)?(float)$raw/1000:null;
        if ($value!==null && ($value< -20 || $value>150)) $value=null;
        $out[]=['id'=>$id,'label'=>$name.' — '.$label,'value'=>$value,'path'=>$p,
                'cpu'=>(bool)preg_match('/^(coretemp|k10temp|zenpower|cpu_thermal)$/',$name),
                'preferred'=>(bool)preg_match('/^(Package id [0-9]+|Tctl|Tdie|temp1)$/',$label)];
    }
    return $out;
}
function sample(array $cfg): array {
    $ss=sensors(); $chosen=null;
    if ($cfg['sensor']==='auto') {
        foreach ($ss as $s) if ($s['cpu'] && $s['value']!==null && $s['preferred'] && ($chosen===null || $s['value']>$chosen['value'])) $chosen=$s;
        if ($chosen===null) foreach ($ss as $s) if ($s['cpu'] && $s['value']!==null && ($chosen===null || $s['value']>$chosen['value'])) $chosen=$s;
    } else foreach ($ss as $s) if ($s['id']===$cfg['sensor']) $chosen=$s;
    $line=strtok((string)@file_get_contents(root_path('/proc/stat')),"\n");
    $load=null;
    $prev=json_read(run_dir().'/cpu.json');
    if (preg_match('/^cpu\s+(.+)$/',(string)$line,$m)) {
        $a=array_map('intval',preg_split('/\s+/',trim($m[1])));
        $total=array_sum(array_slice($a,0,8)); $idle=($a[3]??0)+($a[4]??0);
        if (isset($prev['total']) && $total>$prev['total']) $load=max(0,min(100,100*(1-($idle-$prev['idle'])/($total-$prev['total']))));
        json_write(run_dir().'/cpu.json',['total'=>$total,'idle'=>$idle]);
    }
    $display=td_display_state($cfg['quiet_hours']);
    $test=json_read(run_dir().'/display-test.json');
    if ($test && ($display['off'] || microtime(true)-($test['started_at']??0)>8 || microtime(true)<($test['started_at']??0))) {
        @unlink(run_dir().'/display-test.json'); $test=[];
    }
    $data=array_merge(['timestamp'=>microtime(true),'cpu_temp'=>$chosen['value']??null,'cpu_load'=>$load,'sensor'=>$chosen['label']??null],td_extra_metrics($cfg));
    $data['rgb']=$cfg['rgb'];
    $data['display_test']=$test?:null;
    $data['display']=$display;
    json_write(run_dir().'/feed/metrics.json',$data);
    return $data;
}
function save_settings(array $d): void {
    $existing=config();
    $clean=td_settings_normalize($d,true);
    foreach (['frequency_mode','power_sensor','rgb','quiet_hours'] as $key) if (!array_key_exists($key,$d)) $clean[$key]=$existing[$key];
    if (isset($d['rgb']) && !array_key_exists('temperature_zones',$d['rgb'])) $clean['rgb']['temperature_zones']=$existing['rgb']['temperature_zones'];
    $ids=array_column(sensors(),'id');
    if ($clean['sensor']!=='auto' && !in_array($clean['sensor'],$ids,true) && $clean['sensor']!==$existing['sensor']) fail('Unknown CPU sensor');
    $powers=array_column(td_extra_metric_sources()['power'],'id');
    if (!in_array($clean['power_sensor'],['auto','off'],true) && !in_array($clean['power_sensor'],$powers,true) && $clean['power_sensor']!==$existing['power_sensor']) fail('Unknown power sensor');
    if ($clean!==$existing) json_write(state_dir().'/settings.json',$clean);
}
function display_test(): void {
    $i=owned_state(); init_run();
    if (td_display_state(config()['quiet_hours'])['off']) fail('Display is off by schedule; disable the schedule before running a visual test');
    $backend=json_read(run_dir().'/driver/status.json');
    $maxAge=max(10,config()['interval']*2+2);
    if (!supervisor_alive() || !($backend['ready']??false) || time()-($backend['timestamp']??0)>$maxAge) fail('Start the backend and wait for it to connect before running a visual test');
    if (!docker_ok()) fail('Docker unavailable : visual test refused');
    $container=inspect('container',container_name());
    if (!$container || !own_container($container,$i) || !($container['State']['Running']??false)) fail('The plugin backend must be running before a visual test');
    json_write(run_dir().'/display-test.json',['id'=>bin2hex(random_bytes(8)),'started_at'=>microtime(true),'duration'=>8]);
    sample(config());
}
function release(): array { return json_read(ui_dir().'/release.json'); }
function ensure_image(array &$i): string {
    $rel=release(); $id=$rel['image_id']; $tag=$rel['image_tag'];
    $existing=inspect('image',$id);
    if ($existing!==null) {
        if ((labels($existing)[TD_LABEL.'.owner']??'')!==TD_NAME) fail('Existing image is missing the expected label');
        if (!isset($i['images'][$id])) { $i['images'][$id]=['tag'=>$tag,'created'=>false]; inv_save($i); }
        return $id;
    }
    $conflict=inspect('image',$tag);
    if ($conflict!==null) fail('Image tag belongs to another image; preserved');
    // Write-ahead ownership: an interrupted load can be cleaned up by exact ID.
    $before=$i;
    $i['docker_touched']=true; $i['images'][$id]=['tag'=>$tag,'created'=>true];
    if ($i!==$before) inv_save($i);
    $archive=state_dir().'/packages/'.$rel['version'].'/backend-image.tar.xz';
    if (!is_file($archive) || hash_file('sha256',$archive)!==$rel['image_sha256']) fail('Docker archive missing or SHA-256 mismatch');
    [$code,$out,$err]=docker(['image','load','--input',$archive],180);
    if ($code!==0) fail('Loading image : '.$err);
    $loaded=inspect('image',$id);
    if ($loaded===null || (labels($loaded)[TD_LABEL.'.owner']??'')!==TD_NAME) fail('Incorrect image ID or label after loading');
    return $id;
}
function remove_containers(array $i): void {
    [$code,$out,$err]=docker(['container','ls','--all','--quiet','--filter','label='.TD_LABEL.'.instance='.$i['instance']]);
    if ($code!==0) fail('Container inventory : '.$err);
    foreach (array_filter(explode("\n",$out)) as $id) {
        $c=inspect('container',$id);
        if ($c===null) continue;
        if (!own_container($c,$i)) fail('Container has the instance label but a different owner; preserved');
        [$code,,$err]=docker(['container','stop','--time','5',$id],10);
        if ($code!==0) fail('Stopping container : '.$err);
        [$code,,$err]=docker(['container','rm',$id]);
        if ($code!==0) fail('Removing container : '.$err);
    }
}
function create_backend(array &$i,array $usb,array $cfg): void {
    $c=inspect('container',container_name());
    if ($c!==null && !own_container($c,$i)) fail('Container name already in use; refusing takeover');
    remove_containers($i);
    $id=ensure_image($i);
    if (!($i['docker_touched']??false)) { $i['docker_touched']=true; inv_save($i); }
    @unlink(run_dir().'/driver/status.json');
    // Only the dynamically resolved USB node. No Docker socket, /boot, /mnt,
    // --privileged, host PID, wildcard device cgroup rule or host network.
    $args=['container','create','--name',container_name(),'--label',TD_LABEL.'.owner='.TD_NAME,
           '--label',TD_LABEL.'.instance='.$i['instance'],'--label',TD_LABEL.'.version='.release()['version'],
           '--label','net.unraid.docker.managed='.TD_NAME,
           '--label','net.unraid.docker.icon=file:///usr/local/emhttp/plugins/'.TD_NAME.'/logo.png',
           '--label','net.unraid.docker.webui=/Settings/ThermalrightDigital',
           '--restart=no','--network=none','--read-only','--cap-drop=ALL','--security-opt=no-new-privileges',
           '--pids-limit=64','--memory=192m','--cpus=0.5','--log-driver=none',
           '--tmpfs','/tmp:rw,noexec,nosuid,size=16m','--tmpfs','/run:rw,noexec,nosuid,size=1m',
           '--device',$usb['node'].':'.$usb['node'].':rw',
           '--mount','type=bind,src='.run_dir().'/feed,dst=/host,readonly',
           '--mount','type=bind,src='.run_dir().'/driver,dst=/state',
           '--env','TD_INTERVAL='.$cfg['interval'],$id];
    [$code,,$err]=docker($args);
    if ($code!==0) fail('Creating backend : '.$err);
    [$code,,$err]=docker(['container','start',container_name()]);
    if ($code!==0) fail('Starting backend : '.$err);
}
function supervisor_alive(): bool {
    $p=json_read(run_dir().'/supervisor.json');
    if (!isset($p['pid'],$p['start'])) return false;
    $cmd=(string)@file_get_contents('/proc/'.$p['pid'].'/cmdline');
    $stat=(string)@file_get_contents('/proc/'.$p['pid'].'/stat');
    $fields=preg_split('/\s+/',substr($stat,(int)strrpos($stat,')')+2));
    return in_array(__FILE__,explode("\0",$cmd),true) && in_array('supervise',explode("\0",$cmd),true) && ($fields[19]??'')===$p['start'];
}
function start_service(): void {
    owned_state(); init_run();
    if (is_file(state_dir().'/uninstall-pending.json')) fail('Cleanup pending: retry uninstall');
    atomic(run_dir().'/desired','1');
    if (supervisor_alive()) return;
    $cmd='nohup setsid '.escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' supervise </dev/null >/dev/null 2>&1 &';
    exec($cmd);
    for ($n=0;$n<30;$n++) { if (supervisor_alive()) return; usleep(100000); }
    fail('Supervisor did not start: check the logs');
}
function stop_service(): void {
    @unlink(run_dir().'/desired');
    @unlink(run_dir().'/display-test.json');
    for ($n=0;$n<30 && supervisor_alive();$n++) usleep(100000);
    if (supervisor_alive()) {
        $pid=json_read(run_dir().'/supervisor.json')['pid'];
        $stat=(string)@file_get_contents('/proc/'.$pid.'/stat');
        $f=preg_split('/\s+/',substr($stat,(int)strrpos($stat,')')+2));
        // setsid gives the supervisor its own process group; verify before
        // terminating it AND its pending Docker CLI child (e.g. image load).
        if (($f[2]??'')!==(string)$pid || ($f[3]??'')!==(string)$pid) fail('Unexpected supervisor process group');
        command(['/bin/kill','-TERM','--','-'.(string)$pid],2);
        for ($n=0;$n<20 && supervisor_alive();$n++) usleep(100000);
        if (supervisor_alive()) command(['/bin/kill','-KILL','--','-'.(string)$pid],2);
        @unlink(run_dir().'/supervisor.json');
    }
    $i=owned_state();
    if (docker_ok()) remove_containers($i);
    elseif ($i['docker_touched']??false) fail('Docker unavailable : device stop and cleanup not confirmed');
    if (is_dir(run_dir().'/driver')) json_write(run_dir().'/driver/status.json',['ready'=>false,'timestamp'=>time(),'message'=>'Stopped']);
}
function supervise(): void {
    init_run();
    $lock=fopen(run_dir().'/supervisor.lock','c');
    if (!flock($lock,LOCK_EX|LOCK_NB)) return;
    $stat=file_get_contents('/proc/'.getmypid().'/stat');
    $fields=preg_split('/\s+/',substr($stat,(int)strrpos($stat,')')+2));
    json_write(run_dir().'/supervisor.json',['pid'=>getmypid(),'start'=>$fields[19]]);
    $i=owned_state(); $last=''; $address=''; $retry=0; $until=time()+180;
    try {
        while (is_file(run_dir().'/desired') && !is_file(state_dir().'/uninstall-pending.json')) {
            $cfg=config(); sample($cfg);
            $message='';
            try {
                if (!array_ready()) fail('Waiting for the array to start');
                if (!docker_ok()) fail('Waiting for Docker');
                $usbs=usb_devices();
                if (count($usbs)!==1) { remove_containers($i); $address=''; fail(count($usbs)?'Multiple 0416:8001 displays: ambiguous selection':'Display 0416:8001 not found'); }
                $usb=$usbs[0]; $signature=$usb['node'].'|'.$usb['port'].'|'.$cfg['interval'];
                if (!file_exists(root_path($usb['node']))) fail('USB device node not yet available');
                $c=inspect('container',container_name());
                if ($c!==null && !own_container($c,$i)) fail('Backend name already used by a pre-existing container');
                if ($signature!==$address || $c===null || !($c['State']['Running']??false)) {
                    if (time()>=$retry) {
                        $retry=time()+30; create_backend($i,$usb,$cfg); $address=$signature;
                    }
                }
                $bs=json_read(run_dir().'/driver/status.json');
                if (($bs['ready']??false) && time()-($bs['timestamp']??0)<max(10,$cfg['interval']*2+2)) {
                    $until=time()+180;
                    $message=($bs['display_off']??false) && ($bs['display_reason']??'')==='schedule'?'Display off by schedule; backend running':'Backend running, USB frames sent';
                }
                else {
                    $message=$bs['error']??'Connecting to driver';
                    if (time()>$until) { event_log('Driver not ready after 180 s : '.$message); break; }
                }
            } catch (Throwable $e) {
                $message=$e->getMessage();
                if (time()>$until) { event_log('Stopped waiting after 180 s : '.$message); break; }
            }
            if ($message!==$last) { event_log($message); $last=$message; }
            json_write(run_dir().'/service.json',['message'=>$message,'timestamp'=>time()]);
            usleep(1000000);
        }
    } finally {
        try { if (docker_ok()) remove_containers($i); } catch (Throwable $e) { event_log($e->getMessage()); }
        @unlink(run_dir().'/supervisor.json'); @unlink(run_dir().'/desired');
        flock($lock,LOCK_UN); fclose($lock);
    }
}
function legacy(): array {
    $out=['appdata'=>is_dir(root_path('/mnt/user/appdata/thermalright-screen'))];
    if (docker_ok()) {
        $out['container']=inspect('container','thermalright-screen')!==null;
        $out['image']=inspect('image','thermalright-screen')!==null;
        [$code,$ids]=docker(['container','ls','-aq','--filter','label=trcc.setup=unraid-thermalright-0416-8001']);
        $out['labelled_containers']=$code===0?array_filter(explode("\n",$ids)):[];
    }
    return $out;
}
function status(): array {
    $cfg=config(); $r=run_dir();
    $metrics=json_read($r.'/feed/metrics.json',['cpu_temp'=>null,'cpu_load'=>null]);
    if (time()-($metrics['timestamp']??0)>10) $metrics=['cpu_temp'=>null,'cpu_load'=>null];
    return ['unraid'=>version_check(),'release'=>release()['version']??null,'settings'=>$cfg,
            'display'=>td_display_state($cfg['quiet_hours']),
            'supervisor'=>supervisor_alive(),'docker'=>docker_ok(),'usb'=>usb_devices(),'metrics'=>$metrics,
            'sensors'=>array_map(fn($s)=>array_diff_key($s,['path'=>true]),sensors()),
            'metric_sources'=>td_extra_metric_sources(),
            'service'=>json_read($r.'/service.json'),'backend'=>json_read($r.'/driver/status.json'),
            'pending'=>is_file(state_dir().'/uninstall-pending.json'),'legacy'=>legacy()];
}
function logs(): string {
    $out='';
    foreach (['service.log.1','service.log','driver/driver.log.1','driver/driver.log'] as $name) {
        $p=run_dir().'/'.$name;
        if (is_file($p)) $out.="\n--- $name ---\n".substr((string)file_get_contents($p),-32768);
    }
    return $out;
}
function erase_tree(string $dir): void {
    if (is_link($dir)) fail('Symbolic link preserved : '.$dir);
    if (!is_dir($dir)) return;
    foreach (new FilesystemIterator($dir) as $f) {
        if ($f->isLink()) fail('Unexpected symbolic link preserved : '.$f->getPathname());
        if ($f->isDir()) erase_tree($f->getPathname()); else if (!unlink($f->getPathname())) fail('Removal failed');
    }
    if (!rmdir($dir)) fail('Directory not removed : '.$dir);
}
function residuals(): array {
    $paths=[state_dir(),run_dir(),ui_dir(),dirname(ui_dir()).'/.'.TD_NAME.'-stage',dirname(ui_dir()).'/.'.TD_NAME.'-backup',root_path('/boot/config/plugins/'.TD_NAME.'.plg'),root_path('/var/log/plugins/'.TD_NAME.'.plg'),root_path('/boot/config/plugins-removed/'.TD_NAME.'.plg')];
    $r=['paths'=>array_values(array_filter($paths,fn($p)=>file_exists($p)||is_link($p))),'docker'=>'unavailable: incomplete check'];
    if (docker_ok()) {
        $r['docker']=[];
        foreach (['container','image','volume','network'] as $kind) {
            [$code,$out,$err]=docker([$kind,'ls','--quiet','--filter','label='.TD_LABEL.'.owner='.TD_NAME]);
            $r['docker'][$kind]=$code===0?array_values(array_filter(explode("\n",$out))):['ERROR: '.$err];
        }
    }
    return $r;
}
function schedule_manifest_cleanup(array $i): void {
    $native=root_path('/boot/config/plugins/'.TD_NAME.'.plg');
    if (!is_file($native)) return;
    $hash=hash_file('sha256',$native);
    if (!in_array($hash,array_column($i['bundles']??[],'plg_sha256'),true)) fail('Installed manifest not in inventory; preserved');
    // Unraid moves the .plg AFTER the remove hook returns. A bounded job
    // removes only that exact manifest from its native archive. No script,
    // PID file, private log or cron job is left behind.
    $script='for ((n=0;n<100;n++)); do
 if [[ ! -e "$1" && -f "$2" && ! -L "$2" ]]; then
  actual=$(sha256sum "$2"); actual=${actual%% *}
  if [[ "$actual" == "$3" ]]; then rm -- "$2"; exit $?; fi
 fi
 sleep 0.1
done
logger -t thermalright-digital "Uninstall incomplete: native manifest still present; run audit-residuals.sh."
exit 1';
    $args=['nohup','setsid','/bin/bash','-c',$script,'td-finalize-manifest',$native,root_path('/boot/config/plugins-removed/'.TD_NAME.'.plg'),$hash];
    exec(implode(' ',array_map('escapeshellarg',$args)).' </dev/null >/dev/null 2>&1 &');
}
function uninstall(): void {
    if (!is_dir(state_dir())) { echo "No persistent plugin state.\n"; return; }
    $i=owned_state();
    // Disable future events BEFORE trying Docker. On failure keep the .plg,
    // inventory, packages, UI and diagnostic so the removal is retryable.
    json_write(state_dir().'/uninstall-pending.json',['since'=>time(),'instance'=>$i['instance']]);
    $cfg=config(); $cfg['autostart']=false; json_write(state_dir().'/settings.json',$cfg);
    stop_service();
    if ($i['docker_touched']??false) {
        if (!docker_ok()) fail('Uninstall incomplete: Docker required; inventory preserved');
        remove_containers($i);
        foreach ($i['images']??[] as $id=>$record) {
            if (!($record['created']??false)) continue;
            $image=inspect('image',$id); if ($image===null) continue;
            if ((labels($image)[TD_LABEL.'.owner']??'')!==TD_NAME) fail('Image ownership unverified; preserved');
            [$code,$refs,$err]=docker(['container','ls','-aq','--filter','ancestor='.$id]);
            if ($code!==0) fail('Cannot check image references : '.$err);
            $tags=$image['RepoTags']??[];
            $tag=$record['tag'];
            if ($refs!=='' || array_diff($tags,[$tag,'<none>:<none>'])) {
                // Shared layers/image are exempt; release only our tag when safe.
                if (in_array($tag,$tags,true) && count($tags)>1) {
                    [$code,,$err]=docker(['image','rm',$tag]); if ($code!==0) fail($err);
                }
                if (in_array($tag,$tags,true) && count($tags)<=1) fail('Plugin image used by another container; remove that dependency before retrying');
                continue;
            }
            [$code,,$err]=docker(['image','rm',$id]); if ($code!==0) fail('Removing image : '.$err);
        }
    }
    // Persistent files must be known. An unexpected file blocks removal
    // instead of being silently claimed (especially a pre-existing folder).
    $allowed=['inventory.json','settings.json','uninstall-pending.json','installed.json','previous.json','previous.plg','maintenance.json','packages'];
    foreach (new FilesystemIterator(state_dir()) as $f) if (!in_array($f->getFilename(),$allowed,true) || $f->isLink()) fail('Pre-existing or unknown file preserved : '.$f->getPathname());
    foreach (glob(state_dir().'/packages/*')?:[] as $pkg) {
        $m=json_read($pkg.'/release.json');
        if (($m['owner']??'')!==TD_NAME) fail('Package has no ownership marker; preserved : '.$pkg);
        foreach (new FilesystemIterator($pkg) as $f) if (!in_array($f->getFilename(),['payload.txz','backend-image.tar.xz','release.json',TD_NAME.'.plg','.download-origin'],true) || $f->isLink()) fail('Unknown package file preserved');
        if (is_file($pkg.'/.download-origin') && trim((string)file_get_contents($pkg.'/.download-origin'))!==TD_NAME) fail('Invalid download marker');
        foreach (['payload.txz'=>'payload_sha256','backend-image.tar.xz'=>'image_sha256'] as $file=>$key)
            if (is_file($pkg.'/'.$file) && hash_file('sha256',$pkg.'/'.$file)!==($m[$key]??'')) fail('Modified package preserved : '.$file);
        $plg=$pkg.'/'.TD_NAME.'.plg';
        if (is_file($plg) && hash_file('sha256',$plg)!==($i['bundles'][$m['version']]['plg_sha256']??'')) fail('Source manifest untracked or modified : '.$plg);
    }
    foreach (['stage','backup'] as $suffix) {
        $p=dirname(ui_dir()).'/.'.TD_NAME.'-'.$suffix;
        if (is_dir($p) && trim((string)@file_get_contents($p.'/.td-owner'))!==TD_NAME) fail('Pre-existing transaction directory preserved : '.$p);
    }
    schedule_manifest_cleanup($i);
    // Keep the audit helper runnable outside the removed tree.
    foreach (['stage','backup'] as $suffix) erase_tree(dirname(ui_dir()).'/.'.TD_NAME.'-'.$suffix);
    erase_tree(ui_dir()); erase_tree(run_dir()); erase_tree(state_dir());
    echo "Private resources removed. The Unraid manager will finish removing the manifest; run the audit after 10 seconds.\n";
}
function cli(array $argv): int {
    $action=$argv[1]??'status';
    try {
        if ($action==='residuals') { echo json_encode(residuals(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n"; return 0; }
        if ($action==='version-check') { echo version_check()."\n"; return 0; }
        if ($action==='selftest') { version_check(); $rel=json_read(__DIR__.'/release.json'); if (($rel['owner']??'')!==TD_NAME) fail('Incorrect release metadata'); echo "OK\n"; return 0; }
        if ($action==='is-active') { echo supervisor_alive()?'1':'0'; return 0; }
        if ($action==='is-ready') { $s=json_read(run_dir().'/driver/status.json'); echo (supervisor_alive() && ($s['ready']??false) && time()-($s['timestamp']??0)<max(10,config()['interval']*2+2))?'1':'0'; return 0; }
        if ($action==='supervise') { supervise(); return 0; }
        if ($action==='initialize') {
            version_check();
            if (!is_file(state_dir().'/inventory.json')) inv_save(['owner'=>TD_NAME,'instance'=>bin2hex(random_bytes(16)),'images'=>[],'docker_touched'=>false,'schema'=>1]);
            owned_state(); init_run();
            if (!is_file(state_dir().'/settings.json')) json_write(state_dir().'/settings.json',config());
            $i=owned_state(); $rel=release();
            if (!isset($i['resources'])) {
                $i['resources']=[
                    'private_directories'=>['/boot/config/plugins/'.TD_NAME,'/var/run/'.TD_NAME,'/usr/local/emhttp/plugins/'.TD_NAME],
                    'transaction_directories'=>['/usr/local/emhttp/plugins/.'.TD_NAME.'-stage','/usr/local/emhttp/plugins/.'.TD_NAME.'-backup'],
                    'native_manifests'=>['/boot/config/plugins/'.TD_NAME.'.plg','/var/log/plugins/'.TD_NAME.'.plg','/boot/config/plugins-removed/'.TD_NAME.'.plg'],
                    'container'=>container_name(),'container_label_owner'=>TD_LABEL.'.owner='.TD_NAME,
                    'container_label_instance'=>TD_LABEL.'.instance='.$i['instance'],
                    'events'=>['docker_started','started','stopping','stopping_docker','stopped'],
                    'volumes'=>[],'networks'=>[],'scheduled_tasks'=>[]];
                inv_save($i);
            }
            if (($i['resources']['container']??'')!==container_name()) {
                $i['resources']['container']=container_name(); inv_save($i);
            }
            $plg=state_dir().'/packages/'.$rel['version'].'/'.TD_NAME.'.plg';
            if (is_file($plg) && !isset($i['bundles'][$rel['version']])) {
                $i['bundles'][$rel['version']]=['plg_sha256'=>hash_file('sha256',$plg)]; inv_save($i);
            }
            return 0;
        }
        if ($action==='event-start') {
            if (is_file(state_dir().'/uninstall-pending.json')) return 0;
            if (config()['autostart']) start_service(); return 0;
        }
        if ($action==='status') { echo json_encode(status(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n"; return 0; }
        if ($action==='logs') { echo logs(); return 0; }
        if ($action==='uninstall' && !is_dir(state_dir())) { echo "Already uninstalled.\n"; return 0; }
        init_run();
        $lock=fopen(run_dir().'/control.lock','c');
        if (!flock($lock,LOCK_EX|LOCK_NB)) fail('An operation is already running');
        try {
            if ($action==='start') start_service();
            elseif ($action==='stop' || $action==='event-stop') stop_service();
            elseif ($action==='restart') { stop_service(); start_service(); }
            elseif ($action==='settings') save_settings(json_decode($argv[2]??'',true,16,JSON_THROW_ON_ERROR));
            elseif ($action==='display-test') { display_test(); echo "8-second visual test started; test values are synthetic readings.\n"; }
            elseif ($action==='legacy-stop') {
                $c=inspect('container','thermalright-screen');
                if ($c===null || (labels($c)['trcc.setup']??'')!=='unraid-thermalright-0416-8001') fail('Legacy container absent or label mismatch; stop it manually');
                [$code,,$err]=docker(['container','stop','--time','5','thermalright-screen']);
                if ($code!==0) fail($err);
                echo "Legacy container stopped at your request; container, image and appdata preserved.\n";
            }
            elseif ($action==='uninstall') uninstall();
            else fail('Unknown action');
        } finally { flock($lock,LOCK_UN); fclose($lock); }
        return 0;
    } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); return 1; }
}
if (PHP_SAPI==='cli' && realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) exit(cli($argv));
