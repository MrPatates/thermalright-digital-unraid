<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/plugin/control.php';
require_once dirname(__DIR__).'/plugin/metrics.php';

$metricChecks=0; $metricRoots=[];
function metric_check(bool $ok,string $label): void {
    global $metricChecks;
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    $metricChecks++; echo 'PASS '.$label."\n";
}
function metric_put(string $path,string $data): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
    file_put_contents($path,$data); clearstatcache(true,$path);
}
function metric_fixture(): string {
    global $metricRoots;
    $root='/tmp/td-metrics-'.bin2hex(random_bytes(8));
    mkdir($root,0700); $metricRoots[]=$root; putenv('TD_TEST_ROOT='.$root);
    mkdir($root.'/sys',0700,true); mkdir(run_dir(),0700,true);
    metric_put($root.'/proc/sys/kernel/random/boot_id','12345678-1234-1234-1234-123456789abc');
    return $root;
}
function metric_link(string $target,string $path): void {
    if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
    symlink($target,$path); clearstatcache(true,$path);
}
function metric_hwmon(string $driver,array $attrs,int $number=0): string {
    $dir=root_path('/sys/class/hwmon/hwmon'.$number);
    metric_put($dir.'/name',$driver);
    foreach ($attrs as $file=>$value) metric_put($dir.'/'.$file,(string)$value);
    return $dir;
}
function metric_rapl(int $index,string $name,float $energy=1000000000,string $driver='intel-rapl'): string {
    $controller=root_path('/sys/devices/virtual/powercap/'.$driver);
    $zone=$controller.'/'.$driver.':'.$index;
    foreach (['name'=>$name,'energy_uj'=>(string)$energy,'max_energy_range_uj'=>'10000000000','enabled'=>'1'] as $file=>$value) metric_put($zone.'/'.$file,$value);
    $class=root_path('/sys/class/powercap');
    if (!is_link($class.'/'.$driver)) metric_link($controller,$class.'/'.$driver);
    metric_link($zone,$class.'/'.$driver.':'.$index);
    return $zone;
}
function metric_drop(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (new FilesystemIterator($path,FilesystemIterator::SKIP_DOTS) as $entry) metric_drop($entry->getPathname());
        rmdir($path);
    } else unlink($path);
}

