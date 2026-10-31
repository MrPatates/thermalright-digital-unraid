#!/usr/local/bin/php
<?php
// Deliberately stateful test double. No actual Docker daemon is contacted.
$path=getenv('TD_FAKE_STATE');
$lock=fopen($path.'.lock','c');flock($lock,LOCK_EX);
$state=json_decode(file_get_contents($path),true);
$args=array_slice($argv,1); $kind=$args[0]??''; $op=$args[1]??'';
function done_fake($code=0,$out='',$err='') { global $path,$state; $temp=$path.'.tmp.'.getmypid();file_put_contents($temp,json_encode($state));rename($temp,$path); if($out!=='') echo $out."\n"; if($err!=='') fwrite(STDERR,$err."\n"); exit($code); }
if(!($state['online']??true)) done_fake(1,'','Cannot connect to the Docker daemon');
if($kind==='info') done_fake(0,'27.5.1');
$items=&$state[$kind.'s']; if(!is_array($items)) $items=[];
if($op==='inspect') {
 $id=$args[2];
 foreach($items as $obj) if($obj['Id']===$id || ($obj['Name']??'')===$id || in_array($id,$obj['RepoTags']??[],true)) done_fake(0,json_encode([$obj]));
 done_fake(1,'','Error: No such '.($kind==='image'?'image':'container').': '.$id);
}
if($op==='ls') {
 $out=[];$filter='';foreach($args as $n=>$arg) if($arg==='--filter')$filter=$args[$n+1];
 foreach($items as $obj) {
  if(str_starts_with($filter,'label=')) {[$k,$v]=explode('=',substr($filter,6),2);if(($obj['Config']['Labels'][$k]??$obj['Labels'][$k]??'')!==$v)continue;}
  if(str_starts_with($filter,'ancestor=') && ($obj['Image']??'')!==substr($filter,9))continue;
  $out[]=$obj['Id'];
 }
 done_fake(0,implode("\n",$out));
}
if($kind==='image' && $op==='load') {
 $r=json_decode(file_get_contents(getenv('TD_TEST_ROOT').'/usr/local/emhttp/plugins/thermalright-digital/release.json'),true);
 $state['images'][$r['image_id']]=['Id'=>$r['image_id'],'Config'=>['Labels'=>['io.local.thermalright-digital.owner'=>'thermalright-digital']],'RepoTags'=>[$r['image_tag']]];
 done_fake(0,'Loaded image: '.$r['image_tag']);
}
if($kind==='container' && $op==='create') {
 $labels=[];$name='';
 foreach($args as $n=>$arg){if($arg==='--label'){[$k,$v]=explode('=',$args[$n+1],2);$labels[$k]=$v;}if($arg==='--name')$name=$args[$n+1];}
 $id='fake-'.bin2hex(random_bytes(8));
 $items[$id]=['Id'=>$id,'Name'=>$name,'Config'=>['Labels'=>$labels],'Image'=>end($args),'State'=>['Running'=>false],'args'=>$args];
 done_fake(0,$id);
}
if($kind==='container' && in_array($op,['start','stop','rm'],true)) {
 $id=end($args);
 foreach($items as $key=>$obj) if($key===$id || $obj['Name']===$id) {
  if($op==='rm')unset($items[$key]);else $items[$key]['State']['Running']=$op==='start';
  $release=json_decode((string)@file_get_contents(getenv('TD_TEST_ROOT').'/usr/local/emhttp/plugins/thermalright-digital/release.json'),true);
  if($op==='start' && !($state['not_ready']??false) && ($state['not_ready_version']??'')!==($release['version']??'')) file_put_contents(getenv('TD_TEST_ROOT').'/var/run/thermalright-digital/driver/status.json',json_encode(['ready'=>true,'timestamp'=>time(),'model'=>'FAKE PA120']));
  done_fake();
 }
 done_fake(1,'','No such container');
}
if($kind==='image' && $op==='rm') {
 $id=end($args);
 foreach($items as $key=>$obj) if($key===$id || in_array($id,$obj['RepoTags']??[],true)) {
  if($key===$id)unset($items[$key]);else { $items[$key]['RepoTags']=array_values(array_diff($obj['RepoTags'],[$id])); if(!$items[$key]['RepoTags'])unset($items[$key]); }
  done_fake();
 }
 done_fake(1,'','No such image');
}
if(in_array($kind,['volume','network'],true) && $op==='rm') { $id=end($args);if(isset($items[$id])){unset($items[$id]);done_fake();}done_fake(1,'','No such object'); }
done_fake(2,'','Fake Docker: unsupported command '.json_encode($args));
