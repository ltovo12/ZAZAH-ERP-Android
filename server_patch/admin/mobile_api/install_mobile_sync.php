<?php
declare(strict_types=1);
require_once __DIR__.'/config_mobile.php';

foreach([__DIR__.'/../../config.php',__DIR__.'/../../../config.php'] as $f){
    if(is_file($f)){ require_once $f; break; }
}

function install_page(string $message='', bool $ok=false): void {
    $alert=$message!=='' ? '<div class="alert '.($ok?'ok':'err').'">'.htmlspecialchars($message,ENT_QUOTES,'UTF-8').'</div>' : '';
    $form=$ok ? '<div class="warn"><b>Important :</b> supprimez maintenant <code>install_mobile_sync.php</code> du serveur.</div>' :
    '<form method="post"><label>Token de synchronisation</label><input name="token" type="password" required placeholder="Même token que config_mobile.php"><button>Installer / vérifier</button></form>';
    echo '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ZAZAH Mobile Sync</title>
    <style>body{font-family:Arial;background:#f1f5f9;padding:20px;color:#0f172a}.box{max-width:560px;margin:35px auto;background:#fff;padding:24px;border-radius:16px;box-shadow:0 8px 25px #0002}
    h1{margin-top:0}label{display:block;font-weight:700;margin:16px 0 7px}input,button{width:100%;box-sizing:border-box;padding:13px;border-radius:10px;font-size:16px}
    input{border:1px solid #cbd5e1}button{border:0;background:#f97316;color:#fff;font-weight:800;margin-top:10px}.alert,.warn{padding:13px;border-radius:10px;margin:14px 0}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}.warn{background:#fff7ed;color:#9a3412}</style>
    </head><body><div class="box"><h1>ZAZAH Mobile Sync</h1><p>Installation des tables anti-doublon Android SQLite (ventes + clients/variants/stock).</p>'.$alert.$form.'</div></body></html>';
    exit;
}
if(!defined('ZAZAH_MOBILE_TOKEN')) install_page('ZAZAH_MOBILE_TOKEN absent de config_mobile.php.');
if(!isset($conn)||!($conn instanceof mysqli)) install_page('Connexion MySQL introuvable.');
$conn->set_charset('utf8mb4');
if($_SERVER['REQUEST_METHOD']!=='POST') install_page();
$token=(string)($_POST['token']??'');
if($token===''||!hash_equals((string)ZAZAH_MOBILE_TOKEN,$token)) install_page('Token invalide.');
try{
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_sync_sales(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        uuid VARCHAR(64) NOT NULL UNIQUE,
        vente_id BIGINT NULL,
        num_vente VARCHAR(100) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_sync_vente_id(vente_id),
        INDEX idx_mobile_sync_num_vente(num_vente)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_sync_ops(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        op_uuid VARCHAR(64) NOT NULL UNIQUE,
        op_type VARCHAR(30) NOT NULL,
        server_id BIGINT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_sync_op_type(op_type),
        INDEX idx_mobile_sync_server_id(server_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    install_page('Installation réussie : ventes + opérations master prêtes.',true);
}catch(Throwable $e){install_page('Erreur MySQL : '.$e->getMessage());}