try {
    metric_fixture();
    $m=td_extra_metrics([]); $sources=td_extra_metric_sources();
    metric_check($m['cpu_mhz']===null && $m['cpu_watts']===null && $m['frequency_kind']===null,'absence de sources : null, jamais zéro inventé');
    metric_check($sources['power']===[] && !$sources['frequency']['available'],'inventaire vide sans mesure depuis la machine réelle');

    $base=root_path('/sys/devices/system/cpu/cpufreq');
    foreach (['policy0'=>['cpuinfo_cur_freq'=>'5500000','scaling_cur_freq'=>'2000000','affected_cpus'=>'0 1 2 3'],
              'policy4'=>['cpuinfo_cur_freq'=>'3500000','affected_cpus'=>'4'],
              'policy8'=>['cpuinfo_cur_freq'=>'9000000','affected_cpus'=>'']] as $policy=>$attrs)
        foreach ($attrs as $file=>$value) metric_put($base.'/'.$policy.'/'.$file,$value);
    metric_link($base.'/policy0',$base.'/policy99');
    metric_link($base.'/policy0',root_path('/sys/devices/system/cpu/cpu0/cpufreq'));
    $m=td_extra_metrics(['frequency_mode'=>'max']);
    metric_check($m['cpu_mhz']===5500.0 && $m['frequency_kind']==='measured','kHz vers MHz et priorité de cpuinfo_cur_freq mesurée');
    $m=td_extra_metrics(['frequency_mode'=>'average']);
    metric_check($m['cpu_mhz']===4500.0 && str_contains($m['frequency_source'],'moyenne arithmétique'),'moyenne par politique unique, non par thread et sans politique inactive');
    metric_check(count(td_extra_metric_sources()['frequency']['policies'])===2,'liens cpufreq dédupliqués et politiques hors ligne exclues');
    metric_put($base.'/policy4/cpuinfo_cur_freq','0'); metric_put($base.'/policy4/scaling_cur_freq','4500000');
    $m=td_extra_metrics(['frequency_mode'=>'average']);
    metric_check($m['cpu_mhz']===5000.0 && $m['frequency_kind']==='requested' && str_contains($m['frequency_source'],'scaling_cur_freq'),'repli et mélange mesuré/demandé clairement signalés');
    metric_put($base.'/policy0/cpuinfo_cur_freq','NaN'); metric_put($base.'/policy0/scaling_cur_freq','-1500000');
    metric_put($base.'/policy4/scaling_cur_freq','99999999999999999');
    $m=td_extra_metrics([]);
    metric_check($m['cpu_mhz']===null && !td_extra_metric_sources()['frequency']['available'],'fréquences invalides, négatives et excessives indisponibles');
    metric_link('/sys/devices/system/cpu/cpufreq/policy0',$base.'/policy100');
    metric_check(count(td_metric_frequency_policies())===2,'un lien de fixture ne peut lire le sysfs de l’hôte');

    metric_fixture();
    metric_hwmon('amdgpu',['power1_label'=>'CPU Package','power1_input'=>'85000000']);
    metric_hwmon('it87',['power1_label'=>'CPU Package','power1_input'=>'45000000'],1);
    metric_hwmon('k10temp',['power1_cap'=>'95000000','power1_crit'=>'100000000'],2);
    metric_check(td_extra_metric_sources()['power']===[] && td_extra_metrics([])['cpu_watts']===null,'GPU, puce carte mère et TDP/limites ne deviennent pas watts CPU');
    $dir=metric_hwmon('fam15h_power',['power1_average'=>'61500000','power1_input'=>'64500000','power1_crit'=>'90000000'],3);
    $sources=td_extra_metric_sources()['power']; $id=$sources[0]['id']; $m=td_extra_metrics([]);
    metric_check(count($sources)===1 && $m['cpu_watts']===61.5 && $sources[0]['kind']==='hwmon_average','vrai hwmon CPU en µW, moyenne prioritaire sans doublon input');
    metric_check(preg_match('/^[a-f0-9]{24}$/D',$id)===1 && array_keys($sources[0])===['id','label','kind','available'],'descripteurs publics sans chemin, identifiant opaque 24 hex');
    metric_put($dir.'/power1_average','bad');
    metric_check(td_extra_metrics([])['cpu_watts']===64.5 && td_extra_metric_sources()['power'][0]['id']===$id,'repli hwmon input sans changer l’identité du canal');
    metric_put($dir.'/power1_enable','0');
    metric_check(td_extra_metrics([])['cpu_watts']===null && !td_extra_metric_sources()['power'][0]['available'],'canal CPU désactivé indisponible');
    metric_put($dir.'/power1_enable','1'); metric_put($dir.'/power1_input','0');
    metric_check(td_extra_metrics([])['cpu_watts']===0.0,'zéro réel du capteur conservé');
    metric_check(td_extra_metrics(['power_sensor'=>'off'])['cpu_watts']===null && td_extra_metrics(['power_sensor'=>'../../etc/shadow'])['cpu_watts']===null && td_extra_metrics(['power_sensor'=>str_repeat('f',24)])['cpu_watts']===null,'désactivation, chemin injecté et ID inconnu sans lecture arbitraire');

    metric_fixture();
    $device=root_path('/sys/devices/pci0000:00/0000:00:18.3/hwmon/');
    metric_put($device.'hwmon7/name','zenpower'); metric_put($device.'hwmon7/power1_label','SVI2_P_Core'); metric_put($device.'hwmon7/power1_input','41200000');
    metric_put($device.'hwmon7/power2_label','SVI2_P_SoC'); metric_put($device.'hwmon7/power2_input','6500000');
    $class=root_path('/sys/class/hwmon/'); metric_link($device.'hwmon7',$class.'hwmon7');
    $id=td_extra_metric_sources()['power'][0]['id'];
    metric_check(td_extra_metrics([])['cpu_watts']===null && td_extra_metrics(['power_sensor'=>$id])['cpu_watts']===41.2,'rails CPU sélectionnables explicitement, jamais additionnés en package');
    rename($device.'hwmon7',$device.'hwmon12'); unlink($class.'hwmon7'); metric_link($device.'hwmon12',$class.'hwmon12');
    metric_check(td_extra_metric_sources()['power'][0]['id']===$id,'ID hwmon stable après renumérotation du pilote');

    metric_fixture();
    $zone=metric_rapl(0,'package-0'); metric_rapl(1,'psys');
    $child=$zone.'/intel-rapl:0:0';
    foreach (['name'=>'core','energy_uj'=>'300000000','max_energy_range_uj'=>'10000000000'] as $file=>$value) metric_put($child.'/'.$file,$value);
    metric_link($child,root_path('/sys/class/powercap/intel-rapl:0:0'));
    metric_rapl(0,'package-0',1500000000,'intel-rapl-mmio');
    $scan=td_metric_power_scan(); $id=$scan['auto'];
    metric_check(count($scan['sources'])===2 && $scan['sources'][$id]['label']==='intel-rapl — package-0','RAPL physique/classe dédupliqué, sous-domaines et psys exclus, MSR avant MMIO');
    $m=td_extra_metrics([]);
    metric_check($m['cpu_watts']===null && $m['power_source']==='intel-rapl — package-0','premier compteur d’énergie indisponible avec source identifiée');
    $cache=json_read(run_dir().'/power.json');
    metric_check(isset($cache['sources'][$id]) && $cache['sources'][$id]['at']<microtime(true),'cache RAM utilisant le temps monotone');
    $cache['sources'][$id]['at']=hrtime(true)/1000000000-1.0; json_write(run_dir().'/power.json',$cache);
    metric_put($zone.'/energy_uj','1050000000'); $m=td_extra_metrics([]);
    metric_check($m['cpu_watts']!==null && $m['cpu_watts']>40 && $m['cpu_watts']<=51,'delta RAPL en watts et cache entier JSON réutilisé');

    // Pure delta checks are deterministic: no waits or real energy measurements.
    $current=$scan['sources'][$id]; $current['_energy']=2000000000.0;
    $previous=['energy'=>1950000000,'range'=>10000000000,'identity'=>$current['_identity'],'at'=>100,'boot'=>'boot'];
    metric_check(td_metric_energy_watts($current,$previous,101,'boot')===50.0,'µJ / secondes vers W exacts');
    $previous['energy']=9990000000; $current['_energy']=40000000.0;
    metric_check(td_metric_energy_watts($current,$previous,101,'boot')===50.0,'retour circulaire du compteur corrigé via max_energy_range_uj');
    $previous['energy']=5000000000; $current['_energy']=10000000.0;
    metric_check(td_metric_energy_watts($current,$previous,101,'boot')===null,'remise à zéro du compteur sans faux pic');
    $previous['energy']=1950000000; $current['_energy']=2000000000.0;
    metric_check(td_metric_energy_watts($current,$previous,111,'boot')===null && td_metric_energy_watts($current,$previous,99,'boot')===null && td_metric_energy_watts($current,$previous,100.01,'boot')===null,'cache périmé, temps inversé et intervalle trop court invalidés');
    metric_check(td_metric_energy_watts($current,$previous,101,'another-boot')===null,'redémarrage invalide le compteur précédent');
    $changed=$current; $changed['_identity'].='replaced';
    metric_check(td_metric_energy_watts($changed,$previous,101,'boot')===null,'source remplacée : nouvelle référence obligatoire');
    $changed=$current; $changed['_range']=12000000000.0;
    metric_check(td_metric_energy_watts($changed,$previous,101,'boot')===null,'changement de plage compteur : mesure indisponible');
    $changed=$current; $changed['_range']=1000000000.0; $changed['_energy']=10000000.0;
    $small=$previous; $small['range']=1000000000; $small['energy']=990000000;
    metric_check(td_metric_energy_watts($changed,$small,101,'boot')===null,'intervalle pouvant masquer plusieurs retours circulaires refusé');
    $current['_energy']=1950000000.0;
    metric_check(td_metric_energy_watts($current,$previous,101,'boot')===0.0,'delta énergie réellement nul conservé');
    $previous['energy']='1950000000';
    metric_check(td_metric_energy_watts($current,$previous,101,'boot')===null,'cache de type invalide rejeté');

    metric_put(run_dir().'/power.json','not JSON');
    metric_check(td_extra_metrics([])['cpu_watts']===null,'cache corrompu réinitialisé sans arrêter le superviseur');
    metric_put($zone.'/energy_uj','unavailable');
    // The equivalent MMIO source takes over with its own initial baseline.
    $m=td_extra_metrics([]);
    metric_check($m['cpu_watts']===null && str_contains($m['power_source'],'mmio'),'repli de fournisseur garde une première mesure indisponible');
    $mmio=root_path('/sys/devices/virtual/powercap/intel-rapl-mmio/intel-rapl-mmio:0'); metric_put($mmio.'/energy_uj','unavailable');
    metric_check(td_extra_metrics([])['cpu_watts']===null && json_read(run_dir().'/power.json')['sources']===[],'compteur illisible efface la référence énergie');

    metric_fixture(); $z0=metric_rapl(0,'package-0'); $z1=metric_rapl(1,'package-1');
    $scan=td_metric_power_scan(); $group=$scan['auto'];
    metric_check(count($scan['sources'])===3 && $scan['sources'][$group]['kind']==='powercap_energy_total','multi-socket : total explicite des seuls packages, sans sous-domaines');
    td_extra_metrics([]); $cache=json_read(run_dir().'/power.json');
    foreach ($cache['sources'] as &$sample) $sample['at']=hrtime(true)/1000000000-1.0;
    unset($sample); json_write(run_dir().'/power.json',$cache);
    metric_put($z0.'/energy_uj','1050000000'); metric_put($z1.'/energy_uj','1075000000');
    $m=td_extra_metrics([]);
    metric_check($m['cpu_watts']!==null && $m['cpu_watts']>110 && $m['cpu_watts']<=127,'multi-socket : somme des deltas de packages indépendants');
    metric_put($z1.'/energy_uj','invalid');
    metric_check(td_extra_metrics([])['cpu_watts']===null,'package manquant : aucun total partiel trompeur');

    // Regression: AMD package energy remains readable while RAPL power-limit
    // controls report enabled=0. These are distinct kernel interfaces.
    metric_fixture(); $zone=metric_rapl(0,'package-0',41777528224);
    metric_put($zone.'/max_energy_range_uj','65532610987');
    metric_put($zone.'/enabled','0');
    $scan=td_metric_power_scan(); $id=$scan['auto'];
    metric_check($scan['sources'][$id]['available'],'RAPL enabled=0 : compteur lisible indépendant des limites de puissance');
    $m=td_extra_metrics([]);
    metric_check($m['cpu_watts']===null && isset(json_read(run_dir().'/power.json')['sources'][$id]),'RAPL enabled=0 : premier échantillon conservé en RAM');
    $cache=json_read(run_dir().'/power.json');
    $cache['sources'][$id]['at']=hrtime(true)/1000000000-2.0;
    json_write(run_dir().'/power.json',$cache);
    metric_put($zone.'/energy_uj','41838690375');
    $m=td_extra_metrics([]);
    metric_check($m['cpu_watts']!==null && $m['cpu_watts']>30 && $m['cpu_watts']<31,'RAPL enabled=0 : progression du compteur convertie en watts');
    metric_check(file_get_contents($zone.'/enabled')==='0','lecture des watts conserve les contrôles RAPL');
    metric_put($zone.'/energy_uj','unreadable');
    metric_check(td_extra_metrics([])['cpu_watts']===null && json_read(run_dir().'/power.json')['sources']===[],'RAPL enabled=0 : compteur illisible reste indisponible');

    echo 'TOTAL '.$metricChecks." contrôles métriques réussis\n";
} catch (Throwable $e) {
    fwrite(STDERR,$e->getMessage()."\n".$e->getTraceAsString()."\n");
    exit(1);
} finally {
    foreach ($metricRoots as $root) metric_drop($root);
    putenv('TD_TEST_ROOT');
}
