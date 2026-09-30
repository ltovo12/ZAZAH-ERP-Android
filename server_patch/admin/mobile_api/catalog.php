<?php
require __DIR__.'/bootstrap.php';

function zazah_request_scheme(): string {
    $https = $_SERVER['HTTPS'] ?? '';
    if ($https !== '' && strtolower((string)$https) !== 'off') return 'https';
    if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return 'https';
    return 'http';
}

function zazah_host_root(): string {
    $host = $_SERVER['HTTP_HOST'] ?? 'zazahtsenasabotsy.infinityfree.io';
    return zazah_request_scheme().'://'.$host.'/';
}

function zazah_project_root(): string {
    $script = str_replace('\\','/', $_SERVER['SCRIPT_NAME'] ?? '/zazah/admin/mobile_api/catalog.php');
    $projectPath = dirname(dirname(dirname($script)));
    $projectPath = trim(str_replace('\\','/',$projectPath), '/');
    return zazah_host_root().($projectPath !== '' ? $projectPath.'/' : '');
}

function zazah_image_url($raw, string $kind='product'): string {
    $value = trim((string)$raw);
    if ($value === '') return '';
    $value = str_replace('\\','/',$value);
    if (preg_match('~^https?://~i',$value)) return str_replace(' ','%20',$value);

    while (str_starts_with($value,'./')) $value = substr($value,2);
    while (str_starts_with($value,'../')) $value = substr($value,3);

    if (str_starts_with($value,'/')) {
        return rtrim(zazah_host_root(),'/').str_replace(' ','%20',$value);
    }

    if (str_starts_with($value,'zazah/')) {
        return zazah_host_root().str_replace(' ','%20',$value);
    }

    if (strpos($value,'/') === false) {
        $folder = $kind === 'variant' ? 'uploads/variantes/' : 'uploads/produits/';
        $value = $folder.$value;
    }

    return zazah_project_root().str_replace(' ','%20',ltrim($value,'/'));
}

function zazah_row_pick(array $row, array $keys, $default='') {
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null) return $row[$key];
    }
    return $default;
}

function zazah_payroll_month(array $row): string {
    $month = trim((string)zazah_row_pick($row, ['mois_paie','mois','periode_mois'], ''));
    if (preg_match('/^\d{4}-\d{2}$/', $month)) return $month;
    $start = trim((string)zazah_row_pick($row, ['periode_debut','date_debut','debut'], ''));
    if (preg_match('/^(\d{4}-\d{2})-\d{2}/', $start, $m)) return $m[1];
    return '';
}

function zazah_ticket_system_info(mysqli $conn): array {
    $result = [
        'name' => 'ZAZAH TSENA SABOTSY',
        'address' => '',
        'phone' => '',
        'nif' => '',
        'stat' => '',
        'msg' => '',
    ];
    if (!table_exists($conn, 'system_info')) return $result;
    $columns = cols($conn, 'system_info');
    if (!isset($columns['meta_field']) || !isset($columns['meta_value'])) return $result;

    $values = [];
    $r = $conn->query("SELECT meta_field, meta_value FROM system_info");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $key = strtolower(trim((string)($row['meta_field'] ?? '')));
            if ($key === '') continue;
            $value = trim((string)($row['meta_value'] ?? ''));
            $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $values[$key] = $value;
        }
    }
    $first = static function(array $keys, string $fallback='') use ($values): string {
        foreach ($keys as $key) {
            $k = strtolower((string)$key);
            if (array_key_exists($k, $values) && trim((string)$values[$k]) !== '') return trim((string)$values[$k]);
        }
        return $fallback;
    };
    $result['name'] = $first(['name','system_name','company_name','short_name'], $result['name']);
    $result['address'] = $first(['address','adresse'], '');
    $result['phone'] = $first(['contact','phone','telephone','tel','mobile'], '');
    $result['nif'] = $first(['nif'], '');
    $result['stat'] = $first(['stat'], '');
    $result['msg'] = $first(['msg'], '');
    return $result;
}

