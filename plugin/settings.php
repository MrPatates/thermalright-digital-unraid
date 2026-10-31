<?php
/* Settings schema shared by native UI, CLI and host sampler. GPL-3.0+. */
declare(strict_types=1);

function td_rgb_defaults(): array {
    return ['mode'=>'static','color'=>'#00b4ff','brightness'=>100,'period'=>6.0,'target'=>'all',
            'temperature_zones'=>['temperature','load','power','frequency','decoration'],
            'thresholds'=>[40.0,60.0,80.0],'temperature_colors'=>['#00b4ff','#00ff00','#ff9900','#ff0000'],
            'smoothing'=>3.0,'zone_colors'=>['temperature'=>'#00b4ff','load'=>'#00b4ff','power'=>'#00b4ff','frequency'=>'#00b4ff','decoration'=>'#00b4ff']];
}
function td_settings_defaults(): array {
    return ['autostart'=>false,'interval'=>1,'sensor'=>'auto','frequency_mode'=>'max','power_sensor'=>'auto','rgb'=>td_rgb_defaults()];
}
function td_setting_number(mixed $value,float $low,float $high,string $label): float {
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value<$low || $value>$high) fail('Invalid value : '.$label);
    return (float)$value;
}
function td_setting_color(mixed $value): string {
    if (!is_string($value) || !preg_match('/^#[a-fA-F0-9]{6}$/D',$value)) fail('Invalid RGB color: #rrggbb required');
    return strtolower($value);
}
function td_settings_normalize(array $data,bool $request=false): array {
    $defaults=td_settings_defaults();
    if ($request && (array_diff(array_keys($data),array_keys($defaults)) || !isset($data['autostart'],$data['interval'],$data['sensor']))) fail('Incomplete or unknown settings');
    $out=array_replace($defaults,array_intersect_key($data,$defaults));
    if (!is_bool($out['autostart']) || !is_int($out['interval']) || $out['interval']<1 || $out['interval']>60) fail('Invalid autostart or interval');
    if (!is_string($out['sensor']) || !preg_match('/^(auto|[a-f0-9]{24})$/D',$out['sensor'])) fail('Invalid CPU sensor ID');
    if (!is_string($out['frequency_mode']) || !in_array($out['frequency_mode'],['max','average'],true)) fail('Invalid frequency mode');
    if (!is_string($out['power_sensor']) || !preg_match('/^(auto|off|[a-f0-9]{24})$/D',$out['power_sensor'])) fail('Invalid power sensor ID');
    $rgb=array_key_exists('rgb',$data)?$data['rgb']:td_rgb_defaults();
    if (!is_array($rgb) || array_diff(array_keys($rgb),array_keys(td_rgb_defaults()))) fail('Invalid or unknown RGB settings');
    $rgb=array_replace(td_rgb_defaults(),$rgb);
    if (!is_string($rgb['mode']) || !in_array($rgb['mode'],['static','zones','temperature','breathing','rainbow'],true)) fail('Invalid RGB mode');
    if (!is_string($rgb['target']) || !in_array($rgb['target'],['all','digits','decoration'],true)) fail('Invalid RGB target');
    $rgb['color']=td_setting_color($rgb['color']);
    if (!is_int($rgb['brightness']) || $rgb['brightness']<0 || $rgb['brightness']>100) fail('Invalid brightness (0–100)');
    $rgb['period']=td_setting_number($rgb['period'],1,60,'RGB period');
    $rgb['smoothing']=td_setting_number($rgb['smoothing'],0,30,'RGB smoothing');
    if (!is_array($rgb['thresholds']) || !array_is_list($rgb['thresholds']) || count($rgb['thresholds'])!==3) fail('Three temperature thresholds are required');
    $rgb['thresholds']=array_map(fn($v)=>td_setting_number($v,0,125,'temperature threshold'),$rgb['thresholds']);
    if ($rgb['thresholds'][0]>=$rgb['thresholds'][1] || $rgb['thresholds'][1]>=$rgb['thresholds'][2]) fail('Thresholds must be strictly increasing');
    if (!is_array($rgb['temperature_colors']) || !array_is_list($rgb['temperature_colors']) || count($rgb['temperature_colors'])!==4) fail('Four temperature colors are required');
    $rgb['temperature_colors']=array_map('td_setting_color',$rgb['temperature_colors']);
    $zones=td_rgb_defaults()['zone_colors'];
    if (!is_array($rgb['temperature_zones']) || !array_is_list($rgb['temperature_zones']) || count($rgb['temperature_zones'])<1 || count($rgb['temperature_zones'])>count($zones)) fail('Select at least one temperature category');
    foreach ($rgb['temperature_zones'] as $zone) if (!is_string($zone) || !array_key_exists($zone,$zones)) fail('Invalid temperature category');
    if (count(array_unique($rgb['temperature_zones']))!==count($rgb['temperature_zones'])) fail('Duplicate temperature categories');
    if (!is_array($rgb['zone_colors']) || array_diff(array_keys($rgb['zone_colors']),array_keys($zones))) fail('Invalid zone colors');
    $rgb['zone_colors']=array_map('td_setting_color',array_replace($zones,$rgb['zone_colors']));
    $out['rgb']=$rgb;
    return $out;
}
