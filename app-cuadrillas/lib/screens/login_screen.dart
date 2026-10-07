import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../config.dart';
import 'ordenes_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _usuario = TextEditingController();
  final _pass = TextEditingController();
  bool _cargando = false;
  bool _verPass = false;
  String? _error;

  @override
  void dispose() {
    _usuario.dispose();
    _pass.dispose();
    super.dispose();
  }

  Future<void> _entrar() async {
    FocusScope.of(context).unfocus();
    final u = _usuario.text.trim();
    final p = _pass.text;
    if (u.isEmpty || p.isEmpty) {
      setState(() => _error = 'Escribe tu usuario y contraseña.');
      return;
    }
    setState(() {
      _cargando = true;
      _error = null;
    });
    try {
      await ApiClient.instance.login(u, p, device: 'app');
      if (!mounted) return;
      Navigator.of(context).pushReplacement(
        MaterialPageRoute(builder: (_) => const OrdenesScreen()),
      );
    } on ApiException catch (e) {
      setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _cargando = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    const brand = Color(Config.brand);
    return Scaffold(
      backgroundColor: brand,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(28),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                const Icon(Icons.local_shipping_rounded, color: Colors.white, size: 64),
                const SizedBox(height: 14),
                const Text('Cuadrillas',
                    style: TextStyle(color: Colors.white, fontSize: 30, fontWeight: FontWeight.w800)),
                const Text('Querétaro con Futuro',
                    style: TextStyle(color: Colors.white70, fontSize: 14)),
                const SizedBox(height: 34),
                Container(
                  padding: const EdgeInsets.all(22),
                  decoration: BoxDecoration(
                    color: Colors.white,
                    borderRadius: BorderRadius.circular(18),
                  ),
                  child: Column(
                    children: [
                      TextField(
                        controller: _usuario,
                        autocorrect: false,
                        enableSuggestions: false,
                        textInputAction: TextInputAction.next,
                        decoration: const InputDecoration(
                          labelText: 'Usuario',
                          prefixIcon: Icon(Icons.person_outline),
                          border: OutlineInputBorder(),
                        ),
                      ),
                      const SizedBox(height: 14),
                      TextField(
                        controller: _pass,
                        obscureText: !_verPass,
                        textInputAction: TextInputAction.done,
                        onSubmitted: (_) => _entrar(),
                        decoration: InputDecoration(
                          labelText: 'Contraseña',
                          prefixIcon: const Icon(Icons.lock_outline),
                          border: const OutlineInputBorder(),
                          suffixIcon: IconButton(
                            icon: Icon(_verPass ? Icons.visibility_off : Icons.visibility),
                            onPressed: () => setState(() => _verPass = !_verPass),
                          ),
                        ),
                      ),
                      if (_error != null) ...[
                        const SizedBox(height: 14),
                        Text(_error!,
                            style: const TextStyle(color: Color(0xFFB91C1C), fontWeight: FontWeight.w600)),
                      ],
                      const SizedBox(height: 20),
                      SizedBox(
                        width: double.infinity,
                        height: 50,
                        child: FilledButton(
                          onPressed: _cargando ? null : _entrar,
                          child: _cargando
                              ? const SizedBox(
                                  width: 22, height: 22, child: CircularProgressIndicator(strokeWidth: 2.5, color: Colors.white))
                              : const Text('Entrar', style: TextStyle(fontSize: 16, fontWeight: FontWeight.w700)),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
