<?php
/**
 * Cuadrillas · Padrón — alta/edición de cuadrillas y operadores de campo.
 * Operadores con login propio (para la app), password hasheado. Padrón APARTE
 * de la tabla `usuarios` del portal.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../core/guard.php';
require_module('cuadrillas');
require_once __DIR__ . '/lib.php';

$pdo = cuad_pdo();
cuad_ensure_schema($pdo);

$flash = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    require_editor('cuadrillas');                       // escribir exige editor/admin
    $accion = (string)($_POST['accion'] ?? '');
    try {
        if ($accion === 'guardar_cuadrilla') {
            cuad_cuadrilla_guardar($pdo, $_POST);
            $flash = ['ok', 'Cuadrilla guardada.'];
        } elseif ($accion === 'guardar_operador') {
            cuad_operador_guardar($pdo, $_POST);
            $flash = ['ok', 'Operador guardado.'];
        }
    } catch (Throwable $e) {
        $flash = ['err', $e->getMessage()];
    }
    $_SESSION['cuad_flash'] = $flash;
    header('Location: padron.php'); exit;
}
$flash = $_SESSION['cuad_flash'] ?? null; unset($_SESSION['cuad_flash']);

$cuadrillas = cuad_cuadrillas($pdo);
$operadores = cuad_operadores($pdo);
$puedeEditar = puede_editar('cuadrillas');

$ktTitle = 'Cuadrillas · Padrón';
$ktActive = 'cuadrillas';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
:where(#pad) .pcard{ background:var(--card); border:1px solid var(--border); border-radius:14px; }
:where(#pad) .ptbl{ width:100%; border-collapse:collapse; font-size:14px; }
:where(#pad) .ptbl th{ text-align:left; font-size:11px; letter-spacing:.06em; text-transform:uppercase; color:var(--muted-foreground); font-weight:700; padding:10px 12px; border-bottom:1px solid var(--border); }
:where(#pad) .ptbl td{ padding:11px 12px; border-bottom:1px solid var(--border); vertical-align:middle; }
:where(#pad) .ptbl tr:last-child td{ border-bottom:0; }
:where(#pad) .pill{ display:inline-flex; align-items:center; gap:6px; padding:2px 9px; border-radius:100px; font-size:11.5px; font-weight:700; }
:where(#pad) .pill.on{ background:#dcfce7; color:#15803d; } :where(#pad) .pill.off{ background:#f1f5f9; color:#64748b; }
:where(#pad) .pill.lider{ background:#e0e7ff; color:#4338ca; } :where(#pad) .pill.oper{ background:#f1f5f9; color:#475569; }
:where(#pad) .pdot{ width:11px; height:11px; border-radius:3px; display:inline-block; vertical-align:-1px; margin-right:7px; }
:where(#pad) .pform{ display:grid; gap:12px; grid-template-columns:repeat(2,1fr); padding:16px; }
:where(#pad) .pform .full{ grid-column:1/-1; }
:where(#pad) .pform label{ font-size:12px; font-weight:600; color:var(--foreground); display:block; margin-bottom:5px; }
:where(#pad) .pform input, :where(#pad) .pform select{ width:100%; height:38px; padding:0 11px; border:1px solid var(--border); border-radius:8px; background:var(--background); color:var(--foreground); font-size:14px; }
:where(#pad) .pform .hint{ font-size:11.5px; color:var(--muted-foreground); margin-top:4px; }
:where(#pad) .btn{ all:unset; cursor:pointer; display:inline-flex; align-items:center; gap:7px; height:38px; padding:0 16px; border-radius:8px; font-size:13.5px; font-weight:700; }
:where(#pad) .btn.pri{ background:var(--primary); color:#fff; } :where(#pad) .btn.pri:hover{ filter:brightness(1.05); }
:where(#pad) .btn.gho{ color:var(--primary); } :where(#pad) .btn.gho:hover{ background:color-mix(in srgb,var(--primary) 10%, transparent); }
:where(#pad) .pedit{ all:unset; cursor:pointer; color:var(--primary); font-size:12.5px; font-weight:700; }
:where(#pad) summary{ cursor:pointer; list-style:none; padding:14px 16px; display:flex; align-items:center; gap:9px; font-weight:700; font-size:14px; }
:where(#pad) summary::-webkit-details-marker{ display:none; }
:where(#pad) .palert{ padding:11px 15px; border-radius:10px; font-size:13.5px; font-weight:600; margin-bottom:16px; }
:where(#pad) .palert.ok{ background:#dcfce7; color:#15803d; } :where(#pad) .palert.err{ background:#fee2e2; color:#b91c1c; }
@media (max-width:640px){ :where(#pad) .pform{ grid-template-columns:1fr; } }
</style>

<div id="pad" class="flex flex-col gap-6">

  <?php if ($flash): ?>
    <div class="palert <?= $flash[0]==='ok'?'ok':'err' ?>"><?= e($flash[1]) ?></div>
  <?php endif; ?>

  <!-- ============ CUADRILLAS ============ -->
  <div class="pcard">
    <div class="flex items-center justify-between" style="padding:16px 18px;border-bottom:1px solid var(--border)">
      <div>
        <h2 class="text-lg font-bold" style="color:var(--primary)">Cuadrillas</h2>
        <p class="text-sm" style="color:var(--muted-foreground)">Equipos de campo. Cada cuadrilla recibe órdenes y se compone de operadores.</p>
      </div>
      <span class="pill" style="background:var(--muted);color:var(--muted-foreground)"><?= count($cuadrillas) ?> en total</span>
    </div>

    <?php if ($puedeEditar): ?>
    <details>
      <summary><i class="ki-filled ki-plus-squared" style="color:var(--primary)"></i> Nueva cuadrilla</summary>
      <form method="post" class="pform" style="border-top:1px solid var(--border)">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_cuadrilla"><input type="hidden" name="id" id="cq-id" value="">
        <div><label>Nombre</label><input name="nombre" id="cq-nombre" required placeholder="Cuadrilla Centro"></div>
        <div><label>Zona base (opcional)</label><input name="zona_base" id="cq-zona" placeholder="Delegación Centro Histórico"></div>
        <div><label>Color</label><input type="color" name="color" id="cq-color" value="#0f766e" style="height:38px;padding:3px"></div>
        <div><label>Estado</label><select name="activa" id="cq-activa"><option value="1">Activa</option><option value="0">Inactiva</option></select></div>
        <div class="full"><button class="btn pri" type="submit"><i class="ki-filled ki-check"></i> Guardar cuadrilla</button> <button type="reset" class="btn gho" onclick="document.getElementById('cq-id').value=''">Limpiar</button></div>
      </form>
    </details>
    <?php endif; ?>

    <div style="overflow-x:auto">
      <table class="ptbl">
        <thead><tr><th>Cuadrilla</th><th>Zona base</th><th>Operadores</th><th>Estado</th><?php if($puedeEditar):?><th></th><?php endif;?></tr></thead>
        <tbody>
        <?php if (!$cuadrillas): ?>
          <tr><td colspan="5" style="color:var(--muted-foreground);padding:22px 12px">Aún no hay cuadrillas. Crea la primera arriba.</td></tr>
        <?php else: foreach ($cuadrillas as $c): ?>
          <tr>
            <td><span class="pdot" style="background:<?= e($c['color']) ?>"></span><b><?= e($c['nombre']) ?></b></td>
            <td style="color:var(--muted-foreground)"><?= e($c['zona_base'] ?? '—') ?></td>
            <td><?= (int)$c['n_operadores'] ?></td>
            <td><span class="pill <?= $c['activa']?'on':'off' ?>"><?= $c['activa']?'Activa':'Inactiva' ?></span></td>
            <?php if($puedeEditar):?><td><button class="pedit" onclick='cqEdit(<?= json_encode($c, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button></td><?php endif;?>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ============ OPERADORES ============ -->
  <div class="pcard">
    <div class="flex items-center justify-between" style="padding:16px 18px;border-bottom:1px solid var(--border)">
      <div>
        <h2 class="text-lg font-bold" style="color:var(--primary)">Operadores</h2>
        <p class="text-sm" style="color:var(--muted-foreground)">Personal de campo con acceso a la app. Login propio (no es un usuario del portal).</p>
      </div>
      <span class="pill" style="background:var(--muted);color:var(--muted-foreground)"><?= count($operadores) ?> en total</span>
    </div>

    <?php if ($puedeEditar): ?>
    <details>
      <summary><i class="ki-filled ki-plus-squared" style="color:var(--primary)"></i> Nuevo operador</summary>
      <form method="post" class="pform" style="border-top:1px solid var(--border)">
        <?= csrf_field() ?><input type="hidden" name="accion" value="guardar_operador"><input type="hidden" name="id" id="op-id" value="">
        <div><label>Nombre completo</label><input name="nombre" id="op-nombre" required placeholder="Juan Pérez"></div>
        <div><label>Teléfono (opcional)</label><input name="telefono" id="op-tel" placeholder="442 123 4567"></div>
        <div><label>Cuadrilla</label><select name="cuadrilla_id" id="op-cuad"><option value="">— Sin asignar —</option>
          <?php foreach ($cuadrillas as $c): ?><option value="<?= (int)$c['id'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?>
        </select></div>
        <div><label>Rol</label><select name="rol" id="op-rol"><option value="operador">Operador</option><option value="lider">Líder</option></select></div>
        <div><label>Usuario (login app)</label><input name="usuario" id="op-usuario" required placeholder="jperez" autocomplete="off"><div class="hint">3–80 car.: letras, números, . _ -</div></div>
        <div><label>Contraseña</label><input type="password" name="password" id="op-pass" placeholder="mín. 6 caracteres" autocomplete="new-password"><div class="hint" id="op-passhint">Se usa para entrar a la app.</div></div>
        <div><label>Estado</label><select name="activo" id="op-activo"><option value="1">Activo</option><option value="0">Inactivo</option></select></div>
        <div class="full"><button class="btn pri" type="submit"><i class="ki-filled ki-check"></i> Guardar operador</button> <button type="reset" class="btn gho" onclick="opReset()">Limpiar</button></div>
      </form>
    </details>
    <?php endif; ?>

    <div style="overflow-x:auto">
      <table class="ptbl">
        <thead><tr><th>Operador</th><th>Usuario</th><th>Cuadrilla</th><th>Rol</th><th>Estado</th><?php if($puedeEditar):?><th></th><?php endif;?></tr></thead>
        <tbody>
        <?php if (!$operadores): ?>
          <tr><td colspan="6" style="color:var(--muted-foreground);padding:22px 12px">Aún no hay operadores.</td></tr>
        <?php else: foreach ($operadores as $o): ?>
          <tr>
            <td><b><?= e($o['nombre']) ?></b><?php if($o['telefono']):?><div style="font-size:12px;color:var(--muted-foreground)"><?= e($o['telefono']) ?></div><?php endif;?></td>
            <td><code style="font-size:12.5px"><?= e($o['usuario']) ?></code></td>
            <td style="color:var(--muted-foreground)"><?= e($o['cuadrilla'] ?? '— sin asignar —') ?></td>
            <td><span class="pill <?= $o['rol']==='lider'?'lider':'oper' ?>"><?= $o['rol']==='lider'?'Líder':'Operador' ?></span></td>
            <td><span class="pill <?= $o['activo']?'on':'off' ?>"><?= $o['activo']?'Activo':'Inactivo' ?></span></td>
            <?php if($puedeEditar):?><td><button class="pedit" onclick='opEdit(<?= json_encode($o, JSON_UNESCAPED_UNICODE|JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>Editar</button></td><?php endif;?>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php if ($puedeEditar): ?>
<script>
function openDetails(form){ const d=form.closest('details'); if(d) d.open=true; form.scrollIntoView({block:'center'}); }
function cqEdit(c){
  document.getElementById('cq-id').value=c.id; document.getElementById('cq-nombre').value=c.nombre||'';
  document.getElementById('cq-zona').value=c.zona_base||''; document.getElementById('cq-color').value=c.color||'#0f766e';
  document.getElementById('cq-activa').value=String(c.activa);
  openDetails(document.getElementById('cq-nombre').form);
}
function opEdit(o){
  document.getElementById('op-id').value=o.id; document.getElementById('op-nombre').value=o.nombre||'';
  document.getElementById('op-tel').value=o.telefono||''; document.getElementById('op-cuad').value=o.cuadrilla_id?String(o.cuadrilla_id):'';
  document.getElementById('op-rol').value=o.rol||'operador'; document.getElementById('op-usuario').value=o.usuario||'';
  document.getElementById('op-activo').value=String(o.activo);
  const p=document.getElementById('op-pass'); p.value=''; p.required=false;
  document.getElementById('op-passhint').textContent='Déjala vacía para conservar la contraseña actual.';
  openDetails(document.getElementById('op-nombre').form);
}
function opReset(){
  document.getElementById('op-id').value=''; const p=document.getElementById('op-pass'); p.required=false;
  document.getElementById('op-passhint').textContent='Se usa para entrar a la app.';
}
</script>
<?php endif; ?>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
