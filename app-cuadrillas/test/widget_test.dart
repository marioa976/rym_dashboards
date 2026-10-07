import 'package:flutter_test/flutter_test.dart';

import 'package:cuadrillas_app/main.dart';

void main() {
  testWidgets('Arranca en la pantalla de login', (WidgetTester tester) async {
    await tester.pumpWidget(const CuadrillasApp());
    // Sin sesión guardada, la app muestra el login.
    expect(find.text('Entrar'), findsOneWidget);
    expect(find.text('Cuadrillas'), findsWidgets);
  });
}
