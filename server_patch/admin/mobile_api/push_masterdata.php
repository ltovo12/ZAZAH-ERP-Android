<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

function zazah_next_client_code(mysqli $conn): string {
    $prefix = date('Y');
    $next = 1;
    $cc = cols($conn, 'client_list');
    if (isset($cc['client_code'])) {
        $like = $prefix.'%';
        $stmt = $conn->prepare("SELECT MAX(client_code) AS max_code FROM client_list WHERE client_code LIKE ?");
        $stmt->bind_param('s', $like);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $max = trim((string)($row['max_code'] ?? ''));
        if ($max !== '' && strpos($max, $prefix) === 0) {
            $suffix = preg_replace('/\\D+/', '', substr($max, strlen($prefix)));
            if ($suffix !== '') $next = ((int)$suffix) + 1;
        }
    }
    return $prefix.sprintf('%04d', $next);
}

function zazah_save_client_meta(mysqli $conn, int $clientId, string $contact, string $address): void {
    if (!table_exists($conn, 'client_meta')) return;
    $mc = cols($conn, 'client_meta');
    if (!isset($mc['client_id'], $mc['meta_field'], $mc['meta_value'])) return;

    $fields = ['contact' => $contact, 'address' => $address];
    foreach ($fields as $field => $value) {
        $del = $conn->prepare("DELETE FROM client_meta WHERE client_id=? AND meta_field=?");
        $del->bind_param('is', $clientId, $field);
        $del->execute();
        $del->close();

        if ($value !== '') {
            $ins = $conn->prepare("INSERT INTO client_meta(client_id,meta_field,meta_value) VALUES(?,?,?)");
            $ins->bind_param('iss', $clientId, $field, $value);
            $ins->execute();
            $ins->close();
        }
    }
}

function zazah_insert_client_compatible(mysqli $conn, string $name, string $contact, string $address): int {
    $code = zazah_next_client_code($conn);
    $data = [
        'client_code' => $code,
        'fullname' => $name,
        'status' => 1,
        'password' => password_hash($code, PASSWORD_BCRYPT),
        // Si certaines versions ont ces colonnes directement, on les remplit aussi.
        'contact' => $contact,
        'address' => $address,
    ];
    $sid = insert_filtered($conn, 'client_list', $data);
    if ($sid < 1) throw new RuntimeException('Création client en ligne impossible');
    zazah_save_client_meta($conn, $sid, $contact, $address);
    return $sid;
}

function zazah_update_client_compatible(mysqli $conn, int $clientId, string $name, string $contact, string $address): int {
    if ($clientId < 1) throw new RuntimeException('ID client en ligne invalide');
    $check = $conn->prepare("SELECT id FROM client_list WHERE id=? LIMIT 1");
    $check->bind_param('i', $clientId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$exists) throw new RuntimeException("Client en ligne $clientId introuvable");

    $cc = cols($conn, 'client_list');
    $sets = [];
    if (isset($cc['fullname'])) $sets[] = "`fullname`=".sql_quote($conn, $name);
    if (isset($cc['contact'])) $sets[] = "`contact`=".sql_quote($conn, $contact);
    if (isset($cc['address'])) $sets[] = "`address`=".sql_quote($conn, $address);
    if (isset($cc['status'])) $sets[] = "`status`=1";
    if (!$sets) throw new RuntimeException('Aucune colonne client modifiable en ligne');

    $sql = "UPDATE `client_list` SET ".implode(',', $sets)." WHERE id=".(int)$clientId." LIMIT 1";
    if (!$conn->query($sql)) throw new RuntimeException('UPDATE client_list: '.$conn->error);
    zazah_save_client_meta($conn, $clientId, $contact, $address);
    return $clientId;
}

function zazah_first_col(array $columns, array $candidates): string {
    foreach ($candidates as $name) {
        if (isset($columns[$name])) return $name;
    }
    return '';
}

function zazah_find_salary_by_matricule(mysqli $conn, string $matricule): int {
    if ($matricule === '' || !table_exists($conn, 'salaries')) return 0;
    $sc = cols($conn, 'salaries');
    if (!isset($sc['matricule'])) return 0;
    $st = $conn->prepare("SELECT id FROM salaries WHERE matricule=? LIMIT 1");
    $st->bind_param('s', $matricule);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    return (int)($row['id'] ?? 0);
}

