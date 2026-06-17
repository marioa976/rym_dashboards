# Portal Querétaro con Futuro — Dashboards

Portal modular en **PHP plano + MariaDB** que unifica los dashboards de
**DIF**, **Zendesk** (Reportes de Servicio) y **Qrobici** bajo un único login
con **roles por módulo** e identidad visual de *Querétaro con Futuro*.

URL base esperada: `http://localhost:8888/portal/`

## Estructura
```
portal/
├── index.php                 # Inicio: muestra solo los módulos permitidos
├── login.php / logout.php / acceso-denegado.php
├── bin_admin.php             # CLI: crear usuarios y asignar módulos
├── config/                   # config.php (CENTRAL: portal + credenciales de los 3 módulos)
├── core/                     # auth, guard, helpers
├── sql/
│   ├── schema.sql            # BD del portal (usuarios/roles/módulos) + seed
│   └── 02_homologacion.sql   # rutas de entrada de los módulos
├── views/layout/             # header / footer (sidebar + topbar QRO)
├── assets/                   # css (tokens de marca), js, img
└── modules/
    ├── _portalbar.php        # barra superior compartida (zendesk/qrobici)
    ├── dif/                   # app DIF (entrada: index.php)
    ├── zendesk/              # app Reportes de Servicio (entrada: dashboard.php)
    └── qrobici/              # app Qrobici (entrada: index.php)
```

## Arquitectura de la homologación
- **Acceso:** cada `modules/<m>/config.php` invoca el guard del portal
  (`require_module('<m>')`). Quien no tiene el módulo asignado recibe 403.
  El guard se dispara al cargar el config/db de la app (sin tocar su lógica).
- **Credenciales centralizadas:** las 3 bases y las API keys viven en
  `config/config.php → 'modulos'`. Cada config de módulo es un *shim* que toma
  de ahí y conserva la forma que su código original espera.
- **Navegación:** barra QRO común. DIF usa `_nav.php` rebrandeado; Zendesk y
  Qrobici incluyen `_portalbar.php`.

## Bases de datos (UNA sola: `portal_qro`)
Todo el portal, DIF y Zendesk viven en **una** base local `portal_qro`.
Qrobici es la excepción: usa una BD **remota** con vistas.

| Módulo  | Tablas en `portal_qro`                  | Origen |
|---------|------------------------------------------|--------|
| Portal  | `usuarios`, `modulos`, `usuario_modulo`, `sesiones` | seed |
| DIF     | `padron`, `geocode_cache`, `import_log`  | dump de `padron_dif` |
| Zendesk | `tickets`, `cat_*`, `cargas`, vistas `v_*` | dump de `reportes_servicio` |
| Qrobici | — (remota, vistas `dwh_viajes`/`dwh_planes`) | servidor GCP |

## Instalación (MAMP)
1. Arranca MAMP (Apache 8888, MySQL 8889).
2. Crea/llena la base única con el esquema unificado:
   ```bash
   /Applications/MAMP/Library/bin/mysql -u root -proot -P8889 -h127.0.0.1 < sql/schema.sql
   ```
3. (Cuando quieras) vuelca los datos reales de las bases viejas:
   ```bash
   bash sql/migrar_datos.sh
   ```
4. Entra a `http://localhost:8888/portal/login.php`.

> Nota: `schema.sql` reemplaza al viejo flujo de 3 bases.
> Los `modules/*/schema.sql` se conservan solo como referencia/origen.

## Acceso inicial
- Usuario: `admin@qro.gob.mx` · Contraseña: `Cambiar.2026` ← **cámbiala ya.**
- El admin (`es_admin=1`) ve los 3 módulos automáticamente.

## Crear usuarios y asignar módulos
```bash
php bin_admin.php crear "Ana López" ana@qro.gob.mx "Pass.Segura1"
php bin_admin.php asignar ana@qro.gob.mx dif lector
php bin_admin.php asignar ana@qro.gob.mx zendesk editor
php bin_admin.php listar
```

## Seguridad incluida
- Login con `password_hash` (bcrypt) + rehash, CSRF, sesión endurecida, bloqueo
  por intentos fallidos, PDO con prepared statements.
- `.htaccess` que niega acceso directo a `config/`, `core/`, `sql/` y, en cada
  módulo, a archivos de datos (`.xlsx/.csv/.sql/.zip`) e includes (`config.php`,
  `db.php`, `lib_*.php`, `_nav.php`, `_portalbar.php`).

## Pendientes recomendados (producción)
- Mover credenciales de `config/config.php` a variables de entorno.
- Restringir la API key de Google Maps por dominio (hoy es la misma en los 3).
- Rotar las credenciales remotas de Qrobici (estuvieron en texto plano).
- Forzar HTTPS y `APP_ENV=production`.
- Revisar `modules/zendesk/dashboard.html` (mockup estático heredado).
