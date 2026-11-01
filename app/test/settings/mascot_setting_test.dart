import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/features/settings/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  testWidgets('settings screen toggles mascot switch and updates state', (
    tester,
  ) async {
    final storage = InMemoryTokenStorage();
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          tokenStorageProvider.overrideWithValue(storage),
        ],
        child: const MaterialApp(
          home: SettingsScreen(),
        ),
      ),
    );
    await tester.pumpAndSettle();

    final switchFinder = find.byKey(const ValueKey('settings_mascot'));
    await tester.drag(find.byType(ListView), const Offset(0, -300));
    await tester.pumpAndSettle();

    expect(switchFinder, findsOneWidget);
    expect(tester.widget<SwitchListTile>(switchFinder).value, isTrue);

    await tester.tap(switchFinder);
    await tester.pumpAndSettle();

    expect(tester.widget<SwitchListTile>(switchFinder).value, isFalse);
  });
}
