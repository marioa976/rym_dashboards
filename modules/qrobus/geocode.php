<?php
/**
 * Qrobus · Geocodificador.
 *  - Manual: pegas una dirección → latitud/longitud (no escribe la tabla).
 *  - Masivo: recorre dwh_unidos sin coordenadas y llena latitud/longitud.
 * Escritura sobre la tabla ⇒ require_editor('qrobus').
 */
declare(strict_types=1);
set_time_limit(0);
ini_set('memory_limit', '512M');
ignore_user_abort(true);

require_once __DIR__ . '/config.php';        // guard: require_module('qrobus')
require_once __DIR__ . '/lib.php';
if (PHP_SAPI !== 'cli') require_editor('qrobus');   // herramienta de escritura

$cfg    = qb_config();
$apiKey = $cfg['google_maps_api_key'] ?? '';
$gm     = $cfg['geocode'] ?? [];
$tabla  = qb_tabla();
// Costo estimado de la Geocoding API (US$5.00 / 1,000; ~10,000 gratis/mes desde mar-2025). Editable por env.
$USD_1000 = (float)(getenv('QROBUS_GEO_USD_1000') ?: 5.0);
$FREE_MES = (int)(getenv('QROBUS_GEO_FREE_MES') ?: 10000);
$SINCOORDS = "latitud IS NOT NULL AND latitud <> 0 AND longitud IS NOT NULL AND longitud <> 0";
$CONDIR    = "(TRIM(COALESCE(calle,''))<>'' OR TRIM(COALESCE(colonia,''))<>'' OR TRIM(COALESCE(municipio,''))<>'')";

/* ------------------------------ AJAX ------------------------------ */
$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $pdo = qb_pdo();

        if ($action === 'stats') { echo json_encode(qb_stats($pdo)); exit; }

        if ($action === 'manual') {
            if (!$apiKey) throw new RuntimeException('Falta GOOGLE_MAPS_API_KEY en la configuración.');
            $dir = trim((string)($_POST['direccion'] ?? ''));
            if ($dir === '') throw new RuntimeException('Escribe una dirección.');
            $r = qb_geocode($pdo, $dir, $apiKey, $gm);
            echo json_encode(array_merge(['ok' => $r['status'] === 'OK', 'query' => $dir], $r)); exit;
        }

        if ($action === 'buscar') {
            $q = trim((string)($_POST['q'] ?? ''));
            $soloPend = (($_POST['pend'] ?? '1') !== '0');
            $w = []; $p = [];
            if ($soloPend) $w[] = "NOT ($SINCOORDS)";
            if ($q !== '') {
                if (ctype_digit($q)) { $w[] = "id_tramite = ?"; $p[] = (int)$q; }
                else {
                    $w[] = "(colonia LIKE ? OR calle LIKE ? OR municipio LIKE ? OR curp LIKE ?)";
                    $like = "%$q%"; array_push($p, $like, $like, $like, $like);
                }
            }
            $wsql = $w ? ('WHERE ' . implode(' AND ', $w)) : '';
            $st = $pdo->prepare("SELECT id_tramite, calle, colonia, municipio, cp, latitud, longitud
                                   FROM `$tabla` $wsql ORDER BY id_tramite LIMIT 25");
            $st->execute($p);
            echo json_encode(['items' => $st->fetchAll()]); exit;
        }

        if ($action === 'save_one') {
            $id  = (int)($_POST['id'] ?? 0);
            $lat = (float)($_POST['lat'] ?? 0);
            $lng = (float)($_POST['lng'] ?? 0);
            if (!$id || !$lat || !$lng) throw new RuntimeException('Faltan id/lat/lng.');
            $st = $pdo->prepare("UPDATE `$tabla` SET latitud=?, longitud=? WHERE id_tramite=?");
            $st->execute([$lat, $lng, $id]);
            echo json_encode(['ok' => true, 'id' => $id, 'stats' => qb_stats($pdo)]); exit;
        }

        if ($action === 'batch') {
            if (!$apiKey) throw new RuntimeException('Falta GOOGLE_MAPS_API_KEY en la configuración.');
            $lote = max(1, min(50, (int)($_POST['lote'] ?? 10)));
            // Opcional: saltar registros con C.P. vacío.
            $reqCp = (($_POST['req_cp'] ?? '0') === '1');
            $wCp   = $reqCp ? " AND TRIM(COALESCE(cp,''))<>'' " : "";
            $sel = $pdo->query("SELECT id_tramite, calle, colonia, municipio, cp
                                  FROM `$tabla`
                                 WHERE NOT ($SINCOORDS) AND $CONDIR $wCp
                              ORDER BY id_tramite LIMIT $lote");
            $rows = $sel->fetchAll();
            $upd = $pdo->prepare("UPDATE `$tabla` SET latitud=?, longitud=? WHERE id_tramite=?");
            $items = []; $okN = 0; $failN = 0;
            foreach ($rows as $row) {
                $q = qb_query_dir($row);
                if ($q === '') { $failN++; $items[] = ['id'=>(int)$row['id_tramite'], 'status'=>'SIN_DIRECCION']; continue; }
                $g = qb_geocode($pdo, $q, $apiKey, $gm, 120000);
                if ($g['status'] === 'OK' && $g['lat'] && $g['lng']) {
                    $upd->execute([$g['lat'], $g['lng'], (int)$row['id_tramite']]);
                    $okN++;
                    $items[] = ['id'=>(int)$row['id_tramite'], 'status'=>'OK', 'lat'=>$g['lat'], 'lng'=>$g['lng'],
                                'dir'=>$g['formatted_address'], 'cache'=>$g['cache_hit']];
                } else {
                    $failN++;
                    $items[] = ['id'=>(int)$row['id_tramite'], 'status'=>$g['status'], 'q'=>$q];
                }
            }
            $st = qb_stats($pdo);
            // Restantes según el mismo filtro (respeta "ignorar sin C.P.").
            $restantes = (int)$pdo->query("SELECT COUNT(*) FROM `$tabla` WHERE NOT ($SINCOORDS) AND $CONDIR $wCp")->fetchColumn();
            echo json_encode(['procesados'=>count($rows), 'ok'=>$okN, 'fail'=>$failN,
                              'items'=>$items, 'stats'=>$st, 'restantes'=>$restantes]); exit;
        }
        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        echo json_encode(['error' => $e->getMessage()]); exit;
    }
}

