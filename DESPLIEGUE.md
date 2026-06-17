# Despliegue — Portal Querétaro con Futuro

## Arquitectura
- **App**: contenedor PHP 8.2 + Apache en **Google Cloud Run** (ver `Dockerfile`).
- **BD**: VM pequeña con **MariaDB** (la base `portal_qro`).
- **Config**: 100% por **variables de entorno** (ver `.env.example`).
  En local se usan vía `.env`; en Cloud Run se definen en el servicio.

## 1. Preparar la base de datos (VM con MariaDB)
1. Crea la BD y carga el esquema:
   ```sql
   CREATE DATABASE portal_qro CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
   Importa los `.sql` de la carpeta `sql/` (esquema, mapeo, colonias, etc.).
2. Crea un usuario de aplicación (no uses root):
   ```sql
   CREATE USER 'portal_app'@'%' IDENTIFIED BY 'UNA_CONTRASEÑA_FUERTE';
   GRANT ALL PRIVILEGES ON portal_qro.* TO 'portal_app'@'%';
   FLUSH PRIVILEGES;
   ```
3. **Red**: Cloud Run debe poder llegar a la VM. Dos opciones:
   - **Recomendado**: VM y Cloud Run en la misma VPC + **Serverless VPC Access connector**, y conectar por IP privada.
   - Rápido (menos seguro): abrir el puerto 3306 de la VM a las IP de salida de Cloud Run y conectar por IP pública.

## 2. Variables de entorno en Cloud Run
No subas el `.env`. Define estas variables en el servicio (las no sensibles como
texto plano y las sensibles con **Secret Manager**):

No sensibles (`--set-env-vars`):
```
APP_ENV=production
APP_BASE_URL=
DB_HOST=<ip-de-tu-VM>
DB_PORT=3306
DB_NAME=portal_qro
MAP_ID=<tu-map-id>
ZENDESK_SUBDOMAIN=municipioqueretaro
ZENDESK_USER=integraciones@municipiodequeretaro.gob.mx/token
ZENDESK_TAG_DEFAULT=servicio_recoleccion_tiliches
QROBICI_DB_HOST=34.136.63.53
QROBICI_DB_PORT=3306
QROBICI_DB_NAME=qrobici
```
Sensibles (mejor como secretos, `--set-secrets`):
```
DB_USER, DB_PASS, GOOGLE_MAPS_API_KEY, ZENDESK_TOKEN,
QROBICI_DB_USER, QROBICI_DB_PASS, WAZE_FEED_URL
```

## 3. Construir y desplegar
Con el `Dockerfile` en la raíz, Cloud Build arma la imagen solo:
```bash
gcloud run deploy portal-qro \
  --source . \
  --region us-central1 \
  --allow-unauthenticated \
  --max-instances 1 \
  --set-env-vars APP_ENV=production,APP_BASE_URL=,DB_HOST=...,DB_PORT=3306,DB_NAME=portal_qro,...
# y los secretos:
#  --set-secrets DB_USER=DB_USER:latest,DB_PASS=DB_PASS:latest,GOOGLE_MAPS_API_KEY=...
```

## 4. Notas importantes
- **Sesiones**: el portal usa sesiones en archivo. Cloud Run puede levantar varias
  instancias y NO comparten archivos → un usuario podría "perder" la sesión.
  Por eso arriba va `--max-instances 1` para empezar. Si crece el tráfico,
  conviene mover las sesiones a la BD (te lo puedo implementar).
- **APP_BASE_URL**: en Cloud Run la app vive en la raíz, así que va vacío (`""`).
  En MAMP es `/portal`.
- **Crons de Zendesk** (`modules/zendesk/cron_import.php`): Cloud Run no corre crons
  por sí solo. Usa **Cloud Scheduler** apuntando a un endpoint, o un Cloud Run Job.
- **Map ID**: crea uno propio en Google Cloud Console (no uses `DEMO_MAP_ID` en prod).
