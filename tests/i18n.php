<?php
// GPL-3.0-or-later. All mutated catalogs/configuration live in a private fixture.
declare(strict_types=1);
$fixture=sys_get_temp_dir().'/td-i18n-'.bin2hex(random_bytes(8));
mkdir($fixture.'/lang',0700,true);
foreach (glob('/work/plugin/lang/*.json') as $path) copy($path,$fixture.'/lang/'.basename($path));
copy('/work/plugin/i18n.php',$fixture.'/i18n.php');
copy('/work/plugin/ThermalrightDigital.page',$fixture.'/ThermalrightDigital.page');
register_shutdown_function(static function()use($fixture){
    $items=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($items as $item) $item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());
    rmdir($fixture);
});
require $fixture.'/i18n.php';
function check(bool $condition,string $name):void {
    if(!$condition)throw new RuntimeException('FAIL i18n: '.$name);
    echo 'PASS i18n: '.$name."\n";
}
mkdir($fixture.'/boot/config/plugins/dynamix',0700,true);
$cfg=$fixture.'/boot/config/plugins/dynamix/dynamix.cfg';
file_put_contents($cfg,"[display]\nlocale=\"fr_FR\"\n");
putenv('TD_TEST_ROOT='.$fixture);
check(td_i18n_locale()==='fr_FR','persistent Unraid language fallback');
$_SESSION=['locale'=>'de_DE'];
check(td_i18n_locale()==='de_DE','current native session overrides persistent language');
$_SESSION['locale']='';
check(td_i18n_locale()==='en_US','native empty English locale overrides non-English config');
check(td_i18n_locale('fr-FR.UTF-8')==='fr_FR','locale spelling and encoding');
check(td_i18n_locale('ja-JP')==='ja_JA' && td_i18n_locale('ko-KR')==='ko_KO' && td_i18n_locale('da-DK')==='da_DA','Unraid irregular language aliases');
check(td_i18n_locale('zh-Hant-HK')==='zh_TW' && td_i18n_locale('zh-Hans-CN')==='zh_CN' && td_i18n_locale('pt')==='pt_PT','script and regional fallback');
check(td_i18n_locale('../../etc/passwd')==='en_US' && td_i18n_locale('xx_YY')==='en_US','unknown and path-like locales never become filenames');
check(count(td_i18n_languages())===27,'all 27 official Unraid languages including English');
$english=td_i18n_context('en_US');
foreach(td_i18n_languages() as $code=>$language){
    $context=td_i18n_context($code);
    check($context['locale']===$code && $context['name']===$language['name'] && $context['direction']===($code==='ar_AR'?'rtl':'ltr') && count($context['messages'])===count($english['messages']) && ($code==='en_US'||$context['messages']['Save']!=='Save'),'complete context for '.$code);
}
check(file_get_contents($cfg)==="[display]\nlocale=\"fr_FR\"\n",'selecting languages never rewrites Unraid configuration');
$frPath=$fixture.'/lang/fr_FR.json';$original=file_get_contents($frPath);$broken=json_decode($original,true);
unset($broken['Save']);$broken['Start']='';$broken['Stop']=42;$broken['Frames sent — {model}']='Trames transmises';$broken['Unknown key']='Injected';
file_put_contents($frPath,json_encode($broken));
$context=td_i18n_context('fr_FR');
check($context['messages']['Save']==='Save'&&$context['messages']['Start']==='Start'&&$context['messages']['Stop']==='Stop','missing, empty and non-string entries fall back individually');
check($context['messages']['Frames sent — {model}']==='Frames sent — {model}'&&!isset($context['messages']['Unknown key']),'placeholder mismatches and unknown keys are rejected');
file_put_contents($frPath,'{broken');
check(td_i18n_context('fr_FR')['messages']===$english['messages'],'malformed JSON falls back to English');
unlink($frPath);
check(td_i18n_context('fr_FR')['messages']===$english['messages'],'missing catalog falls back to English');
file_put_contents($frPath,$original);
$GLOBALS['tdI18n']=td_i18n_context('fr_FR');
check(td_t('Frames sent — {model}',['model'=>'LF12'])==='Trames transmises — LF12','named parameter substitution');
check(td_t('A new future message')==='A new future message','unknown message uses source text');
check(!str_contains(td_h('Frames sent — {model}',['model'=>'<img src=x onerror="1">']),'<' ),'parameter HTML is escaped');
$malicious=['messages'=>['Save'=>'</script><script>alert("x")</script>&\'']];
$encoded=td_i18n_json($malicious);
check(!str_contains($encoded,'<')&&!str_contains($encoded,'>')&&json_decode($encoded,true)===$malicious,'inline JSON prevents script breakout and preserves exact content');
ob_start();(static function($fixture){$locale='fr_FR';include $fixture.'/ThermalrightDigital.page';})($fixture);$html=ob_get_clean();
check(str_contains($html,'lang="fr-FR"')&&str_contains($html,'dir="ltr"')&&str_contains($html,'Enregistrer')&&str_contains($html,'Extinction programmée'),'actual French page renders correctly in native include scope');
ob_start();(static function($fixture){$locale='ar_AR';include $fixture.'/ThermalrightDigital.page';})($fixture);$html=ob_get_clean();
check(str_contains($html,'lang="ar-AR"')&&str_contains($html,'dir="rtl"')&&str_contains($html,'حفظ'),'actual Arabic page scopes right-to-left rendering');
// Simulate the native plain-text/Markdown translation API without modifying it.
if (!function_exists('_')) {
    function _($source){return match($source){'Save'=>'<b>Native &amp; local</b>','Frames sent — {model}'=>'Broken without model',default=>$source};}
    function parse_plugin($name){}
    $GLOBALS['locale']='fr_FR';$native=td_i18n_context('fr_FR');
    check($native['messages']['Save']==='Native & local','native language-pack override is decoded to plain text');
    check($native['messages']['Frames sent — {model}']==='Trames transmises — {model}','invalid native placeholders retain bundled translation');
    check(td_i18n_context('de_DE')['messages']['Save']!=='Native & local','native override cannot leak into another locale');
}
