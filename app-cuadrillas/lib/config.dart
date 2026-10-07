/// Configuración de la app.
class Config {
  /// Base de la API de Cuadrillas.
  /// Producción:
  static const String baseUrl =
      'https://dashboards-qro.mimunicipio.app/modules/cuadrillas/api';

  /// Para pruebas contra tu máquina (emulador Android usa 10.0.2.2):
  // static const String baseUrl = 'http://10.0.2.2:8888/portal/modules/cuadrillas/api';

  /// Colores de marca.
  static const int brand = 0xFF005AB2; // azul QRO
  static const int accent = 0xFF0F766E; // verde cuadrillas
}
