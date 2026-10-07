# Cuadrillas · App de campo (Flutter / Android)

App para que las cuadrillas atiendan sus rutas: ver las órdenes del día,
navegar a cada parada y actualizar el estatus. Consume la API del portal
(`modules/cuadrillas/api/`).

> **MVP (Fase 2):** login, mis rutas, detalle de paradas, navegar y cambiar
> estatus (online). Evidencias con cámara + offline llegan en la Fase 3.

## Requisitos
- Flutter 3.19+ (Dart 3.3+). `flutter doctor` en verde para Android.

## Arranque
Este repo guarda solo `lib/` + `pubspec.yaml` (el código). Las carpetas de
plataforma (`android/`, etc.) las genera Flutter y **no** se versionan.

```bash
cd app-cuadrillas

# 1) Genera el proyecto Android (crea android/, .metadata, etc.).
#    Si sobrescribe lib/main.dart, restáuralo: git checkout lib/
flutter create --platforms=android --org app.mimunicipio --project-name cuadrillas_app .
git checkout lib/ pubspec.yaml   # conserva NUESTRO código si create lo tocó

# 2) Dependencias y correr
flutter pub get
flutter run            # con un emulador o dispositivo conectado
```

### Permiso de internet (Android)
`flutter create` ya incluye el permiso de internet en modo debug. Para release,
asegúrate de tener en `android/app/src/main/AndroidManifest.xml`:

```xml
<uses-permission android:name="android.permission.INTERNET"/>
```

## Configuración
`lib/config.dart` → `Config.baseUrl`:
- **Producción** (por defecto): `https://dashboards-qro.mimunicipio.app/modules/cuadrillas/api`
- **Local (emulador Android)**: `http://10.0.2.2:8888/portal/modules/cuadrillas/api`

## Cuentas
Las crea el supervisor en el portal: **Cuadrillas → Padrón → Operadores**
(usuario + contraseña). Cada operador debe estar asignado a una cuadrilla para
ver rutas.

## Estructura
```
lib/
  config.dart            # baseUrl + colores
  estatus.dart           # etiquetas/colores de estatus + chip
  main.dart              # arranque + tema + ruta inicial
  api/api_client.dart    # cliente HTTP + token (SharedPreferences)
  models/                # Operador, Orden, Parada
  screens/
    login_screen.dart
    ordenes_screen.dart  # mis rutas (lista de órdenes)
    orden_screen.dart    # paradas: navegar + cambiar estatus
```

## API que consume
- `POST login.php` → `{ token, operador }`
- `GET  mis_ordenes.php` (Bearer) → órdenes abiertas + paradas
- `POST estatus.php` (Bearer) → actualiza una parada

## Pendiente (Fase 3)
- Evidencias (cámara → Google Cloud Storage).
- Offline-first (cola local + sync).
- Push (FCM) de "nueva ruta asignada".
- Mapa embebido de la ruta (hoy se navega con la app de mapas del teléfono).
