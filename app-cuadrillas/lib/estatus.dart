import 'package:flutter/material.dart';

/// Metadatos de estatus de parada (etiqueta + color).
class EstatusInfo {
  final String key;
  final String label;
  final Color color;
  const EstatusInfo(this.key, this.label, this.color);
}

const estatusParada = <String, EstatusInfo>{
  'pendiente': EstatusInfo('pendiente', 'Pendiente', Color(0xFF64748B)),
  'en_camino': EstatusInfo('en_camino', 'En camino', Color(0xFF1D4ED8)),
  'en_sitio': EstatusInfo('en_sitio', 'En sitio', Color(0xFFB45309)),
  'resuelta': EstatusInfo('resuelta', 'Resuelta', Color(0xFF15803D)),
  'no_resuelta': EstatusInfo('no_resuelta', 'No resuelta', Color(0xFFB91C1C)),
};

const estatusOrden = <String, EstatusInfo>{
  'borrador': EstatusInfo('borrador', 'Borrador', Color(0xFF64748B)),
  'despachada': EstatusInfo('despachada', 'Despachada', Color(0xFF1D4ED8)),
  'en_proceso': EstatusInfo('en_proceso', 'En proceso', Color(0xFFB45309)),
  'cerrada': EstatusInfo('cerrada', 'Cerrada', Color(0xFF15803D)),
  'cancelada': EstatusInfo('cancelada', 'Cancelada', Color(0xFFB91C1C)),
};

EstatusInfo paradaInfo(String k) =>
    estatusParada[k] ?? const EstatusInfo('?', '—', Color(0xFF64748B));
EstatusInfo ordenInfo(String k) =>
    estatusOrden[k] ?? const EstatusInfo('?', '—', Color(0xFF64748B));

class EstatusChip extends StatelessWidget {
  final EstatusInfo info;
  const EstatusChip(this.info, {super.key});
  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          color: info.color.withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(100),
        ),
        child: Text(info.label,
            style: TextStyle(color: info.color, fontWeight: FontWeight.w700, fontSize: 12)),
      );
}
