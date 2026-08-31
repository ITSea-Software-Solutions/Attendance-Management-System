import 'package:flutter/material.dart';

import 'core/scope.dart';
import 'ui/home.dart';
import 'ui/login.dart';

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();
  final state = AppState();
  await state.bootstrap();
  runApp(AmsApp(state: state));
}

class AmsApp extends StatelessWidget {
  const AmsApp({super.key, required this.state});
  final AppState state;

  @override
  Widget build(BuildContext context) {
    const seed = Color(0xFF10685A);
    return AppScope(
      state: state,
      child: MaterialApp(
        title: 'TrueCrew',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: seed),
          useMaterial3: true,
          inputDecorationTheme:
              const InputDecorationTheme(border: OutlineInputBorder()),
        ),
        darkTheme: ThemeData(
          colorScheme:
              ColorScheme.fromSeed(seedColor: seed, brightness: Brightness.dark),
          useMaterial3: true,
          inputDecorationTheme:
              const InputDecorationTheme(border: OutlineInputBorder()),
        ),
        home: const _Root(),
      ),
    );
  }
}

class _Root extends StatelessWidget {
  const _Root();
  @override
  Widget build(BuildContext context) {
    final app = AppScope.of(context);
    return app.user == null ? const LoginScreen() : const HomeScreen();
  }
}
