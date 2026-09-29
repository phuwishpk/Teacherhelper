import 'dart:async';
import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/hand_in/hand_in_models.dart';
import 'package:eduvision/features/hand_in/hand_in_repository.dart';
import 'package:eduvision/features/hand_in/student_assignments_page.dart';
import 'package:eduvision/features/hand_in/student_hand_in_screen.dart';
import 'package:eduvision/features/scan/scan_camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../assignments/answer_key_fixtures.dart';
import '../helpers/pump_screen.dart';
import 'hand_in_fakes.dart';

final _now = DateTime.utc(2026, 10, 2, 3);

StudentAssignment _assignment({
  int id = 7,
  DateTime? dueAt,
  bool canSubmit = true,
  String status = StudentAssignment.notSubmitted,
  int? submissionId,
  bool late = false,
}) => StudentAssignment(
  id: id,
  title: 'เศษส่วน ชุดที่ $id',
  classroomName: 'ป.5/2',
  subjectName: 'คณิตศาสตร์',
  dueAt: dueAt,
  canSubmit: canSubmit,
  status: status,
  submissionId: submissionId,
  submittedAt: status == StudentAssignment.notSubmitted
      ? null
      : DateTime.utc(2026, 10, 1),
  late: late,
);

PickedDocument _file(String name) =>
    PickedDocument(name: name, bytes: Uint8List(4));

class _Camera implements ScanCamera {
  int shots = 0;

  @override
  Future<void> initialize() async {}

  @override
  double get previewAspectRatio => 3 / 4;

  @override
  Widget buildPreview() => const ColoredBox(color: Colors.grey);

  @override
  Future<String> takePicture() async => '/cache/page${++shots}.jpg';

  @override
  Future<void> setTorch(bool on) async {}

  @override
  Future<void> dispose() async {}
}

Future<void> _pumpHandIn(
  WidgetTester tester,
  FakeHandIn handIn,
  StudentAssignment assignment, {
  FakeDocumentPicker? picker,
}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    StudentHandInScreen(
      assignmentId: assignment.id,
      initial: assignment,
      now: () => _now,
    ),
    overrides: [
      handInRepositoryProvider.overrideWithValue(handIn),
      documentFilePickerProvider.overrideWithValue(
        picker ?? FakeDocumentPicker(),
      ),
      keyPhotoCameraFactoryProvider.overrideWithValue(_Camera.new),
    ],
  );
}

