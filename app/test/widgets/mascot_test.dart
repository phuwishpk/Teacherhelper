import 'package:eduvision/core/widgets/mascot.dart';
import 'package:eduvision/core/widgets/mascot_mood.dart';
import 'package:eduvision/core/widgets/mascot_painter.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  Widget buildFrame({
    required MascotMood mood,
    double size = 40,
    bool disableAnimations = false,
    String? semanticLabel,
  }) {
    return MaterialApp(
      home: MediaQuery(
        data: MediaQueryData(disableAnimations: disableAnimations),
        child: Scaffold(
          body: Center(
            child: Mascot(
              mood: mood,
              size: size,
              semanticLabel: semanticLabel,
            ),
          ),
        ),
      ),
    );
  }

  Finder findMascotPaint() => find.descendant(
    of: find.byType(Mascot),
    matching: find.byWidgetPredicate(
      (w) => w is CustomPaint && w.painter is MascotPainter,
    ),
  );

  testWidgets('renders all five moods without error', (tester) async {
    for (final mood in MascotMood.values) {
      await tester.pumpWidget(buildFrame(mood: mood));
      expect(find.byType(Mascot), findsOneWidget);
      expect(findMascotPaint(), findsOneWidget);
      final customPaint = tester.widget<CustomPaint>(findMascotPaint());
      final painter = customPaint.painter as MascotPainter;
      expect(painter.mood, mood);
      await tester.pump(const Duration(milliseconds: 350));
    }
  });

  testWidgets('renders with the requested size', (tester) async {
    const targetSize = 64.0;
    await tester.pumpWidget(
      buildFrame(mood: MascotMood.idle, size: targetSize),
    );
    final sizedBox = tester.widget<SizedBox>(
      find.descendant(
        of: find.byType(Mascot),
        matching: find.byType(SizedBox),
      ),
    );
    expect(sizedBox.width, targetSize);
    expect(sizedBox.height, targetSize);
  });

  testWidgets(
    'thinking mood animates, and stops when animations are disabled',
    (tester) async {
      await tester.pumpWidget(buildFrame(mood: MascotMood.thinking));
      await tester.pump(const Duration(milliseconds: 100));

      var customPaint = tester.widget<CustomPaint>(findMascotPaint());
      var painter = customPaint.painter as MascotPainter;
      expect(painter.pulse, isNotNull);

      await tester.pumpWidget(
        buildFrame(mood: MascotMood.thinking, disableAnimations: true),
      );
      await tester.pump(const Duration(milliseconds: 100));

      customPaint = tester.widget<CustomPaint>(findMascotPaint());
      painter = customPaint.painter as MascotPainter;
      expect(painter.pulse, isNull);
    },
  );

  testWidgets('provides semantics label when supplied', (tester) async {
    await tester.pumpWidget(
      buildFrame(mood: MascotMood.idle, semanticLabel: Mascot.name),
    );
    expect(find.bySemanticsLabel(Mascot.name), findsOneWidget);
  });
}
