<?php
declare(strict_types=1);
require '/work/plugin/control.php';
$checks=0;
function qcheck(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; echo "PASS schedule: $label\n"; }
function qtime(array $quiet,string $clock,string $zone='Europe/Paris'): bool {
    return td_display_state($quiet,new DateTimeImmutable($clock,new DateTimeZone($zone)))['off'];
}
function qput(string $path,string $text): void { if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true); file_put_contents($path,$text); clearstatcache(true,$path); }
$root='/tmp/td-schedule-'.bin2hex(random_bytes(8));mkdir($root);putenv('TD_TEST_ROOT='.$root);
$originalZone=date_default_timezone_get();
try {
    $quiet=td_quiet_defaults();
    qcheck(!$quiet['enabled'] && !qtime($quiet,'2026-11-02 23:30'),'legacy/default schedule never blanks the display');
    $quiet=array_replace($quiet,['enabled'=>true,'days'=>[1],'start'=>'09:00','end'=>'17:00']);
    foreach (['2026-11-02 08:59:59'=>false,'2026-11-02 09:00:00'=>true,'2026-11-02 16:59:59'=>true,'2026-11-02 17:00:00'=>false,'2026-11-03 12:00'=>false] as $clock=>$off)
        qcheck(qtime($quiet,$clock)===$off,'same-day boundary '.$clock);
    $quiet['start']='23:00';$quiet['end']='07:00';
    foreach (['2026-11-02 06:00'=>false,'2026-11-02 22:59:59'=>false,'2026-11-02 23:00'=>true,'2026-11-03 00:00'=>true,'2026-11-03 06:59:59'=>true,'2026-11-03 07:00'=>false,'2026-11-03 23:30'=>false] as $clock=>$off)
        qcheck(qtime($quiet,$clock)===$off,'overnight interval belongs to its starting day '.$clock);
    $quiet['days']=[7];
    qcheck(qtime($quiet,'2026-11-02 06:30') && !qtime($quiet,'2026-11-02 07:00'),'Sunday night wraps into Monday');
    $quiet['days']=[1];$quiet['all_day']=true;
    foreach (['2026-11-01 23:59:59'=>false,'2026-11-02 00:00'=>true,'2026-11-02 23:59:59'=>true,'2026-11-03 00:00'=>false] as $clock=>$off)
        qcheck(qtime($quiet,$clock)===$off,'entire-day midnight boundary '.$clock);
    $quiet['days']=[1,3,5];
    qcheck(qtime($quiet,'2026-11-04 14:00') && !qtime($quiet,'2026-11-05 14:00'),'several selected weekdays stay independent');
    $quiet=array_replace($quiet,['days'=>[6],'all_day'=>false,'start'=>'23:00','end'=>'07:00']);
    foreach (['2026-03-29T00:30:00Z'=>true,'2026-03-29T01:30:00Z'=>true,'2026-03-29T05:00:00Z'=>false,'2026-10-25T00:30:00Z'=>true,'2026-10-25T01:30:00Z'=>true,'2026-10-25T06:00:00Z'=>false] as $utc=>$off) {
        $now=(new DateTimeImmutable($utc))->setTimezone(new DateTimeZone('Europe/Paris'));
        qcheck(td_display_state($quiet,$now)['off']===$off,'DST follows local wall time '.$utc);
    }
    $quiet=array_replace(td_quiet_defaults(),['enabled'=>true,'all_day'=>true]);
    $state=td_display_state($quiet,new DateTimeImmutable('2026-11-02 12:00',new DateTimeZone('Pacific/Auckland')));
    qcheck($state['off'] && $state['reason']==='schedule' && $state['timezone']==='Pacific/Auckland' && str_contains($state['local_time'],'12:00:00'),'status reports evaluated server timezone and local clock');
    qput(root_path('/etc/placeholder'),'');
    qput(root_path('/var/local/emhttp/var.ini'),"timeZone=\"America/New_York\"\n");
    date_default_timezone_set('UTC');
    symlink('/usr/share/zoneinfo/Europe/Paris',root_path('/etc/localtime'));
    qcheck(td_host_timezone()->getName()==='Europe/Paris','host localtime link takes priority over process/browser timezone');
    unlink(root_path('/etc/localtime'));
    qcheck(td_host_timezone()->getName()==='America/New_York','native Unraid timeZone is used if localtime is not a symlink');
    qput(root_path('/var/local/emhttp/var.ini'),"timeZone=\"Invalid/Zone\"\n");
    qcheck(td_host_timezone()->getName()==='UTC','invalid timezone falls back to configured PHP timezone');
    symlink('/usr/share/zoneinfo/Asia/Tokyo',root_path('/etc/localtime'));
    qcheck(td_host_timezone()->getName()==='Asia/Tokyo','timezone changes are read without restarting the service');
    $invalid=[null,'23:00',true,['extra'=>1],['enabled'=>1],['all_day'=>'yes'],['days'=>'Monday'],['days'=>[0]],['days'=>[8]],['days'=>['1']],['days'=>[1.0]],['days'=>[true]],['days'=>[1,1]],['days'=>['day'=>1]],['enabled'=>true,'days'=>[]],['start'=>'24:00'],['end'=>'07:60'],['start'=>'7:00'],['start'=>'23:00;id'],['start'=>null],['enabled'=>true,'start'=>'07:00','end'=>'07:00']];
    foreach ($invalid as $n=>$value) {
        $rejected=false;
        try { td_settings_normalize(['quiet_hours'=>$value]); } catch (RuntimeException $e) { $rejected=true; }
        qcheck($rejected,'invalid schedule rejected '.$n);
    }
    qcheck(td_quiet_normalize(['enabled'=>false,'days'=>[]])['days']===[],'disabled schedule may keep an empty draft day selection');
    qcheck(td_quiet_normalize(['enabled'=>true,'all_day'=>true,'start'=>'00:00','end'=>'00:00'])['all_day'],'entire-day mode does not require different times');
    init_run();qput(state_dir().'/settings.json',json_encode(array_replace(td_settings_defaults(),['quiet_hours'=>$quiet])));
    json_write(state_dir().'/inventory.json',['owner'=>TD_NAME,'instance'=>str_repeat('a',32)]);
    json_write(run_dir().'/display-test.json',['id'=>'ongoing','started_at'=>microtime(true),'duration'=>8]);
    $feed=sample(config());
    qcheck($feed['display']['off'] && $feed['display']['reason']==='schedule','host feed carries scheduled off independently of metric availability');
    qcheck($feed['display_test']===null && !is_file(run_dir().'/display-test.json'),'scheduled off cancels an ongoing visual test marker');
    $rejected=false;try { display_test(); } catch (RuntimeException $e) { $rejected=str_contains($e->getMessage(),'off by schedule'); }
    qcheck($rejected,'visual test cannot bypass a scheduled off period');
    save_settings(['autostart'=>false,'interval'=>1,'sensor'=>'auto']);
    qcheck(config()['quiet_hours']===$quiet,'legacy client preserves saved schedule');
    $cfg=config();$cfg['quiet_hours']['enabled']=false;save_settings($cfg);
    qcheck(!sample(config())['display']['off'],'disabling schedule restores live output on the next sample');
    $before=file_get_contents(state_dir().'/settings.json');sample(config());sample(config());
    qcheck(file_get_contents(state_dir().'/settings.json')===$before,'schedule evaluation does not write persistent settings');
    echo "TOTAL schedule: $checks checks passed\n";
} finally { date_default_timezone_set($originalZone); if (is_link(root_path('/etc/localtime'))) unlink(root_path('/etc/localtime')); erase_tree($root); }
