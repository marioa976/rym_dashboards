# Padrón DIF — Importador + Geocodificador

Pipeline para subir `PADRON-AYUDAS-255000.xlsx` a MariaDB y completar
latitud/longitud usando la **Google Maps Geocoding API**.

## Archivos

| Archivo        | Qué hace                                                                |
| -------------- | ----------------------------------------------------------------------- |
| `schema.sql`   | Base `padron_dif` con `padron`, `geocode_cache`, `import_log`.          |
| `config.php`   | Credenciales DB y `google_maps.api_key`.                                |
| `composer.json`| Dependencias (OpenSpout).                                               |
| `import.php`   | XLSX → tabla `padron` (PHP 8.2, streaming, OpenSpout).                  |
| `geocode.php`  | Completa lat/lng vía Google Maps con estrategias por colonia.           |

## Flujo

```bash
mysql -uroot -proot < schema.sql
composer install
# (edita config.php y pega tu API key)
php import.php
php geocode.php --test       # ← OBLIGATORIO antes de correr el batch
php geocode.php
```

## ⚠️ Diagnóstico antes de gastar API

`php geocode.php --test` hace **una sola llamada** y muestra el JSON completo
que devuelve Google, incluyendo `error_message`. Si la key tiene algún problema
de configuración (lo más común), aquí te enteras antes de gastar 50 mil
llamadas para nada.

**Causas comunes de que todo regrese ERROR:**

1. La **Geocoding API** no está habilitada en tu proyecto de Google Cloud.
   - Console → APIs & Services → Library → "Geocoding API" → Enable.
2. El proyecto no tiene **billing** activado (Google Maps lo exige incluso
   para el free tier).
3. La key tiene **restricciones de HTTP referrer** que no aplican a peticiones
   server-side. Para uso desde PHP/CLI, restringe por IP o deja "None".
4. La key tiene **restricciones de API** y Geocoding no está incluida en la lista.

## Estrategias de búsqueda

El script intenta la mejor consulta posible según los datos del registro:

1. `colonia_cp`    — colonia + CP + delegación + estado + país
2. `colonia`       — colonia + delegación + estado + país
3. `calle_colonia` — calle + colonia + delegación + estado + país
4. `cp`            — CP + estado + país

Cada consulta única se guarda en `geocode_cache` para no re-cobrar a la API
cuando varios registros comparten dirección.

## Modos de mantenimiento

```bash
php geocode.php --clear-cache       # vacía TODO el cache
php geocode.php --clear-errores     # sólo borra entradas con error del cache
php geocode.php --reset-padron      # vuelve a NULL los marcados con ERROR (intentos=0)
php geocode.php --limit=20          # procesa sólo 20 (prueba)
php geocode.php --reintenta-errores # vuelve a intentar los que fallaron
php geocode.php --solo-id=1234      # un único registro
php geocode.php --dry-run           # imprime queries sin gastar API
```

**Si ya corriste con la key mal configurada** y se envenenó el cache con miles
de ERROR, primero limpia:

```bash
php geocode.php --clear-errores
php geocode.php --reset-padron
php geocode.php --test              # confirma que ahora sí responde OK
php geocode.php --limit=20          # prueba con 20
php geocode.php                     # ahora sí, batch completo
```

## Notas

- La tabla `padron` permite NULL en todos los campos: se importan filas
  aunque vengan vacías.
- El CP se normaliza (sólo dígitos; si tiene 4 se le antepone `0`).
- Las fechas Excel se convierten a `YYYY-MM-DD`.
- `geocode_intentos` se incrementa por registro para evitar reintentos infinitos
  (configurable en `config.php` → `google_maps.max_intentos`).
- Hay un sleep entre llamadas (`sleep_us`, default 120ms) para no exceder
  el rate limit gratis de Google.
