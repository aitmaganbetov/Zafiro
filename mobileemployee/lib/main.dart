import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
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
  final session = Session(ApiClient())..restore();
  // Вышли (или токен истёк) — закрываем открытые поверх экраны: остаётся вход.
  var wasSignedIn = false;
  session.addListener(() {
    if (wasSignedIn && !session.signedIn) {
      _navigator.currentState?.popUntil((route) => route.isFirst);
    }
    wasSignedIn = session.signedIn;
  });
  runApp(ZafiroStaffApp(session: session));
}

final _navigator = GlobalKey<NavigatorState>();

class ZafiroStaffApp extends StatelessWidget {
  const ZafiroStaffApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      navigatorKey: _navigator,
      title: 'Zafiro Staff',
      debugShowCheckedModeBanner: false,
      // Как панель на сайте: светлая и тёмная тема, по настройке телефона.
      theme: buildTheme(Brightness.light),
      darkTheme: buildTheme(Brightness.dark),
      themeMode: ThemeMode.system,
      locale: const Locale('ru'),
      supportedLocales: const [Locale('ru')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      home: ListenableBuilder(
        listenable: session,
        builder: (context, _) {
          if (session.restoring) {
            return const Scaffold(body: Center(child: BrandMark(height: 48)));
          }
          if (!session.signedIn) return LoginScreen(session: session);
          if (session.me == null) return _ProfileError(session: session);
          // Новый клуб — экраны с его данными создаются заново.
          return HomeScreen(
            key: ValueKey(session.clubVersion),
            session: session,
          );
        },
      ),
    );
  }
}

/// Токен есть, но профиль не загрузился (нет сети).
class _ProfileError extends StatefulWidget {
  const _ProfileError({required this.session});

  final Session session;

  @override
  State<_ProfileError> createState() => _ProfileErrorState();
}

class _ProfileErrorState extends State<_ProfileError> {
  bool _busy = false;

  Future<void> _retry() async {
    setState(() => _busy = true);
    await widget.session.reloadMe();
    if (mounted) setState(() => _busy = false);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const BrandMark(height: 44),
                const SizedBox(height: 24),
                ErrorBox(
                  widget.session.loadError ?? 'Не удалось загрузить профиль.',
                ),
                const SizedBox(height: 16),
                BusyButton(label: 'Повторить', busy: _busy, onPressed: _retry),
                const SizedBox(height: 8),
                TextButton(
                  onPressed: widget.session.logout,
                  child: const Text('Выйти'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
