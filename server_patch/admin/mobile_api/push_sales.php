<?php
require __DIR__.'/bootstrap.php';
try{
    if(!table_exists($conn,'mobile_sync_sales')) throw new RuntimeException("Lancez install_mobile_sync.php une fois.");
    $body=json_decode(file_get_contents('php://input'),true);$sales=$body['sales']??[];
    if(!is_array($sales))throw new RuntimeException('Payload invalide');
    $accepted=[];$rejected=[];

    foreach($sales as $sale){
        $uuid=trim((string)($sale['uuid']??''));if($uuid==='')continue;
        $chk=$conn->prepare("SELECT vente_id,num_vente FROM mobile_sync_sales WHERE uuid=? LIMIT 1");
        $chk->bind_param('s',$uuid);$chk->execute();$old=$chk->get_result()->fetch_assoc();$chk->close();
        if($old){$accepted[]=['uuid'=>$uuid,'server_id'=>(int)$old['vente_id'],'num_vente'=>$old['num_vente']];continue;}

        $lines=$sale['lignes']??[];if(!is_array($lines)||!$lines)throw new RuntimeException("Panier vide pour $uuid");
        $emp=(int)($sale['emplacement_id']??0);if($emp<1)throw new RuntimeException("Emplacement invalide");
        $clientServer=(int)($sale['client_server_id']??0);
        $rem=max(0,(float)($sale['remise']??0));$tax=max(0,min(100,(float)($sale['taux_tva']??0)));
        $delivery=max(0,(float)($sale['frais_livraison']??0));$received=max(0,(float)($sale['montant_recu']??0));
        $mode=strtoupper(trim((string)($sale['mode_paiement']??'ESPECES')));
        if(!in_array($mode,['ESPECES','MOBILE_MONEY','CARTE','VIREMENT','MIXTE'],true))$mode='ESPECES';
        $comment=trim((string)($sale['commentaire']??''));
        $requestedStatus=strtoupper((string)($sale['statut_paiement']??'EN_ATTENTE'));

        $conn->begin_transaction();
        try{
            $validated=[];$subtotal=0;
            $lock=$conn->prepare("SELECT COALESCE(s.quantite,0) q,p.name,COALESCE(v.couleur,'') couleur,COALESCE(v.taille,'') taille
                FROM services_variantes v JOIN services_list p ON p.id=v.service_id
                LEFT JOIN stocks s ON s.variante_id=v.id AND s.emplacement_id=?
                WHERE v.id=? FOR UPDATE");
            foreach($lines as $line){
                $vid=(int)($line['variante_id']??0);$qty=round(((float)($line['quantite']??0))*2)/2;$price=max(0,(float)($line['prix']??0));
                if($vid<1||$qty<0.5)continue;
                $lock->bind_param('ii',$emp,$vid);$lock->execute();$p=$lock->get_result()->fetch_assoc();
                if(!$p)throw new RuntimeException("Variante $vid introuvable");
                if($qty>(float)$p['q']+0.00001)throw new RuntimeException("Stock serveur insuffisant : ".$p['name']);
                $des=trim((string)($line['designation']??''));if($des===''){$des=$p['name'];$d=[];if($p['couleur']!=='')$d[]=$p['couleur'];if($p['taille']!=='')$d[]='Taille '.$p['taille'];if($d)$des.=' ('.implode(' • ',$d).')';}
                $lt=round($qty*$price,2);$subtotal+=$lt;$validated[]=['variante_id'=>$vid,'designation'=>$des,'quantite'=>$qty,'prix'=>$price,'total_ligne'=>$lt];
            }
            $lock->close();if(!$validated)throw new RuntimeException("Aucune ligne valide");
            $subtotal=round($subtotal,2);$rem=min($subtotal,$rem);$mtva=round($subtotal*$tax/100,2);
            $total=round(max(0,$subtotal+$mtva-$rem+$delivery),2);
            $change=max(0,round($received-$total,2));
            $payStatus=$received<=0.00001?'EN_ATTENTE':($received+0.00001<$total?'PARTIEL':'PAYE');
            if(str_contains($requestedStatus,'PART') && $received>0 && $received<$total)$payStatus='PARTIEL';

            $next=$conn->query("SELECT COALESCE(MAX(id),0)+1 n FROM ventes")->fetch_assoc()['n'];
            $num='VTE-MOB-'.date('Ymd').'-'.str_pad((string)$next,5,'0',STR_PAD_LEFT);
            $date=(string)($sale['date_vente']??date('Y-m-d H:i:s'));
            if($delivery>0&&!isset(cols($conn,'ventes')['frais_livraison']))$comment=trim($comment." | Livraison ".number_format($delivery,0,',',' ')." Ar");
            $venteData=['num_vente'=>$num,'client_id'=>$clientServer>0?$clientServer:null,'emplacement_id'=>$emp,'date_vente'=>$date,
                'sous_total'=>$subtotal,'remise'=>$rem,'frais_livraison'=>$delivery,'montant_total'=>$total,'montant_recu'=>$received,
                'monnaie_rendue'=>$change,'mode_paiement'=>$mode,'statut'=>'VALIDEE','commentaire'=>$comment];
            $venteId=insert_filtered($conn,'ventes',$venteData);

            foreach($validated as $x){
                insert_filtered($conn,'vente_lignes',['vente_id'=>$venteId,'variante_id'=>$x['variante_id'],'designation'=>$x['designation'],
                    'quantite'=>$x['quantite'],'prix_unitaire'=>$x['prix'],'total_ligne'=>$x['total_ligne']]);
                $u=$conn->prepare("UPDATE stocks SET quantite=quantite-? WHERE variante_id=? AND emplacement_id=? AND quantite>=?");
                $u->bind_param('diid',$x['quantite'],$x['variante_id'],$emp,$x['quantite']);$u->execute();
                if($u->affected_rows!==1)throw new RuntimeException("Stock modifié pendant la synchro : ".$x['designation']);$u->close();
                if(table_exists($conn,'stock_mouvements'))insert_filtered($conn,'stock_mouvements',[
                    'reference'=>$num,'type_mouvement'=>'SORTIE','variante_id'=>$x['variante_id'],'emplacement_source_id'=>$emp,
                    'emplacement_destination_id'=>null,'quantite'=>$x['quantite'],'motif'=>'VENTE_POS','commentaire'=>'Vente mobile '.$num,'date_mouvement'=>$date]);
            }

            if(table_exists($conn,'factures')){
                $numFac='FAC-'.$num;$rateRem=$subtotal>0?round($rem/$subtotal*100,4):0;
                $facId=insert_filtered($conn,'factures',['num_facture'=>$numFac,'client_id'=>$clientServer>0?$clientServer:null,'date_facture'=>$date,
                    'montant_ht'=>$subtotal,'taux_remise'=>$rateRem,'remise'=>$rem,'taux_tva'=>$tax,'montant_tva'=>$mtva,
                    'frais_livraison'=>$delivery,'montant_ttc'=>$total,'statut_paiement'=>$payStatus,'mode_paiement'=>$mode,
                    'notes'=>'Facture générée depuis ZAZAH Android SQLite '.$uuid]);
                if(table_exists($conn,'lignes_facture'))foreach($validated as $x)insert_filtered($conn,'lignes_facture',[
                    'facture_id'=>$facId,'variante_id'=>$x['variante_id'],'emplacement_id'=>$emp,'designation_copie'=>$x['designation'],
                    'prix_applique'=>$x['prix'],'quantite'=>$x['quantite']]);
            }
            if($received>0.00001 && table_exists($conn,'vente_paiements'))insert_filtered($conn,'vente_paiements',[
                'vente_id'=>$venteId,'mode_paiement'=>$mode,'montant'=>min($received,$total),'reference_paiement'=>'MOBILE-SQLITE','date_paiement'=>$date]);

            $m=$conn->prepare("INSERT INTO mobile_sync_sales(uuid,vente_id,num_vente) VALUES(?,?,?)");
            $m->bind_param('sis',$uuid,$venteId,$num);$m->execute();$m->close();
            $conn->commit();$accepted[]=['uuid'=>$uuid,'server_id'=>$venteId,'num_vente'=>$num];
        }catch(Throwable $e){
            $conn->rollback();
            $rejected[]=[
                'uuid'=>$uuid,
                'num_vente'=>(string)($sale['num_vente']??''),
                'error'=>$e->getMessage()
            ];
            continue;
        }
    }
    out(['ok'=>true,'accepted'=>$accepted,'rejected'=>$rejected,'server_time'=>date('Y-m-d H:i:s')]);
}catch(Throwable $e){out(['ok'=>false,'error'=>$e->getMessage()],500);}
