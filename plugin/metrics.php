<?php
/* Host-only, read-only CPU telemetry. GPL-3.0-or-later. */
declare(strict_types=1);

// Linux ABI: cpufreq values are kHz, hwmon power is microWatt and powercap
// energy is microJoule. No limits, TDP values, voltages or GPU readings are used.
// https://docs.kernel.org/admin-guide/pm/cpufreq.html
// https://docs.kernel.org/hwmon/sysfs-interface.html
// https://docs.kernel.org/power/powercap/powercap.html

function td_metric_sys_real(string $path): ?string {
    $real=realpath($path); $sys=realpath(root_path('/sys'));
    return $real!==false && $sys!==false && str_starts_with($real,$sys.'/') ? $real : null;
}
function td_metric_text(string $path): ?string {
    if (td_metric_sys_real($path)===null) return null;
    $text=@file_get_contents($path,false,null,0,256);
    return $text===false ? null : trim($text);
}
function td_metric_uint(string $path, float $maximum=9007199254740991.0): ?float {
    $text=td_metric_text($path);
    if ($text===null || !preg_match('/^[0-9]{1,16}$/D',$text)) return null;
    $n=(float)$text;
    return is_finite($n) && $n<=$maximum ? $n : null;
}
function td_metric_stable(string $path): string {
    // Test fixtures use the same identities without depending on their /tmp root.
    $prefix=root_path('/');
    if ($prefix!=='/' && str_starts_with($path,$prefix)) $path=substr($path,strlen($prefix)-1);
    return preg_replace('~/hwmon/hwmon[0-9]+$~','',$path);
}

function td_metric_frequency_policies(): array {
    $out=[]; $seen=[];
    foreach (glob(root_path('/sys/devices/system/cpu/cpufreq/policy*')) ?: [] as $dir) {
        if (!preg_match('/^policy[0-9]+$/D',basename($dir))) continue;
        $real=td_metric_sys_real($dir);
        if ($real===null || isset($seen[$real])) continue;
        $seen[$real]=true;
        $cpus=td_metric_text($dir.'/affected_cpus');
        if ($cpus==='') continue; // The policy has no online CPU.
        $value=td_metric_uint($dir.'/cpuinfo_cur_freq',20000000);
        $kind='measured'; $attribute='cpuinfo_cur_freq';
        if ($value===null || $value<=0) {
            $value=td_metric_uint($dir.'/scaling_cur_freq',20000000);
            $kind='requested'; $attribute='scaling_cur_freq';
        }
        if ($value!==null && $value<=0) $value=null;
        $out[]=['id'=>basename($dir),'cpus'=>$cpus,'available'=>$value!==null,
                'kind'=>$value===null ? null : $kind,'source'=>$value===null ? null : $attribute,
                'mhz'=>$value===null ? null : $value/1000];
    }
    usort($out,fn(array $a,array $b): int => strnatcmp($a['id'],$b['id']));
    return $out;
}
function td_metric_frequency(array $policies, string $mode): array {
    $valid=array_values(array_filter($policies,fn(array $p): bool => $p['available']));
    $mode=$mode==='average' ? 'average' : 'max';
    if (!$valid) return ['cpu_mhz'=>null,'frequency_source'=>null,'frequency_kind'=>null,'frequency_mode'=>$mode];
    $values=array_column($valid,'mhz');
    $requested=in_array('requested',array_column($valid,'kind'),true);
    $attributes=array_values(array_unique(array_column($valid,'source')));
    $aggregation=$mode==='average' ? 'arithmetic mean of unique active policies' : 'maximum of unique active policies';
    return ['cpu_mhz'=>$mode==='average' ? array_sum($values)/count($values) : max($values),
            'frequency_source'=>'cpufreq — '.$aggregation.' ; '.implode(' + ',$attributes),
            // A mixture is conservatively marked requested, never all measured.
            'frequency_kind'=>$requested ? 'requested' : 'measured','frequency_mode'=>$mode];
}

