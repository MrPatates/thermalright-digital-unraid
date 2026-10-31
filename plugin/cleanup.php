<?php
/* Explicit maintenance CLI, GPL-3.0-or-later. Audit is the default. */
declare(strict_types=1);
if (PHP_SAPI!=='cli') { http_response_code(404); exit; }
$tdControl=(getenv('TD_TEST_ROOT')?:'').'/usr/local/emhttp/plugins/thermalright-digital/control.php';
// A downloaded CLI must never include an unrelated /tmp/control.php.
if (!is_file($tdControl) && defined('TD_CLEANUP_TESTING')) $tdControl=__DIR__.'/control.php';
if (!is_file($tdControl)) { fwrite(STDERR,"Install the plugin before running cleanup.\n"); exit(1); }
require_once $tdControl;
const TD_OLD_LABEL='unraid-thermalright-0416-8001';

// Enumerate direct storage directories; deletion still requires the existing
// labelled-container or exact package/hash ownership proofs below.
function clean_storage_roots(): array {
    $roots=['/mnt/user'];
    foreach (glob(root_path('/mnt/*'),GLOB_ONLYDIR)?:[] as $dir) {
        $name=basename($dir);
        if (is_link($dir) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]*$/D',$name) || in_array($name,['user','user0','disks','remotes','addons','rootshare'],true)) continue;
        $roots[]='/mnt/'.$name;
    }
    return array_values(array_unique($roots));
}
function clean_appdata_paths(): array {
    $roots=array_filter(clean_storage_roots(),fn($p)=>!is_link(root_path($p.'/appdata')));
    return array_values(array_map(fn($p)=>$p.'/appdata/thermalright-screen',$roots));
}

