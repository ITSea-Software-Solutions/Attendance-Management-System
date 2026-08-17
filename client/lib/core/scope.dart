import 'package:flutter/widgets.dart';

import '../data/repo.dart';

export '../data/repo.dart' show AppState;

/// Minimal app-wide state access (InheritedNotifier — no external dep).
class AppScope extends InheritedNotifier<AppState> {
  const AppScope({super.key, required AppState state, required super.child})
      : super(notifier: state);

  static AppState of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppScope>();
    assert(scope != null, 'AppScope missing from widget tree');
    return scope!.notifier!;
  }
}
