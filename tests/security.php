<?php
declare(strict_types=1);
@mkdir('/var/local/emhttp',0700,true);
file_put_contents('/var/local/emhttp/var.ini',"csrf_token=\"native-token\"\n");
$cases=[['GET',[],405],['POST',['csrf_token'=>'wrong','action'=>'start'],403],['POST',['csrf_token'=>'native-token','action'=>'shell'],400]];
foreach($cases as [$method,$post,$expected]) {
 $code='$_SERVER["REQUEST_METHOD"]='.var_export($method,true).';$_POST='.var_export($post,true).';register_shutdown_function(function(){fwrite(STDERR,(string)http_response_code());});require "/work/plugin/api.php";';
 $p=proc_open([PHP_BINARY,'-r',$code],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
 $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);proc_close($p);
 if((int)$err!==$expected || !isset(json_decode($out,true)['error']))throw new RuntimeException('HTTP check failed : '.$out.' '.$err);
 echo 'PASS API method/CSRF/action : HTTP '.$expected."\n";
}