function zazah_salary_data(mysqli $conn, array $x): array {
    $sc = cols($conn, 'salaries');
    $data = [];
    if (isset($sc['matricule'])) {
        $m = trim((string)($x['matricule'] ?? ''));
        $data['matricule'] = $m !== '' ? $m : null;
    }
    if (isset($sc['nom'])) $data['nom'] = trim((string)($x['nom'] ?? ''));

    $phone = trim((string)($x['telephone'] ?? $x['contact'] ?? ''));
    $phoneCol = zazah_first_col($sc, ['telephone','contact','phone','mobile','tel']);
    if ($phoneCol !== '') $data[$phoneCol] = $phone;

    if (isset($sc['email'])) $data['email'] = trim((string)($x['email'] ?? ''));
    $addressCol = zazah_first_col($sc, ['address','adresse']);
    if ($addressCol !== '') $data[$addressCol] = trim((string)($x['address'] ?? $x['adresse'] ?? ''));

    $baseCol = zazah_first_col($sc, ['salaire_base','salaire']);
    if ($baseCol !== '') $data[$baseCol] = max(0, (float)($x['salaire_base'] ?? 0));

    $mealCol = zazah_first_col($sc, ['allocation_repas_jour','allocation_repas','repas_jour']);
    if ($mealCol !== '') $data[$mealCol] = max(0, (float)($x['allocation_repas_jour'] ?? 0));

    $daysCol = zazah_first_col($sc, ['jours_repas_defaut','jours_repas']);
    if ($daysCol !== '') $data[$daysCol] = max(0, (float)($x['jours_repas_defaut'] ?? 26));

    $activeCol = zazah_first_col($sc, ['actif','status']);
    if ($activeCol !== '') $data[$activeCol] = ((int)($x['actif'] ?? 1)) ? 1 : 0;
    return $data;
}

function zazah_update_salary_compatible(mysqli $conn, int $id, array $x): int {
    if ($id < 1) throw new RuntimeException('ID salarié en ligne invalide');
    if (!table_exists($conn, 'salaries')) throw new RuntimeException('Table salaries absente en ligne');
    $check = $conn->prepare("SELECT id FROM salaries WHERE id=? LIMIT 1");
    $check->bind_param('i', $id);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$exists) throw new RuntimeException("Salarié en ligne $id introuvable");

    $data = zazah_salary_data($conn, $x);
    if (trim((string)($data['nom'] ?? '')) === '') throw new RuntimeException('Nom salarié vide');
    $sets = [];
    foreach ($data as $k => $v) {
        if ($v === null) $sets[] = "`$k`=NULL";
        elseif (is_int($v) || is_float($v)) $sets[] = "`$k`=".(0+$v);
        else $sets[] = "`$k`=".sql_quote($conn, (string)$v);
    }
    if (!$sets) throw new RuntimeException('Aucune colonne salarié modifiable');
    if (!$conn->query("UPDATE salaries SET ".implode(',', $sets)." WHERE id=".(int)$id." LIMIT 1")) {
        throw new RuntimeException('UPDATE salaries: '.$conn->error);
    }
    return $id;
}

function zazah_insert_salary_compatible(mysqli $conn, array $x): int {
    if (!table_exists($conn, 'salaries')) throw new RuntimeException('Table salaries absente en ligne');
    $data = zazah_salary_data($conn, $x);
    if (trim((string)($data['nom'] ?? '')) === '') throw new RuntimeException('Nom salarié vide');
    $id = insert_filtered($conn, 'salaries', $data);
    if ($id < 1) throw new RuntimeException('Création salarié en ligne impossible');
    return $id;
}

function zazah_advance_table(mysqli $conn): string {
    if (table_exists($conn, 'avances_salaire')) return 'avances_salaire';
    if (table_exists($conn, 'avances')) return 'avances';
    return '';
}

