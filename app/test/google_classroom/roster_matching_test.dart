import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/google_classroom/google_providers.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/google_classroom/roster_matching_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'google_fakes.dart';

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/1',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
      googleLink: ClassroomGoogleLink(
        courseId: '6210',
        courseName: 'คณิต ป.5/1',
      ),
    ),
  ];

  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
    RosterStudent(studentId: 4568, studentNumber: 13, name: 'ด.ช. สมชาย'),
    RosterStudent(studentId: 4569, studentNumber: 14, name: 'ด.ช. มานะ'),
  ];
}

const _entries = [
  GoogleRosterEntry(
    googleUserId: '1001',
    name: 'Somying Rakrian',
    email: 'somying@gmail.com',
    suggestedStudentId: 4567,
  ),
  GoogleRosterEntry(
    googleUserId: '1002',
    name: 'Chai',
    email: 'chai@gmail.com',
  ),
];

Future<FakeGoogleRepository> _pump(WidgetTester tester) async {
  tester.view.physicalSize = const Size(1000, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final google = FakeGoogleRepository(rosterRows: _entries);
  await pumpScreen(
    tester,
    const GoogleRosterScreen(classroomId: 7),
    overrides: [
      googleClassroomEnabledProvider.overrideWithValue(true),
      googleClassroomRepositoryProvider.overrideWithValue(google),
      classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
    ],
  );
  return google;
}

Future<void> _pick(
  WidgetTester tester,
  String googleUserId,
  String label,
) async {
  await tester.tap(find.byKey(ValueKey('roster_pick_$googleUserId')));
  await tester.pumpAndSettle();
  await tester.tap(find.text(label).last);
  await tester.pumpAndSettle();
}

/// DESIGN §18.7: suggested pairs pre-filled, the teacher edits, the screen
/// warns about unmatched accounts/students and refuses duplicates.
void main() {
  testWidgets('suggested pairs are pre-filled and unmatched ones warned', (
    tester,
  ) async {
    await _pump(tester);

    expect(
      find.textContaining('คณิต ป.5/1: จับคู่แล้ว 1/2 บัญชี'),
      findsOneWidget,
    );
    final first = find.byKey(const ValueKey('roster_entry_1001'));
    expect(
      find.descendant(of: first, matching: find.text('เลขที่ 12  ด.ญ. สมหญิง')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: first, matching: find.text('เสนอจากชื่อ')),
      findsOneWidget,
    );
    final second = find.byKey(const ValueKey('roster_entry_1002'));
    expect(
      find.descendant(of: second, matching: find.text('ไม่จับคู่')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('roster_unmatched_accounts')),
      findsOneWidget,
    );
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('roster_unmatched_students')),
        matching: find.textContaining('2 คน (เลขที่ 13, เลขที่ 14)'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('the same student twice blocks saving', (tester) async {
    final google = await _pump(tester);

    await _pick(tester, '1002', 'เลขที่ 12  ด.ญ. สมหญิง');

    expect(find.byKey(const ValueKey('roster_duplicates')), findsOneWidget);
    expect(
      find.text('นักเรียนคนนี้ถูกเลือกให้บัญชีอื่นแล้ว'),
      findsNWidgets(2),
    );
    final save = tester.widget<FilledButton>(
      find.byKey(const ValueKey('roster_save')),
    );
    expect(save.onPressed, isNull);
    expect(google.savedMatches, isEmpty);
  });

  testWidgets('saving sends every pair, including "not matched"', (
    tester,
  ) async {
    final google = await _pump(tester);

    await _pick(tester, '1002', 'เลขที่ 13  ด.ช. สมชาย');
    expect(
      find.byKey(const ValueKey('roster_unmatched_accounts')),
      findsNothing,
    );

    await _pick(tester, '1001', 'ไม่จับคู่');
    await tester.tap(find.byKey(const ValueKey('roster_save')));
    await tester.pumpAndSettle();

    expect(google.savedMatches.single, {'1001': null, '1002': 4568});
    expect(find.text('stub-home'), findsOneWidget, reason: 'back after saving');
  });

  testWidgets('a suggestion outside the room is dropped', (tester) async {
    tester.view.physicalSize = const Size(1000, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final google = FakeGoogleRepository(
      rosterRows: const [
        GoogleRosterEntry(
          googleUserId: '1003',
          name: 'Left school',
          suggestedStudentId: 9999,
        ),
      ],
    );
    await pumpScreen(
      tester,
      const GoogleRosterScreen(classroomId: 7),
      overrides: [
        googleClassroomEnabledProvider.overrideWithValue(true),
        googleClassroomRepositoryProvider.overrideWithValue(google),
        classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
      ],
    );
    expect(find.text('ไม่จับคู่'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('roster_save')));
    await tester.pumpAndSettle();
    expect(google.savedMatches.single, {'1003': null});
  });
}
