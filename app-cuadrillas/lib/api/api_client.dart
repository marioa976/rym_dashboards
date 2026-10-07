import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import '../config.dart';
import '../models/operador.dart';
import '../models/orden.dart';

class ApiException implements Exception {
  final String message;
  final int? code;
  ApiException(this.message, [this.code]);
  @override
  String toString() => message;
}

/// Cliente de la API de Cuadrillas. Singleton sencillo; guarda el token en
/// SharedPreferences y lo manda en cada petición.
class ApiClient {
  ApiClient._();
  static final ApiClient instance = ApiClient._();

  String? _token;
  Operador? operador;

  bool get isLoggedIn => _token != null;

  /// Carga sesión guardada al arrancar la app.
  Future<void> load() async {
    final p = await SharedPreferences.getInstance();
    _token = p.getString('token');
    final oj = p.getString('operador');
    if (oj != null) {
      try {
        operador = Operador.fromJson(Map<String, dynamic>.from(jsonDecode(oj)));
      } catch (_) {}
    }
  }

  Future<void> _persist() async {
    final p = await SharedPreferences.getInstance();
    if (_token != null) {
      await p.setString('token', _token!);
    } else {
      await p.remove('token');
    }
    if (operador != null) {
      await p.setString('operador', jsonEncode(operador!.toJson()));
    } else {
      await p.remove('operador');
    }
  }

  Map<String, String> get _headers => {
        'Content-Type': 'application/json',
        if (_token != null) 'Authorization': 'Bearer $_token',
        if (_token != null) 'X-Auth-Token': _token!,
      };

  Map<String, dynamic> _decode(http.Response r) {
    dynamic body;
    try {
      body = jsonDecode(r.body);
    } catch (_) {
      throw ApiException('Respuesta inválida del servidor (${r.statusCode}).', r.statusCode);
    }
    if (body is Map && body['ok'] == true) {
      return Map<String, dynamic>.from(body);
    }
    final msg = (body is Map && body['error'] != null)
        ? body['error'].toString()
        : 'Error ${r.statusCode}';
    throw ApiException(msg, r.statusCode);
  }

  Uri _u(String path) => Uri.parse('${Config.baseUrl}/$path');

  Future<void> login(String usuario, String password, {String? device}) async {
    late http.Response r;
    try {
      r = await http
          .post(_u('login.php'),
              headers: {'Content-Type': 'application/json'},
              body: jsonEncode({
                'usuario': usuario,
                'password': password,
                if (device != null) 'device': device,
              }))
          .timeout(const Duration(seconds: 20));
    } catch (e) {
      throw ApiException('No hay conexión con el servidor.');
    }
    final data = _decode(r);
    _token = data['token'] as String;
    operador = Operador.fromJson(Map<String, dynamic>.from(data['operador']));
    await _persist();
  }

  Future<void> logout() async {
    _token = null;
    operador = null;
    await _persist();
  }

  Future<List<Orden>> misOrdenes({String? fecha}) async {
    final path = fecha == null ? 'mis_ordenes.php' : 'mis_ordenes.php?fecha=$fecha';
    late http.Response r;
    try {
      r = await http.get(_u(path), headers: _headers).timeout(const Duration(seconds: 20));
    } catch (e) {
      throw ApiException('No hay conexión con el servidor.');
    }
    final data = _decode(r);
    return ((data['ordenes'] as List?) ?? [])
        .map((o) => Orden.fromJson(Map<String, dynamic>.from(o)))
        .toList();
  }

  /// Actualiza el estatus de una parada. Devuelve el resumen de la orden.
  Future<Map<String, dynamic>> actualizarEstatus(
    int paradaId,
    String estatus, {
    String? motivo,
    double? lat,
    double? lng,
  }) async {
    late http.Response r;
    try {
      r = await http
          .post(_u('estatus.php'),
              headers: _headers,
              body: jsonEncode({
                'parada_id': paradaId,
                'estatus': estatus,
                if (motivo != null) 'motivo': motivo,
                if (lat != null) 'lat': lat,
                if (lng != null) 'lng': lng,
              }))
          .timeout(const Duration(seconds: 20));
    } catch (e) {
      throw ApiException('No hay conexión con el servidor.');
    }
    return _decode(r);
  }
}