function clean_pkg_check(string $dir,array $i): array {
    if (is_link($dir) || !is_dir($dir)) fail('Unrecognized package directory : '.$dir);
    $r=json_read($dir.'/release.json'); $version=$r['version']??'';
    if (($r['owner']??'')!==TD_NAME || basename($dir)!==$version) fail('Package ownership unverified : '.$dir);
    $plg=$dir.'/'.TD_NAME.'.plg';
    if (!is_file($plg) || hash_file('sha256',$plg)!==($i['bundles'][$version]['plg_sha256']??'')) fail('Manifest untracked or modified : '.$dir);
    if (!str_contains((string)file_get_contents($plg),"release_sha='".hash_file('sha256',$dir.'/release.json')."'")) fail('Modified package metadata : '.$dir);
    foreach (new FilesystemIterator($dir) as $f) {
        if ($f->isLink() || !$f->isFile() || !in_array($f->getFilename(),['payload.txz','backend-image.tar.xz','release.json',TD_NAME.'.plg','.download-origin'],true)) fail('Unknown file preserved : '.$f->getPathname());
    }
    foreach (['payload.txz'=>'payload_sha256','backend-image.tar.xz'=>'image_sha256'] as $name=>$key)
        if (is_file($dir.'/'.$name) && hash_file('sha256',$dir.'/'.$name)!==($r[$key]??'')) fail('Modified archive preserved : '.$dir.'/'.$name);
    if (is_file($dir.'/.download-origin') && trim((string)file_get_contents($dir.'/.download-origin'))!==TD_NAME) fail('Incorrect download marker');
    return $r;
}
function clean_containers(): array {
    [$code,$out,$err]=docker(['container','ls','--all','--quiet']);
    if ($code) fail('Cannot read Docker inventory : '.$err);
    $all=[];
    foreach (array_filter(explode("\n",$out)) as $id) { $c=inspect('container',$id); if ($c) $all[$c['Id']]=$c; }
    return $all;
}
function clean_legacy_plan(array $all,array $saved): array {
    $plan=array_merge(['containers'=>[],'images'=>[],'paths'=>[],'volumes'=>[],'networks'=>[]],$saved);
    foreach ($all as $id=>$c) {
        if (ltrim($c['Name']??'','/')!=='thermalright-screen') continue;
        if ((labels($c)['trcc.setup']??'')!==TD_OLD_LABEL) { echo "KEPT : container name matches but the expected legacy label is missing ($id).\n"; continue; }
        if ((labels($c)[TD_LABEL.'.owner']??'')===TD_NAME) { echo "KEPT : active plugin container.\n"; continue; }
        $plan['containers'][$id]=['name'=>'thermalright-screen','label'=>TD_OLD_LABEL];
        $image=$c['Image']??''; if ($image!=='') $plan['images'][$image]=['container_proof'=>$id];
        foreach ($c['Mounts']??[] as $mount) {
            $source=rtrim($mount['Source']??'','/');
            if (in_array($source,clean_appdata_paths(),true))
                $plan['paths'][$source]=['container_proof'=>$id];
        }
    }
    // A separately labelled image remains identifiable after container removal.
    [$code,$ids,$err]=docker(['image','ls','--quiet','--filter','label=trcc.setup='.TD_OLD_LABEL]);
    if ($code) fail('Cannot inventory legacy images : '.$err);
    foreach (array_filter(explode("\n",$ids)) as $id) {
        $im=inspect('image',$id);
        if ($im && (labels($im)['trcc.setup']??'')===TD_OLD_LABEL) $plan['images'][$im['Id']]=['label'=>TD_OLD_LABEL];
    }
    foreach (['volume','network'] as $kind) {
        [$code,$ids,$err]=docker([$kind,'ls','--quiet','--filter','label=trcc.setup='.TD_OLD_LABEL]);
        if ($code) fail('Inventory '.$kind.' failed: '.$err);
        foreach (array_filter(explode("\n",$ids)) as $id) $plan[$kind.'s'][$id]=['label'=>TD_OLD_LABEL];
    }
    return $plan;
}
function clean_old_tag(string $tag): bool { return (bool)preg_match('~^(?:localhost/)?thermalright-screen(?::[^/]+)?$~D',$tag); }
function clean_shared_path(string $path,array $all,array $removing): bool {
    foreach ($all as $id=>$c) if (!isset($removing[$id])) foreach ($c['Mounts']??[] as $m) {
        $source=rtrim($m['Source']??'','/');
        if ($source!=='' && ($path===$source || str_starts_with($path,$source.'/') || str_starts_with($source,$path.'/'))) return true;
    }
    return false;
}
function clean_legacy_apply(array $plan,array $all,bool $apply): array {
    $remaining=$plan;
    foreach ($plan['containers'] as $id=>$proof) {
        $c=$all[$id]??null;
        if (!$c) { unset($remaining['containers'][$id]); continue; }
        if ((labels($c)['trcc.setup']??'')!==TD_OLD_LABEL || ltrim($c['Name']??'','/')!=='thermalright-screen') fail('Container identity changed; cleanup stopped');
        echo ($apply?'REMOVING':'CANDIDATE')." legacy container : $id\n";
        if ($apply) {
            [$code,,$err]=docker(['container','stop','--time','5',$id],10); if ($code) fail($err);
            [$code,,$err]=docker(['container','rm',$id]); if ($code) fail($err);
            unset($remaining['containers'][$id]);
        }
    }
    foreach ($plan['images'] as $id=>$proof) {
        $im=inspect('image',$id); if (!$im) { unset($remaining['images'][$id]); continue; }
        if ((labels($im)[TD_LABEL.'.owner']??'')===TD_NAME) { echo "KEPT : current plugin image $id\n"; unset($remaining['images'][$id]); continue; }
        $tags=$im['RepoTags']??[];
        $named=(bool)array_filter($tags,'clean_old_tag');
        $label=(labels($im)['trcc.setup']??'')===TD_OLD_LABEL;
        if (!$label && !(isset($proof['container_proof']) && $named)) { echo "KEPT : image with insufficient ownership evidence $id\n"; continue; }
        $foreign=array_diff($tags,array_filter($tags,'clean_old_tag'),['<none>:<none>']);
        $shared=false; foreach ($all as $cid=>$c) if (($c['Image']??'')===$id && !isset($plan['containers'][$cid])) $shared=true;
        if ($shared || $foreign) { echo "KEPT : shared image $id\n"; continue; }
        echo ($apply?'REMOVING':'CANDIDATE')." legacy image : $id\n";
        if ($apply) { [$code,,$err]=docker(['image','rm',$id]); if ($code) fail($err); unset($remaining['images'][$id]); }
    }
    foreach ($plan['paths'] as $path=>$proof) {
        $disk=root_path($path);
        if (!file_exists($disk)) { unset($remaining['paths'][$path]); continue; }
        if (is_link($disk) || clean_shared_path($path,clean_containers(),$plan['containers'])) { echo "KEPT : shared path or symbolic link $path\n"; continue; }
        // Do not cross nested mount points or delete files currently bind-mounted elsewhere.
        foreach (@file(root_path('/proc/self/mountinfo'),FILE_IGNORE_NEW_LINES)?:[] as $line) {
            $fields=explode(' ',$line); $mount=str_replace(['\\040','\\011','\\134'],[' ',"\t",'\\'],$fields[4]??'');
            if (str_starts_with($mount,$path.'/')) fail('Nested mount preserved : '.$mount);
        }
        echo ($apply?'REMOVING':'CANDIDATE')." legacy appdata verified through its mount : $path\n";
        if ($apply) { erase_tree($disk); unset($remaining['paths'][$path]); }
    }
    foreach (['volume','network'] as $kind) foreach ($plan[$kind.'s'] as $id=>$proof) {
        [$code,$out,$err]=docker([$kind,'inspect',$id]);
        if ($code) { if (str_contains($err,'No such')) { unset($remaining[$kind.'s'][$id]); continue; } fail($err); }
        $object=json_decode($out,true,32,JSON_THROW_ON_ERROR)[0];
        if (($object['Labels']['trcc.setup']??'')!==TD_OLD_LABEL) { echo "KEPT : $kind missing expected label $id\n"; continue; }
        $shared=false;
        foreach ($all as $cid=>$c) if (!isset($plan['containers'][$cid])) {
            foreach ($c['Mounts']??[] as $m) if ($kind==='volume' && ($m['Name']??'')===$id) $shared=true;
            if ($kind==='network' && isset($object['Containers'][$cid])) $shared=true;
        }
        if ($shared) { echo "KEPT : $kind shared $id\n"; continue; }
        echo ($apply?'REMOVING':'CANDIDATE')." $kind labeled legacy resource : $id\n";
        if ($apply) { [$code,,$err]=docker([$kind,'rm',$id]); if ($code) fail($err); unset($remaining[$kind.'s'][$id]); }
    }
    return $remaining;
}
function clean_automation_audit(): array {
    $files=[root_path('/boot/config/go')];
    foreach (['/boot/config/plugins/user.scripts/scripts/*/script','/boot/config/plugins/user.scripts/*.cron','/etc/cron.d/*','/boot/config/plugins/dockerMan/templates-user/*.xml'] as $pattern) $files=array_merge($files,glob(root_path($pattern))?:[]);
    $found=[];
    foreach ($files as $file) if (is_file($file) && !is_link($file) && filesize($file)<1048576 && str_contains((string)file_get_contents($file),'thermalright-screen')) $found[]=$file;
    foreach ($found as $file) echo "REVIEW, KEPT : reference in $file\n";
    foreach (clean_appdata_paths() as $p)
        if (is_dir(root_path($p))) { echo "Appdata still present : $p (removed only with mount evidence from the labeled container).\n"; $found[]=$p; }
    $old=inspect('image','thermalright-screen');
    if ($old) { echo 'Image still named thermalright-screen : '.$old['Id']."\n"; $found[]=$old['Id']; }
    return $found;
}
function clean_legacy_metadata(array $plan,array $all,bool $apply): void {
    if (!$plan['containers']) return;
    foreach ($all as $id=>$c) if (ltrim($c['Name']??'','/')==='thermalright-screen' && !isset($plan['containers'][$id])) return;
    // This is Unraid's documented manager metadata, not Docker layer storage.
    $auto=root_path('/var/lib/docker/unraid-autostart');
    if (is_file($auto) && !is_link($auto)) {
        $old=(string)file_get_contents($auto);
        $lines=preg_split('/(?<=\n)/',$old);
        $new=implode('',array_filter($lines,fn($line)=>!preg_match('/^\s*thermalright-screen(?:\s|$)/',$line)));
        if ($new!==$old) {
            echo ($apply?'REMOVING':'CANDIDATE')." Unraid autostart entry : thermalright-screen\n";
            if ($apply) { $mode=fileperms($auto)&0777; if (file_get_contents($auto)!==$old) fail('Autostart changed concurrently; retry'); atomic($auto,$new); chmod($auto,$mode); }
        }
    }
    foreach (glob(root_path('/boot/config/plugins/dockerMan/templates-user/*.xml'))?:[] as $p) {
        if (is_link($p)) continue;
        $xml=@simplexml_load_file($p,'SimpleXMLElement',LIBXML_NONET);
        if (!$xml || (string)$xml->Name!=='thermalright-screen' || !clean_old_tag((string)$xml->Repository)) continue;
        echo ($apply?'REMOVING':'CANDIDATE')." verified legacy Docker template : $p\n";
        if ($apply && !unlink($p)) fail('Docker template not removed');
    }
}
function clean_downloads(bool $apply): array {
    $catalogue=[
        'thermalright-digital-2026.10.31.1.zip'=>'d4a0412dedbd256da836229b4b98626cd8a42e221a796961feedb7e096c05ee9',
        'thermalright-digital-2026.10.31.2.zip'=>'d780c3a70ebbf6226c8581707316e216fc4022071598e4377236155e65bae226',
        'thermalright-update.tar.gz'=>'29af8dac561ce751a2b683415d466c02d1570a5e990635216edfa12aa0ac24e9',
        'thermalright-digital-update-2026.10.31.2.tar.gz'=>'29af8dac561ce751a2b683415d466c02d1570a5e990635216edfa12aa0ac24e9'];
    $left=[];
    foreach (array_merge(['/tmp'],array_diff(clean_storage_roots(),['/mnt/user'])) as $root) foreach ($catalogue as $name=>$hash) {
        $p=root_path($root.'/'.$name); if (!file_exists($p) && !is_link($p)) continue;
        if (is_link($p) || !is_file($p) || hash_file('sha256',$p)!==$hash) { echo "KEPT : unrecognized download $p\n"; $left[]=$p; continue; }
        echo ($apply?'REMOVING':'CANDIDATE')." verified old download : $p\n";
        if ($apply && !unlink($p)) fail('Download not removed');
    }
    return $left;
}
function clean_main(array $args): int {
    $apply=in_array('--apply',$args,true);
    if (array_diff(array_slice($args,1),['--apply','--audit'])) fail('Use --audit (read-only) or --apply');
    $i=owned_state(); $current=json_read(state_dir().'/installed.json')['version']??'';
    if ($current==='' || $current!==(release()['version']??'')) fail('Cannot determine active version; cleanup refused');
    if (!docker_ok()) fail('Docker unavailable : cleanup not confirmed; nothing removed');
    $all=clean_containers();
    $saved=json_read(state_dir().'/maintenance.json');
    if ($saved && (($saved['owner']??'')!==TD_NAME || ($saved['instance']??'')!==$i['instance'])) fail('Unrecognized cleanup journal');
    $plan=clean_legacy_plan($all,$saved['legacy']??[]);
    echo ($apply?'CLEANUP':'READ-ONLY AUDIT')." ; active version protected : $current\n";
    $packages=[]; $staging=[]; $blocked=[]; $hashes=array_column($i['bundles']??[],'plg_sha256');
    foreach (glob(state_dir().'/packages/*')?:[] as $p) {
        if (basename($p)===$current) continue;
        try { clean_pkg_check($p,$i); $packages[]=$p; echo ($apply?'REMOVING':'CANDIDATE')." old version : ".basename($p)."\n"; }
        catch (Throwable $e) { echo 'KEPT : '.$e->getMessage()."\n"; $blocked[]=$p; }
    }
    foreach (array_map(fn($p)=>$p.'/thermalright-digital',array_diff(clean_storage_roots(),['/mnt/user'])) as $root) {
        if (is_link(root_path($root)) || is_link(root_path($root.'/packages'))) { echo "KEPT : installation copy behind a symbolic link $root\n"; $blocked[]=$root; continue; }
        foreach (glob(root_path($root.'/packages/*'))?:[] as $p) {
            try { clean_pkg_check($p,$i); if (clean_shared_path($root,$all,[])) fail('Copy used by a container'); $staging[]=$p; echo ($apply?'REMOVING':'CANDIDATE')." installation copy : $p\n"; }
            catch (Throwable $e) { echo 'KEPT : '.$e->getMessage()."\n"; $blocked[]=$p; }
        }
    }
    if ($apply) json_write(state_dir().'/maintenance.json',['owner'=>TD_NAME,'instance'=>$i['instance'],'legacy'=>$plan]);
    clean_legacy_metadata($plan,$all,$apply);
    $left=clean_legacy_apply($plan,$all,$apply);
    if ($apply) {
        json_write(state_dir().'/maintenance.json',['owner'=>TD_NAME,'instance'=>$i['instance'],'legacy'=>$left]);
        // Only old, verified package trees. Current config/process/container stay intact.
        foreach ($packages as $dir) { clean_pkg_check($dir,$i); $v=basename($dir); erase_tree($dir); unset($i['bundles'][$v]); }
        foreach ($staging as $dir) { erase_tree($dir); @rmdir(dirname($dir)); @rmdir(dirname(dirname($dir))); }
        foreach ($i['images']??[] as $id=>$record) {
            if ($id===(release()['image_id']??'') || !($record['created']??false)) continue;
            $image=inspect('image',$id);
            if (!$image) { unset($i['images'][$id]); continue; }
            if ((labels($image)[TD_LABEL.'.owner']??'')!==TD_NAME) { echo "KEPT : unverified old plugin image $id\n"; $blocked[]=$id; continue; }
            [$code,$refs,$err]=docker(['container','ls','-aq','--filter','ancestor='.$id]);
            if ($code) fail($err);
            if ($refs!=='' || array_diff($image['RepoTags']??[],[$record['tag'],'<none>:<none>'])) { echo "KEPT : shared old plugin image $id\n"; $blocked[]=$id; continue; }
            [$code,,$err]=docker(['image','rm',$id]); if ($code) fail($err);
            unset($i['images'][$id]); echo "REMOVING old plugin image : $id\n";
        }
        inv_save($i);
        if ($packages) {
            inv_save($i);
            $prev=json_read(state_dir().'/previous.json');
            if ($prev && !isset($i['bundles'][$prev['version']??''])) {
                @unlink(state_dir().'/previous.json');
                $p=state_dir().'/previous.plg';
                if (is_file($p) && in_array(hash_file('sha256',$p),$hashes,true)) @unlink($p);
            }
        }
    }
    $automation=clean_automation_audit();
    $blocked=array_merge($blocked,clean_downloads($apply));
    $pending=(bool)array_filter($left);
    if ($apply && !$pending) @unlink(state_dir().'/maintenance.json');
    echo $apply?"Verified resource cleanup complete. Items marked KEPT/REVIEW remain in place.\n":"Run again with --apply to remove only the verified candidates above.\n";
    return (($apply && $pending) || $automation || $blocked)?2:0;
}
if (realpath($_SERVER['SCRIPT_FILENAME'])===__FILE__) {
    try {
        if (in_array('--apply',$argv,true)) {
            init_run(); $lock=fopen(run_dir().'/control.lock','c');
            if (!flock($lock,LOCK_EX|LOCK_NB)) fail('A plugin operation is running');
            try { $code=clean_main($argv); } finally { flock($lock,LOCK_UN); fclose($lock); }
        } else $code=clean_main($argv);
        exit($code);
    } catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
}
