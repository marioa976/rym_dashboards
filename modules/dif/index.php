<?php
/**
 * index.php  —  Landing del sistema.
 *
 * Abre:  http://localhost:8888/dif/
 */

declare(strict_types=1);

$config = @include __DIR__ . '/config.php';
$stats = null;
$electoral = null;
$cacheStats = null;
$error = null;

if (is_array($config)) {
    try {
        $db = $config['db'];
        $pdo = new PDO(
            "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset={$db['charset']}",
            $db['user'], $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $pdo->exec("SET NAMES utf8mb4");
        $pdo->exec("SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED"); // el índice espacial no funciona bajo READ-UNCOMMITTED (error 1207)

        $cols = $pdo->query("SHOW COLUMNS FROM padron")->fetchAll(PDO::FETCH_COLUMN);
        $tieneActivo = in_array('activo', $cols, true);
        $w = $tieneActivo ? "WHERE activo=1" : "";

        $stats = $pdo->query("
            SELECT
                COUNT(*) AS total,
                SUM(latitud IS NOT NULL AND longitud IS NOT NULL) AS con_coords,
                SUM(latitud IS NULL OR longitud IS NULL)          AS sin_coords,
                COUNT(DISTINCT colonia)    AS colonias,
                COUNT(DISTINCT delegacion) AS delegaciones,
                COUNT(DISTINCT programa)   AS programas,
                MIN(fecha_entrega) AS desde,
                MAX(fecha_entrega) AS hasta
              FROM padron $w
        ")->fetch(PDO::FETCH_ASSOC);

        $cacheStats = $pdo->query("
            SELECT COUNT(*) AS total, SUM(status='OK') AS ok
              FROM geocode_cache
        ")->fetch(PDO::FETCH_ASSOC);

        // Tablas electorales
        $tieneElec = $pdo->query("SHOW TABLES LIKE 'secciones_geo'")->fetchColumn();
        if ($tieneElec) {
            $electoral = $pdo->query("
                SELECT
                    (SELECT COUNT(*) FROM secciones)     AS secciones,
                    (SELECT COUNT(*) FROM distritos)     AS distritos,
                    (SELECT COUNT(*) FROM secciones_geo) AS poligonos,
                    (SELECT COUNT(*) FROM municipios)    AS municipios
            ")->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$navActive = 'home';
?><?php
$ktTitle  = 'Padrón DIF — Inicio';
$ktActive = 'dif';
require __DIR__ . '/../../views/layout/kt_top.php';
?>
<style>
  :root{
    --bg:#f5f7fa; --panel:#fff; --bd:#e2e8f0; --fg:#0f172a; --mut:#64748b;
    --accent:#254185; --accent2:#005ab2;
    --ok:#188a5b; --warn:#d99000; --err:#ce3a2b;
    --shadow:0 1px 3px rgba(15,23,42,.06), 0 1px 2px rgba(15,23,42,.05);
  }
  *{box-sizing:border-box}
  html,body{margin:0;background:var(--bg);color:var(--fg);
            font-family:-apple-system,BlinkMacSystemFont,'Segoe UI','Inter',sans-serif;
            font-size:14px;line-height:1.5}
  .hero{padding:48px 24px 24px;max-width:1200px;margin:0 auto}
  .hero h1{margin:0;font-size:32px;letter-spacing:-.5px}
  .hero p{color:var(--mut);font-size:15px;margin:6px 0 0;max-width:640px}

  .container{padding:0 24px 48px;max-width:1200px;margin:0 auto}

  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:12px;margin:24px 0 32px}
  .stat{background:var(--panel);border:1px solid var(--bd);border-radius:10px;padding:14px 16px;box-shadow:var(--shadow)}
  .stat .lbl{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;font-weight:600}
  .stat .val{font-size:24px;font-weight:700;margin-top:4px}
  .stat .val.ok{color:var(--ok)} .stat .val.warn{color:var(--warn)}
  .stat .sub{font-size:11px;color:var(--mut);margin-top:2px}

  .section-title{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;font-weight:600;
                 margin:32px 0 12px;padding-bottom:8px;border-bottom:1px solid var(--bd)}

  .cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px}
  .card{background:var(--panel);border:1px solid var(--bd);border-radius:12px;padding:22px;
        box-shadow:var(--shadow);text-decoration:none;color:var(--fg);
        transition:all .2s;position:relative;overflow:hidden;display:flex;flex-direction:column}
  .card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(15,23,42,.1);border-color:var(--accent2)}
  .card .icon{font-size:32px;margin-bottom:10px}
  .card h3{margin:0;font-size:17px;font-weight:600}
  .card p{margin:6px 0 16px;font-size:13px;color:var(--mut);flex:1}
  .card .cta{font-size:13px;color:var(--accent);font-weight:500;display:flex;align-items:center;gap:6px}
  .card .cta::after{content:'→';transition:transform .15s}
  .card:hover .cta::after{transform:translateX(4px)}
  .card.disabled{opacity:.55;pointer-events:none}
  .card .tag{position:absolute;top:14px;right:14px;font-size:10px;padding:2px 8px;
             border-radius:4px;font-weight:600}
  .card .tag.ok{background:rgba(22,163,74,.12);color:var(--ok)}
  .card .tag.warn{background:rgba(217,119,6,.12);color:var(--warn)}
  .card .tag.err{background:rgba(220,38,38,.12);color:var(--err)}

  .info-banner{background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.2);
               color:var(--err);padding:14px 18px;border-radius:8px;margin-bottom:20px;font-size:13.5px}
  .ok-banner{background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);
             color:#166534;padding:12px 18px;border-radius:8px;margin-bottom:14px;font-size:13px}

  footer{text-align:center;color:var(--mut);font-size:12px;padding:24px;border-top:1px solid var(--bd);margin-top:48px}
</style>

<div class="hero">
  <h1>🏛 Sistema Padrón DIF Querétaro</h1>
  <p>Gestiona el padrón de beneficiarios de programas sociales: importa, geocodifica, deduplica y visualiza
     en dashboards ejecutivos y reportes electorales con polígonos del IEEQ.</p>
</div>

<div class="container">

  <?php if ($error): ?>
    <div class="info-banner">
      ⚠️ No se pudo conectar a la base de datos:<br>
      <code><?= htmlspecialchars($error) ?></code><br>
      Revisa <code>config.php</code> y que MAMP esté corriendo.
    </div>
  <?php endif; ?>

  <?php if ($stats): ?>
    <?php
      $pct = (int)$stats['total'] > 0
           ? round(((int)$stats['con_coords'] * 100) / (int)$stats['total'], 1)
           : 0;
    ?>
    <div class="ok-banner">
      ✓ Conexión a MariaDB correcta. <strong><?= number_format((int)$stats['total']) ?></strong> registros activos en el padrón.
    </div>

    <h2 class="section-title">Estado del padrón</h2>
    <div class="stats">
      <div class="stat">
        <div class="lbl">Registros totales</div>
        <div class="val"><?= number_format((int)$stats['total']) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">Con coordenadas</div>
        <div class="val ok"><?= number_format((int)$stats['con_coords']) ?></div>
        <div class="sub"><?= $pct ?>% del total</div>
      </div>
      <div class="stat">
        <div class="lbl">Sin coordenadas</div>
        <div class="val warn"><?= number_format((int)$stats['sin_coords']) ?></div>
        <div class="sub">pendientes de geocode</div>
      </div>
      <div class="stat">
        <div class="lbl">Colonias</div>
        <div class="val"><?= number_format((int)$stats['colonias']) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">Delegaciones</div>
        <div class="val"><?= number_format((int)$stats['delegaciones']) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">Programas</div>
        <div class="val"><?= number_format((int)$stats['programas']) ?></div>
      </div>
      <?php if (!empty($stats['desde'])): ?>
      <div class="stat">
        <div class="lbl">Rango fechas</div>
        <div class="val" style="font-size:14px"><?= $stats['desde'] ?> →<br><?= $stats['hasta'] ?></div>
      </div>
      <?php endif; ?>
      <?php if ($cacheStats): ?>
      <div class="stat">
        <div class="lbl">Cache geocode</div>
        <div class="val"><?= number_format((int)$cacheStats['total']) ?></div>
        <div class="sub"><?= number_format((int)$cacheStats['ok']) ?> OK reutilizables</div>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h2 class="section-title">Reportes</h2>
  <div class="cards">

    <a href="dashboard.php" class="card">
      <span class="tag ok">Reporte</span>
      <div class="icon">📊</div>
      <h3>Dashboard Ejecutivo</h3>
      <p>15 KPIs, 10 gráficas, mapa de calor y calidad de datos. Filtros por programa, coordinación, sexo, edad y fechas.</p>
      <span class="cta">Abrir dashboard</span>
    </a>

    <a href="electoral.php" class="card<?= $electoral ? '' : ' disabled' ?>">
      <span class="tag <?= $electoral ? 'ok' : 'warn' ?>"><?= $electoral ? 'Reporte' : 'Requiere dump IEEQ' ?></span>
      <div class="icon">🗳</div>
      <h3>Reporte Electoral</h3>
      <p>Cruce padrón × <?= $electoral ? number_format((int)$electoral['secciones']) : '—' ?> secciones electorales del IEEQ.
         Choropleth por apoyos, 15 distritos, rankings y popup por sección.</p>
      <span class="cta">Abrir reporte</span>
    </a>

  </div>

  <h2 class="section-title">Operación</h2>
  <div class="cards">

    <a href="upload.php" class="card">
      <span class="tag ok">Importar</span>
      <div class="icon">📤</div>
      <h3>Subir XLSX</h3>
      <p>Carga el padrón desde Excel. Detección automática de columnas por nombre. Log de progreso en vivo.</p>
      <span class="cta">Subir archivo</span>
    </a>

    <a href="geocode_ui.php" class="card">
      <span class="tag ok">API Google</span>
      <div class="icon">🌐</div>
      <h3>Geocodificar</h3>
      <p>Completa lat/lng pendientes vía Google Maps. Cache + bbox cleanup + modo manual + control fino del costo.</p>
      <span class="cta">Geocodificar</span>
    </a>

    <a href="dedupe.php" class="card">
      <span class="tag warn">Mantenimiento</span>
      <div class="icon">🧹</div>
      <h3>Deduplicar</h3>
      <p>Marca duplicados como inactivos conservando el que ya tiene coordenadas. Reversible.</p>
      <span class="cta">Deduplicar</span>
    </a>

  </div>

  <?php if ($electoral): ?>
    <h2 class="section-title">Catálogo electoral IEEQ</h2>
    <div class="stats">
      <div class="stat">
        <div class="lbl">Distritos locales</div>
        <div class="val"><?= number_format((int)$electoral['distritos']) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">Municipios</div>
        <div class="val"><?= number_format((int)$electoral['municipios']) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">Secciones</div>
        <div class="val"><?= number_format((int)$electoral['secciones']) ?></div>
      </div>
      <div class="stat">
        <div class="lbl">Polígonos cargados</div>
        <div class="val ok"><?= number_format((int)$electoral['poligonos']) ?></div>
      </div>
    </div>
  <?php endif; ?>

</div>

<footer>
  Sistema Padrón DIF · Querétaro · MariaDB + PHP 8.2 + Google Maps + IEEQ
</footer>

<?php require __DIR__ . '/../../views/layout/kt_bottom.php'; ?>
