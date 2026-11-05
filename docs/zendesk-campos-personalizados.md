# Zendesk · Mapeo de campos del portal

> Referencia del mapeo de campos de Zendesk → columnas de la tabla `tickets` en
> `portal_qro`. Fuente: tabla **`zendesk_mapeo`** (la que lee `zd_cargar_mapeo()` en
> `modules/zendesk/_zendesk_lib.php`). Generado el 2026-11-05.
>
> Total: **64 campos** · estándar: **8** · personalizados: **56**.
> Todos con `guardar=1` se escriben en `tickets` al importar/sincronizar.
> El `campo_id` es el ID del campo personalizado en Zendesk (los estándar no lo usan).

## Campos estándar (8)

| campo_id | Nombre | Columna (`tickets`) | Tipo | Guardar |
|---|---|---|---|---|
| `std_id` | Ticket ID | `ticket_id` | texto | sí |
| `std_created_at` | Creado | `fecha_creacion` | fecha | sí |
| `std_subject` | Asunto | `zd_asunto` | texto | sí |
| `std_description` | Descripción | `zd_descripcion` | textolargo | sí |
| `std_status` | Estado (Zendesk) | `zd_estado` | texto | sí |
| `std_priority` | Prioridad (Zendesk) | `zd_prioridad` | texto | sí |
| `std_updated_at` | Actualizado | `zd_actualizado` | fecha | sí |
| `std_tags` | Tags | `zd_tags` | textolargo | sí |

## Campos personalizados (56)

| campo_id | Nombre | Columna (`tickets`) | Tipo | Guardar |
|---|---|---|---|---|
| `ID del campo` | Nombre conocido | `zd_nombre_conocido` | texto | sí |
| `30277746014619` | External ID Trámite (Uso Interno) | `zd_external_id_tramite_uso_interno` | texto | sí |
| `30278458271131` | Fecha estimada resolución | `zd_fecha_estimada_resolucion` | fecha | sí |
| `30283185089563` | Language | `zd_language` | texto | sí |
| `30283185103259` | Language confidence | `zd_language_confidence` | texto | sí |
| `30283185109147` | Sentiment | `zd_sentiment` | texto | sí |
| `30283202402587` | Sentiment confidence | `zd_sentiment_confidence` | texto | sí |
| `30289064739483` | Calle | `zd_calle` | texto | sí |
| `30310204899355` | Geolocalizacion | `latitud` | geo | sí |
| `30774801051675` | Nombre | `zd_nombre` | texto | sí |
| `30774806575643` | Apellido paterno | `zd_apellido_paterno` | texto | sí |
| `30774844883355` | Apellido materno | `zd_apellido_materno` | texto | sí |
| `30774870688027` | Teléfono | `zd_telefono` | texto | sí |
| `30774968022939` | Dirección | `zd_direccion` | textolargo | sí |
| `30874463523099` | Servicio | `zd_servicio` | texto | sí |
| `30930277484955` | Dependencia | `zd_dependencia` | texto | sí |
| `30954708072347` | Delegación | `zd_delegacion` | texto | sí |
| `30954716982683` | Colonia | `zd_colonia` | texto | sí |
| `31056030284059` | Imagen Evidencia (URL) | `zd_imagen_evidencia_url` | textolargo | sí |
| `31398369804827` | ID Siebel (Histórico Siebel) | `zd_id_siebel_historico_siebel` | textolargo | sí |
| `31398433135131` | Trámite / Servicio (Histórico Siebel) | `zd_tramite_servicio_historico_siebel` | textolargo | sí |
| `31657758911387` | Trámite | `zd_tramite` | texto | sí |
| `31658502069787` | CURP | `zd_curp` | texto | sí |
| `31658508667291` | Clave Catastral | `zd_clave_catastral` | texto | sí |
| `31758914331419` | Texto Notificación - Estado trámite | `zd_texto_notificacion_estado_tramite` | textolargo | sí |
| `31758940040475` | Texto Notificación - Detalle trámite | `zd_texto_notificacion_detalle_tramite` | textolargo | sí |
| `31758941273115` | Texto Notificación - Next steps trámite | `zd_texto_notificacion_next_steps_tramite` | textolargo | sí |
| `32061554573851` | Origen | `zd_origen` | texto | sí |
| `32633428068507` | Foto | `zd_foto` | textolargo | sí |
| `34447263331099` | Waiting Time | `zd_waiting_time` | texto | sí |
| `34447268839195` | Aircall SMS ID | `zd_aircall_sms_id` | texto | sí |
| `34447282840603` | Talk Time | `zd_talk_time` | texto | sí |
| `34447299021467` | Aircall Call ID | `zd_aircall_call_id` | texto | sí |
| `34447299077275` | Duration | `zd_duration` | texto | sí |
| `34656252740251` | Histórico Estados | `zd_historico_estados` | textolargo | sí |
| `38364924412059` | Primer mensaje | `zd_primer_mensaje` | textolargo | sí |
| `39003466062875` | Ticket actualizado | `zd_ticket_actualizado` | texto | sí |
| `39065662480411` | Estado de aprobación | `zd_estado_de_aprobacion` | texto | sí |
| `39077160152475` | Motivo de no resolución exitosa | `zd_motivo_de_no_resolucion_exitosa` | textolargo | sí |
| `39077332704411` | Tipo de resolución | `zd_tipo_de_resolucion` | texto | sí |
| `39102788352155` | Llamada de cierre 070 | `zd_llamada_de_cierre_070` | texto | sí |
| `39103356425499` | Observación ciudadano | `zd_observacion_ciudadano` | textolargo | sí |
| `39433926299419` | Días hasta entrega | `zd_dias_hasta_entrega` | texto | sí |
| `39476158463515` | Tiempo estimado de resolución | `zd_tiempo_estimado_de_resolucion` | texto | sí |
| `39593888123675` | Open | `zd_open` | texto | sí |
| `40264807997723` | Motivo de no resolución | `zd_motivo_de_no_resolucion` | textolargo | sí |
| `41208139785115` | Satisfaction rating url | `zd_satisfaction_rating_url` | texto | sí |
| `42697341168795` | Promotor | `zd_promotor` | texto | sí |
| `42958319160475` | Equivalencia Zuper | `zd_equivalencia_zuper` | texto | sí |
| `43004553297179` | Tipo de resolución | `zd_tipo_de_resolucion_2` | texto | sí |
| `44200813400603` | Folio SIPUM | `zd_folio_sipum` | texto | sí |
| `47924465416219` | ID SIEBEL | `zd_id_siebel` | texto | sí |
| `49468096117275` | Tareas necesarias | `zd_tareas_necesarias` | textolargo | sí |
| `50340639690139` | Nivel de resolución | `zd_nivel_de_resolucion` | texto | sí |
| `50340653684507` | Grupo de canales | `zd_grupo_de_canales` | texto | sí |
| `51306711778331` | Colonia Geolocalizada | `latitud` | geo | sí |

