import 'package:flutter/material.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'api/api_client.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'session.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('ru');
  final session = Session(ApiClient())..restore();
  runApp(ZafiroClientApp(session: session));
}

class ZafiroClientApp extends StatelessWidget {
  const ZafiroClientApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    const seed = Color(0xFF2563EB);
    return MaterialApp(
      title: 'Zafiro',
      debugShowCheckedModeBanner: false,
      theme: ThemeData(colorSchemeSeed: seed, useMaterial3: true),
      darkTheme: ThemeData(
        colorSchemeSeed: seed,
        brightness: Brightness.dark,
        useMaterial3: true,
      ),
      home: ListenableBuilder(
        listenable: session,
        builder: (context, _) {
          if (session.restoring) {
            return const Scaffold(
              body: Center(child: CircularProgressIndicator()),
            );
          }
          return session.signedIn
              ? HomeScreen(session: session)
              : LoginScreen(session: session);
        },
      ),
    );
  }
}
