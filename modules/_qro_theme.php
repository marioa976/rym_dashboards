<?php
/**
 * _qro_theme.php — Homologación visual "Querétaro con Futuro" (versión segura).
 *
 * Se inyecta DESPUÉS del <style> propio de cada app (vía _portalbar.php y
 * dif/_nav.php). Unifica SOLO:
 *   1) Tipografía (Montserrat en todo).
 *   2) Colores de MARCA / acento / estado / gráficas.
 *
 * IMPORTANTE: NO toca tokens estructurales (--bg, --surface, --text, --panel,
 * --fg, --tinta, --blanco, --border…). Esos definen si cada pantalla es clara
 * u oscura; remapearlos rompía el contraste (texto oscuro sobre fondo oscuro
 * en páginas de tema oscuro como las animaciones de ruta). Cada app conserva
 * su sistema claro/oscuro; la marca se transmite por tipografía + acentos.
 */
if (defined('QRO_THEME_DONE')) return;   // evita doble inclusión (p. ej. _portalbar + dif/_nav)
define('QRO_THEME_DONE', 1);
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style id="qro-theme">
/* ---- Solo colores de MARCA / acento / estado (no estructura) ---- */
:root{
  /* Acentos / marca — usados por botones, links, tabs, indicadores */
  --accent:#254185;  --accent2:#005ab2;
  --azul:#254185;    --azul-d:#1a2f63;   --cielo:#2a9eda;
  --info:#2a9eda;    --neutral:#005ab2;

  /* Estados semánticos (mismos tonos en los 3 módulos) */
  --ok:#188a5b; --positive:#188a5b; --verde:#188a5b; --success:#188a5b;
  --warn:#d99000; --warning:#d99000; --ambar:#d99000;
  --err:#ce3a2b; --negative:#ce3a2b; --rojo:#ce3a2b; --danger:#ce3a2b; --rosa:#ce3a2b;

  /* Paleta de gráficas institucional */
  --chart1:#254185; --chart2:#005ab2; --chart3:#2a9eda; --chart4:#188a5b;
  --chart5:#d99000; --chart6:#ce3a2b; --chart7:#1a2f63; --chart8:#5b667a;
}

/* ---- Tipografía única: Montserrat en todo (manual §7) ---- */
*:not(i):not(.material-icons){ font-family:'Montserrat',Arial,sans-serif !important; }

/* NOTA: NO remapeamos .topbar/.tabs globalmente. Esos selectores existen en
   módulos con temas distintos (DIF dashboard usa texto blanco; Qrobici usa
   texto oscuro), y forzarlos a azul rompía el contraste en Qrobici. Cada
   módulo define el color de su propio encabezado. */

/* Botones / links primarios de marca (definen su propio texto blanco) */
.btn-primary,.btn.primary,.nav a.primary,.speed-pick button.active{
  background:var(--accent) !important; border-color:var(--accent) !important; color:#fff !important;
}

/* Scrollbar sutil en tono marca */
*::-webkit-scrollbar-thumb{ background:#c3cfe6; border-radius:8px; }
</style>
<script>
/* Tipografía de Chart.js si está presente */
(function(){
  function applyChartTheme(){
    if(window.Chart && Chart.defaults){
      Chart.defaults.font.family = "'Montserrat',Arial,sans-serif";
    }
  }
  if(window.Chart){ applyChartTheme(); }
  else{ document.addEventListener('DOMContentLoaded', applyChartTheme); }
})();
</script>
