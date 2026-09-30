<?php
// ZAZAH ERP V5.25.30 — compatibilité token Android verrouillé.
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__.'/config_mobile.php';
foreach([__DIR__.'/../../config.php',__DIR__.'/../../../config.php'] as $f){ if(is_file($f)){require_once $f;break;} }
if(!isset($conn)||!($conn instanceof mysqli)){http_response_code(500);die(json_encode(['ok'=>false,'error'=>'Connexion MySQL introuvable']));}
$conn->set_charset('utf8mb4');

/**
 * V5.25.28 - lecture robuste du token.
 * InfinityFree / certains WebView peuvent modifier la façon dont les en-têtes
 * personnalisés arrivent dans PHP. On accepte donc plusieurs transports.
 */
function zazah_mobile_request_tokens(): array {
    $candidates = [];

    $candidates[] = $_GET['sync_token'] ?? '';
    $candidates[] = $_POST['sync_token'] ?? '';
    $candidates[] = $_GET['token'] ?? '';
    $candidates[] = $_POST['token'] ?? '';
    $candidates[] = $_SERVER['HTTP_X_ZAZAH_SYNC_TOKEN'] ?? '';
    $candidates[] = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';

    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                $key = strtolower(trim((string)$name));
                if ($key === 'x-zazah-sync-token' || $key === 'x-auth-token') {
                    $candidates[] = $value;
                }
                if ($key === 'authorization') {
                    $auth = trim((string)$value);
                    if (stripos($auth, 'Bearer ') === 0) {
                        $candidates[] = substr($auth, 7);
                    }
                }
            }
        }
    }

    foreach (['HTTP_AUTHORIZATION','REDIRECT_HTTP_AUTHORIZATION'] as $authKey) {
        $auth = trim((string)($_SERVER[$authKey] ?? ''));
        if (stripos($auth, 'Bearer ') === 0) {
            $candidates[] = substr($auth, 7);
        }
    }

    $clean = [];
    foreach ($candidates as $candidate) {
        $candidate = trim((string)$candidate);
        if ($candidate !== '' && !in_array($candidate, $clean, true)) {
            $clean[] = $candidate;
        }
    }
    return $clean;
}

$tokenOk = false;
foreach (zazah_mobile_request_tokens() as $candidateToken) {
    if (hash_equals((string)ZAZAH_MOBILE_TOKEN, $candidateToken)) {
        $tokenOk = true;
        break;
    }
}
if (!$tokenOk) {
    http_response_code(401);
    die(json_encode([
        'ok'=>false,
        'error'=>'TOKEN invalide. Installez le patch serveur V5.25.30 et l application Android V5.25.30 : le token est désormais verrouillé automatiquement.'
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function out(array $x,int $code=200):void{http_response_code($code);echo json_encode($x,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function cols(mysqli $c,string $t):array{$a=[];$r=$c->query("SHOW COLUMNS FROM `$t`");if($r)while($x=$r->fetch_assoc())$a[$x['Field']]=1;return $a;}
function table_exists(mysqli $c,string $t):bool{
    $safe=$c->real_escape_string($t);
    $r=$c->query("SHOW TABLES LIKE '".$safe."'");
    if(!$r){ throw new RuntimeException('SHOW TABLES failed: '.$c->error); }
    return $r->num_rows>0;
}
function sql_quote(mysqli $c,$v):string{
    if($v===null) return 'NULL';
    if(is_bool($v)) return $v ? '1' : '0';
    if(is_int($v)||is_float($v)) return (string)$v;
    return "'".$c->real_escape_string((string)$v)."'";
}
function insert_filtered(mysqli $c,string $table,array $data):int{
    $cc=cols($c,$table);$d=[];
    foreach($data as $k=>$v){ if(isset($cc[$k])) $d[$k]=$v; }
    if(!$d) throw new RuntimeException("Aucune colonne compatible pour $table");
    $ks=array_keys($d);$vals=[];
    foreach($ks as $k){ $vals[] = sql_quote($c,$d[$k]); }
    $sql="INSERT INTO `$table` (`".implode("`,`",$ks)."`) VALUES(".implode(',',$vals).")";
    if(!$c->query($sql)) throw new RuntimeException("INSERT $table: ".$c->error);
    return (int)$c->insert_id;
}