try{
    $systemInfo=zazah_ticket_system_info($conn);
    $emplacements=[];$r=$conn->query("SELECT id,nom FROM emplacements ORDER BY id");while($x=$r->fetch_assoc())$emplacements[]=$x;

    $clients=[];$cc=cols($conn,'client_list');
    $sel=['id','fullname'];foreach(['contact','address'] as $k)if(isset($cc[$k]))$sel[]=$k;
    $r=$conn->query("SELECT ".implode(',',$sel)." FROM client_list ORDER BY fullname LIMIT 5000");
    while($x=$r->fetch_assoc()){$x+=['contact'=>'','address'=>''];$clients[]=$x;}

    $salaries=[];
    if(table_exists($conn,'salaries')){
        $sc=cols($conn,'salaries');
        $phoneCol='';foreach(['telephone','contact','phone','mobile','tel'] as $k){if(isset($sc[$k])){$phoneCol=$k;break;}}
        $addressCol='';foreach(['address','adresse'] as $k){if(isset($sc[$k])){$addressCol=$k;break;}}
        $baseCol='';foreach(['salaire_base','salaire'] as $k){if(isset($sc[$k])){$baseCol=$k;break;}}
        $mealCol='';foreach(['allocation_repas_jour','allocation_repas','repas_jour'] as $k){if(isset($sc[$k])){$mealCol=$k;break;}}
        $daysCol='';foreach(['jours_repas_defaut','jours_repas'] as $k){if(isset($sc[$k])){$daysCol=$k;break;}}
        $activeCol='';foreach(['actif','status'] as $k){if(isset($sc[$k])){$activeCol=$k;break;}}
        $r=$conn->query("SELECT * FROM salaries ORDER BY nom,id LIMIT 5000");
        while($x=$r->fetch_assoc()){
            $salaries[]=[
                'id'=>(int)($x['id']??0),
                'matricule'=>(string)($x['matricule']??''),
                'nom'=>(string)($x['nom']??''),
                'telephone'=>$phoneCol!==''?(string)($x[$phoneCol]??''):'',
                'email'=>(string)($x['email']??''),
                'address'=>$addressCol!==''?(string)($x[$addressCol]??''):'',
                'salaire_base'=>$baseCol!==''?(float)($x[$baseCol]??0):0,
                'allocation_repas_jour'=>$mealCol!==''?(float)($x[$mealCol]??0):0,
                'jours_repas_defaut'=>$daysCol!==''?(float)($x[$daysCol]??26):26,
                'actif'=>$activeCol!==''?(int)($x[$activeCol]??1):1,
            ];
        }
    }


    $advances=[];
    $advanceTable='';
    if(table_exists($conn,'avances_salaire')) $advanceTable='avances_salaire';
    elseif(table_exists($conn,'avances')) $advanceTable='avances';
    if($advanceTable!==''){
        $r=$conn->query("SELECT * FROM `{$advanceTable}` ORDER BY id LIMIT 10000");
        while($x=$r->fetch_assoc()){
            $id=(int)zazah_row_pick($x,['id'],0);
            $sid=(int)zazah_row_pick($x,['salarie_id','employee_id'],0);
            $date=trim((string)zazah_row_pick($x,['date_avance','date','date_demande'],''));
            $amount=(float)zazah_row_pick($x,['montant','avance','amount'],0);
            if($id<1||$sid<1||$date===''||$amount<=0) continue;
            $advances[]=[
                'id'=>$id,
                'salarie_id'=>$sid,
                'date_avance'=>$date,
                'montant'=>$amount,
                'remarque'=>(string)zazah_row_pick($x,['remarque','commentaire','note'],''),
                'created_at'=>(string)zazah_row_pick($x,['created_at','date_creation'],''),
            ];
        }
    }

    $paies=[];
    if(table_exists($conn,'paies')){
        $r=$conn->query("SELECT * FROM paies ORDER BY id LIMIT 10000");
        while($x=$r->fetch_assoc()){
            $id=(int)zazah_row_pick($x,['id'],0);
            $sid=(int)zazah_row_pick($x,['salarie_id','employee_id'],0);
            if($id<1||$sid<1) continue;
            $month=zazah_payroll_month($x);
            $start=trim((string)zazah_row_pick($x,['periode_debut','date_debut','debut'],''));
            $end=trim((string)zazah_row_pick($x,['periode_fin','date_fin','fin'],''));
            $daysWorked=(float)zazah_row_pick($x,['jours_travailles','jours_repas'],0);
            $mealDays=(float)zazah_row_pick($x,['jours_repas','jours_travailles'],0);
            $mealTotal=(float)zazah_row_pick($x,['allocation_repas_total','repas_total'],0);
            $mealDay=(float)zazah_row_pick($x,['allocation_repas_jour','repas_jour'],0);
            if($mealDay<=0 && $mealDays>0 && $mealTotal>0) $mealDay=$mealTotal/$mealDays;
            $paies[]=[
                'id'=>$id,
                'salarie_id'=>$sid,
                'mois_paie'=>$month,
                'periode_debut'=>$start,
                'periode_fin'=>$end,
                'salaire_base'=>(float)zazah_row_pick($x,['salaire_base','salaire'],0),
                'jours_travailles'=>$daysWorked,
                'allocation_repas_jour'=>$mealDay,
                'jours_repas'=>$mealDays,
                'allocation_repas_total'=>$mealTotal,
                'avance'=>(float)zazah_row_pick($x,['avance','total_avance'],0),
                'net_a_payer'=>(float)zazah_row_pick($x,['net_a_payer','net','montant_net'],0),
                'remarque'=>(string)zazah_row_pick($x,['remarque','commentaire','note'],''),
                'created_at'=>(string)zazah_row_pick($x,['created_at','date_creation'],''),
                'updated_at'=>(string)zazah_row_pick($x,['updated_at','date_modification','date_creation'],''),
            ];
        }
    }

    $paiePaiements=[];
    if(table_exists($conn,'paie_paiements')){
        $r=$conn->query("SELECT * FROM paie_paiements ORDER BY id LIMIT 20000");
        while($x=$r->fetch_assoc()){
            $id=(int)zazah_row_pick($x,['id'],0);
            $pid=(int)zazah_row_pick($x,['paie_id'],0);
            $amount=(float)zazah_row_pick($x,['montant','amount'],0);
            $date=trim((string)zazah_row_pick($x,['date_paiement','date'],''));
            if($id<1||$pid<1||$amount<=0||$date==='') continue;
            $paiePaiements[]=[
                'id'=>$id,
                'paie_id'=>$pid,
                'montant'=>$amount,
                'date_paiement'=>$date,
                'mode_paiement'=>(string)zazah_row_pick($x,['mode_paiement','mode'],'ESPECES'),
                'reference'=>(string)zazah_row_pick($x,['reference','ref'],''),
                'remarque'=>(string)zazah_row_pick($x,['remarque','commentaire','note'],''),
                'created_at'=>(string)zazah_row_pick($x,['created_at','date_creation'],''),
            ];
        }
    }

    $pc=cols($conn,'services_list');$psel=['id','name'];
    $psel[]=isset($pc['price'])?'price':'0 AS price';$psel[]=isset($pc['status'])?'status':'1 AS status';
    $psel[]=isset($pc['image'])?"COALESCE(image,'') AS image_url":"'' AS image_url";
    $products=[];$r=$conn->query("SELECT ".implode(',',$psel)." FROM services_list ORDER BY name");
    while($x=$r->fetch_assoc()){
        $x['image_url']=zazah_image_url($x['image_url']??'','product');
        $products[]=$x;
    }

    $vc=cols($conn,'services_variantes');$variants=[];
    $price=isset($vc['prix_specifique'])?"COALESCE(prix_specifique,0)":"0";
    $image=isset($vc['image'])?"COALESCE(image,'')":"''";
    $r=$conn->query("SELECT id,service_id,COALESCE(couleur,'') couleur,COALESCE(taille,'') taille,COALESCE(code_barre,'') code_barre,$price prix,$image image_url FROM services_variantes ORDER BY service_id,id");
    while($x=$r->fetch_assoc()){
        $x['image_url']=zazah_image_url($x['image_url']??'','variant');
        $variants[]=$x;
    }

    $stocks=[];$r=$conn->query("SELECT variante_id,emplacement_id,quantite FROM stocks ORDER BY variante_id,emplacement_id");
    while($x=$r->fetch_assoc())$stocks[]=$x;

    // Packs Nouveau-né en ligne (V5.25.18). Les anciennes apps ignorent simplement ces clés.
    $packs=[];$packArticles=[];
    if(table_exists($conn,'packs')){
        $pkc=cols($conn,'packs');
        $slogan=isset($pkc['slogan'])?"COALESCE(slogan,'') slogan":"'' slogan";
        $categorie=isset($pkc['categorie'])?"COALESCE(categorie,'Nouveau-né') categorie":"'Nouveau-né' categorie";
        $r=$conn->query("SELECT id,nom,COALESCE(description,'') description,$slogan,$categorie,COALESCE(prix_vente,0) prix_vente,COALESCE(actif,1) actif,COALESCE(created_at,'') created_at,COALESCE(updated_at,'') updated_at FROM packs ORDER BY id");
        if($r) while($x=$r->fetch_assoc()){
            $packs[]=[
                'id'=>(int)($x['id']??0),
                'nom'=>(string)($x['nom']??''),
                'description'=>(string)($x['description']??''),
                'slogan'=>(string)($x['slogan']??''),
                'categorie'=>(string)($x['categorie']??'Nouveau-né'),
                'prix_vente'=>(float)($x['prix_vente']??0),
                'actif'=>(int)($x['actif']??1),
                'created_at'=>(string)($x['created_at']??''),
                'updated_at'=>(string)($x['updated_at']??''),
            ];
        }
    }
    if(table_exists($conn,'pack_articles')){
        $r=$conn->query("SELECT id,pack_id,variante_id,nom_article,COALESCE(quantite,1) quantite,COALESCE(prix_unitaire,0) prix_unitaire,COALESCE(ordre,0) ordre FROM pack_articles ORDER BY pack_id,ordre,id");
        if($r) while($x=$r->fetch_assoc()){
            $packArticles[]=[
                'id'=>(int)($x['id']??0),
                'pack_id'=>(int)($x['pack_id']??0),
                'variante_id'=>isset($x['variante_id'])&&$x['variante_id']!==null?(int)$x['variante_id']:0,
                'nom_article'=>(string)($x['nom_article']??''),
                'quantite'=>(float)($x['quantite']??1),
                'prix_unitaire'=>(float)($x['prix_unitaire']??0),
                'ordre'=>(int)($x['ordre']??0),
            ];
        }
    }

    out(['ok'=>true,'server_time'=>date('Y-m-d H:i:s'),'image_base_url'=>zazah_project_root(),'system_info'=>$systemInfo,'emplacements'=>$emplacements,'clients'=>$clients,'salaries'=>$salaries,'avances'=>$advances,'paies'=>$paies,'paie_paiements'=>$paiePaiements,'produits'=>$products,'variantes'=>$variants,'stocks'=>$stocks,'packs'=>$packs,'pack_articles'=>$packArticles]);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
