import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/date_symbol_data_local.dart';

import 'api/api_client.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'session.dart';
import 'theme.dart';
import 'widgets.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  await initializeDateFormatting('ru');
  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      systemNavigationBarColor: Brand.background,
      systemNavigationBarIconBrightness: Brightness.light,
    ),
  );
  final session = Session(ApiClient())..restore();
  runApp(ZafiroClientApp(session: session));
}

class ZafiroClientApp extends StatelessWidget {
  const ZafiroClientApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Zafiro',
      debugShowCheckedModeBanner: false,
      // Сайт тёмный — приложение тоже, независимо от темы телефона.
      theme: buildTheme(),
      builder: (context, child) => Backdrop(child: child!),
      home: ListenableBuilder(
        listenable: session,
        builder: (context, _) {
          if (session.restoring) {
            return const Scaffold(body: Center(child: BrandMark(height: 44)));
          }
          return session.signedIn
              ? HomeScreen(session: session)
              : LoginScreen(session: session);
        },
      ),
    );
  }
}
