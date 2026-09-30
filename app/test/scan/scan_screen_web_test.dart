@TestOn('browser')
library;

import 'package:eduvision/features/scan/scan_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

// Run with: flutter test --platform chrome test/scan/scan_screen_web_test.dart
//
// The real providers on purpose: in a browser the drift database cannot be
// opened, so building the scan processor would fail.
void main() {
  testWidgets('the web preview explains scanning without building the '
      'processor', (tester) async {
    await pumpScreen(tester, const ScanScreen());

    expect(find.text('สแกนใบงานได้เฉพาะในแอป Android'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('leaving the screen leaves no lifecycle observer behind', (
    tester,
  ) async {
    await pumpScreen(tester, const ScanScreen());
    await tester.pageBack();
    await tester.pumpAndSettle();

    // Switching browser tabs used to reach the disposed screen's ref.
    for (final state in [
      AppLifecycleState.inactive,
      AppLifecycleState.hidden,
      AppLifecycleState.inactive,
      AppLifecycleState.resumed,
    ]) {
      tester.binding.handleAppLifecycleStateChanged(state);
    }
    await tester.pump();
    expect(tester.takeException(), isNull);
  });
}
