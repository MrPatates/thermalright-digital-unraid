<?php
declare(strict_types=1);
require '/work/plugin/control.php';
$count=0;
function scheck(bool $ok,string $label): void { global $count;if(!$ok)throw new RuntimeException($label);$count++;echo "PASS settings : $label\n"; }
function sbad(array $data,string $label): void {$rejected=false;try{td_settings_normalize($data,true);}catch(RuntimeException $e){$rejected=true;}scheck($rejected,$label);}
$old=['autostart'=>false,'interval'=>1,'sensor'=>'auto'];
$new=td_settings_normalize($old,true);
scheck($new['rgb']['mode']==='static' && $new['rgb']['color']==='#00b4ff' && $new['frequency_mode']==='max' && $new['power_sensor']==='auto','legacy settings migrate while preserving cyan appearance');
scheck(td_settings_normalize($new,true)===$new,'normalization is idempotent');
scheck($new['rgb']['temperature_zones']===['temperature','load','power','frequency','decoration'],'legacy RGB settings default to all thermal categories');
$legacy=$new;unset($legacy['rgb']['temperature_zones']);scheck(td_settings_normalize($legacy,true)['rgb']['temperature_zones']===$new['rgb']['temperature_zones'],'legacy RGB without category selection preserved');
$selected=$new;$selected['rgb']['mode']='temperature';$selected['rgb']['temperature_zones']=['temperature'];
scheck(td_settings_normalize($selected,true)['rgb']['temperature_zones']===['temperature'],'dynamic temperature limited to its own category');
foreach([[],['temperature','temperature'],['unknown'],['temperature',null],'temperature',null,['key'=>'temperature'],['temperature','load','power','frequency','decoration','temperature']] as $invalid) {
 $d=$new;$d['rgb']['temperature_zones']=$invalid;sbad($d,'invalid thermal selection rejected');
}
foreach(['static','zones','temperature','breathing','rainbow'] as $mode) {
 $d=$new;$d['rgb']['mode']=$mode;$d['rgb']['color']='#12ABef';$v=td_settings_normalize($d,true);scheck($v['rgb']['mode']===$mode && $v['rgb']['color']==='#12abef','RGB mode '.$mode);
}
foreach([['mode'=>'shell'],['target'=>'../all'],['color'=>'red; echo'],['brightness'=>101],['brightness'=>-1],['brightness'=>'100'],['period'=>0],['period'=>61],['smoothing'=>-1],['smoothing'=>31],['thresholds'=>[40,40,80]],['thresholds'=>[80,60,40]],['thresholds'=>[40,60]],['thresholds'=>[40,60,126]],['temperature_colors'=>['#000000']],['temperature_colors'=>['#000000','#000000','#000000','</script>']],['zone_colors'=>['extra'=>'#00b4ff']],['unknown'=>true]] as $bad) {
 $d=$new;$d['rgb']=array_replace($d['rgb'],$bad);sbad($d,'invalid RGB rejected : '.array_key_first($bad));
}
$d=$new;$d['rgb']=null;sbad($d,'null RGB rejected');
$d=$new;$d['frequency_mode']='nominal';sbad($d,'unsupported frequency mode rejected');
$d=$new;$d['power_sensor']='../../etc/shadow';sbad($d,'arbitrary power source path rejected');
$d=$new;$d['other']=true;sbad($d,'unknown server setting rejected');
$root='/tmp/td-settings-'.bin2hex(random_bytes(4));mkdir($root);putenv('TD_TEST_ROOT='.$root);mkdir(state_dir(),0700,true);init_run();
json_write(state_dir().'/settings.json',$old);
$before=file_get_contents(state_dir().'/settings.json');config();scheck(file_get_contents(state_dir().'/settings.json')===$before,'new defaults read without flash writes');
$d=$new;$d['rgb']['mode']='rainbow';$d['rgb']['brightness']=25;save_settings($d);
$saved=config();scheck($saved['rgb']['mode']==='rainbow' && $saved['rgb']['brightness']===25,'RGB settings saved');
save_settings($selected);$saved=config();scheck($saved['rgb']['temperature_zones']===['temperature'],'thermal selection saved');
save_settings(['autostart'=>true,'interval'=>2,'sensor'=>'auto']);scheck(config()['rgb']===$saved['rgb'],'legacy client retains new settings');
$rejected=false;try{$d=config();$d['power_sensor']=str_repeat('f',24);save_settings($d);}catch(RuntimeException $e){$rejected=true;}scheck($rejected,'missing sensor ID rejected');
$rejected=false;try{display_test();}catch(RuntimeException $e){$rejected=true;}scheck($rejected && !is_file(run_dir().'/display-test.json'),'visual test refused without ready backend');
$d=config();$d['rgb']['mode']='temperature';$d['rgb']['temperature_zones']=['temperature'];save_settings($d);
$legacy=$d;unset($legacy['rgb']['temperature_zones']);$legacy['rgb']['brightness']=70;save_settings($legacy);
scheck(config()['rgb']['temperature_zones']===['temperature'],'legacy RGB form retains category selection');
erase_tree($root);
echo "TOTAL settings : $count checks passed\n";
