<?php
// ZAZAH ERP V5.25.31 — synchronisation bidirectionnelle des packs + token robuste.
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; connect-src 'self'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1"><title>ZAZAH Sync</title>
<style>:root{color-scheme:light}*{box-sizing:border-box}body{margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,Helvetica,sans-serif}.wrap{max-width:720px;margin:0 auto;padding:18px}.card{background:#fff;border-radius:16px;padding:18px;box-shadow:0 8px 26px rgba(15,23,42,.08)}.logo{display:flex;align-items:center;gap:10px;font-weight:900;font-size:21px}.dot{width:14px;height:14px;border-radius:50%;background:#f97316}.small{font-size:13px;color:#64748b;line-height:1.45}.status{margin-top:16px;padding:14px;border-radius:12px;background:#eff6ff;color:#1e3a8a;white-space:pre-wrap;font-weight:700;line-height:1.45}.status.ok{background:#dcfce7;color:#166534}.status.err{background:#fee2e2;color:#991b1b}.progress{height:9px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-top:14px}.bar{height:100%;width:0;background:#16a34a;transition:width .25s ease}button{width:100%;border:0;border-radius:11px;padding:14px;margin-top:16px;background:#16a34a;color:#fff;font-size:16px;font-weight:800}button:disabled{opacity:.55}</style>
</head><body><div class="wrap"><div class="card"><div class="logo"><span class="dot"></span>ZAZAH ERP — Sync SQLite</div><p class="small">Clients, salariés, avances, variants, ajouts stock, packs et ventes sont envoyés avant le rechargement du catalogue. Les packs créés ou modifiés en ligne sont ensuite téléchargés vers SQLite.</p><div id="status" class="status">Initialisation…</div><div class="progress"><div id="bar" class="bar"></div></div><button id="retry" type="button">Synchroniser maintenant</button></div></div>
<script>
(function(){'use strict';
const SYNC_VERSION='5.25.31';
const statusEl=document.getElementById('status'),bar=document.getElementById('bar'),retry=document.getElementById('retry');let running=false;
function nativeAvailable(){return typeof window.ZazahAndroid!=='undefined'&&typeof window.ZazahAndroid.getToken==='function'}
function setStatus(text,kind,progress){statusEl.textContent=text;statusEl.className='status'+(kind?' '+kind:'');if(typeof progress==='number')bar.style.width=Math.max(0,Math.min(100,progress))+'%';try{if(nativeAvailable())window.ZazahAndroid.reportStatus(String(text))}catch(e){}}
function parseJsonText(text,label){try{return JSON.parse(text)}catch(e){const start=String(text||'').slice(0,180).replace(/\s+/g,' ');throw new Error(label+' : réponse non JSON ('+start+')')}}
function apiPathWithToken(path,token){const sep=String(path).indexOf('?')>=0?'&':'?';return path+sep+'sync_token='+encodeURIComponent(token)}
async function api(path,options,token){const opt=Object.assign({cache:'no-store',credentials:'same-origin'},options||{});opt.headers=Object.assign({},opt.headers||{}, {'X-ZAZAH-SYNC-TOKEN':token,'X-Auth-Token':token,'Authorization':'Bearer '+token});const url=apiPathWithToken(path,token);const r=await fetch(url,opt);const text=await r.text();const json=parseJsonText(text,path);if(!r.ok||!json.ok)throw new Error(json.error||('HTTP '+r.status));return{json,text}}
function sendCatalogInChunks(text){const session='cat-'+Date.now()+'-'+Math.random().toString(16).slice(2),chunkSize=48000;window.ZazahAndroid.catalogBegin(session);for(let i=0;i<text.length;i+=chunkSize)window.ZazahAndroid.catalogChunk(session,text.slice(i,i+chunkSize));window.ZazahAndroid.catalogEnd(session)}
async function run(){if(running)return;running=true;retry.disabled=true;try{
 if(!nativeAvailable())throw new Error('Ouvrez cette page depuis l’application ZAZAH Android.');
 const token=String(window.ZazahAndroid.getToken()||'');if(!token)throw new Error('Token Android vide. Vérifiez Paramètres serveur.');
 const headers={'Content-Type':'application/json; charset=utf-8'};

 setStatus('1/4 — Lecture clients, salariés, avances, variants et ajouts stock SQLite…','',10);
 let master=parseJsonText(String(window.ZazahAndroid.getPendingMasterData()||'{}'),'SQLite master');
 const masterCount=(Array.isArray(master.clients)?master.clients.length:0)+(Array.isArray(master.salaries)?master.salaries.length:0)+(Array.isArray(master.avances)?master.avances.length:0)+(Array.isArray(master.variants)?master.variants.length:0)+(Array.isArray(master.stocks)?master.stocks.length:0)+(Array.isArray(master.packs)?master.packs.length:0);
 let clientsSent=0,salariesSent=0,advancesSent=0,variantsSent=0,stocksSent=0,packsSent=0;
 if(masterCount){
   setStatus('1/4 — Envoi de '+masterCount+' modification(s) vers MySQL…','',24);
   const pushed=await api('push_masterdata.php',{method:'POST',headers,body:JSON.stringify(master)},token);
   clientsSent=Array.isArray(pushed.json.clients)?pushed.json.clients.length:0;
   salariesSent=Array.isArray(pushed.json.salaries)?pushed.json.salaries.length:0;
   advancesSent=Array.isArray(pushed.json.avances)?pushed.json.avances.length:0;
   variantsSent=Array.isArray(pushed.json.variants)?pushed.json.variants.length:0;
   stocksSent=Array.isArray(pushed.json.stocks)?pushed.json.stocks.length:0;
   packsSent=Array.isArray(pushed.json.packs)?pushed.json.packs.length:0;
   window.ZazahAndroid.markMasterDataSynced(JSON.stringify(pushed.json));
 } else setStatus('1/4 — Aucun client/salarié/avance/variant/stock local à envoyer.','',28);

 setStatus('2/4 — Lecture des ventes SQLite…','',38);
 let pending=parseJsonText(String(window.ZazahAndroid.getPendingSales()||'[]'),'SQLite ventes');if(!Array.isArray(pending))pending=[];
 let salesSent=0;
 if(pending.length){
   setStatus('2/4 — Envoi de '+pending.length+' vente(s) au serveur…','',52);
   const push=await api('push_sales.php',{method:'POST',headers,body:JSON.stringify({sales:pending})},token);
   const accepted=Array.isArray(push.json.accepted)?push.json.accepted:[];window.ZazahAndroid.markSalesSynced(JSON.stringify(accepted));salesSent=accepted.length;
 } else setStatus('2/4 — Aucune vente locale à envoyer.','',56);

 setStatus('3/4 — Téléchargement produits, packs, clients, salariés, paies, avances, paiements et stock…','',68);
 const cat=await api('catalog.php',{method:'GET'},token);
 const payrollReceived=Array.isArray(cat.json.paies)?cat.json.paies.length:0;
 const advancesReceived=Array.isArray(cat.json.avances)?cat.json.avances.length:0;
 const payrollPaymentsReceived=Array.isArray(cat.json.paie_paiements)?cat.json.paie_paiements.length:0;
 const packsReceived=Array.isArray(cat.json.packs)?cat.json.packs.length:0;
 setStatus('4/4 — Mise à jour de SQLite…','',84);sendCatalogInChunks(cat.text);
 const remainingMaster=Number(window.ZazahAndroid.getPendingMasterCount()||0),remainingSales=Number(window.ZazahAndroid.getPendingCount()||0);
 const msg='Synchronisation terminée.\n'+clientsSent+' client(s) envoyé(s).\n'+salariesSent+' salarié(s) envoyé(s).\n'+advancesSent+' avance(s) envoyée(s).\n'+packsSent+' pack(s) envoyé(s).\n'+packsReceived+' pack(s) reçu(s).\n'+payrollReceived+' paie(s) reçue(s).\n'+advancesReceived+' avance(s) reçue(s).\n'+payrollPaymentsReceived+' paiement(s) paie reçu(s).\n'+variantsSent+' variant(s) envoyé(s).\n'+stocksSent+' ajout(s) stock envoyé(s).\n'+salesSent+' vente(s) envoyée(s).\n'+remainingMaster+' opération(s) master restante(s).\n'+remainingSales+' vente(s) restante(s).';
 setStatus(msg,'ok',100);window.ZazahAndroid.finishSync(msg);
 }catch(e){const msg=(e&&e.message)?e.message:String(e);setStatus('Erreur de synchronisation :\n'+msg+'\n\nLes données restent dans SQLite.','err',0);try{if(nativeAvailable())window.ZazahAndroid.reportError(msg)}catch(_){}}finally{running=false;retry.disabled=false}}
retry.addEventListener('click',run);setTimeout(run,700);
})();
</script></body></html>