void main() {
  group('งานที่ต้องส่ง', () {
    testWidgets('lists the work with its hand-in labels and opens it', (
      tester,
    ) async {
      final handIn = FakeHandIn(
        assignments: [
          _assignment(id: 1, dueAt: DateTime.utc(2026, 10, 1)),
          _assignment(
            id: 2,
            dueAt: DateTime.utc(2026, 10, 1),
            canSubmit: false,
          ),
          _assignment(id: 3, status: StudentAssignment.submitted, late: true),
          _assignment(
            id: 4,
            status: StudentAssignment.published,
            submissionId: 70,
          ),
        ],
      );
      final opened = <String>[];
      await pumpScreen(
        tester,
        Scaffold(body: StudentAssignmentsPage(now: () => _now)),
        overrides: [handInRepositoryProvider.overrideWithValue(handIn)],
        extraRoutes: [
          GoRoute(
            path: '/student/assignments/:aid/hand-in',
            builder: (_, state) {
              opened.add(state.uri.path);
              return Text('hand-in ${state.pathParameters['aid']}');
            },
          ),
          GoRoute(
            path: '/student/results/:sid',
            builder: (_, state) =>
                Text('result ${state.pathParameters['sid']}'),
          ),
        ],
      );

      expect(find.text('เลยกำหนด ส่งได้แต่จะติดป้ายส่งช้า'), findsOneWidget);
      expect(find.text('ปิดรับแล้ว'), findsOneWidget);
      expect(find.text('ส่งช้า'), findsOneWidget);
      expect(find.textContaining('ส่งแล้ว'), findsOneWidget);
      expect(find.text('ประกาศผลแล้ว'), findsOneWidget);

      await tester.tap(find.text('เศษส่วน ชุดที่ 1'));
      await tester.pumpAndSettle();
      expect(find.text('hand-in 1'), findsOneWidget);

      Navigator.of(tester.element(find.text('hand-in 1'))).pop();
      await tester.pumpAndSettle();
      await tester.tap(find.text('เศษส่วน ชุดที่ 4'));
      await tester.pumpAndSettle();
      expect(find.text('result 70'), findsOneWidget);
    });

    testWidgets('says so when there is nothing to hand in', (tester) async {
      await pumpScreen(
        tester,
        const Scaffold(body: StudentAssignmentsPage()),
        overrides: [handInRepositoryProvider.overrideWithValue(FakeHandIn())],
      );
      expect(find.text('ยังไม่มีงานที่ต้องส่ง'), findsOneWidget);
    });
  });

  group('ส่งงาน', () {
    testWidgets('picks files, shows progress and the receipt', (tester) async {
      final handIn = FakeHandIn()..gate = Completer<void>();
      final picker = FakeDocumentPicker([_file('p1.jpg'), _file('p2.pdf')]);
      await _pumpHandIn(tester, handIn, _assignment(), picker: picker);

      final send = find.byKey(const ValueKey('hand_in_send'));
      expect(tester.widget<FilledButton>(send).onPressed, isNull);

      await tester.tap(find.byKey(const ValueKey('hand_in_pick')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกแล้ว 2/5 ไฟล์'), findsOneWidget);
      expect(find.textContaining('p2.pdf'), findsOneWidget);

      await tester.tap(send);
      await tester.pump();
      expect(find.text('กำลังอัปโหลด 50%'), findsOneWidget);

      handIn.gate!.complete();
      await tester.pumpAndSettle();
      expect(handIn.submitted.single.$1, 7);
      expect(handIn.submitted.single.$2.map((f) => f.name), [
        'p1.jpg',
        'p2.pdf',
      ]);
      expect(find.text('ส่งงานแล้ว'), findsOneWidget);
      expect(find.textContaining('2 หน้า'), findsOneWidget);

      await tester.tap(find.text('เสร็จ'));
      await tester.pumpAndSettle();
      expect(find.text('stub-home'), findsOneWidget);
    });

    testWidgets('shows the server refusal and keeps the files', (tester) async {
      final handIn = FakeHandIn()
        ..error = apiError(422, 'ส่งได้ไม่เกิน 5 หน้า', 'too_many_pages');
      final picker = FakeDocumentPicker([_file('long.pdf')]);
      await _pumpHandIn(tester, handIn, _assignment(), picker: picker);

      await tester.tap(find.byKey(const ValueKey('hand_in_pick')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('hand_in_send')));
      await tester.pumpAndSettle();

      expect(find.text('ส่งได้ไม่เกิน 5 หน้า'), findsOneWidget);
      expect(find.text('เลือกแล้ว 1/5 ไฟล์'), findsOneWidget);
    });

    testWidgets('takes photos with the camera and caps the files at 5', (
      tester,
    ) async {
      final picker = FakeDocumentPicker([
        for (var i = 1; i <= 4; i++) _file('f$i.jpg'),
      ]);
      await _pumpHandIn(tester, FakeHandIn(), _assignment(), picker: picker);

      await tester.tap(find.byKey(const ValueKey('hand_in_camera')));
      await tester.pumpAndSettle();
      expect(find.text('ถ่ายรูปงาน'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('key_photo_shutter')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('เสร็จ (1)'));
      await tester.pumpAndSettle();
      expect(find.textContaining('page-1.jpg'), findsOneWidget);

      // 1 photo + 4 picked = 5: the buttons turn off.
      await tester.tap(find.byKey(const ValueKey('hand_in_pick')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกแล้ว 5/5 ไฟล์'), findsOneWidget);
      final pick = find.byKey(const ValueKey('hand_in_pick'));
      expect(tester.widget<ButtonStyleButton>(pick).onPressed, isNull);

      await tester.tap(find.byTooltip('เอาไฟล์นี้ออก').first);
      await tester.pumpAndSettle();
      expect(find.text('เลือกแล้ว 4/5 ไฟล์'), findsOneWidget);
    });

    testWidgets('warns about late work and asks before handing in again', (
      tester,
    ) async {
      final handIn = FakeHandIn();
      final picker = FakeDocumentPicker([_file('p1.jpg')]);
      await _pumpHandIn(
        tester,
        handIn,
        _assignment(
          dueAt: DateTime.utc(2026, 10, 1),
          status: StudentAssignment.submitted,
        ),
        picker: picker,
      );

      expect(find.text('เลยกำหนดส่งแล้ว'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('hand_in_pick')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ส่งงานใหม่'));
      await tester.pumpAndSettle();
      expect(find.text('ส่งงานใหม่?'), findsOneWidget);
      await tester.tap(find.text('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(handIn.submitted, isEmpty);

      await tester.tap(find.text('ส่งงานใหม่'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ส่งใหม่'));
      await tester.pumpAndSettle();
      expect(handIn.submitted, hasLength(1));
    });

    testWidgets('a closed assignment has no send button', (tester) async {
      await _pumpHandIn(
        tester,
        FakeHandIn(),
        _assignment(dueAt: DateTime.utc(2026, 10, 1), canSubmit: false),
      );
      expect(find.text('ปิดรับงานแล้ว'), findsOneWidget);
      expect(find.byKey(const ValueKey('hand_in_send')), findsNothing);
    });

    testWidgets('opened by id finds the assignment in the list', (
      tester,
    ) async {
      final handIn = FakeHandIn(assignments: [_assignment(id: 9)]);
      await pumpScreen(
        tester,
        const StudentHandInScreen(assignmentId: 9),
        overrides: [handInRepositoryProvider.overrideWithValue(handIn)],
      );
      expect(find.text('เศษส่วน ชุดที่ 9'), findsOneWidget);
    });

    testWidgets('an unknown id says it is not found', (tester) async {
      await pumpScreen(
        tester,
        const StudentHandInScreen(assignmentId: 9),
        overrides: [handInRepositoryProvider.overrideWithValue(FakeHandIn())],
      );
      expect(find.text('ไม่พบการบ้านนี้'), findsOneWidget);
    });
  });
}
