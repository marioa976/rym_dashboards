# CLAUDE.md — Portal "Querétaro con Futuro"

Contexto para trabajar este proyecto en Claude Code. Léelo antes de tocar código.

## Qué es
Portal PHP-plano + MariaDB/MySQL que unifica varios **dashboards municipales** en un
solo sitio con login, roles y módulos. No usa framework: PHP puro con PDO.

Módulos (carpeta `modules/<clave>/`):
- **dif** — padrón de apoyos DIF (importación XLSX, geocodificación, mapa).
- **zendesk** — tickets de atención ciudadana (sync API, dashboard, mapa, cuadrillas, por sección).
- **qrobici** — movilidad (BD remota propia).
- **electoral** — resultados IEEQ por sección (etiqueta VISIBLE = "Seccional"; la clave interna sigue siendo `electoral`).
- **qrobus** — beneficiarios "Unidos" (BD remota `iqt`): geocodificador + KPIs + mapa.
- **bloque** — edificio de innovación (cursos/actividades): KPIs + por delegación.
- **areasverdes** — áreas verdes municipales (listado oficial con coordenadas, sin
  geocodificar): reporte geográfico = mapa de marcadores por delegación + KPIs + tabla.
  Usa `delegaciones_geo` (límites oficiales del KMZ) para colorear/validar por geometría.
- **obras** — obra pública municipal POA 2024-2026: reporte geográfico (marcadores
  color=estatus, tamaño=inversión) + KPIs de inversión/avance + tabla. Coordenadas
  extraídas resolviendo los links cortos `maps.app.goo.gl` del Excel fuente.
- **ejecutivo** — tablero de dirección que CRUZA los demás módulos por delegación.
  Solo lee (no carga datos). Tres vistas: `index.php` (KPIs cruzados + matriz
  delegación×módulo + gráficas Chart.js; incluye Qrobici vía su BD remota, agregado
  y cacheado en `sys_get_temp_dir()`), `mapa.php` (capas encendibles: límites, obras,
  áreas verdes, estaciones Qrobici, tickets/DIF en calor deck.gl vía `data.php` AJAX)
  y `electoral.php` (mapa seccional Ayuntamiento 2024: participación / partido ganador).
  Capas del mapa además incluyen: calor de RUTAS Qrobici (decodifica `RECORRIDO` con
  `qrb_polyline_decode` del módulo qrobici, streaming sin búfer para no agotar memoria)
  y Waze en vivo (alertas por categoría + embotellamientos; reusa `lib_waze.php`/
  `waze_feed_url` de qrobici SIN incluir su `config.php` para no disparar su guard).
  Qrobus queda FUERA por decisión del usuario. La delegación de cada fuente se
  normaliza con `ej_canon()`; Qrobici usa point-in-polygon en PHP (`ej_deleg_punto`).

## Cómo correr en local (MAMP)
- Apache/MySQL de MAMP. Web en `http://localhost:8888/portal/`, MySQL en `127.0.0.1:8889` (root/root).
- BD unificada: **`portal_qro`** (usuarios, roles, módulos, DIF, Zendesk, electoral, bloque).
- Config central: `config/config.php` — **todo sale de variables de entorno**; en local las
  provee `.env` en la raíz (NO se sube a git; está en `.gitignore` y bloqueado por `.htaccess`).
- Secretos (tokens, claves de BD remotas, API keys) viven SOLO en `.env` y en las variables de
  entorno de Cloud Run. **Nunca** los pongas como default en `config.php` ni los subas a git.

## Producción
- Cloud Run con `Dockerfile` (php:8.2-apache). Escucha en `$PORT`. Variables por entorno / Secret Manager.
- BD de portal en una VM MariaDB; Qrobici y Qrobus tienen BDs remotas propias (`*_DB_*` en env).
- Deploy = build/push de la imagen + correr los SQL de `sql/` que apliquen.

