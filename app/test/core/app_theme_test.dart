import 'dart:math' as math;

import 'package:eduvision/core/theme/app_theme.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §27.4, §27.5: one typeface, flat cards, and text that can be read
/// on every surface it is put on (WCAG AA, 4.5 : 1).
void main() {
  double contrast(Color a, Color b) {
    final la = a.computeLuminance(), lb = b.computeLuminance();
    return (math.max(la, lb) + 0.05) / (math.min(la, lb) + 0.05);
  }

  for (final (name, theme) in [
    ('light', AppTheme.light()),
    ('dark', AppTheme.dark()),
  ]) {
    test('$name: text is readable on its surface', () {
      final s = theme.colorScheme;
      final pairs = {
        'onSurface on surface': (s.onSurface, s.surface),
        'onSurface on card': (s.onSurface, s.surfaceContainerLow),
        'onSurfaceVariant on surface': (s.onSurfaceVariant, s.surface),
        'onSurfaceVariant on card': (s.onSurfaceVariant, s.surfaceContainerLow),
        'onPrimary on primary': (s.onPrimary, s.primary),
        'primary on card': (s.primary, s.surfaceContainerLow),
        'onPrimaryContainer': (s.onPrimaryContainer, s.primaryContainer),
        'onSecondaryContainer': (s.onSecondaryContainer, s.secondaryContainer),
        'onTertiaryContainer': (s.onTertiaryContainer, s.tertiaryContainer),
        'onErrorContainer': (s.onErrorContainer, s.errorContainer),
        'error on card': (s.error, s.surfaceContainerLow),
        'onInverseSurface': (s.onInverseSurface, s.inverseSurface),
      };
      for (final e in pairs.entries) {
        expect(
          contrast(e.value.$1, e.value.$2),
          greaterThanOrEqualTo(4.5),
          reason: '$name: ${e.key}',
        );
      }
    });

    test('$name: one typeface, flat cards, 44 px buttons', () {
      expect(theme.textTheme.bodyMedium!.fontFamily, AppTheme.fontFamily);
      expect(theme.textTheme.titleLarge!.fontFamily, AppTheme.fontFamily);
      expect(theme.textTheme.bodyMedium!.height, greaterThanOrEqualTo(1.4));
      expect(theme.cardTheme.elevation, 0);
      expect(theme.colorScheme.surfaceTint, Colors.transparent);
      final size = theme.filledButtonTheme.style!.minimumSize!.resolve({})!;
      expect(size.height, greaterThanOrEqualTo(44));
      expect(theme.visualDensity, VisualDensity.standard);
    });
  }

  test('cards stand out from the page in both modes', () {
    final light = AppTheme.light().colorScheme;
    final dark = AppTheme.dark().colorScheme;
    expect(
      light.surfaceContainerLow.computeLuminance(),
      greaterThan(light.surface.computeLuminance()),
    );
    expect(
      dark.surfaceContainerLow.computeLuminance(),
      greaterThan(dark.surface.computeLuminance()),
    );
  });
}
