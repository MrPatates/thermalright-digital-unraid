<?php
/* Bundled UI catalogs using Unraid's selected locale. GPL-3.0-or-later. */
declare(strict_types=1);

function td_i18n_languages(): array {
    static $languages;
    return $languages??=json_decode((string)file_get_contents(__DIR__.'/lang/languages.json'),true,16,JSON_THROW_ON_ERROR);
}
function td_i18n_locale(?string $selected=null): string {
    if ($selected===null && array_key_exists('locale',$_SESSION??[])) $selected=is_string($_SESSION['locale'])?$_SESSION['locale']:'';
    if ($selected===null) {
        $root=PHP_SAPI==='cli'?(getenv('TD_TEST_ROOT')?:''):'';
        $cfg=@parse_ini_file($root.'/boot/config/plugins/dynamix/dynamix.cfg',true,INI_SCANNER_RAW) ?: [];
        $selected=is_string($cfg['display']['locale']??null)?$cfg['display']['locale']:'';
    }
    $selected=str_replace('-','_',explode('.',$selected,2)[0]);
    $aliases=['ja_JP'=>'ja_JA','ko_KR'=>'ko_KO','da_DK'=>'da_DA','nb_NO'=>'no_NO','ca_ES'=>'ca_CA','bn_BD'=>'bn_BN','zh_Hans'=>'zh_CN','zh_Hant'=>'zh_TW','zh_HK'=>'zh_TW'];
    if (str_starts_with($selected,'zh_Hant_')) $selected='zh_TW';
    if (str_starts_with($selected,'zh_Hans_')) $selected='zh_CN';
    $selected=$aliases[$selected]??$selected;
    $languages=td_i18n_languages();
    if (isset($languages[$selected])) return $selected;
    $base=strtolower(explode('_',$selected,2)[0]);
    if ($base==='pt') return 'pt_PT';
    if ($base==='zh') return 'zh_CN';
    foreach ($languages as $code=>$data) if (strtolower(substr($code,0,2))===$base) return $code;
    return 'en_US';
}
function td_i18n_context(?string $selected=null): array {
    $locale=td_i18n_locale($selected);
    $english=json_decode((string)file_get_contents(__DIR__.'/lang/en_US.json'),true,32,JSON_THROW_ON_ERROR);
    $messages=$english;
    if ($locale!=='en_US') {
        try {
            $catalog=json_decode((string)@file_get_contents(__DIR__.'/lang/'.$locale.'.json'),true,32,JSON_THROW_ON_ERROR);
            foreach ($english as $key=>$value) if (is_string($catalog[$key]??null) && $catalog[$key]!=='' && td_i18n_parameters($key)===td_i18n_parameters($catalog[$key])) $messages[$key]=$catalog[$key];
        } catch (Throwable $e) { error_log('Thermalright translation '.$locale.': '.$e->getMessage()); }
    }
    // Honor official language-pack translations when Unraid has loaded them.
    // No core language files/caches are installed or modified by this plugin.
    if (function_exists('parse_plugin') && function_exists('_') && isset($GLOBALS['locale']) && td_i18n_locale((string)$GLOBALS['locale'])===$locale) {
        foreach ($english as $source=>$value) {
            $native=html_entity_decode(strip_tags(_($source)),ENT_QUOTES|ENT_HTML5,'UTF-8');
            if ($native!==$source && $native!=='' && td_i18n_parameters($source)===td_i18n_parameters($native)) $messages[$source]=$native;
        }
    }
    return ['locale'=>$locale,'name'=>td_i18n_languages()[$locale]['name'],'direction'=>td_i18n_languages()[$locale]['direction'],'messages'=>$messages];
}
function td_i18n_parameters(string $text): array {
    preg_match_all('/\{[A-Za-z0-9_]+\}/',$text,$matches);
    $keys=array_values(array_unique($matches[0]));sort($keys);return $keys;
}
function td_t(string $source,array $parameters=[]): string {
    global $tdI18n;
    $text=$tdI18n['messages'][$source]??$source;
    $replace=[];
    foreach ($parameters as $key=>$value) $replace['{'.$key.'}']=(string)$value;
    return strtr($text,$replace);
}
function td_h(string $source,array $parameters=[]): string {
    return htmlspecialchars(td_t($source,$parameters),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
function td_i18n_json(array $context): string {
    return json_encode($context,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
}