function zazah_insert_advance_compatible(mysqli $conn, int $salaryId, string $date, float $amount, string $remark): int {
    $table = zazah_advance_table($conn);
    if ($table === '') throw new RuntimeException('Table avances absente en ligne');
    $ac = cols($conn, $table);
    $salaryCol = zazah_first_col($ac, ['salarie_id','employee_id']);
    $dateCol = zazah_first_col($ac, ['date_avance','date','date_demande']);
    $amountCol = zazah_first_col($ac, ['montant','avance','amount']);
    $remarkCol = zazah_first_col($ac, ['remarque','commentaire','note']);
    if ($salaryCol === '' || $dateCol === '' || $amountCol === '') {
        throw new RuntimeException('Colonnes avances incompatibles en ligne');
    }
    $data = [
        $salaryCol => $salaryId,
        $dateCol => $date,
        $amountCol => $amount,
    ];
    if ($remarkCol !== '') $data[$remarkCol] = $remark;
    $createdCol = zazah_first_col($ac, ['created_at','date_creation']);
    if ($createdCol !== '') $data[$createdCol] = date('Y-m-d H:i:s');
    $id = insert_filtered($conn, $table, $data);
    if ($id < 1) throw new RuntimeException('Création avance en ligne impossible');
    return $id;
}


