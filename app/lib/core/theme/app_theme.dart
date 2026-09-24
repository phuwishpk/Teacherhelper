import 'package:flutter/material.dart';

/// Material 3 theme. M0 uses the platform default font: Android ships
/// Noto Sans Thai, so Thai renders correctly without bundling a font.
/// A branded Thai font (e.g. Sarabun) is a Phase 1 UI decision.
abstract final class AppTheme {
  static const seed = Color(0xFF1E6FD9);

  static ThemeData light() => ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(seedColor: seed),
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(),
    ),
  );

  static ThemeData dark() => ThemeData(
    useMaterial3: true,
    colorScheme: ColorScheme.fromSeed(
      seedColor: seed,
      brightness: Brightness.dark,
    ),
    inputDecorationTheme: const InputDecorationTheme(
      border: OutlineInputBorder(),
    ),
  );
}
