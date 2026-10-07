import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../config.dart';
import '../estatus.dart';
import '../models/orden.dart';
import '../models/parada.dart';

class OrdenScreen extends StatefulWidget {
  final Orden orden;
  const OrdenScreen({super.key, required this.orden});
  @override
  State<OrdenScreen> createState() => _OrdenScreenState();
}

class _OrdenScreenState extends State<OrdenScreen> {
  late Orden orden = widget.orden;
  int? _guardando;   // id de parada guardando estatus
  int? _subiendo;    // id de parada subiendo foto
  final _picker = ImagePicker();

  Future<void> _tomarFoto(Parada p) async {
    final XFile? x = await _picker.pickImage(
      source: ImageSource.camera,
      maxWidth: 1600,
      imageQuality: 70,
    );
    if (x == null) return;
    final bytes = await x.readAsBytes();
    setState(() => _subiendo = p.id);
    try {
      final res = await ApiClient.instance.subirEvidencia(p.id, bytes, tipo: 'despues');
      final ev = res['evidencia'];
      if (ev is Map && ev['id'] != null) {
        setState(() => p.evidencias.add((ev['id'] as num).toInt()));
      }
      _toast('Foto guardada.');
    } on ApiException catch (e) {
      _toast(e.message);
    } finally {
      if (mounted) setState(() => _subiendo = null);
    }
  }

