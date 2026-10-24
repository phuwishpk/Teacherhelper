import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/core/widgets/content_column.dart';
import 'package:flutter/cupertino.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §27.7: Material on Android and the web, Apple's alert and spinner
/// on iOS. (The adaptive switch stays a `Switch` drawn the iOS way, so it is
/// not checked here.)
void main() {
  bool? result;

  // The spinner never settles, so wait a fixed time instead.
  Future<void> settle(WidgetTester tester) async {
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 500));
  }

  Future<void> pumpPage(WidgetTester tester) async {
    result = null;
    await tester.pumpWidget(
      MaterialApp(
        theme: AppTheme.light(),
        home: Builder(
          builder: (context) => Scaffold(
            body: Column(
              children: [
                TextButton(
                  onPressed: () async => result = await confirm(
                    context,
                    title: 'ลบห้องนี้?',
                    message: 'ลบแล้วกู้คืนไม่ได้',
                    confirmLabel: 'ลบ',
                    destructive: true,
                  ),
                  child: const Text('เปิด'),
                ),
                SwitchListTile.adaptive(
                  title: const Text('แจ้งเตือน'),
                  value: true,
                  onChanged: (_) {},
                ),
                const CircularProgressIndicator.adaptive(),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Future<void> open(WidgetTester tester) async {
    await tester.tap(find.text('เปิด'));
    await settle(tester);
    expect(find.text('ลบแล้วกู้คืนไม่ได้'), findsOneWidget);
  }

  testWidgets('Android: a Material dialog and spinner', (tester) async {
    await pumpPage(tester);
    expect(find.byType(CupertinoActivityIndicator), findsNothing);

    await open(tester);
    expect(find.byType(AlertDialog), findsOneWidget);
    expect(find.byType(CupertinoAlertDialog), findsNothing);
    final delete = tester.widget<FilledButton>(
      find.widgetWithText(FilledButton, 'ลบ'),
    );
    expect(
      delete.style?.backgroundColor?.resolve({}),
      AppTheme.light().colorScheme.error,
    );
    await tester.tap(find.text('ลบ'));
    await settle(tester);
    expect(result, isTrue);

    await open(tester);
    await tester.tap(find.text('ยกเลิก'));
    await settle(tester);
    expect(result, isFalse);
  });

  testWidgets('iOS: an iOS alert with a red destructive button', (
    tester,
  ) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    await pumpPage(tester);
    expect(find.byType(CupertinoActivityIndicator), findsOneWidget);

    await open(tester);
    expect(find.byType(CupertinoAlertDialog), findsOneWidget);
    expect(find.byType(AlertDialog), findsNothing);
    final delete = tester.widget<CupertinoDialogAction>(
      find.widgetWithText(CupertinoDialogAction, 'ลบ'),
    );
    expect(delete.isDestructiveAction, isTrue);
    await tester.tap(find.text('ลบ'));
    await settle(tester);
    expect(result, isTrue);

    await open(tester);
    await tester.tap(find.text('ยกเลิก'));
    await settle(tester);
    expect(result, isFalse);
    debugDefaultTargetPlatformOverride = null;
  });
}
