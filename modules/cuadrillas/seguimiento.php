<?php
/**
 * Cuadrillas · Seguimiento — órdenes despachadas con su avance.
 * Vista de supervisor: estatus por orden, progreso de paradas y detalle
 * expandible. (La actualización en vivo la alimentará la app en la Fase 2.)
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/guard.php';
require_module('cuadrillas');
require_once __DIR__ . '/lib.php';

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);

$ordenes = cuad_ordenes($pdo);
$paradas = cuad_paradas_por_orden($pdo, array_column($ordenes, 'id'));
// evidencias por parada (para las miniaturas)
$todasParadas = [];
foreach ($paradas as $ps) foreach ($ps as $p) $todasParadas[] = (int)$p['id'];
$evidencias = cuad_evidencias_por_parada($pdo, $todasParadas);

$flash = $_SESSION['cuad_flash'] ?? null; unset($_SESSION['cuad_flash']);

$EST = [
  'borrador'   => ['Borrador',   '#f1f5f9', '#64748b'],
  'despachada' => ['Despachada', '#dbeafe', '#1d4ed8'],
  'en_proceso' => ['En proceso', '#fef3c7', '#b45309'],
  'cerrada'    => ['Cerrada',    '#dcfce7', '#15803d'],
  'cancelada'  => ['Cancelada',  '#fee2e2', '#b91c1c'],
];
$PEST = [
  'pendiente'   => ['Pendiente',  '#64748b'],
  'en_camino'   => ['En camino',  '#1d4ed8'],
  'en_sitio'    => ['En sitio',   '#b45309'],
  'resuelta'    => ['Resuelta',   '#15803d'],
  'no_resuelta' => ['No resuelta','#b91c1c'],
];

$ktTitle  = 'Cuadrillas · Seguimiento';
$ktActive = 'cuadrillas';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
:where(#seg) .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; }
:where(#seg) .ord{ border-bottom:1px solid var(--border); }
:where(#seg) .ord:last-child{ border-bottom:0; }
:where(#seg) summary{ cursor:pointer; list-style:none; padding:15px 18px; display:grid; grid-template-columns:1fr auto; gap:12px; align-items:center; }
:where(#seg) summary::-webkit-details-marker{ display:none; }
:where(#seg) summary:hover{ background:color-mix(in srgb,var(--primary) 4%, transparent); }
:where(#seg) .ot{ font-weight:700; font-size:14.5px; display:flex; align-items:center; gap:9px; }
:where(#seg) .dot{ width:11px; height:11px; border-radius:3px; flex-shrink:0; }
:where(#seg) .om{ font-size:12.5px; color:var(--muted-foreground); margin-top:3px; font-family:'Montserrat',monospace; }
:where(#seg) .pill{ display:inline-flex; align-items:center; padding:3px 10px; border-radius:100px; font-size:11.5px; font-weight:700; }
:where(#seg) .prog{ display:flex; align-items:center; gap:10px; }
:where(#seg) .bar{ width:120px; height:7px; border-radius:4px; background:var(--muted); overflow:hidden; }
:where(#seg) .bar > span{ display:block; height:100%; background:var(--primary); border-radius:4px; }
:where(#seg) .pfrac{ font-family:'Montserrat',monospace; font-size:12.5px; color:var(--muted-foreground); min-width:46px; text-align:right; }
:where(#seg) .paradas{ padding:4px 18px 16px; }
:where(#seg) .ptbl{ width:100%; border-collapse:collapse; font-size:13.5px; }
:where(#seg) .ptbl th{ text-align:left; font-size:10.5px; letter-spacing:.05em; text-transform:uppercase; color:var(--muted-foreground); font-weight:700; padding:7px 10px; }
:where(#seg) .ptbl td{ padding:8px 10px; border-top:1px solid var(--border); }
:where(#seg) .pst{ font-weight:700; font-size:11.5px; }
:where(#seg) .empty{ padding:30px 18px; color:var(--muted-foreground); text-align:center; }
:where(#seg) .palert{ padding:11px 15px; border-radius:10px; font-size:13.5px; font-weight:600; margin-bottom:16px; }
:where(#seg) .palert.ok{ background:#dcfce7; color:#15803d; } :where(#seg) .palert.err{ background:#fee2e2; color:#b91c1c; }
</style>

<div id="seg" class="flex flex-col gap-5">
  <?php if ($flash): ?><div class="palert <?= $flash[0]==='ok'?'ok':'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>

  <div class="flex items-center justify-between">
    <div>
      <h2 class="text-lg font-bold" style="color:var(--primary)">Órdenes</h2>
      <p class="text-sm" style="color:var(--muted-foreground)"><?= count($ordenes) ?> órdenes · avance de paradas en campo</p>
    </div>
    <a class="pill" style="background:var(--primary);color:#fff;height:38px;padding:0 16px;gap:7px;text-decoration:none" href="<?= e(url('modules/cuadrillas/asignar.php')) ?>"><i class="ki-filled ki-plus"></i> Asignar / despachar</a>
  </div>

  <div class="card">
    <?php if (!$ordenes): ?>
      <div class="empty">Aún no hay órdenes despachadas.<br>Ve a <a style="color:var(--primary)" href="<?= e(url('modules/cuadrillas/asignar.php')) ?>">Asignar / despachar</a> y manda un plan a campo.</div>
    <?php else: foreach ($ordenes as $o):
        $e = $EST[$o['estatus']] ?? $EST['borrador'];
        $tot = max(1, (int)$o['n_paradas']);
        $res = (int)$o['resueltas'];
        $pct = round(100 * $res / $tot);
        $ps  = $paradas[(int)$o['id']] ?? [];
    ?>
      <details class="ord">
        <summary>
          <div>
            <div class="ot"><span class="dot" style="background:<?= e($o['color'] ?: '#0f766e') ?>"></span><?= e($o['titulo']) ?></div>
            <div class="om"><?= e($o['cuadrilla'] ?? 'Sin cuadrilla') ?> · <?= e(date('d/m/Y', strtotime((string)$o['fecha']))) ?> · <?= (int)$o['n_paradas'] ?> paradas<?= $o['km']>0?' · '.number_format((float)$o['km'],1).' km':'' ?></div>
          </div>
          <div class="flex items-center gap-4">
            <div class="prog">
              <div class="bar"><span style="width:<?= $pct ?>%"></span></div>
              <div class="pfrac"><?= $res ?>/<?= (int)$o['n_paradas'] ?></div>
            </div>
            <span class="pill" style="background:<?= $e[1] ?>;color:<?= $e[2] ?>"><?= $e[0] ?></span>
          </div>
        </summary>
        <div class="paradas">
          <table class="ptbl">
            <thead><tr><th>#</th><th>Parada</th><th>Dirección</th><th>Ticket</th><th>Estatus</th><th>Evidencia</th></tr></thead>
            <tbody>
              <?php foreach ($ps as $p): $pe = $PEST[$p['estatus']] ?? $PEST['pendiente']; $evs = $evidencias[(int)$p['id']] ?? []; ?>
                <tr>
                  <td style="color:var(--muted-foreground)"><?= (int)$p['idx']+1 ?></td>
                  <td><?= e($p['titulo'] ?? '—') ?></td>
                  <td style="color:var(--muted-foreground)"><?= e($p['direccion'] ?? '—') ?></td>
                  <td><?php if($p['ticket_id']):?><code style="font-size:12px">#<?= (int)$p['ticket_id'] ?></code><?php else:?>—<?php endif;?></td>
                  <td><span class="pst" style="color:<?= $pe[1] ?>"><?= $pe[0] ?></span><?php if($p['estatus']==='no_resuelta' && $p['motivo_no']):?> <span style="color:var(--muted-foreground);font-weight:400">(<?= e($p['motivo_no']) ?>)</span><?php endif;?></td>
                  <td>
                    <?php if(!$evs):?><span style="color:var(--muted-foreground)">—</span>
                    <?php else: ?><div style="display:flex;gap:5px;flex-wrap:wrap">
                      <?php foreach($evs as $ev): ?>
                        <a href="<?= e(url('modules/cuadrillas/evidencia_ver.php?id='.(int)$ev['id'])) ?>" target="_blank" title="<?= e($ev['tipo'].($ev['nota']?' · '.$ev['nota']:'')) ?>">
                          <img src="<?= e(url('modules/cuadrillas/evidencia_ver.php?id='.(int)$ev['id'])) ?>" alt="evidencia" loading="lazy" style="width:40px;height:40px;object-fit:cover;border-radius:6px;border:1px solid var(--border)">
                        </a>
                      <?php endforeach; ?>
                    </div><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
              <?php if(!$ps):?><tr><td colspan="6" style="color:var(--muted-foreground)">Sin paradas.</td></tr><?php endif;?>
            </tbody>
          </table>
        </div>
      </details>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
