<?php
/**
 * QroBici · Dashboard (Claude) — enlace al Claude Dashboard de movilidad.
 *
 * El dashboard vive en claude.ai (hosteado, compartible por link). Esta página
 * del portal solo lo presenta y lleva a él; no embebe (es cross-origin a claude.ai).
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/guard.php';
require_module('qrobici');

// URL del Claude Dashboard de QroBici.
const QB_DASH_URL = 'https://claude.ai/artifact/Hf8WDw5cPe9t85EWmbJBnb';

$ktTitle  = 'QroBici · Dashboard (Claude)';
$ktActive = 'qrobici';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
:where(#qbd){ max-width:720px; }
:where(#qbd) .card{ background:var(--card); border:1px solid var(--border); border-radius:16px; padding:28px; }
:where(#qbd) .eyebrow{ font-size:11px; font-weight:700; letter-spacing:.14em; text-transform:uppercase; color:var(--muted-foreground); }
:where(#qbd) h2{ font-size:22px; font-weight:800; letter-spacing:-.01em; margin:6px 0 8px; color:var(--primary); }
:where(#qbd) p{ color:var(--muted-foreground); font-size:14.5px; line-height:1.55; margin:0 0 10px; }
:where(#qbd) .btn{ display:inline-flex; align-items:center; gap:9px; height:46px; padding:0 22px; border-radius:10px; background:var(--primary); color:#fff; font-weight:700; font-size:15px; text-decoration:none; margin-top:8px; }
:where(#qbd) .btn:hover{ filter:brightness(1.06); }
:where(#qbd) .note{ display:flex; gap:10px; margin-top:18px; padding:13px 15px; background:var(--muted); border-radius:10px; font-size:13px; color:var(--foreground); }
:where(#qbd) .note i{ color:var(--primary); flex-shrink:0; margin-top:1px; }
:where(#qbd) .chips{ display:flex; flex-wrap:wrap; gap:8px; margin:16px 0 4px; }
:where(#qbd) .chip{ font-size:12px; font-weight:600; color:var(--muted-foreground); background:var(--muted); border:1px solid var(--border); border-radius:100px; padding:4px 11px; }
</style>

<div id="qbd">
  <div class="card">
    <div class="eyebrow">QroBici · Movilidad</div>
    <h2>Dashboard de movilidad</h2>
    <p>Tablero de indicadores de los viajes de bicicleta pública: viajes totales, usuarios,
       distancia, velocidad, viajes por día y por hora, reparto mecánica/eléctrica y las
       estaciones más usadas. Hecho como <b>Claude Dashboard</b> (hosteado y compartible por
       link), con cada número citando su fuente.</p>
    <div class="chips">
      <span class="chip">Viajes por día</span>
      <span class="chip">Por hora</span>
      <span class="chip">Mecánica vs eléctrica</span>
      <span class="chip">Top estaciones</span>
    </div>
    <a class="btn" href="<?= QB_DASH_URL ?>" target="_blank" rel="noopener">
      <i class="ki-filled ki-chart-line"></i> Abrir dashboard
    </a>
    <div class="note">
      <i class="ki-filled ki-information-2"></i>
      <div>Se abre en <b>claude.ai</b> (requiere tu sesión de Claude). Es un tablero de cifras
        <b>guardadas</b> del periodo, no una consulta en vivo. Para que otras personas del
        equipo puedan abrirlo, compártelo desde el menú <b>Share</b> del propio dashboard.</div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
