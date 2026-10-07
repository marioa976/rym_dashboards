import 'package:flutter/material.dart';

import 'api/api_client.dart';
import 'config.dart';
import 'screens/login_screen.dart';
import 'screens/ordenes_screen.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await ApiClient.instance.load();
  runApp(const CuadrillasApp());
}

class CuadrillasApp extends StatelessWidget {
  const CuadrillasApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Cuadrillas',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(
        useMaterial3: true,
        colorSchemeSeed: const Color(Config.brand),
        scaffoldBackgroundColor: const Color(0xFFF4F6FA),
        appBarTheme: const AppBarTheme(
          backgroundColor: Color(Config.brand),
          foregroundColor: Colors.white,
          elevation: 0,
        ),
      ),
      home: ApiClient.instance.isLoggedIn ? const OrdenesScreen() : const LoginScreen(),
    );
  }
}
