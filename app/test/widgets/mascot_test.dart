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
    bool moves = true,
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
              moves: moves,
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
      find.descendant(of: find.byType(Mascot), matching: find.byType(SizedBox)),
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

  group('moving about', () {
    const firstRest = Duration(milliseconds: 1200);
    const midSway = Duration(milliseconds: 350);
    const restAfter = Duration(seconds: 4);

    Matrix4 sway(WidgetTester tester) => tester
        .widget<Transform>(
          find.descendant(
            of: find.byType(Mascot),
            matching: find.byType(Transform),
          ),
        )
        .transform;

    MascotPainter painter(WidgetTester tester) =>
        tester.widget<CustomPaint>(findMascotPaint()).painter! as MascotPainter;

    testWidgets('it stands still, sways for a moment, rests and sways again', (
      tester,
    ) async {
      await tester.pumpWidget(buildFrame(mood: MascotMood.idle));
      expect(sway(tester), Matrix4.identity());

      await tester.pump(firstRest);
      await tester.pump(midSway);
      expect(sway(tester), isNot(Matrix4.identity()));

      await tester.pump(const Duration(seconds: 3));
      expect(sway(tester), Matrix4.identity());
      expect(tester.binding.hasScheduledFrame, isFalse);

      await tester.pump(restAfter);
      await tester.pump(midSway);
      expect(sway(tester), isNot(Matrix4.identity()));
    });

    testWidgets('it leaves pumpAndSettle able to end', (tester) async {
      await tester.pumpWidget(buildFrame(mood: MascotMood.wave));
      await tester.pumpAndSettle();
      await tester.pump(firstRest);
      await tester.pumpAndSettle();
      expect(sway(tester), Matrix4.identity());
    });

    testWidgets('the raised hand waves while it sways', (tester) async {
      await tester.pumpWidget(buildFrame(mood: MascotMood.wave));
      expect(painter(tester).wave, isNull);

      await tester.pump(firstRest);
      await tester.pump(midSway);
      expect(painter(tester).wave, isNotNull);
    });

    testWidgets('it stays still when the device asks for less motion', (
      tester,
    ) async {
      await tester.pumpWidget(
        buildFrame(mood: MascotMood.idle, disableAnimations: true),
      );
      await tester.pump(const Duration(seconds: 10));

      expect(sway(tester), Matrix4.identity());
      expect(tester.binding.hasScheduledFrame, isFalse);
    });

    testWidgets('it stops at once when the device starts asking for that', (
      tester,
    ) async {
      await tester.pumpWidget(buildFrame(mood: MascotMood.idle));
      await tester.pump(firstRest);
      await tester.pump(midSway);

      await tester.pumpWidget(
        buildFrame(mood: MascotMood.idle, disableAnimations: true),
      );
      await tester.pump();
      expect(sway(tester), Matrix4.identity());
    });

    testWidgets('moves: false keeps one of many still', (tester) async {
      await tester.pumpWidget(buildFrame(mood: MascotMood.idle, moves: false));
      await tester.pump(firstRest);
      await tester.pump(midSway);

      expect(sway(tester), Matrix4.identity());
      expect(tester.binding.hasScheduledFrame, isFalse);
    });
  });

  testWidgets('provides semantics label when supplied', (tester) async {
    await tester.pumpWidget(
      buildFrame(mood: MascotMood.idle, semanticLabel: Mascot.name),
    );
    expect(find.bySemanticsLabel(Mascot.name), findsOneWidget);
  });
}
