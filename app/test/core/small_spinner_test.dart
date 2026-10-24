import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/core/widgets/small_spinner.dart';
import 'package:flutter/cupertino.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §27.7: the spinner of a busy button fits the icon's box and takes
/// the button's icon colour on iOS; a thin ring elsewhere.
void main() {
  Future<void> pumpBusyButton(WidgetTester tester) => tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.light(),
      home: Scaffold(
        body: Center(
          child: FilledButton.icon(
            onPressed: () {},
            icon: const SmallSpinner(size: 16),
            label: const Text('บันทึก'),
          ),
        ),
      ),
    ),
  );

  testWidgets('Android: a thin ring in a 16 px box', (tester) async {
    await pumpBusyButton(tester);
    expect(find.byType(CupertinoActivityIndicator), findsNothing);
    final ring = tester.widget<CircularProgressIndicator>(
      find.byType(CircularProgressIndicator),
    );
    expect(ring.strokeWidth, 2);
    expect(tester.getSize(find.byType(SmallSpinner)), const Size(16, 16));
  });

  testWidgets('iOS: the activity indicator sized to the box, icon colour', (
    tester,
  ) async {
    debugDefaultTargetPlatformOverride = TargetPlatform.iOS;
    await pumpBusyButton(tester);
    final indicator = tester.widget<CupertinoActivityIndicator>(
      find.byType(CupertinoActivityIndicator),
    );
    expect(indicator.radius, 8);
    expect(indicator.color, AppTheme.light().colorScheme.onPrimary);
    expect(tester.getSize(find.byType(SmallSpinner)), const Size(16, 16));
    debugDefaultTargetPlatformOverride = null;
  });
}
