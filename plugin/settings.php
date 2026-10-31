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
    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || $value<$low || $value>$high) fail('Valeur invalide : '.$label);
    return (float)$value;
}
function td_setting_color(mixed $value): string {
    if (!is_string($value) || !preg_match('/^#[a-fA-F0-9]{6}$/D',$value)) fail('Couleur RGB invalide, format #rrggbb requis');
    return strtolower($value);
}
function td_settings_normalize(array $data,bool $request=false): array {
    $defaults=td_settings_defaults();
    if ($request && (array_diff(array_keys($data),array_keys($defaults)) || !isset($data['autostart'],$data['interval'],$data['sensor']))) fail('Paramètres incomplets ou inconnus');
    $out=array_replace($defaults,array_intersect_key($data,$defaults));
    if (!is_bool($out['autostart']) || !is_int($out['interval']) || $out['interval']<1 || $out['interval']>60) fail('Démarrage automatique ou intervalle invalide');
    if (!is_string($out['sensor']) || !preg_match('/^(auto|[a-f0-9]{24})$/D',$out['sensor'])) fail('Identifiant de sonde CPU invalide');
    if (!is_string($out['frequency_mode']) || !in_array($out['frequency_mode'],['max','average'],true)) fail('Mode de fréquence invalide');
    if (!is_string($out['power_sensor']) || !preg_match('/^(auto|off|[a-f0-9]{24})$/D',$out['power_sensor'])) fail('Identifiant de sonde de puissance invalide');
    $rgb=array_key_exists('rgb',$data)?$data['rgb']:td_rgb_defaults();
    if (!is_array($rgb) || array_diff(array_keys($rgb),array_keys(td_rgb_defaults()))) fail('Paramètres RGB invalides ou inconnus');
    $rgb=array_replace(td_rgb_defaults(),$rgb);
    if (!is_string($rgb['mode']) || !in_array($rgb['mode'],['static','zones','temperature','breathing','rainbow'],true)) fail('Mode RGB invalide');
    if (!is_string($rgb['target']) || !in_array($rgb['target'],['all','digits','decoration'],true)) fail('Zone RGB invalide');
    $rgb['color']=td_setting_color($rgb['color']);
    if (!is_int($rgb['brightness']) || $rgb['brightness']<0 || $rgb['brightness']>100) fail('Luminosité invalide (0 à 100)');
    $rgb['period']=td_setting_number($rgb['period'],1,60,'période RGB');
    $rgb['smoothing']=td_setting_number($rgb['smoothing'],0,30,'lissage RGB');
    if (!is_array($rgb['thresholds']) || !array_is_list($rgb['thresholds']) || count($rgb['thresholds'])!==3) fail('Trois seuils de température sont requis');
    $rgb['thresholds']=array_map(fn($v)=>td_setting_number($v,0,125,'seuil de température'),$rgb['thresholds']);
    if ($rgb['thresholds'][0]>=$rgb['thresholds'][1] || $rgb['thresholds'][1]>=$rgb['thresholds'][2]) fail('Les seuils doivent être strictement croissants');
    if (!is_array($rgb['temperature_colors']) || !array_is_list($rgb['temperature_colors']) || count($rgb['temperature_colors'])!==4) fail('Quatre couleurs de température sont requises');
    $rgb['temperature_colors']=array_map('td_setting_color',$rgb['temperature_colors']);
    $zones=td_rgb_defaults()['zone_colors'];
    if (!is_array($rgb['temperature_zones']) || !array_is_list($rgb['temperature_zones']) || count($rgb['temperature_zones'])<1 || count($rgb['temperature_zones'])>count($zones)) fail('Choisir au moins une catégorie pour la température');
    foreach ($rgb['temperature_zones'] as $zone) if (!is_string($zone) || !array_key_exists($zone,$zones)) fail('Catégorie de température invalide');
    if (count(array_unique($rgb['temperature_zones']))!==count($rgb['temperature_zones'])) fail('Catégories de température en double');
    if (!is_array($rgb['zone_colors']) || array_diff(array_keys($rgb['zone_colors']),array_keys($zones))) fail('Couleurs des zones invalides');
    $rgb['zone_colors']=array_map('td_setting_color',array_replace($zones,$rgb['zone_colors']));
    $out['rgb']=$rgb;
    return $out;
}
