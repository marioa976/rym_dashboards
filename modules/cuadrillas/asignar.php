<?php
/**
 * Cuadrillas · Asignar y despachar.
 * Toma un plan guardado por el planificador (cuadrillas_planes), liga cada ruta
 * a una cuadrilla real + fecha, y crea las ÓRDENES (una por día) con sus paradas
 * (una por ticket de Zendesk). Al despachar quedan listas para la app.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/guard.php';
require_module('cuadrillas');
require_once __DIR__ . '/lib.php';

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check() && ($_POST['accion'] ?? '') === 'despachar') {
    require_editor('cuadrillas');
    $plan = cuad_plan($pdo, (int)($_POST['plan_id'] ?? 0));
    if (!$plan) {
        $flash = ['err', 'El plan ya no existe o está vacío.'];
    } else {
        $asig = is_array($_POST['asig'] ?? null) ? $_POST['asig'] : [];
        try {
            $uid = (int)(Auth::user()['id'] ?? 0) ?: null;
            $res = cuad_despachar($pdo, $plan, $asig, $uid);
            if ($res['ordenes'] === 0) {
                $flash = ['err', 'No asignaste ninguna ruta (elige cuadrilla y fecha en al menos una).'];
            } else {
                $_SESSION['cuad_flash'] = ['ok', "Despachadas {$res['ordenes']} órdenes · {$res['paradas']} paradas."];
                header('Location: seguimiento.php'); exit;
            }
        } catch (Throwable $e) {
            error_log('[portal][cuadrillas] despachar: ' . $e->getMessage());
            $flash = ['err', 'No se pudo despachar el plan. Intenta de nuevo.'];
        }
    }
}

$planes     = cuad_planes($pdo);
$cuadrillas  = cuad_cuadrillas($pdo, true);          // solo activas
$puedeEditar = puede_editar('cuadrillas');
$plan_id     = (int)($_GET['plan_id'] ?? ($_POST['plan_id'] ?? 0));
$plan        = $plan_id > 0 ? cuad_plan($pdo, $plan_id) : null;
$fecha_def   = date('Y-m-d', strtotime('+1 day'));

$ktTitle  = 'Cuadrillas · Asignar y despachar';
$ktActive = 'cuadrillas';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
:where(#asg) .card{ background:var(--card); border:1px solid var(--border); border-radius:14px; }
:where(#asg) .hd{ padding:16px 18px; border-bottom:1px solid var(--border); }
:where(#asg) .plist{ display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:14px; padding:18px; }
:where(#asg) .pitem{ border:1px solid var(--border); border-radius:12px; padding:15px; text-decoration:none; color:inherit; transition:.15s; display:block; }
:where(#asg) .pitem:hover{ border-color:var(--primary); transform:translateY(-2px); }
:where(#asg) .pitem.sel{ border-color:var(--primary); box-shadow:0 0 0 2px color-mix(in srgb,var(--primary) 25%, transparent); }
:where(#asg) .pitem .nm{ font-weight:700; font-size:14.5px; }
:where(#asg) .pitem .mt{ font-size:12px; color:var(--muted-foreground); margin-top:4px; font-family:'Montserrat',monospace; }
:where(#asg) .rtbl{ width:100%; border-collapse:collapse; }
:where(#asg) .rtbl th{ text-align:left; font-size:11px; letter-spacing:.05em; text-transform:uppercase; color:var(--muted-foreground); font-weight:700; padding:10px 14px; border-bottom:1px solid var(--border); }
:where(#asg) .rtbl td{ padding:12px 14px; border-bottom:1px solid var(--border); vertical-align:middle; font-size:14px; }
:where(#asg) .rtbl tr:last-child td{ border-bottom:0; }
:where(#asg) .rtbl select, :where(#asg) .rtbl input[type=date]{ height:36px; padding:0 10px; border:1px solid var(--border); border-radius:8px; background:var(--background); color:var(--foreground); font-size:13.5px; min-width:150px; }
:where(#asg) .muted{ color:var(--muted-foreground); }
:where(#asg) .chip{ font-family:'Montserrat',monospace; font-size:12px; color:var(--muted-foreground); }
:where(#asg) .btn{ all:unset; cursor:pointer; display:inline-flex; align-items:center; gap:8px; height:40px; padding:0 18px; border-radius:9px; font-size:14px; font-weight:700; }
:where(#asg) .btn.pri{ background:var(--primary); color:#fff; } :where(#asg) .btn.pri:hover{ filter:brightness(1.05); }
:where(#asg) .btn.gho{ color:var(--primary); }
:where(#asg) .palert{ padding:11px 15px; border-radius:10px; font-size:13.5px; font-weight:600; margin-bottom:16px; }
:where(#asg) .palert.ok{ background:#dcfce7; color:#15803d; } :where(#asg) .palert.err{ background:#fee2e2; color:#b91c1c; }
:where(#asg) .empty{ padding:26px 18px; color:var(--muted-foreground); }
</style>

<div id="asg" class="flex flex-col gap-6">
  <?php if ($flash): ?><div class="palert <?= $flash[0]==='ok'?'ok':'err' ?>"><?= e($flash[1]) ?></div><?php endif; ?>

  <!-- Paso 1: elegir plan -->
  <div class="card">
    <div class="hd flex items-center justify-between">
      <div>
        <h2 class="text-lg font-bold" style="color:var(--primary)">1 · Elige un plan</h2>
        <p class="text-sm muted">Planes generados por el planificador de rutas (Zendesk → Cuadrillas).</p>
      </div>
      <a class="btn gho" href="<?= e(url('modules/zendesk/cuadrillas.php')) ?>"><i class="ki-filled ki-plus"></i> Generar un plan nuevo</a>
    </div>
    <?php if (!$planes): ?>
      <div class="empty">Aún no hay planes guardados. Genera uno en <a style="color:var(--primary)" href="<?= e(url('modules/zendesk/cuadrillas.php')) ?>">Zendesk · Cuadrillas</a> y guárdalo; aquí lo despachas.</div>
    <?php else: ?>
      <div class="plist">
        <?php foreach ($planes as $p): ?>
          <a class="pitem <?= $plan && $plan['id']===(int)$p['id']?'sel':'' ?>" href="?plan_id=<?= (int)$p['id'] ?>">
            <div class="nm"><?= e($p['nombre']) ?></div>
            <div class="mt"><?= (int)$p['n_cuadrillas'] ?> cuadrillas · <?= (int)$p['n_tickets'] ?> tickets · <?= number_format((float)$p['km'],1) ?> km</div>
            <div class="mt"><?= e(date('d/m/Y H:i', strtotime((string)$p['creado_en']))) ?></div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Paso 2: asignar rutas a cuadrillas -->
  <?php if ($plan): ?>
    <?php $rutas = $plan['pl']['plan'] ?? []; ?>
    <div class="card">
      <div class="hd">
        <h2 class="text-lg font-bold" style="color:var(--primary)">2 · Asigna cada ruta a una cuadrilla</h2>
        <p class="text-sm muted">Plan <b><?= e($plan['nombre']) ?></b>. Cada ruta se despacha como órdenes (una por día) a la cuadrilla elegida.</p>
      </div>

      <?php if (!$cuadrillas): ?>
        <div class="empty">No tienes cuadrillas activas. Crea al menos una en <a style="color:var(--primary)" href="<?= e(url('modules/cuadrillas/padron.php')) ?>">el Padrón</a>.</div>
      <?php else: ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="accion" value="despachar">
        <input type="hidden" name="plan_id" value="<?= (int)$plan['id'] ?>">
        <div style="overflow-x:auto">
          <table class="rtbl">
            <thead><tr><th>Ruta</th><th>Carga</th><th>Cuadrilla destino</th><th>Fecha base</th></tr></thead>
            <tbody>
            <?php foreach ($rutas as $i => $cu):
                $nt = (int)($cu['total_tickets'] ?? 0);
                $km = (float)($cu['total_km'] ?? 0);
                $nd = count($cu['dias'] ?? []);
                $vacia = $nt === 0 || $nd === 0;
            ?>
              <tr>
                <td><b>Cuadrilla <?= (int)($cu['cuadrilla'] ?? ($i+1)) ?></b><?php if($nd>1):?> <span class="chip"><?= $nd ?> días</span><?php endif;?></td>
                <td><span class="chip"><?= $nt ?> tickets · <?= number_format($km,1) ?> km</span></td>
                <td>
                  <?php if ($vacia): ?><span class="muted">— sin paradas —</span>
                  <?php else: ?>
                  <select name="asig[<?= $i ?>][cuadrilla_id]" <?= $puedeEditar?'':'disabled' ?>>
                    <option value="">— No despachar —</option>
                    <?php foreach ($cuadrillas as $c): ?>
                      <option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?><?= $c['zona_base']?' ('.e($c['zona_base']).')':'' ?></option>
                    <?php endforeach; ?>
                  </select>
                  <?php endif; ?>
                </td>
                <td><?php if(!$vacia): ?><input type="date" name="asig[<?= $i ?>][fecha]" value="<?= e($fecha_def) ?>" <?= $puedeEditar?'':'disabled' ?>><?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php if ($puedeEditar): ?>
        <div class="hd" style="border-top:1px solid var(--border);border-bottom:0;display:flex;justify-content:flex-end;gap:10px">
          <button class="btn pri" type="submit"><i class="ki-filled ki-send"></i> Despachar seleccionadas</button>
        </div>
        <?php else: ?>
        <div class="empty">Necesitas nivel <b>editor</b> en el módulo para despachar.</div>
        <?php endif; ?>
      </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