## Campos personalizados por tema

**👤 Identidad del ciudadano:** Nombre · Apellido paterno · Apellido materno · Nombre conocido · CURP · Teléfono · Promotor

**📍 Ubicación:** Dirección · Calle · Colonia · Delegación · Clave Catastral · Geolocalización (geo) · Colonia Geolocalizada (geo)

**🗂️ Trámite / servicio:** Trámite · Servicio · Dependencia · Origen · Grupo de canales · Equivalencia Zuper · Folio SIPUM · External ID Trámite · Trámite/Servicio (histórico Siebel) · ID Siebel (histórico) · ID SIEBEL

**✅ Resolución / estado:** Fecha estimada resolución · Tiempo estimado de resolución · Días hasta entrega · Estado de aprobación · Nivel de resolución · Tipo de resolución (×2) · Motivo de no resolución · Motivo de no resolución exitosa · Llamada de cierre 070 · Histórico Estados · Ticket actualizado · Open · Tareas necesarias

**📷 Evidencias:** Imagen Evidencia (URL) · Foto

**💬 Notificaciones / comunicación:** Texto Notificación – Estado / Detalle / Next steps · Primer mensaje · Observación ciudadano · Satisfaction rating url

**🤖 Análisis IA (Zendesk):** Language · Language confidence · Sentiment · Sentiment confidence

**☎️ Telefonía (Aircall):** Waiting Time · Talk Time · Duration · Aircall SMS ID · Aircall Call ID

## ⚠️ Anomalías a revisar

1. **Fila con `campo_id` = "ID del campo"** (nombre "Nombre conocido"): parece un renglón de encabezado/semilla que se coló como dato; conviene corregir su `campo_id` real o eliminarlo.
2. **Colisión de columna `latitud`**: los campos *Geolocalización* (`30310204899355`) y *Colonia Geolocalizada* (`51306711778331`) mapean ambos a la columna `latitud` (tipo geo). Si un ticket trae ambos, el último procesado pisa al otro.
3. **Nombres duplicados**: *Tipo de resolución* (columnas `zd_tipo_de_resolucion` y `zd_tipo_de_resolucion_2`) e *ID SIEBEL* / *ID Siebel*.

## Notas técnicas

- El mapeo se sincroniza con `zd_sincronizar_estructura()` (crea/crece columnas en `tickets`).
- `tipo` admite: `texto`, `textolargo`, `fecha`, `geo` (lat/lng), además de los estándar.
- La **delegación** (`zd_delegacion`, campo `30954708072347`) se normaliza a las 7 oficiales con `zd_deleg_canon()`; la sección/delegación por geometría la asigna el cron `cron_geo.php` de madrugada.
- Si se agrega una columna a `tickets`, hay que **recrear** la vista `v_tickets` (`CREATE OR REPLACE VIEW`).
