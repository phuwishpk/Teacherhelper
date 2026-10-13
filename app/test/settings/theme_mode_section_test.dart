import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/theme/theme_mode_controller.dart';
import 'package:eduvision/features/settings/theme_mode_section.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('light until the user chooses, then the remembered theme', () async {
    final fresh = ProviderContainer(
      overrides: [
        tokenStorageProvider.overrideWithValue(InMemoryTokenStorage()),
      ],
    );
    addTearDown(fresh.dispose);
    await fresh.read(themeModeProvider.notifier).load();
    expect(fresh.read(themeModeProvider), ThemeMode.light);

    final returning = ProviderContainer(
      overrides: [
        tokenStorageProvider.overrideWithValue(
          InMemoryTokenStorage(themeMode: 'dark'),
        ),
      ],
    );
    addTearDown(returning.dispose);
    await returning.read(themeModeProvider.notifier).load();
    expect(returning.read(themeModeProvider), ThemeMode.dark);
  });

  testWidgets('the card switches the app theme and remembers it', (
    tester,
  ) async {
    final storage = InMemoryTokenStorage();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [tokenStorageProvider.overrideWithValue(storage)],
        child: Consumer(
          builder: (context, ref, _) => MaterialApp(
            theme: ThemeData.light(),
            darkTheme: ThemeData.dark(),
            themeMode: ref.watch(themeModeProvider),
            home: const Scaffold(body: ThemeModeSection()),
          ),
        ),
      ),
    );
    Brightness brightness() =>
        Theme.of(tester.element(find.byType(ThemeModeSection))).brightness;
    expect(brightness(), Brightness.light);

    await tester.tap(find.text('มืด'));
    await tester.pumpAndSettle();
    expect(brightness(), Brightness.dark);
    expect(storage.themeMode, 'dark');

    await tester.tap(find.text('ตามเครื่อง'));
    await tester.pumpAndSettle();
    expect(storage.themeMode, 'system');

    await tester.tap(find.text('สว่าง'));
    await tester.pumpAndSettle();
    expect(brightness(), Brightness.light);
    expect(storage.themeMode, 'light');
  });
}
