import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';

/// The theme the user picked for this device (DESIGN §27.1): light until
/// they choose otherwise, remembered next to the login tab.
class ThemeModeController extends Notifier<ThemeMode> {
  @override
  ThemeMode build() => ThemeMode.light;

  /// Reads the remembered choice; called once before the first frame.
  Future<void> load() async {
    try {
      final saved = await ref.read(tokenStorageProvider).readThemeMode();
      state = _parse(saved) ?? state;
    } catch (_) {
      // Storage unavailable: stay on the light theme.
    }
  }

  Future<void> select(ThemeMode mode) async {
    state = mode;
    try {
      await ref.read(tokenStorageProvider).writeThemeMode(mode.name);
    } catch (_) {
      // Remembering the theme is a convenience; never fail the page over it.
    }
  }

  static ThemeMode? _parse(String? name) => switch (name) {
    'light' => ThemeMode.light,
    'dark' => ThemeMode.dark,
    'system' => ThemeMode.system,
    _ => null,
  };
}

final themeModeProvider = NotifierProvider<ThemeModeController, ThemeMode>(
  ThemeModeController.new,
);
