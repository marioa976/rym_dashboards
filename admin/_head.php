<?php
$user = Auth::user();
$adminActive = $adminActive ?? 'index';
$adminTitulo = $adminTitulo ?? 'Panel';
$f = flash_take();

$ktTitle       = 'Administración · ' . $adminTitulo;
$ktActive      = 'admin';
$ktAdminActive = $adminActive;    // resalta la sub-página en el sidebar
require __DIR__ . '/../views/layout/kt_top.php';
?>
<style>
  .admin-tools{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:18px}
  .adm-table{width:100%;border-collapse:collapse;background:#fff;border:1px solid var(--qro-border);border-radius:12px;overflow:hidden}
  .adm-table th{background:#eef5fc;color:var(--qro-blue-dark);text-align:left;padding:11px 13px;font-size:12.5px;text-transform:uppercase;letter-spacing:.3px}
  .adm-table td{padding:11px 13px;border-top:1px solid var(--qro-border);font-size:14px;vertical-align:middle}
  .adm-table tbody tr:hover{background:#f7fafe}
  .chip{display:inline-block;padding:3px 9px;border-radius:999px;font-size:11.5px;font-weight:600;margin:2px 3px 2px 0}
  .chip-mod{background:rgba(42,158,218,.12);color:var(--qro-blue)}
  .chip-admin{background:rgba(37,65,133,.12);color:var(--qro-blue-dark)}
  .chip-off{background:#f0f1f4;color:#8a94a6}
  .badge-on{background:rgba(24,138,91,.12);color:var(--qro-success);border-radius:999px;padding:3px 9px;font-size:11.5px;font-weight:600}
  .badge-off{background:rgba(206,58,43,.10);color:var(--qro-danger);border-radius:999px;padding:3px 9px;font-size:11.5px;font-weight:600}
  .adm-form{background:#fff;border:1px solid var(--qro-border);border-radius:14px;padding:20px;box-shadow:var(--qro-shadow-sm)}
  .adm-grid{display:grid;gap:14px;grid-template-columns:repeat(auto-fit,minmax(200px,1fr))}
  .inline-form{display:inline}
  .btn-xs{padding:6px 10px;font-size:13px;border-radius:8px}
  .muted{color:var(--qro-text-muted);font-size:13px}
  .kpi-grid{display:grid;gap:16px;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));margin-bottom:8px}
</style>
<?php if ($f): ?>
  <div class="alert <?= $f['tipo'] === 'ok' ? 'alert-ok' : 'alert-danger' ?>"
       style="margin-bottom:16px;<?= $f['tipo']==='ok' ? 'background:rgba(24,138,91,.10);color:#166534;border:1px solid rgba(24,138,91,.25)' : '' ?>">
    <?= e($f['msg']) ?>
  </div>
<?php endif; ?>
