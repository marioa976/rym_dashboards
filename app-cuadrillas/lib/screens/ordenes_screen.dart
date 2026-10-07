import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../estatus.dart';
import '../models/orden.dart';
import 'login_screen.dart';
import 'orden_screen.dart';

class OrdenesScreen extends StatefulWidget {
  const OrdenesScreen({super.key});
  @override
  State<OrdenesScreen> createState() => _OrdenesScreenState();
}

class _OrdenesScreenState extends State<OrdenesScreen> {
  late Future<List<Orden>> _future;

  @override
  void initState() {
    super.initState();
    _future = ApiClient.instance.misOrdenes();
  }

  Future<void> _recargar() async {
    final f = ApiClient.instance.misOrdenes();
    setState(() => _future = f);
    await f.catchError((_) => <Orden>[]);
  }

  Future<void> _salir() async {
    await ApiClient.instance.logout();
    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );
  }

  @override
  Widget build(BuildContext context) {
    final op = ApiClient.instance.operador;
    return Scaffold(
      appBar: AppBar(
        title: const Text('Mis rutas'),
        actions: [
          IconButton(
            tooltip: 'Salir',
            icon: const Icon(Icons.logout),
            onPressed: _salir,
          ),
        ],
      ),
      body: Column(
        children: [
          if (op != null)
            Container(
              width: double.infinity,
              color: const Color(0xFF004a94),
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
              child: Text(
                '${op.nombre}${op.cuadrilla != null ? ' · ${op.cuadrilla}' : ''}',
                style: const TextStyle(color: Colors.white70, fontSize: 13),
              ),
            ),
          Expanded(
            child: RefreshIndicator(
              onRefresh: _recargar,
              child: FutureBuilder<List<Orden>>(
                future: _future,
                builder: (context, snap) {
                  if (snap.connectionState == ConnectionState.waiting) {
                    return const Center(child: CircularProgressIndicator());
                  }
                  if (snap.hasError) {
                    return _mensaje(Icons.cloud_off, 'No se pudieron cargar las rutas',
                        '${snap.error}', _recargar);
                  }
                  final ordenes = snap.data ?? [];
                  if (ordenes.isEmpty) {
                    return _mensaje(Icons.check_circle_outline, 'Sin rutas pendientes',
                        'No tienes órdenes abiertas. Desliza hacia abajo para actualizar.', _recargar);
                  }
                  return ListView.builder(
                    physics: const AlwaysScrollableScrollPhysics(),
                    padding: const EdgeInsets.all(14),
                    itemCount: ordenes.length,
                    itemBuilder: (_, i) => _OrdenCard(
                      orden: ordenes[i],
                      onTap: () async {
                        await Navigator.of(context).push(
                          MaterialPageRoute(builder: (_) => OrdenScreen(orden: ordenes[i])),
                        );
                        _recargar();
                      },
                    ),
                  );
                },
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _mensaje(IconData ic, String titulo, String sub, VoidCallback onRetry) => ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 90),
          Icon(ic, size: 64, color: Colors.black26),
          const SizedBox(height: 14),
          Center(child: Text(titulo, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w700))),
          const SizedBox(height: 6),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 40),
            child: Text(sub, textAlign: TextAlign.center, style: const TextStyle(color: Colors.black54)),
          ),
          const SizedBox(height: 18),
          Center(child: OutlinedButton(onPressed: onRetry, child: const Text('Reintentar'))),
        ],
      );
}

class _OrdenCard extends StatelessWidget {
  final Orden orden;
  final VoidCallback onTap;
  const _OrdenCard({required this.orden, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final info = ordenInfo(orden.estatus);
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      elevation: 0,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(14),
        side: const BorderSide(color: Color(0xFFE2E8F0)),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(orden.titulo,
                        style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
                  ),
                  EstatusChip(info),
                ],
              ),
              const SizedBox(height: 4),
              Text('${orden.fecha} · ${orden.nParadas} paradas${orden.km > 0 ? ' · ${orden.km.toStringAsFixed(1)} km' : ''}',
                  style: const TextStyle(color: Colors.black54, fontSize: 12.5)),
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: ClipRRect(
                      borderRadius: BorderRadius.circular(4),
                      child: LinearProgressIndicator(
                        value: orden.avance,
                        minHeight: 7,
                        backgroundColor: const Color(0xFFE2E8F0),
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Text('${orden.nResueltas}/${orden.nParadas}',
                      style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 12.5)),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
