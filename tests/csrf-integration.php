<?php
// GPL-3.0-or-later. Execute the separately licensed, unchanged Unraid prepend.
declare(strict_types=1);
@mkdir('/var/local/emhttp',0700,true);
@mkdir('/usr/local/emhttp',0700,true);
if (!file_exists('/usr/local/emhttp/state')) symlink('/var/local/emhttp','/usr/local/emhttp/state');
if (file_exists('/etc/localtime') || is_link('/etc/localtime')) unlink('/etc/localtime');
symlink('/usr/share/zoneinfo/UTC','/etc/localtime');
file_put_contents('/var/local/emhttp/var.ini',"csrf_token=\"native-token\"\n");
$api=$argv[1]??'/work/plugin/api.php';
$cases=[
 ['native token consumed, independent header valid',['csrf_token'=>'native-token','action'=>'logs'],'native-token',200],
 ['wrong native token cannot bypass prepend',['csrf_token'=>'wrong','action'=>'logs'],'native-token',403],
 ['missing native token cannot bypass prepend',['action'=>'logs'],'native-token',403],
 ['wrong independent header rejected',['csrf_token'=>'native-token','action'=>'logs'],'wrong',403],
 ['missing independent header after native consumption rejected',['csrf_token'=>'native-token','action'=>'logs'],null,403],
 ['unknown action rejected after native authentication',['csrf_token'=>'native-token','action'=>'shell'],'native-token',400],
];
foreach ($cases as [$name,$post,$header,$expected]) {
 $code='$_SERVER["REQUEST_METHOD"]="POST";$_SERVER["SCRIPT_NAME"]="/plugins/thermalright-digital/api.php";$_SERVER["REQUEST_URI"]=$_SERVER["SCRIPT_NAME"];$_POST='.var_export($post,true).';';
 if ($header!==null) $code.='$_SERVER["HTTP_X_THERMALRIGHT_CSRF_TOKEN"]='.var_export($header,true).';';
 $code.='register_shutdown_function(function(){fwrite(STDERR,"HTTP=".(http_response_code()?:200));});';
 $code.='require "/work/tests/fixtures/unraid-local-prepend.php";if(isset($_POST["csrf_token"]))throw new RuntimeException("Native prepend did not consume the token");require '.var_export($api,true).';';
 $p=proc_open([PHP_BINARY,'-d','short_open_tag=1','-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);proc_close($p);
 preg_match('/HTTP=(\d+)/',$err,$matches);$actual=(int)($matches[1]??0);
 $json=json_decode(trim($out),true);
 if($actual!==$expected || !is_array($json) || ($expected===200 && !array_key_exists('logs',$json)) || ($expected!==200 && !array_key_exists('error',$json))) {
  fwrite(STDERR,'FAIL '.$name.'; attendu '.$expected.', reçu '.$actual.'; '.$out.' '.$err."\n");exit(1);
 }
 echo 'PASS native CSRF : '.$name.' (HTTP '.$actual.")\n";
}
// Render in a scope with no $var: the page must obtain a non-empty native token.
ob_start();
(static function(){include '/work/plugin/ThermalrightDigital.page';})();
$html=ob_get_clean();
if(!preg_match('/name="csrf_token" value="([^"]+)"/',$html,$match) || $match[1]!=='native-token')throw new RuntimeException('Jeton absent du rendu de page');
echo "PASS native CSRF : rendu de page sans variable héritée\n";
