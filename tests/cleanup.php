<?php
declare(strict_types=1);
define('TD_CLEANUP_TESTING',true);
require '/work/plugin/cleanup.php';
$checks=0;
function ccheck(bool $ok,string $label): void { global $checks;if(!$ok)throw new RuntimeException($label);$checks++;echo "PASS nettoyage : $label\n"; }
function cput(string $p,string $s): void {if(!is_dir(dirname($p)))mkdir(dirname($p),0700,true);file_put_contents($p,$s);}
function cdocker(): array {return json_read(getenv('TD_FAKE_STATE'));}
function csave(array $d): void {json_write(getenv('TD_FAKE_STATE'),$d);}
function cfixture(): string {
 $root='/tmp/td-clean-'.bin2hex(random_bytes(4));mkdir($root);putenv('TD_TEST_ROOT='.$root);putenv('TD_TEST_DOCKER=/work/tests/fake-docker.php');putenv('TD_FAKE_STATE='.$root.'/docker.json');
 $i=['owner'=>TD_NAME,'instance'=>str_repeat('c',32),'images'=>[],'bundles'=>[],'docker_touched'=>true];
 foreach(['2026.10.31.1','2026.10.31.2','2026.10.31.3'] as $v){
  $dir=state_dir().'/packages/'.$v;
  $r=['owner'=>TD_NAME,'version'=>$v,'image_id'=>'cur','image_tag'=>'localhost/thermalright-digital:current','payload_sha256'=>hash('sha256','payload'),'image_sha256'=>hash('sha256','image')];
  cput($dir.'/payload.txz','payload');cput($dir.'/backend-image.tar.xz','image');cput($dir.'/release.json',json_encode($r));
  $plg="<PLUGIN><FILE><INLINE><![CDATA[release_sha='".hash_file('sha256',$dir.'/release.json')."']]></INLINE></FILE></PLUGIN>";
  cput($dir.'/thermalright-digital.plg',$plg);$i['bundles'][$v]=['plg_sha256'=>hash('sha256',$plg)];
  if($v==='2026.10.31.3')cput(ui_dir().'/release.json',json_encode($r));
 }
 inv_save($i);json_write(state_dir().'/installed.json',['version'=>'2026.10.31.3']);json_write(state_dir().'/settings.json',['autostart'=>false,'interval'=>5,'sensor'=>'auto']);
 json_write(state_dir().'/previous.json',['version'=>'2026.10.31.2']);copy(state_dir().'/packages/2026.10.31.2/thermalright-digital.plg',state_dir().'/previous.plg');
 cput(root_path('/mnt/user/appdata/thermalright-screen/old.txt'),'old');cput(root_path('/mnt/user/appdata/unrelated/keep.txt'),'keep');
 cput(root_path('/var/lib/docker/unraid-autostart'),"unrelated 5\nthermalright-screen 10\n");
 cput(root_path('/boot/config/plugins/dockerMan/templates-user/my-thermalright-screen.xml'),'<Container><Name>thermalright-screen</Name><Repository>thermalright-screen:latest</Repository></Container>');
 $d=['online'=>true,'containers'=>[
 'current'=>['Id'=>'current','Name'=>'thermalright-digital-backend','Image'=>'cur','State'=>['Running'=>true],'Config'=>['Labels'=>[TD_LABEL.'.owner'=>TD_NAME]],'Mounts'=>[]],
 'old'=>['Id'=>'old','Name'=>'/thermalright-screen','Image'=>'old-image','State'=>['Running'=>true],'Config'=>['Labels'=>['trcc.setup'=>TD_OLD_LABEL]],'Mounts'=>[['Type'=>'bind','Source'=>'/mnt/user/appdata/thermalright-screen']]]],
 'images'=>['cur'=>['Id'=>'cur','RepoTags'=>['localhost/thermalright-digital:current'],'Config'=>['Labels'=>[TD_LABEL.'.owner'=>TD_NAME]]],'old-image'=>['Id'=>'old-image','RepoTags'=>['thermalright-screen:latest'],'Config'=>['Labels'=>[]]]],
 'volumes'=>['old-volume'=>['Id'=>'old-volume','Name'=>'old-volume','Labels'=>['trcc.setup'=>TD_OLD_LABEL]]],
 'networks'=>['old-network'=>['Id'=>'old-network','Labels'=>['trcc.setup'=>TD_OLD_LABEL],'Containers'=>[]]]];csave($d);return $root;
}
function cdrop(string $r): void {erase_tree($r);}
try {
 $r=cfixture();$settings=file_get_contents(state_dir().'/settings.json');$inv=file_get_contents(state_dir().'/inventory.json');
 ccheck(clean_main(['cleanup','--audit'])===2,'audit signale les restes présents');
 ccheck(file_get_contents(state_dir().'/inventory.json')===$inv && file_get_contents(state_dir().'/settings.json')===$settings && is_dir(state_dir().'/packages/2026.10.31.1') && isset(cdocker()['containers']['old']),'audit ne supprime ni ne modifie les ressources');
 ccheck(clean_main(['cleanup','--apply'])===0,'nettoyage vérifié terminé');
 $d=cdocker();ccheck(isset($d['containers']['current'],$d['images']['cur']) && count($d['containers'])===1 && count($d['images'])===1,'conteneur et image courants préservés');
 ccheck(!$d['volumes'] && !$d['networks'],'anciens volume/réseau étiquetés retirés');
 ccheck(!is_dir(root_path('/mnt/user/appdata/thermalright-screen')) && is_file(root_path('/mnt/user/appdata/unrelated/keep.txt')),'seul appdata avec preuve de montage supprimé');
 ccheck(file_get_contents(root_path('/var/lib/docker/unraid-autostart'))==="unrelated 5\n" && !is_file(root_path('/boot/config/plugins/dockerMan/templates-user/my-thermalright-screen.xml')),'autostart partagé préservé et ancien modèle retiré');
 ccheck(is_dir(state_dir().'/packages/2026.10.31.3') && !is_dir(state_dir().'/packages/2026.10.31.2') && !is_file(state_dir().'/previous.plg'),'version active conservée, anciennes versions et ancien manifeste retirés');
 ccheck(file_get_contents(state_dir().'/settings.json')===$settings && count(inventory()['bundles'])===1,'réglages et inventaire courant préservés');
 ccheck(clean_main(['cleanup','--apply'])===0 && !is_file(state_dir().'/maintenance.json'),'nettoyage répété idempotent');cdrop($r);

 $r=cfixture();$d=cdocker();$d['containers']['old']['Config']['Labels']=[];csave($d);
 ccheck(clean_main(['cleanup','--apply'])===2,'ressources sans preuve signalées conservées');
 ccheck(isset(cdocker()['containers']['old'],cdocker()['images']['old-image']) && is_file(root_path('/mnt/user/appdata/thermalright-screen/old.txt')),'homonymes et appdata non prouvé intacts');cdrop($r);

 $r=cfixture();$d=cdocker();$d['containers']['foreign']=['Id'=>'foreign','Name'=>'other','Image'=>'old-image','Config'=>['Labels'=>[]],'Mounts'=>[['Source'=>'/mnt/user/appdata/thermalright-screen']]];csave($d);
 ccheck(clean_main(['cleanup','--apply'])===2,'dépendances partagées bloquent le nettoyage complet');
 ccheck(isset(cdocker()['images']['old-image'],cdocker()['containers']['foreign']) && is_file(root_path('/mnt/user/appdata/thermalright-screen/old.txt')) && is_file(state_dir().'/maintenance.json'),'image/dossier partagés et journal de reprise préservés');cdrop($r);

 $r=cfixture();cput(state_dir().'/packages/2026.10.31.1/foreign.txt','keep');
 ccheck(clean_main(['cleanup','--apply'])===2 && is_file(state_dir().'/packages/2026.10.31.1/foreign.txt'),'fichier inconnu dans une ancienne version conservé');cdrop($r);

 $r=cfixture();$d=cdocker();$d['online']=false;csave($d);
 try {clean_main(['cleanup','--apply']);ccheck(false,'absence Docker');}catch(RuntimeException $e){ccheck(is_dir(state_dir().'/packages/2026.10.31.1') && is_file(root_path('/mnt/user/appdata/thermalright-screen/old.txt')),'Docker absent : aucune suppression');}cdrop($r);
 $r=cfixture();$pool='/mnt/fast_pool';cput(root_path($pool.'/appdata/thermalright-screen/old.txt'),'old');
 $d=cdocker();$d['containers']['old']['Mounts']=[['Type'=>'bind','Source'=>$pool.'/appdata/thermalright-screen']];csave($d);
 clean_main(['cleanup','--apply']);
 ccheck(!is_dir(root_path($pool.'/appdata/thermalright-screen')) && is_file(root_path('/mnt/user/appdata/thermalright-screen/old.txt')),'pool arbitraire : seul appdata prouvé par montage retiré');cdrop($r);

 $r=cfixture();cput(root_path('/outside/appdata/thermalright-screen/keep.txt'),'keep');symlink(root_path('/outside'),root_path('/mnt/pool_link'));
 ccheck(!in_array('/mnt/pool_link',clean_storage_roots(),true),'racine stockage symbolique exclue de la découverte');
 $d=cdocker();$d['containers']['old']['Mounts']=[['Type'=>'bind','Source'=>'/mnt/pool_link/appdata/thermalright-screen']];csave($d);clean_main(['cleanup','--apply']);
 ccheck(is_file(root_path('/outside/appdata/thermalright-screen/keep.txt')),'appdata derrière un lien de stockage conservé');unlink(root_path('/mnt/pool_link'));cdrop($r);
 $r=cfixture();cput(root_path('/mnt/fast_pool/keep.txt'),'pool');cput(root_path('/external/thermalright-screen/keep.txt'),'keep');
 symlink(root_path('/external'),root_path('/mnt/fast_pool/appdata'));
 ccheck(!in_array('/mnt/fast_pool/appdata/thermalright-screen',clean_appdata_paths(),true),'parent appdata symbolique exclu des chemins supprimables');unlink(root_path('/mnt/fast_pool/appdata'));cdrop($r);
 echo "TOTAL nettoyage : $checks contrôles réussis\n";
}catch(Throwable $e){fwrite(STDERR,"FAIL nettoyage : ".$e->getMessage()."\n".$e->getTraceAsString()."\n");exit(1);}
