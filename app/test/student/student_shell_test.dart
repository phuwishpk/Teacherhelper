import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/analysis/analysis_repository.dart';
import 'package:eduvision/features/mastery/course_mastery.dart';
import 'package:eduvision/features/mastery/mastery_models.dart';
import 'package:eduvision/features/mastery/mastery_repository.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:eduvision/features/student/student_shell.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'student_fixtures.dart';

/// The student's home is "วิชาของฉัน" (DESIGN §24.13); grades moved under
/// it, so the shell keeps five tabs.
void main() {
  testWidgets('opens on "วิชาของฉัน" and switches tabs', (tester) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 2.7;
    addTearDown(tester.view.reset);
    await pumpScreen(
      tester,
      const StudentShell(),
      overrides: [
        ...studentViewOverrides(),
        currentUserProvider.overrideWithValue(
          const User(id: 11, name: 'ด.ญ. เอ', role: 'student'),
        ),
        practiceRecommendationsProvider.overrideWith((ref) async => const []),
        myMasteryProvider.overrideWith(
          (ref) async => const MasteryList(rows: []),
        ),
        myAnalysesProvider.overrideWith((ref) async => const []),
        myCoursesProvider.overrideWith((ref) async => const []),
      ],
    );

    final bar = find.byType(NavigationBar);
    for (final label in [
      'วิชาของฉัน',
      'ส่งงาน',
      'ผลการบ้าน',
      'แบบฝึก',
      'ทักษะ',
    ]) {
      expect(
        find.descendant(of: bar, matching: find.text(label)),
        findsOneWidget,
      );
    }
    expect(find.descendant(of: bar, matching: find.text('เกรด')), findsNothing);
    expect(find.text('ต้องส่งอีก 3 งาน'), findsOneWidget);

    await tester.tap(find.descendant(of: bar, matching: find.text('ส่งงาน')));
    await tester.pumpAndSettle();
    expect(find.text('เศษส่วน ชุดที่ 1'), findsOneWidget);
  });
}
