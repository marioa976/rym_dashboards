class Parada {
  final int id;
  final int idx;
  final int? ticketId;
  final String? titulo;
  final String? direccion;
  final double? lat;
  final double? lng;
  String estatus;
  String? motivoNo;
  final List<int> evidencias;

  Parada({
    required this.id,
    required this.idx,
    this.ticketId,
    this.titulo,
    this.direccion,
    this.lat,
    this.lng,
    this.estatus = 'pendiente',
    this.motivoNo,
    List<int>? evidencias,
  }) : evidencias = evidencias ?? [];

  bool get cerrada => estatus == 'resuelta' || estatus == 'no_resuelta';

  factory Parada.fromJson(Map<String, dynamic> j) => Parada(
        id: (j['id'] as num).toInt(),
        idx: (j['idx'] as num).toInt(),
        ticketId: j['ticket_id'] == null ? null : (j['ticket_id'] as num).toInt(),
        titulo: j['titulo']?.toString(),
        direccion: j['direccion']?.toString(),
        lat: j['lat'] == null ? null : (j['lat'] as num).toDouble(),
        lng: j['lng'] == null ? null : (j['lng'] as num).toDouble(),
        estatus: (j['estatus'] ?? 'pendiente').toString(),
        motivoNo: j['motivo_no']?.toString(),
        evidencias: ((j['evidencias'] as List?) ?? [])
            .map((e) => (e['id'] as num).toInt())
            .toList(),
      );
}