function td_metric_hwmon_power(): array {
    $out=[]; $seen=[];
    foreach (glob(root_path('/sys/class/hwmon/hwmon*')) ?: [] as $dir) {
        $real=td_metric_sys_real($dir);
        if ($real===null || isset($seen[$real])) continue;
        $seen[$real]=true; $name=td_metric_text($dir.'/name') ?? '';
        // CPU drivers only. A motherboard/GPU channel merely labelled CPU does
        // not establish that it measures CPU power, so it is not a candidate.
        if (!preg_match('/^(fam15h_power|zenpower|k10temp|coretemp|intel_rapl|peci_cpupower(?:\.cpu[0-9]+)?)$/D',$name)) continue;
        $channels=[];
        foreach (glob($dir.'/power*') ?: [] as $path) {
            if (preg_match('/^(power[0-9]+)_(average|input)$/D',basename($path),$m)) $channels[$m[1]][$m[2]]=$path;
        }
        ksort($channels,SORT_NATURAL);
        foreach ($channels as $channel=>$paths) {
            $label=td_metric_text($dir.'/'.$channel.'_label') ?: $channel;
            $package=(bool)preg_match('/^(?:CPU[ _-]?)?package(?:[ _-]?(?:id[ _-]?)?[0-9]+)?$/iD',$label);
            // fam15h_power power1 covers the entire package (kernel ABI).
            if ($name==='fam15h_power' && $channel==='power1') $package=true;
            if (preg_match('/^peci_cpupower(?:\.cpu[0-9]+)?$/D',$name) && $channel==='power1') $package=true;
            if ($package && $label===$channel) $label='CPU package';
            $enabled=td_metric_text($dir.'/'.$channel.'_enable');
            $value=null; $attribute=isset($paths['average']) ? 'average' : 'input';
            if ($enabled!=='0') foreach (['average','input'] as $kind) {
                if (!isset($paths[$kind])) continue;
                $raw=td_metric_uint($paths[$kind],2000000000);
                if ($raw!==null) { $value=$raw/1000000; $attribute=$kind; break; }
            }
            $id=substr(hash('sha256','hwmon-power|'.td_metric_stable($real).'|'.$name.'|'.$channel),0,24);
            $out[$id]=['id'=>$id,'label'=>$name.' — '.$label.($package ? ' (CPU package)' : ' (CPU component)'),
                'kind'=>'hwmon_'.$attribute,'available'=>$value!==null,
                '_type'=>'direct','_value'=>$value,'_package'=>$package];
        }
    }
    return $out;
}

function td_metric_powercap(): array {
    $queue=[root_path('/sys/class/powercap')]; $seen=[]; $out=[];
    // Both flattened class symlinks and the physical hierarchy occur on Linux.
    // Only RAPL directories are followed; device/subsystem symlink loops are not.
    for ($i=0;$i<count($queue) && $i<512;$i++) {
        $dir=$queue[$i]; $real=td_metric_sys_real($dir);
        if ($real===null || isset($seen[$real])) continue;
        $seen[$real]=true;
        foreach (glob($dir.'/*') ?: [] as $child) {
            if (preg_match('/^(?:intel|amd)-rapl(?:-mmio)?(?::[0-9]+)*$/D',basename($child)) && is_dir($child)) $queue[]=$child;
        }
        // A package has one zone index. Core/DRAM/uncore child zones are excluded.
        if (!preg_match('/^((?:intel|amd)-rapl)(-mmio)?:[0-9]+$/D',basename($real),$match)) continue;
        $name=td_metric_text($dir.'/name') ?? '';
        if (!preg_match('/^package-([0-9]+)$/D',$name,$package)) continue;
        $energy=td_metric_uint($dir.'/energy_uj');
        $range=td_metric_uint($dir.'/max_energy_range_uj');
        // RAPL enabled controls power limiting (PL_ENABLE), not energy
        // telemetry. AMD may expose enabled=0 while energy_uj keeps counting.
        // Never change those controls to read a valid energy counter.
        // https://docs.kernel.org/power/powercap/powercap.html
        $stat=@stat($dir.'/energy_uj');
        $identity=td_metric_stable($real).'|'.($stat['dev']??'').'|'.($stat['ino']??'');
        $max=td_metric_uint($dir.'/max_power_range_uw',2000000000);
        // The ceiling rejects corrupt samples and ambiguous multi-wrap windows;
        // it is a validity bound, never used as the displayed watt measurement.
        $maxWatts=$max!==null && $max>0 ? $max/1000000 : 2000.0;
        $id=substr(hash('sha256','powercap-energy|'.td_metric_stable($real).'|'.$name),0,24);
        $out[$id]=['id'=>$id,'label'=>$match[1].($match[2]??'').' — '.$name,
            'kind'=>'powercap_energy','available'=>$energy!==null && $range!==null && $range>0 && $energy<=$range,
            '_type'=>'energy','_energy'=>$energy,'_range'=>$range,'_max_watts'=>$maxWatts,'_identity'=>$identity,
            '_package'=>(int)$package[1],'_rank'=>empty($match[2]) ? 0 : 1];
    }
    return $out;
}
function td_metric_power_scan(): array {
    $sources=td_metric_hwmon_power()+td_metric_powercap(); $packages=[];
    foreach ($sources as $id=>$source) if ($source['_type']==='energy') {
        $n=$source['_package']; $old=$packages[$n]??null;
        if ($old===null || ($source['available'] && !$sources[$old]['available']) ||
            ($source['available']===$sources[$old]['available'] && $source['_rank']<$sources[$old]['_rank'])) $packages[$n]=$id;
    }
    ksort($packages,SORT_NUMERIC); $auto=null;
    if ($packages) {
        $members=array_values($packages);
        if (count($members)===1) $auto=$members[0];
        else {
            $id=substr(hash('sha256','powercap-total|'.implode('|',$members)),0,24);
            $available=true;
            foreach ($members as $member) $available=$available && $sources[$member]['available'];
            $sources[$id]=['id'=>$id,'label'=>'CPU — powercap package total ('.count($members).' sockets)',
                'kind'=>'powercap_energy_total','available'=>$available,'_type'=>'group','_members'=>$members];
            $auto=$id;
        }
    }
    if ($auto===null || !$sources[$auto]['available']) {
        $direct=array_filter($sources,fn(array $s): bool => $s['_type']==='direct' && $s['_package'] && $s['available']);
        // Multiple package channels may overlap. Never guess or add CPU rails.
        if (count($direct)===1) $auto=array_key_first($direct);
    }
    return ['sources'=>$sources,'auto'=>$auto];
}