  Future<void> _navegar(Parada p) async {
    if (p.lat == null || p.lng == null) {
      _toast('Esta parada no tiene ubicación.');
      return;
    }
    final uri = Uri.parse(
        'https://www.google.com/maps/dir/?api=1&destination=${p.lat},${p.lng}&travelmode=driving');
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      _toast('No se pudo abrir el mapa.');
    }
  }

  Future<void> _cambiarEstatus(Parada p) async {
    final opciones = <String>['en_camino', 'en_sitio', 'resuelta', 'no_resuelta', 'pendiente'];
    final elegido = await showModalBottomSheet<String>(
      context: context,
      showDragHandle: true,
      builder: (_) => SafeArea(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 12),
              child: Align(
                alignment: Alignment.centerLeft,
                child: Text('Cambiar estatus',
                    style: Theme.of(context).textTheme.titleMedium?.copyWith(fontWeight: FontWeight.w700)),
              ),
            ),
            for (final k in opciones)
              ListTile(
                leading: Icon(Icons.circle, color: paradaInfo(k).color, size: 14),
                title: Text(paradaInfo(k).label),
                trailing: p.estatus == k ? const Icon(Icons.check, size: 18) : null,
                onTap: () => Navigator.pop(context, k),
              ),
          ],
        ),
      ),
    );
    if (elegido == null || elegido == p.estatus) return;

    String? motivo;
    if (elegido == 'no_resuelta') {
      motivo = await _pedirMotivo();
      if (motivo == null) return; // cancelado
    }

    setState(() => _guardando = p.id);
    try {
      final res = await ApiClient.instance.actualizarEstatus(p.id, elegido, motivo: motivo);
      setState(() {
        p.estatus = elegido;
        p.motivoNo = elegido == 'no_resuelta' ? motivo : null;
        final o = res['orden'];
        if (o is Map) {
          orden.estatus = (o['estatus'] ?? orden.estatus).toString();
          orden.nResueltas = (o['n_resueltas'] as num?)?.toInt() ?? orden.nResueltas;
        }
      });
    } on ApiException catch (e) {
      _toast(e.message);
    } finally {
      if (mounted) setState(() => _guardando = null);
    }
  }

  Future<String?> _pedirMotivo() async {
    final ctrl = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('¿Por qué no se resolvió?'),
        content: TextField(
          controller: ctrl,
          autofocus: true,
          maxLength: 255,
          decoration: const InputDecoration(hintText: 'Ej. domicilio sin acceso'),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancelar')),
          FilledButton(
            onPressed: () {
              final t = ctrl.text.trim();
              if (t.isEmpty) return;
              Navigator.pop(context, t);
            },
            child: const Text('Guardar'),
          ),
        ],
      ),
    );
  }

  void _toast(String m) {
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(m)));
  }

  @override
  Widget build(BuildContext context) {
    final info = ordenInfo(orden.estatus);
    return Scaffold(
      appBar: AppBar(title: const Text('Ruta')),
      body: Column(
        children: [
          Container(
            width: double.infinity,
            padding: const EdgeInsets.fromLTRB(16, 14, 16, 16),
            color: Colors.white,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(orden.titulo,
                          style: const TextStyle(fontSize: 17, fontWeight: FontWeight.w800)),
                    ),
                    EstatusChip(info),
                  ],
                ),
                const SizedBox(height: 4),
                Text('${orden.fecha} · ${orden.nResueltas}/${orden.nParadas} resueltas',
                    style: const TextStyle(color: Colors.black54, fontSize: 13)),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: ListView.separated(
              padding: const EdgeInsets.all(14),
              itemCount: orden.paradas.length,
              separatorBuilder: (_, __) => const SizedBox(height: 10),
              itemBuilder: (_, i) => _ParadaCard(
                parada: orden.paradas[i],
                guardando: _guardando == orden.paradas[i].id,
                subiendoFoto: _subiendo == orden.paradas[i].id,
                onNavegar: () => _navegar(orden.paradas[i]),
                onEstatus: () => _cambiarEstatus(orden.paradas[i]),
                onFoto: () => _tomarFoto(orden.paradas[i]),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _ParadaCard extends StatelessWidget {
  final Parada parada;
  final bool guardando;
  final bool subiendoFoto;
  final VoidCallback onNavegar;
  final VoidCallback onEstatus;
  final VoidCallback onFoto;
  const _ParadaCard({
    required this.parada,
    required this.guardando,
    required this.subiendoFoto,
    required this.onNavegar,
    required this.onEstatus,
    required this.onFoto,
  });

  @override
  Widget build(BuildContext context) {
    final info = paradaInfo(parada.estatus);
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: const Color(0xFFE2E8F0)),
      ),
      padding: const EdgeInsets.all(14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              CircleAvatar(
                radius: 15,
                backgroundColor: const Color(0xFFEFF2F7),
                child: Text('${parada.idx + 1}',
                    style: const TextStyle(fontWeight: FontWeight.w800, color: Colors.black87, fontSize: 13)),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(parada.titulo ?? 'Parada',
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                    if (parada.direccion != null && parada.direccion!.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text(parada.direccion!,
                            style: const TextStyle(color: Colors.black54, fontSize: 13)),
                      ),
                    if (parada.ticketId != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 2),
                        child: Text('Ticket #${parada.ticketId}',
                            style: const TextStyle(color: Colors.black38, fontSize: 12)),
                      ),
                    if (parada.estatus == 'no_resuelta' && parada.motivoNo != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text('Motivo: ${parada.motivoNo}',
                            style: const TextStyle(color: Color(0xFFB91C1C), fontSize: 12.5)),
                      ),
                  ],
                ),
              ),
              EstatusChip(info),
            ],
          ),
          if (parada.evidencias.isNotEmpty) ...[
            const SizedBox(height: 12),
            SizedBox(
              height: 56,
              child: ListView.separated(
                scrollDirection: Axis.horizontal,
                itemCount: parada.evidencias.length,
                separatorBuilder: (_, __) => const SizedBox(width: 8),
                itemBuilder: (_, i) => ClipRRect(
                  borderRadius: BorderRadius.circular(8),
                  child: Image.network(
                    Config.evidenciaUrl(parada.evidencias[i]),
                    headers: ApiClient.instance.authHeaders,
                    width: 56,
                    height: 56,
                    fit: BoxFit.cover,
                    errorBuilder: (_, __, ___) => Container(
                      width: 56,
                      height: 56,
                      color: const Color(0xFFEFF2F7),
                      child: const Icon(Icons.broken_image_outlined, color: Colors.black26, size: 20),
                    ),
                  ),
                ),
              ),
            ),
          ],
          const SizedBox(height: 12),
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: onNavegar,
                  icon: const Icon(Icons.navigation_outlined, size: 18),
                  label: const Text('Ir'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: subiendoFoto ? null : onFoto,
                  icon: subiendoFoto
                      ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.camera_alt_outlined, size: 18),
                  label: const Text('Foto'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: FilledButton.icon(
                  onPressed: guardando ? null : onEstatus,
                  icon: guardando
                      ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                      : const Icon(Icons.flag_outlined, size: 18),
                  label: const Text('Estatus'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}
