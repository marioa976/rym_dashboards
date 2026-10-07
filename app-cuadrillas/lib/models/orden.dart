import 'parada.dart';

class Orden {
  final int id;
  final String fecha;
  final String titulo;
  String estatus;
  final int nParadas;
  int nResueltas;
  final double km;
  final List<Parada> paradas;

  Orden({
    required this.id,
    required this.fecha,
    required this.titulo,
    required this.estatus,
    required this.nParadas,
    required this.nResueltas,
    required this.km,
    required this.paradas,
  });

  double get avance => nParadas == 0 ? 0 : nResueltas / nParadas;

  factory Orden.fromJson(Map<String, dynamic> j) => Orden(
        id: (j['id'] as num).toInt(),
        fecha: (j['fecha'] ?? '').toString(),
        titulo: (j['titulo'] ?? '').toString(),
        estatus: (j['estatus'] ?? 'despachada').toString(),
        nParadas: (j['n_paradas'] as num?)?.toInt() ?? 0,
        nResueltas: (j['n_resueltas'] as num?)?.toInt() ?? 0,
        km: (j['km'] as num?)?.toDouble() ?? 0,
        paradas: ((j['paradas'] as List?) ?? [])
            .map((p) => Parada.fromJson(Map<String, dynamic>.from(p)))
            .toList(),
      );
}