function td_extra_metric_sources(): array {
    $power=[];
    foreach (td_metric_power_scan()['sources'] as $source) $power[]=array_intersect_key($source,array_flip(['id','label','kind','available']));
    $policies=td_metric_frequency_policies();
    return ['power'=>$power,'frequency'=>[
        'available'=>(bool)array_filter($policies,fn(array $p): bool => $p['available']),
        'policies'=>$policies,'aggregation'=>[
            'max'=>'Maximum of unique active policies',
            'average'=>'Arithmetic mean of unique active policies, without weighting by core count'],
        'fallback'=>'scaling_cur_freq: requested frequency, which may differ from actual frequency']];
}

function td_metric_energy_watts(array $current, ?array $previous, float $now, string $boot): ?float {
    if (!$current['available'] || $previous===null || ($previous['boot']??null)!==$boot ||
        ($previous['identity']??null)!==$current['_identity'] ||
        (!is_float($previous['range']??null) && !is_int($previous['range']??null)) ||
        (float)$previous['range']!==$current['_range']) return null;
    $before=$previous['energy']??null; $at=$previous['at']??null;
    if ((!is_float($before) && !is_int($before)) || (!is_float($at) && !is_int($at)) ||
        !is_finite((float)$before) || !is_finite((float)$at) || $before<0 || $before>$current['_range']) return null;
    $elapsed=$now-$at;
    // Longer gaps could hide multiple wraps. A reset/reboot or concurrent rapid
    // invocation must establish a fresh baseline rather than report a spike.
    if ($elapsed<0.1 || $elapsed>10 || $elapsed*$current['_max_watts']*1000000>=$current['_range']) return null;
    $delta=$current['_energy']-$before;
    if ($delta<0) {
        if ($before<$current['_range']*0.75 || $current['_energy']>$current['_range']*0.25) return null;
        $delta+=$current['_range'];
    }
    $watts=$delta/$elapsed/1000000;
    return is_finite($watts) && $watts>=0 && $watts<=$current['_max_watts'] ? $watts : null;
}
function td_metric_power(array $scan, string $selection): array {
    $id=$selection==='auto' ? $scan['auto'] : $selection;
    $source=is_string($id) && preg_match('/^[a-f0-9]{24}$/D',$id) ? ($scan['sources'][$id]??null) : null;
    if ($selection==='off') $source=null;
    $now=hrtime(true)/1000000000;
    $boot=trim((string)@file_get_contents(root_path('/proc/sys/kernel/random/boot_id')));
    try { $cache=json_read(run_dir().'/power.json'); } catch (Throwable $e) { $cache=[]; }
    $previous=($cache['version']??null)===1 && ($cache['selection']??null)===$id ? ($cache['sources']??[]) : [];
    if (!is_array($previous)) $previous=[];
    $next=[]; $watts=null;
    if ($source!==null && $source['_type']==='direct') $watts=$source['_value'];
    elseif ($source!==null) {
        $members=$source['_type']==='group' ? $source['_members'] : [$id]; $values=[];
        foreach ($members as $member) {
            $s=$scan['sources'][$member];
            $p=$previous[$member]??null;
            $values[]=td_metric_energy_watts($s,is_array($p) ? $p : null,$now,$boot);
            if ($s['available']) $next[$member]=['energy'=>$s['_energy'],'range'=>$s['_range'],'identity'=>$s['_identity'],'at'=>$now,'boot'=>$boot];
        }
        // A partial multi-package result is unavailable, never a smaller total.
        if (!in_array(null,$values,true)) $watts=array_sum($values);
    }
    // Cache only in Unraid RAM. Unavailable/disappeared/disabled sources clear
    // their baseline, so rediscovery always starts with an unavailable sample.
    json_write(run_dir().'/power.json',['version'=>1,'selection'=>$source===null ? null : $id,'sources'=>$next]);
    return ['cpu_watts'=>$watts,'power_source'=>$source['label']??null];
}
function td_extra_metrics(array $cfg): array {
    $frequency=td_metric_frequency(td_metric_frequency_policies(),($cfg['frequency_mode']??'max')==='average' ? 'average' : 'max');
    $power=td_metric_power(td_metric_power_scan(),is_string($cfg['power_sensor']??null) ? $cfg['power_sensor'] : 'auto');
    return $frequency+$power;
}