function zazah_ensure_pack_tables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS packs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        nom VARCHAR(190) NOT NULL,
        description TEXT NULL,
        slogan VARCHAR(255) NOT NULL DEFAULT '',
        categorie VARCHAR(120) NOT NULL DEFAULT 'Nouveau-né',
        prix_vente DECIMAL(14,2) NOT NULL DEFAULT 0,
        actif TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_packs_actif (actif),
        KEY idx_packs_nom (nom)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS pack_articles (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        pack_id BIGINT UNSIGNED NOT NULL,
        variante_id BIGINT NULL,
        nom_article VARCHAR(190) NOT NULL,
        quantite DECIMAL(12,2) NOT NULL DEFAULT 1,
        prix_unitaire DECIMAL(14,2) NOT NULL DEFAULT 0,
        ordre INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_pack_articles_pack (pack_id, ordre, id),
        KEY idx_pack_articles_variante (variante_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

try {
    $conn->query("CREATE TABLE IF NOT EXISTS mobile_sync_ops(
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        op_uuid VARCHAR(64) NOT NULL UNIQUE,
        op_type VARCHAR(30) NOT NULL,
        server_id BIGINT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_mobile_sync_op_type(op_type),
        INDEX idx_mobile_sync_server_id(server_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    zazah_ensure_pack_tables($conn);

    $body=json_decode(file_get_contents('php://input'),true);
    if(!is_array($body)) throw new RuntimeException('Payload invalide');
    $clients=$body['clients']??[];
    $salaries=$body['salaries']??[];
    $advances=$body['avances']??[];
    $variants=$body['variants']??[];
    $stocks=$body['stocks']??[];
    $packs=$body['packs']??[];
    if(!is_array($clients)||!is_array($salaries)||!is_array($advances)||!is_array($variants)||!is_array($stocks)||!is_array($packs)) throw new RuntimeException('Listes invalides');

    $acceptedClients=[];$acceptedSalaries=[];$acceptedAdvances=[];$acceptedVariants=[];$acceptedStocks=[];$acceptedPacks=[];$variantMap=[];$salaryMap=[];

    $findOp=$conn->prepare("SELECT op_type,server_id FROM mobile_sync_ops WHERE op_uuid=? LIMIT 1");
    $saveOp=$conn->prepare("INSERT INTO mobile_sync_ops(op_uuid,op_type,server_id) VALUES(?,?,?)");

    $conn->begin_transaction();
    try {
        foreach($clients as $x){
            $uuid=trim((string)($x['op_uuid']??''));$local=(int)($x['local_id']??0);
            if($uuid===''||$local<1) continue;
            $findOp->bind_param('s',$uuid);$findOp->execute();$old=$findOp->get_result()->fetch_assoc();
            if($old){
                $sid=(int)($old['server_id']??0);
                if($sid>0)$acceptedClients[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];
                continue;
            }
            $name=trim((string)($x['fullname']??''));
            if($name==='') throw new RuntimeException('Nom client vide');
            $contact=trim((string)($x['contact']??''));$address=trim((string)($x['address']??''));
            $requestedServerId=(int)($x['server_id']??0);
            // Nouveau client : création. Client déjà synchronisé : UPDATE du même ID.
            // Le client_code et le password existants ne sont jamais recréés lors d'une modification.
            if($requestedServerId>0){
                $sid=zazah_update_client_compatible($conn,$requestedServerId,$name,$contact,$address);
                $type='CLIENT_UPDATE';
            }else{
                $sid=zazah_insert_client_compatible($conn,$name,$contact,$address);
                $type='CLIENT';
            }
            $saveOp->bind_param('ssi',$uuid,$type,$sid);$saveOp->execute();
            $acceptedClients[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];
        }

        foreach($salaries as $x){
            $uuid=trim((string)($x['op_uuid']??''));$local=(int)($x['local_id']??0);
            if($uuid===''||$local<1) continue;
            $findOp->bind_param('s',$uuid);$findOp->execute();$old=$findOp->get_result()->fetch_assoc();
            if($old){
                $sid=(int)($old['server_id']??0);
                if($sid>0){$salaryMap[$local]=$sid;$acceptedSalaries[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];}
                continue;
            }
            $name=trim((string)($x['nom']??''));
            if($name==='') throw new RuntimeException('Nom salarié vide');
            $requestedServerId=(int)($x['server_id']??0);
            $matricule=trim((string)($x['matricule']??''));
            if($requestedServerId>0){
                $sid=zazah_update_salary_compatible($conn,$requestedServerId,$x);
                $type='SALARY_UPDATE';
            }else{
                $existing=zazah_find_salary_by_matricule($conn,$matricule);
                if($existing>0){
                    $sid=zazah_update_salary_compatible($conn,$existing,$x);
                    $type='SALARY_MATCH';
                }else{
                    $sid=zazah_insert_salary_compatible($conn,$x);
                    $type='SALARY';
                }
            }
            $saveOp->bind_param('ssi',$uuid,$type,$sid);$saveOp->execute();
            $salaryMap[$local]=$sid;
            $acceptedSalaries[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];
        }

        foreach($advances as $x){
            $uuid=trim((string)($x['op_uuid']??''));$local=(int)($x['local_id']??0);
            if($uuid===''||$local<1) continue;
            $findOp->bind_param('s',$uuid);$findOp->execute();$old=$findOp->get_result()->fetch_assoc();
            if($old){
                $sid=(int)($old['server_id']??0);
                if($sid>0)$acceptedAdvances[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];
                continue;
            }
            $salaryLocal=(int)($x['salarie_local_id']??0);
            $salaryServer=(int)($x['salarie_server_id']??0);
            if($salaryLocal>0 && isset($salaryMap[$salaryLocal])) $salaryServer=(int)$salaryMap[$salaryLocal];
            if($salaryServer<1){
                $matricule=trim((string)($x['matricule']??''));
                $salaryServer=zazah_find_salary_by_matricule($conn,$matricule);
            }
            if($salaryServer<1) throw new RuntimeException('Salarié de l\'avance non résolu en ligne');
            $date=trim((string)($x['date_avance']??''));
            $amount=max(0,(float)($x['montant']??0));
            $remark=trim((string)($x['remarque']??''));
            if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||$amount<=0) throw new RuntimeException('Avance locale invalide');
            $table=zazah_advance_table($conn);
            $requestedServerId=(int)($x['server_id']??0);
            $sid=0;
            if($requestedServerId>0){
                $check=$conn->prepare("SELECT id FROM `{$table}` WHERE id=? LIMIT 1");$check->bind_param('i',$requestedServerId);$check->execute();$row=$check->get_result()->fetch_assoc();$check->close();
                if($row)$sid=$requestedServerId;
            }
            if($table==='') throw new RuntimeException('Table avances absente en ligne');
            if($sid<1)$sid=zazah_insert_advance_compatible($conn,$salaryServer,$date,$amount,$remark);
            $type='ADVANCE';$saveOp->bind_param('ssi',$uuid,$type,$sid);$saveOp->execute();
            $acceptedAdvances[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];
        }

        foreach($variants as $x){
            $uuid=trim((string)($x['op_uuid']??''));$local=(int)($x['local_id']??0);
            if($uuid===''||$local===0) continue;
            $findOp->bind_param('s',$uuid);$findOp->execute();$old=$findOp->get_result()->fetch_assoc();
            if($old){
                $sid=(int)($old['server_id']??0);
                if($sid>0){$variantMap[$local]=$sid;$acceptedVariants[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];}
                continue;
            }
            $serviceId=(int)($x['service_id']??0);if($serviceId<1)throw new RuntimeException('Produit invalide pour nouveau variant');
            $chk=$conn->prepare("SELECT id FROM services_list WHERE id=? LIMIT 1");$chk->bind_param('i',$serviceId);$chk->execute();$exists=$chk->get_result()->fetch_assoc();$chk->close();
            if(!$exists)throw new RuntimeException("Produit $serviceId introuvable en ligne");
            $couleur=trim((string)($x['couleur']??''));$taille=trim((string)($x['taille']??''));$barcode=trim((string)($x['code_barre']??''));$prix=max(0,(float)($x['prix']??0));$imageUrl=trim((string)($x['image_url']??''));

            if($local>0){
                // ID positif = variante déjà synchronisée : on modifie son produit parent
                // sans toucher à son ID ni à ses lignes de stock.
                $vs=$conn->prepare("SELECT id FROM services_variantes WHERE id=? LIMIT 1");
                $vs->bind_param('i',$local);$vs->execute();$ve=$vs->get_result()->fetch_assoc();$vs->close();
                if(!$ve)throw new RuntimeException("Variante $local introuvable en ligne");

                $dup=$conn->prepare("SELECT id FROM services_variantes WHERE service_id=? AND id<>? AND LOWER(TRIM(COALESCE(couleur,'')))=LOWER(TRIM(?)) AND LOWER(TRIM(COALESCE(taille,'')))=LOWER(TRIM(?)) LIMIT 1");
                $dup->bind_param('iiss',$serviceId,$local,$couleur,$taille);$dup->execute();$dupRow=$dup->get_result()->fetch_assoc();$dup->close();
                if($dupRow)throw new RuntimeException('Une variante identique existe déjà dans le produit destination');

                $mv=$conn->prepare("UPDATE services_variantes SET service_id=? WHERE id=? LIMIT 1");
                $mv->bind_param('ii',$serviceId,$local);$mv->execute();$mv->close();
                $sid=$local;
                $type='VARIANT_MOVE';
            }elseif($barcode!==''){
                $bc=$conn->prepare("SELECT id FROM services_variantes WHERE code_barre=? LIMIT 1");$bc->bind_param('s',$barcode);$bc->execute();$same=$bc->get_result()->fetch_assoc();$bc->close();
                if($same){
                    $sid=(int)$same['id'];
                }else{
                    $sid=insert_filtered($conn,'services_variantes',['service_id'=>$serviceId,'couleur'=>$couleur,'taille'=>$taille,'code_barre'=>$barcode,'prix_specifique'=>$prix,'image_url'=>$imageUrl,'image'=>$imageUrl]);
                }
                $type='VARIANT';
            }else{
                $sid=insert_filtered($conn,'services_variantes',['service_id'=>$serviceId,'couleur'=>$couleur,'taille'=>$taille,'code_barre'=>'','prix_specifique'=>$prix,'image_url'=>$imageUrl,'image'=>$imageUrl]);
                $type='VARIANT';
            }
            $saveOp->bind_param('ssi',$uuid,$type,$sid);$saveOp->execute();
            $variantMap[$local]=$sid;$acceptedVariants[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid];
        }


        foreach($packs as $x){
            if(!is_array($x)) continue;
            $uuid=trim((string)($x['op_uuid']??''));
            $local=(int)($x['local_id']??0);
            $requestedServerId=(int)($x['server_id']??0);
            $action=strtoupper(trim((string)($x['action']??'UPSERT')));
            if($uuid===''||$local<1) continue;

            $findOp->bind_param('s',$uuid);
            $findOp->execute();
            $old=$findOp->get_result()->fetch_assoc();
            if($old){
                $sid=(int)($old['server_id']??0);
                $acceptedPacks[]=[
                    'op_uuid'=>$uuid,
                    'local_id'=>$local,
                    'server_id'=>$sid,
                    'action'=>$action==='DELETE'?'DELETE':'UPSERT'
                ];
                continue;
            }

            if($action==='DELETE'){
                $sid=$requestedServerId;
                if($sid>0){
                    $delItems=$conn->prepare("DELETE FROM pack_articles WHERE pack_id=?");
                    $delItems->bind_param('i',$sid);$delItems->execute();$delItems->close();
                    $delPack=$conn->prepare("DELETE FROM packs WHERE id=?");
                    $delPack->bind_param('i',$sid);$delPack->execute();$delPack->close();
                }
                $type='PACK_DELETE';
                $saveOp->bind_param('ssi',$uuid,$type,$sid);$saveOp->execute();
                $acceptedPacks[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid,'action'=>'DELETE'];
                continue;
            }

            $name=trim((string)($x['nom']??''));
            if($name==='') throw new RuntimeException('Nom du pack vide');
            $description=trim((string)($x['description']??''));
            $slogan=trim((string)($x['slogan']??''));
            $categorie=trim((string)($x['categorie']??'Nouveau-né'));
            if($categorie==='')$categorie='Nouveau-né';
            $prix=max(0,(float)($x['prix_vente']??0));
            $actif=((int)($x['actif']??1))?1:0;
            $sid=0;

            if($requestedServerId>0){
                $chk=$conn->prepare("SELECT id FROM packs WHERE id=? LIMIT 1");
                $chk->bind_param('i',$requestedServerId);$chk->execute();
                $row=$chk->get_result()->fetch_assoc();$chk->close();
                if($row)$sid=$requestedServerId;
            }
            if($sid<=0){
                // Rapproche les 3 packs standards (et tout pack de même nom) lors
                // de la première synchronisation au lieu de créer un doublon.
                $match=$conn->prepare("SELECT id FROM packs WHERE LOWER(TRIM(nom))=LOWER(TRIM(?)) ORDER BY id LIMIT 1");
                $match->bind_param('s',$name);$match->execute();
                $row=$match->get_result()->fetch_assoc();$match->close();
                if($row)$sid=(int)$row['id'];
            }

            if($sid>0){
                $up=$conn->prepare("UPDATE packs SET nom=?,description=?,slogan=?,categorie=?,prix_vente=?,actif=?,updated_at=NOW() WHERE id=? LIMIT 1");
                $up->bind_param('ssssdii',$name,$description,$slogan,$categorie,$prix,$actif,$sid);
                $up->execute();$up->close();
                $type='PACK_UPDATE';
            }else{
                $ins=$conn->prepare("INSERT INTO packs(nom,description,slogan,categorie,prix_vente,actif) VALUES(?,?,?,?,?,?)");
                $ins->bind_param('ssssdi',$name,$description,$slogan,$categorie,$prix,$actif);
                $ins->execute();$sid=(int)$conn->insert_id;$ins->close();
                if($sid<1) throw new RuntimeException('Création pack en ligne impossible');
                $type='PACK';
            }

            // Le mobile envoie un snapshot complet : on remplace donc exactement
            // la composition du pack en ligne par celle de SQLite.
            $delItems=$conn->prepare("DELETE FROM pack_articles WHERE pack_id=?");
            $delItems->bind_param('i',$sid);$delItems->execute();$delItems->close();

            $articles=$x['articles']??[];
            if(!is_array($articles)) throw new RuntimeException('Articles du pack invalides');
            $orderFallback=1;
            foreach($articles as $a){
                if(!is_array($a)) continue;
                $variantId=(int)($a['variante_id']??0);
                if(isset($variantMap[$variantId])) $variantId=(int)$variantMap[$variantId];

                if($variantId>0){
                    $vs=$conn->prepare("SELECT id FROM services_variantes WHERE id=? LIMIT 1");
                    $vs->bind_param('i',$variantId);$vs->execute();$vr=$vs->get_result()->fetch_assoc();$vs->close();
                    if(!$vr) throw new RuntimeException("Variante $variantId du pack introuvable en ligne");
                }

                $articleName=trim((string)($a['nom_article']??''));
                if($articleName==='') $articleName='Article';
                $qty=round(max(0.5,(float)($a['quantite']??1))*2)/2;
                $unit=max(0,(float)($a['prix_unitaire']??0));
                $ordre=(int)($a['ordre']??$orderFallback);
                if($ordre<1)$ordre=$orderFallback;
                $orderFallback++;

                if($variantId>0){
                    $ai=$conn->prepare("INSERT INTO pack_articles(pack_id,variante_id,nom_article,quantite,prix_unitaire,ordre) VALUES(?,?,?,?,?,?)");
                    $ai->bind_param('iisddi',$sid,$variantId,$articleName,$qty,$unit,$ordre);
                }else{
                    $ai=$conn->prepare("INSERT INTO pack_articles(pack_id,variante_id,nom_article,quantite,prix_unitaire,ordre) VALUES(?,NULL,?,?,?,?)");
                    $ai->bind_param('isddi',$sid,$articleName,$qty,$unit,$ordre);
                }
                $ai->execute();$ai->close();
            }

            $saveOp->bind_param('ssi',$uuid,$type,$sid);$saveOp->execute();
            $acceptedPacks[]=['op_uuid'=>$uuid,'local_id'=>$local,'server_id'=>$sid,'action'=>'UPSERT'];
        }

        foreach($stocks as $x){
            $uuid=trim((string)($x['op_uuid']??''));if($uuid==='')continue;
            $findOp->bind_param('s',$uuid);$findOp->execute();$old=$findOp->get_result()->fetch_assoc();
            if($old){$acceptedStocks[]=['op_uuid'=>$uuid];continue;}
            $vid=(int)($x['variante_id']??0);$emp=(int)($x['emplacement_id']??0);$qty=round(((float)($x['quantite']??0))*2)/2;
            if(isset($variantMap[$vid]))$vid=(int)$variantMap[$vid];
            if($vid<1)throw new RuntimeException('Variant local non encore résolu pour ajout stock');
            if($emp<1||$qty<=0)throw new RuntimeException('Ajout stock invalide');

            $vs=$conn->prepare("SELECT id FROM services_variantes WHERE id=? LIMIT 1");$vs->bind_param('i',$vid);$vs->execute();$ve=$vs->get_result()->fetch_assoc();$vs->close();
            if(!$ve)throw new RuntimeException("Variante $vid introuvable en ligne");

            $lock=$conn->prepare("SELECT quantite FROM stocks WHERE variante_id=? AND emplacement_id=? FOR UPDATE");
            $lock->bind_param('ii',$vid,$emp);$lock->execute();$row=$lock->get_result()->fetch_assoc();$lock->close();
            if($row){
                $up=$conn->prepare("UPDATE stocks SET quantite=quantite+? WHERE variante_id=? AND emplacement_id=?");
                $up->bind_param('dii',$qty,$vid,$emp);$up->execute();$up->close();
            }else{
                insert_filtered($conn,'stocks',['variante_id'=>$vid,'emplacement_id'=>$emp,'quantite'=>$qty]);
            }
            $type='STOCK';$sid=$vid;$saveOp->bind_param('ssi',$uuid,$type,$sid);$saveOp->execute();
            $acceptedStocks[]=['op_uuid'=>$uuid];
        }

        $conn->commit();
    } catch(Throwable $e) {
        $conn->rollback();throw $e;
    } finally {
        $findOp->close();$saveOp->close();
    }

    out(['ok'=>true,'clients'=>$acceptedClients,'salaries'=>$acceptedSalaries,'avances'=>$acceptedAdvances,'variants'=>$acceptedVariants,'stocks'=>$acceptedStocks,'packs'=>$acceptedPacks,'server_time'=>date('Y-m-d H:i:s')]);
} catch(Throwable $e) {
    out(['ok'=>false,'error'=>$e->getMessage()],500);
}
