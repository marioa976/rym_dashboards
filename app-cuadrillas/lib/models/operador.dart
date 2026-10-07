class Operador {
  final int id;
  final String nombre;
  final String? rol;
  final int? cuadrillaId;
  final String? cuadrilla;

  Operador({
    required this.id,
    required this.nombre,
    this.rol,
    this.cuadrillaId,
    this.cuadrilla,
  });

  factory Operador.fromJson(Map<String, dynamic> j) => Operador(
        id: (j['id'] as num).toInt(),
        nombre: (j['nombre'] ?? '').toString(),
        rol: j['rol']?.toString(),
        cuadrillaId: j['cuadrilla_id'] == null ? null : (j['cuadrilla_id'] as num).toInt(),
        cuadrilla: j['cuadrilla']?.toString(),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'nombre': nombre,
        'rol': rol,
        'cuadrilla_id': cuadrillaId,
        'cuadrilla': cuadrilla,
      };
}