/* ------------------------------ HTML ------------------------------ */
$stats = null; $dbError = null;
try { $stats = qb_stats(qb_pdo()); } catch (Throwable $e) { $dbError = $e->getMessage(); }
?><!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Qrobus · Geocodificar</title>
  <link rel="stylesheet" href="../../assets/css/qro.css">
  <style>
    .qb-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media(max-width:900px){.qb-grid{grid-template-columns:1fr}}
    #qb-map{height:260px;border-radius:10px;border:1px solid var(--qro-border);margin-top:10px;overflow:hidden}
    .qb-res{font-size:13px;margin-top:10px}
    .qb-res .row{display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px dashed #eef0f2}
    .qb-bar{height:12px;background:#eef2f6;border-radius:999px;overflow:hidden;margin:8px 0}
    .qb-bar>span{display:block;height:100%;background:linear-gradient(90deg,var(--qro-blue),var(--qro-success));width:0;transition:width .3s}
    .qb-log{font-family:Menlo,Consolas,monospace;font-size:11px;max-height:220px;overflow:auto;margin-top:10px;color:#475569;background:#f8fafc;border:1px solid var(--qro-border);border-radius:8px;padding:8px}
    .badge-ok{color:#188a5b;font-weight:700}.badge-bad{color:#ce3a2b;font-weight:700}
  </style>
</head>
<body>
<?php $portalModulo = 'Qrobus'; $navActive = 'geocode'; include __DIR__ . '/_nav.php'; ?>

<main class="content" style="padding:28px 32px">
  <div class="page-head"><h1>Geocodificador</h1><p class="text-secondary">Convierte direcciones en coordenadas para los beneficiarios de <strong>Unidos</strong>.</p></div>

  <?php if ($dbError): ?>
    <div class="alert alert-danger">No se pudo conectar a la BD de Qrobus (<code>QROBUS_DB_*</code>).<br><span style="font-size:12px"><?= htmlspecialchars($dbError) ?></span></div>
  <?php endif; ?>
  <?php if (!$apiKey): ?>
    <div class="alert alert-danger">Falta <code>GOOGLE_MAPS_API_KEY</code>: la geocodificación no funcionará hasta configurarla.</div>
  <?php endif; ?>

  <div class="qb-grid">
    <!-- Manual -->
    <div class="card">
      <h2 style="margin-top:0">Probar una dirección</h2>
      <div class="field"><label>Buscar registro de la tabla (id, colonia, calle o CURP)</label>
        <input id="qb-buscar" class="input" placeholder="Ej. 12345 o &quot;Centro&quot;">
      </div>
      <div id="qb-lista" style="max-height:170px;overflow:auto;margin-bottom:10px"></div>
      <div class="field"><label>Dirección</label>
        <input id="qb-dir" class="input" placeholder="Ej. Av. 5 de Febrero 100, Centro, Querétaro">
      </div>
      <button class="btn btn-primary" id="qb-btn-manual">Probar</button>
      <span id="qb-sel" class="text-secondary" style="font-size:12px;margin-left:8px"></span>
      <div id="qb-res" class="qb-res"></div>
      <div id="qb-map"></div>
    </div>

    <!-- Masivo -->
    <div class="card">
      <h2 style="margin-top:0">Carga masiva de <span class="mono"><?= htmlspecialchars($tabla) ?></span></h2>
      <p class="text-secondary" style="font-size:13px">Procesa beneficiarios sin coordenadas y con dirección, en lotes. Usa caché para no gastar la API dos veces.</p>
      <div id="qb-stats" style="font-size:13px;margin-bottom:6px">
        <?php if ($stats): ?>
          <strong><?= number_format($stats['con_coords']) ?></strong> con coords ·
          <strong><?= number_format($stats['pendientes']) ?></strong> pendientes ·
          <?= number_format($stats['total']) ?> totales
        <?php endif; ?>
      </div>
      <div class="qb-bar"><span id="qb-progress"></span></div>
      <div id="qb-costo" style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:8px 10px;font-size:12px;color:#92400e;margin:8px 0"></div>
      <div style="display:flex;gap:10px;align-items:end;margin-top:8px">
        <div class="field" style="margin:0"><label>Por lote</label>
          <select id="qb-lote" class="input" style="width:90px"><option>10</option><option>25</option><option selected>50</option></select>
        </div>
        <div class="field" style="margin:0"><label style="white-space:nowrap"><input type="checkbox" id="qb-req-cp"> Ignorar sin C.P.</label></div>
        <button class="btn btn-primary" id="qb-btn-batch">▶ Iniciar</button>
        <button class="btn btn-secondary" id="qb-btn-stop" style="display:none">⏹ Detener</button>
      </div>
      <div id="qb-batch-status" class="text-secondary" style="font-size:12px;margin-top:8px"></div>
      <div id="qb-log" class="qb-log" style="display:none"></div>
    </div>
  </div>
</main>

<script>
const HASKEY = <?= $apiKey ? 'true' : 'false' ?>;
const USD1000 = <?= json_encode($USD_1000) ?>, FREE = <?= (int)$FREE_MES ?>, PEND0 = <?= (int)($stats['pendientes'] ?? 0) ?>;
let apiCalls = 0;
const $ = id => document.getElementById(id);
function renderCosto(pend){
  const el=$('qb-costo'); if(!el) return;
  const cp = pend/1000*USD1000, cs = apiCalls/1000*USD1000;
  el.innerHTML = `💲 Geocodificar los <b>${pend.toLocaleString()}</b> pendientes ≈ <b>US$${cp.toFixed(2)}</b> `
    + `<span style="opacity:.85">(US$${USD1000.toFixed(2)}/1,000 · ~${FREE.toLocaleString()} gratis/mes · la caché no recobra direcciones repetidas)</span>`
    + `<br>Esta sesión: <b>${apiCalls.toLocaleString()}</b> llamadas a la API ≈ <b>US$${cs.toFixed(2)}</b>`;
}
function esc(s){ return String(s??'').replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
async function post(action, data){
  const fd = new FormData(); fd.append('action', action);
  for (const k in data) fd.append(k, data[k]);
  const r = await fetch('geocode.php', {method:'POST', body:fd});
  return r.json();
}

/* ---- Mapa (opcional, para el modo manual) ---- */
let map, marker;
window.initQbMap = function(){
  if(!$('qb-map')) return;
  map = new google.maps.Map($('qb-map'), {center:{lat:20.59,lng:-100.39}, zoom:11, mapTypeControl:false, streetViewControl:false});
};
function ponMarcador(lat,lng){
  if(!map) return;
  const p={lat:+lat,lng:+lng};
  map.setCenter(p); map.setZoom(16);
  if(marker) marker.setMap(null);
  marker = new google.maps.Marker({position:p, map});
}

/* ---- Actualiza los indicadores de avance ---- */
function updateStats(s){
  if(!s) return;
  const p = s.total>0 ? Math.round(s.con_coords/s.total*100) : 0;
  if($('qb-progress')) $('qb-progress').style.width = p+'%';
  if($('qb-stats')) $('qb-stats').innerHTML =
    `<strong>${s.con_coords.toLocaleString()}</strong> con coords · <strong>${s.pendientes.toLocaleString()}</strong> pendientes · ${s.total.toLocaleString()} totales`;
}

/* ---- Buscar y seleccionar un registro de la tabla ---- */
let selectedId = null;
function composeDir(r){
  const p = [];
  for(const k of ['calle','colonia','municipio']){ const v=(r[k]||'').trim(); if(v) p.push(v); }
  if((r.cp||'').trim()) p.push('C.P. '+r.cp.trim());
  p.push('Querétaro, México');
  return p.join(', ');
}
function seleccionar(r){
  selectedId = +r.id_tramite;
  $('qb-dir').value = composeDir(r);
  $('qb-sel').innerHTML = 'Registro <b>#'+selectedId+'</b> seleccionado';
  $('qb-lista').innerHTML = '';
  $('qb-buscar').value = '';
}
let buscarTO=null;
$('qb-buscar').addEventListener('input', () => {
  clearTimeout(buscarTO);
  const q = $('qb-buscar').value.trim();
  if(q.length < 2){ $('qb-lista').innerHTML=''; return; }
  buscarTO = setTimeout(async () => {
    const r = await post('buscar', {q});
    if(r.error){ $('qb-lista').innerHTML='<div class="text-secondary" style="font-size:12px">'+esc(r.error)+'</div>'; return; }
    if(!r.items.length){ $('qb-lista').innerHTML='<div class="text-secondary" style="font-size:12px">Sin coincidencias.</div>'; return; }
    $('qb-lista').innerHTML = r.items.map((it,i)=>{
      const dir = [it.calle,it.colonia,it.municipio].filter(Boolean).join(', ');
      const ya = (it.latitud && it.longitud) ? ' <span class="badge-ok" style="font-size:11px">✓ con coords</span>' : '';
      return `<div class="qb-item" data-i="${i}" style="padding:6px 8px;border-bottom:1px solid #eef0f2;cursor:pointer;font-size:12px">
        <b>#${it.id_tramite}</b> · ${esc(dir||'(sin dirección)')}${ya}</div>`;
    }).join('');
    document.querySelectorAll('#qb-lista .qb-item').forEach(el=>el.addEventListener('click',()=>seleccionar(r.items[+el.dataset.i])));
  }, 300);
});

/* ---- Manual: probar la dirección (y guardar si hay registro seleccionado) ---- */
$('qb-btn-manual').addEventListener('click', async () => {
  const dir = $('qb-dir').value.trim();
  if(!dir){ $('qb-res').innerHTML='<span class="badge-bad">Escribe o selecciona una dirección.</span>'; return; }
  $('qb-res').innerHTML='<span class="text-secondary">Buscando…</span>';
  const r = await post('manual', {direccion:dir});
  if(r.error){ $('qb-res').innerHTML='<span class="badge-bad">'+esc(r.error)+'</span>'; return; }
  if(!r.ok){ $('qb-res').innerHTML='<span class="badge-bad">Sin resultado ('+esc(r.status)+')</span>'; return; }
  const gmaps = 'https://www.google.com/maps?q='+r.lat+','+r.lng;
  const saveBtn = selectedId ? `<button class="btn btn-primary" id="qb-save" data-id="${selectedId}" data-lat="${r.lat}" data-lng="${r.lng}">💾 Guardar en #${selectedId}</button>` : '';
  $('qb-res').innerHTML = `
    <div class="row"><span>Latitud</span><b>${r.lat}</b></div>
    <div class="row"><span>Longitud</span><b>${r.lng}</b></div>
    <div class="row"><span>Precisión</span><b>${esc(r.location_type||'—')}${r.cache_hit?' · (caché)':''}</b></div>
    <div class="row"><span>Dirección</span><b style="max-width:60%;text-align:right">${esc(r.formatted_address||'')}</b></div>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap">
      ${saveBtn}
      <button class="btn btn-secondary" onclick="navigator.clipboard.writeText('${r.lat}, ${r.lng}')">Copiar lat,lng</button>
      <a class="btn btn-secondary" target="_blank" href="${gmaps}">Ver en Google Maps</a>
    </div>
    <div id="qb-save-msg" style="font-size:12px;margin-top:6px"></div>`;
  ponMarcador(r.lat, r.lng);
  const sb = $('qb-save');
  if(sb) sb.addEventListener('click', async () => {
    sb.disabled = true; $('qb-save-msg').textContent = 'Guardando…';
    const s = await post('save_one', {id:sb.dataset.id, lat:sb.dataset.lat, lng:sb.dataset.lng});
    if(s.error){ $('qb-save-msg').innerHTML='<span class="badge-bad">'+esc(s.error)+'</span>'; sb.disabled=false; return; }
    $('qb-save-msg').innerHTML='<span class="badge-ok">✓ Guardado en #'+s.id+'</span>';
    updateStats(s.stats); selectedId=null; $('qb-sel').textContent='';
  });
});

/* ---- Masivo ---- */
let stop=false;
function pct(s){ return s.total>0 ? Math.round(s.con_coords/s.total*100) : 0; }
$('qb-btn-batch').addEventListener('click', async () => {
  stop=false; $('qb-btn-batch').style.display='none'; $('qb-btn-stop').style.display='';
  $('qb-log').style.display='block'; $('qb-log').innerHTML='';
  const lote = +$('qb-lote').value || 10;
  const reqCp = $('qb-req-cp').checked ? 1 : 0;
  let totOk=0, totFail=0;
  while(!stop){
    $('qb-batch-status').textContent='Procesando lote…';
    const r = await post('batch', {lote, req_cp:reqCp});
    if(r.error){ $('qb-batch-status').innerHTML='<span class="badge-bad">'+esc(r.error)+'</span>'; break; }
    totOk+=r.ok; totFail+=r.fail;
    apiCalls += (r.items||[]).filter(i=>i.status!=='SIN_DIRECCION' && !i.cache).length;  // caché no cuenta
    for(const it of r.items){
      const ok = it.status==='OK';
      $('qb-log').insertAdjacentHTML('afterbegin',
        `<div>${ok?'<span class="badge-ok">✓</span>':'<span class="badge-bad">✗</span>'} #${it.id} · ${ok?(it.lat.toFixed(5)+', '+it.lng.toFixed(5)+(it.cache?' (caché)':'')):esc(it.status)}</div>`);
    }
    if(r.stats){ $('qb-progress').style.width=pct(r.stats)+'%';
      $('qb-stats').innerHTML=`<strong>${r.stats.con_coords.toLocaleString()}</strong> con coords · <strong>${r.stats.pendientes.toLocaleString()}</strong> pendientes · ${r.stats.total.toLocaleString()} totales`; }
    renderCosto(r.restantes);
    $('qb-batch-status').textContent=`Acumulado: ${totOk} geocodificados, ${totFail} con error · restantes ${r.restantes.toLocaleString()}`;
    if(r.procesados===0 || r.restantes===0){ $('qb-batch-status').textContent=`✓ Terminado. ${totOk} geocodificados, ${totFail} con error.`; break; }
    await new Promise(res=>setTimeout(res,300));
  }
  $('qb-btn-batch').style.display=''; $('qb-btn-stop').style.display='none';
});
$('qb-btn-stop').addEventListener('click', ()=>{ stop=true; $('qb-btn-stop').style.display='none'; });

renderCosto(PEND0);
if(!HASKEY){ if($('qb-map')) $('qb-map').innerHTML='<div style="padding:20px;color:#991B1B">Google Maps API key no configurada.</div>'; }
</script>
<?php if ($apiKey): ?>
<script async src="https://maps.googleapis.com/maps/api/js?key=<?= urlencode($apiKey) ?>&callback=initQbMap&loading=async&v=weekly"></script>
<?php endif; ?>
</body>
</html>
