import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/merge_students_screen.dart';
import 'package:eduvision/features/classrooms/school_students.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'classroom_fakes.dart';

void _tall(WidgetTester tester) {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

final _previewRoute = GoRoute(
  path: '/classrooms/:id/merge',
  builder: (_, s) => MergePreviewScreen(
    classroomId: int.parse(s.pathParameters['id']!),
    keepId: int.parse(s.uri.queryParameters['keep']!),
    mergeId: int.parse(s.uri.queryParameters['merge']!),
  ),
);

Future<void> _pumpPreview(WidgetTester tester, FakeSchoolClassrooms fake) {
  _tall(tester);
  return pumpScreen(
    tester,
    const MergePreviewScreen(classroomId: 7, keepId: 502, mergeId: 777),
    overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
  );
}

Finder _dialogButton(String label) => find.descendant(
  of: find.byType(AlertDialog),
  matching: find.widgetWithText(FilledButton, label),
);

void main() {
  testWidgets('find the other account, compare, confirm, merge', (
    tester,
  ) async {
    _tall(tester);
    final fake = FakeSchoolClassrooms();
    await pumpScreen(
      tester,
      const MergeStudentSearchScreen(classroomId: 7, studentId: 502),
      overrides: [classroomsRepositoryProvider.overrideWithValue(fake)],
      extraRoutes: [_previewRoute],
    );
    expect(find.text('ค้นหาอีกบัญชีของ ด.ญ. มานี มีนา'), findsOneWidget);

    await tester.enterText(
      find.byKey(const ValueKey('school_student_query')),
      'ด.',
    );
    await tester.testTextInput.receiveAction(TextInputAction.search);
    await tester.pumpAndSettle();
    final self = find.byKey(const ValueKey('school_student_502'));
    expect(tester.widget<ListTile>(self).enabled, isFalse);
    expect(
      find.descendant(of: self, matching: find.text('บัญชีนี้')),
      findsOneWidget,
    );

    await tester.tap(find.byKey(const ValueKey('school_student_501')));
    await tester.pumpAndSettle();
    expect(fake.previews, [(502, 501)]);
    expect(find.text('เทียบบัญชีก่อนรวม'), findsOneWidget);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('merge_keep')),
        matching: find.text('บัญชี 502'),
      ),
      findsOneWidget,
    );
    expect(find.text('3 (เผยแพร่แล้ว 0)'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('merge_submit')));
    await tester.pumpAndSettle();
    expect(find.text('รวม บัญชี 501 เข้ากับ บัญชี 502?'), findsOneWidget);
    expect(find.textContaining('ย้อนกลับไม่ได้'), findsWidgets);
    await tester.tap(find.text('ยกเลิก'));
    await tester.pumpAndSettle();
    expect(fake.merges, isEmpty, reason: 'nothing without the second yes');

    await tester.tap(find.byKey(const ValueKey('merge_submit')));
    await tester.pumpAndSettle();
    await tester.tap(_dialogButton('รวมบัญชี'));
    await tester.pumpAndSettle();
    expect(fake.merges, [(502, 501)]);
    // Both steps close; back where the teacher started.
    expect(find.text('stub-home'), findsOneWidget);
    expect(find.text('รวมบัญชีแล้ว เก็บบัญชี บัญชี 502 ไว้'), findsOneWidget);
  });

  testWidgets('a conflict blocks the merge; swapping asks again', (
    tester,
  ) async {
    final fake = FakeSchoolClassrooms()
      ..preview = (keep, merge) => MergePreview(
        keep: account(keep, 'บัญชี $keep', work: 2),
        merge: account(merge, 'บัญชี $merge', work: 2),
        conflicts: const [
          MergeConflict(
            type: 'submissions',
            message: 'ทั้งสองบัญชีมีงาน เศษส่วน ห้อง ป.5/2',
          ),
        ],
        canMerge: false,
      );
    await _pumpPreview(tester, fake);

    expect(find.byKey(const ValueKey('merge_conflicts')), findsOneWidget);
    expect(find.text('• ทั้งสองบัญชีมีงาน เศษส่วน ห้อง ป.5/2'), findsOneWidget);
    expect(
      tester
          .widget<FilledButton>(find.byKey(const ValueKey('merge_submit')))
          .onPressed,
      isNull,
    );

    await tester.tap(find.byKey(const ValueKey('merge_swap')));
    await tester.pumpAndSettle();
    expect(fake.previews, [(502, 777), (777, 502)]);
    expect(
      find.descendant(
        of: find.byKey(const ValueKey('merge_keep')),
        matching: find.text('บัญชี 777'),
      ),
      findsOneWidget,
    );
  });

  testWidgets('409 merge_conflict at merge time is explained', (tester) async {
    final fake = FakeSchoolClassrooms()
      ..mergeError = dioError(409, {
        'message': 'รวมบัญชีไม่ได้ เพราะข้อมูลของสองบัญชีชนกัน',
        'code': 'merge_conflict',
        'errors': {
          'gradebook_entries': ['คะแนนของ ทดสอบ 1 ไม่เท่ากัน'],
        },
      });
    await _pumpPreview(tester, fake);
    await tester.tap(find.byKey(const ValueKey('merge_submit')));
    await tester.pumpAndSettle();
    await tester.tap(_dialogButton('รวมบัญชี'));
    await tester.pumpAndSettle();

    expect(fake.merges, [(502, 777)]);
    expect(find.byKey(const ValueKey('merge_error')), findsOneWidget);
    expect(
      find.text('รวมบัญชีไม่ได้ เพราะข้อมูลของสองบัญชีชนกัน'),
      findsOneWidget,
    );
    expect(find.text('• คะแนนของ ทดสอบ 1 ไม่เท่ากัน'), findsOneWidget);
    expect(fake.previews, hasLength(2), reason: 'the preview is reloaded');
    expect(find.text('เทียบบัญชีก่อนรวม'), findsOneWidget, reason: 'stays');
  });

  testWidgets('a preview the server refuses shows why', (tester) async {
    final fake = FakeSchoolClassrooms()
      ..preview = (_, _) => throw dioError(403, {
        'message': 'รวมได้เฉพาะครูประจำชั้นของทั้งสองบัญชี',
        'code': 'not_homeroom_teacher',
        'errors': <String, Object?>{},
      });
    await _pumpPreview(tester, fake);
    expect(find.text('รวมได้เฉพาะครูประจำชั้นของทั้งสองบัญชี'), findsOneWidget);
    expect(find.byKey(const ValueKey('merge_submit')), findsNothing);
  });
}