## Convenciones (síguelas)
- **Módulo nuevo**: `modules/<clave>/config.php` (shim que hace `require_module('<clave>')` salvo en CLI
  y devuelve la config), un `lib.php` con la conexión, páginas, y registrar en la tabla `modulos` vía
  `sql/<clave>.sql` (idempotente, `ON DUPLICATE KEY UPDATE`). Chrome con `_portalbar.php` + un `_nav.php`.
- **Tema visual**: `assets/css/qro.css` (paleta azul QRO, Montserrat). Reutilízalo; no inventes estilos nuevos.
- **Permisos** (`core/guard.php`): `require_module('x')` = ver; `require_editor('x')` = escribir;
  `require_admin()` = admin. Niveles en `usuario_modulo` (`lector`/`editor`/`admin`). Los admin
  (`usuarios.es_admin=1`) ven TODOS los módulos. **Regla dura: un lector NO puede cargar ni limpiar datos**
  en ningún módulo (excepción autorizada: guardar/borrar sus propios planes de cuadrillas).
- La lista de módulos se arma **al iniciar sesión** (`$_SESSION['modulos']`): tras cambiar permisos hay
  que **cerrar sesión y volver a entrar**.
- **Gráficas**: Chart.js. **Mapas**: Google Maps (data layer para coropletas; deck.gl para heatmap —
  el `HeatmapLayer` de Google se removió en v3.65). Requiere `GOOGLE_MAPS_API_KEY`.

## Trampas ya resueltas (no las repitas)
- **Cruce espacial / error 1207**: no hacer `ST_Contains` con índice espacial bajo READ-UNCOMMITTED, y
  MariaDB trata SRID 4326 como PLANAR. La solución adoptada: precalcular `seccion_id` con
  `ST_Contains(ST_GeomFromWKB(ST_AsWKB(geom),0), ST_GeomFromText('POINT(lng lat)',0))`, correr en
  READ COMMITTED, y usar prefiltro por bounding-box (`secciones_bbox`). Los reportes agrupan por
  `seccion_id`, NO hacen ST_Contains en cada request.
- **Vistas de MySQL congelan columnas**: si agregas una columna a `tickets`, hay que **recrear**
  `v_tickets` (`CREATE OR REPLACE VIEW`) o los reportes truenan con "Unknown column".
- **Timeout en sincronización Zendesk (local)**: MAMP usa `mod_fastcgi`; su `-idle-timeout` (30s) mata
  peticiones largas ("incomplete headers"). La asignación espacial se desacopló del import y corre una
  sola vez al final. Para cargas grandes usar `modules/zendesk/cron_import.php` (CLI, sin timeout).
- **Import electoral lento en prod**: la BD es remota; se batchearon los INSERT de `resultados_casilla`
  (multi-fila) para no hacer un viaje de red por fila.
- **Geocodificador (qrobus)**: no intenta `CREATE TABLE` (el usuario de BD no tiene permiso); detecta si
  `geocode_cache` existe y si no, geocodifica sin caché. Costo API estimado en la UI (US$5/1000).

## Cómo verificar (no hay `php -l` disponible en algunos entornos)
- Antes de dar por bueno un cambio en un `.php` con HTML/JS, valida el JS extrayendo el `<script>` y
  corriendo `node --check`, y revisa balance de llaves/`endif` del lado PHP.
- Cambios de permisos: prueba con una cuenta NO admin del módulo, no con la tuya.

## SQL relevante en `sql/`
`schema.sql` (base), `electoral.sql`, `qrobus.sql`, `bloque.sql`, `areasverdes.sql`,
`areasverdes_delegaciones.sql` (límites oficiales `delegaciones_geo`), `obras.sql`,
`ejecutivo.sql` (registro de módulos; los de areasverdes/obras además crean su tabla y
cargan datos; `ejecutivo.sql` solo registra, no crea tablas — el módulo solo lee),
`zendesk_ticket_form*.sql` (columna + vista del filtro por formulario),
`rename_electoral_a_seccional.sql`. Todos pensados para ser idempotentes.
