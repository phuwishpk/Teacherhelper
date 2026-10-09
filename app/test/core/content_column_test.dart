import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/core/widgets/content_column.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// DESIGN §27.4: on a wide window the bottom bar and the floating button
/// of a page stay with its content column.
void main() {
  Widget page() => MaterialApp(
    theme: AppTheme.light(),
    home: Scaffold(
      floatingActionButtonLocation: const ContentFabLocation(),
      floatingActionButton: FloatingActionButton(
        onPressed: () {},
        child: const Icon(Icons.add),
      ),
      bottomNavigationBar: BottomActionBar(
        child: FilledButton(
          key: const ValueKey('save'),
          onPressed: () {},
          child: const Text('บันทึก'),
        ),
      ),
      body: const ContentColumn(
        child: SizedBox.expand(key: ValueKey('content')),
      ),
    ),
  );

  Future<void> pumpAt(WidgetTester tester, Size size) async {
    tester.view.physicalSize = size;
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(page());
    await tester.pumpAndSettle();
  }

  testWidgets(
    'phone: the bar fills the width and the button is in the corner',
    (tester) async {
      await pumpAt(tester, const Size(390, 844));

      final bar = tester.getRect(find.byType(BottomActionBar));
      expect(bar.width, 390);
      final fab = tester.getRect(find.byType(FloatingActionButton));
      expect(fab.right, 390 - 16);
      expect(fab.bottom, lessThan(bar.top));
    },
  );

  testWidgets(
    'desktop: the bar content and the button line up with the column',
    (tester) async {
      await pumpAt(tester, const Size(1440, 900));

      const columnLeft = (1440 - 840) / 2;
      const columnRight = columnLeft + 840;
      // The bar itself still spans the window: only its content is narrow.
      expect(tester.getRect(find.byType(BottomActionBar)).width, 1440);
      final save = tester.getRect(find.byKey(const ValueKey('save')));
      expect(save.left, greaterThanOrEqualTo(columnLeft));
      expect(save.right, lessThanOrEqualTo(columnRight));
      final fab = tester.getRect(find.byType(FloatingActionButton));
      expect(fab.right, columnRight - 16);
      final content = tester.getRect(find.byKey(const ValueKey('content')));
      expect(fab.right, lessThanOrEqualTo(content.right + 32));
    },
  );
}
