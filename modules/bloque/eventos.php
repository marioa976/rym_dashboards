<?php
/**
 * Bloque · Eventos — cupo, asistencia y ocupación por evento.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';     // guard: require_module('bloque')
require_once __DIR__ . '/lib.php';

$eventos = []; $dbError = null;
try { $eventos = bloq_eventos(bloq_pdo()); }
catch (Throwable $e) { $dbError = $e->getMessage(); }

function bl_fecha(?string $d): string { return $d ? date('d/m/Y', strtotime($d)) : '—'; }
function bl_ocupColor($p): string {
    if ($p === null) return '#c9ced6';
    return $p >= 90 ? '#c0392b' : ($p >= 70 ? '#e0872b' : ($p >= 40 ? '#2a9eda' : '#7cb342'));
}
?><?php
$ktTitle  = 'Bloque · Eventos';
$ktActive = 'bloque';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
  <style>
    table.bl-tbl{width:100%;border-collapse:collapse;font-size:13px;background:#fff}
    table.bl-tbl th,table.bl-tbl td{padding:9px 12px;border-bottom:1px solid #eef0f2;text-align:left;white-space:nowrap}
    table.bl-tbl th{position:sticky;top:0;background:#eef4fb;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:var(--qro-text-secondary)}
    table.bl-tbl td.n{white-space:normal;min-width:240px;font-weight:600}
    table.bl-tbl td.num{text-align:right;font-variant-numeric:tabular-nums}
    table.bl-tbl tr:hover td{background:#f5f9fe}
    .bl-bar{display:inline-block;vertical-align:middle;width:80px;height:8px;border-radius:999px;background:#eef2f6;overflow:hidden;margin-right:6px}
    .bl-bar>span{display:block;height:100%}
    .bl-scroll{border:1px solid var(--qro-border);border-radius:12px;overflow:auto;max-height:70vh}
  </style>

  <div class="page-head"><h1>Eventos y ocupación</h1>
    <p class="text-secondary"><?= count($eventos) ?> eventos · asistentes = invitados distintos con registro de asistencia.</p></div>

  <?php if ($dbError): ?><div class="alert alert-danger">Error: <?= htmlspecialchars($dbError) ?></div><?php endif; ?>

  <div class="bl-scroll">
    <table class="bl-tbl">
      <thead><tr>
        <th>Evento</th><th>Inicio</th><th>Fin</th><th class="num">Sesiones</th>
        <th class="num">Cupo</th><th class="num">Asistentes</th><th>Ocupación</th><th class="num">Registros</th>
      </tr></thead>
      <tbody>
        <?php foreach ($eventos as $e): $p = $e['ocupacion']; ?>
        <tr>
          <td class="n"><?= htmlspecialchars($e['sNombre'] ?? ('Evento #'.$e['id'])) ?></td>
          <td><?= bl_fecha($e['dFechaInicio']) ?></td>
          <td><?= bl_fecha($e['dFechaFin']) ?></td>
          <td class="num"><?= (int)$e['sesiones'] ?></td>
          <td class="num"><?= number_format((int)$e['iCupo']) ?></td>
          <td class="num"><?= number_format((int)$e['asistentes']) ?></td>
          <td>
            <span class="bl-bar"><span style="width:<?= $p!==null?min(100,(int)$p):0 ?>%;background:<?= bl_ocupColor($p) ?>"></span></span>
            <?= $p!==null ? $p.'%' : '—' ?>
          </td>
          <td class="num"><?= number_format((int)$e['registros']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
