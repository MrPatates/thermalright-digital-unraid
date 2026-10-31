<?php
/* Served ONLY through Unraid's authenticated nginx/PHP location. */
declare(strict_types=1);
// Buffer this endpoint's output so warnings/library output cannot corrupt JSON.
$tdApiBuffer=ob_get_level()+1;
ob_start();
$tdApiResponded=false;
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
function td_json(array $data,int $code=200): never {
    global $tdApiBuffer,$tdApiResponded;
    $body=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
    while (ob_get_level()>=$tdApiBuffer) ob_end_clean();
    $tdApiResponded=true;
    http_response_code($code);
    echo $body;
    exit;
}
function td_error(int $code,string $message): never { td_json(['error'=>$message],$code); }
register_shutdown_function(function(): void {
    global $tdApiResponded;
    if ($tdApiResponded) return;
    $last=error_get_last();
    if ($last && in_array($last['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR],true)) {
        td_error(500,'Erreur PHP interne dans '.basename($last['file']).':'.$last['line'].'. Consulter le journal PHP du serveur.');
    }
});
if ($_SERVER['REQUEST_METHOD']!=='POST') td_error(405,'POST requis');
// Unraid's local_prepend.php validates and UNSETS $_POST['csrf_token'].
// Keep sending that native form field, and independently verify the same
// native token from our namespaced header, which the prepend leaves intact.
// The POST fallback supports direct validation without the native prepend.
$tdVars=@parse_ini_file('/var/local/emhttp/var.ini',false,INI_SCANNER_RAW) ?: [];
$expected=$tdVars['csrf_token']??'';
$provided=$_SERVER['HTTP_X_THERMALRIGHT_CSRF_TOKEN']??($_POST['csrf_token']??'');
if (!is_string($provided) || !is_string($expected) || $expected==='' || !hash_equals($expected,$provided)) td_error(403,'Jeton CSRF invalide');
$action=$_POST['action']??'';
if (!is_string($action) || !in_array($action,['status','logs','diagnostic','start','stop','restart','settings','legacy-stop','display-test'],true)) td_error(400,'Action invalide');
try {
    require __DIR__.'/control.php';
    if ($action==='status') $data=status();
    elseif ($action==='logs') $data=['logs'=>logs()];
    elseif ($action==='diagnostic') $data=['status'=>status(),'inventory'=>inventory(),'residuals'=>residuals(),'logs'=>logs()];
    else {
        $args=[PHP_BINARY,__DIR__.'/control.php',$action];
        if ($action==='settings') {
            $settings=$_POST['settings']??'';
            if (!is_string($settings) || strlen($settings)>4096) td_error(400,'Paramètres invalides');
            $args[]=$settings;
        }
        // FPM's PHP_BINARY may be php-fpm; use the native Unraid CLI.
        $args[0]='/usr/bin/php';
        [$code,$out,$err]=command($args,35);
        if ($code!==0) td_error(409,$err ?: 'Commande échouée');
        $data=['message'=>$out ?: 'Commande prise en compte'];
    }
    td_json($data);
} catch (Throwable $e) {
    error_log('Thermalright API '.$action.': '.$e->getMessage());
    td_error(500,$e->getMessage());
}
