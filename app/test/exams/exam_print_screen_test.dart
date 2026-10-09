import 'dart:async';

import 'package:dio/dio.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/exams/exam_print.dart';
import 'package:eduvision/features/exams/exam_print_screen.dart';
import 'package:eduvision/features/worksheets/pdf_actions.dart';
import 'package:eduvision/features/worksheets/pdf_files.dart';
import 'package:eduvision/features/worksheets/print_job.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../review/review_fixtures.dart';
import 'exam_fakes.dart';
import 'exam_test_helpers.dart';

class _Classrooms extends Fake implements ClassroomsRepository {
  _Classrooms(this.students);

  final List<RosterStudent> students;

  @override
  Future<List<RosterStudent>> roster(int id) async => students;
}

class _PdfFiles extends PdfFiles {
  _PdfFiles({this.web = false}) : super(Dio(), PdfDownloader(Dio()));

  final bool web;
  final fetched = <(String url, String name)>[];
  final saved = <String>[];
  final shared = <(String name, String subject)>[];
  final opened = <String>[];

  @override
  bool get canOpen => !web;

  @override
  String get saveLabel => web ? 'ดาวน์โหลด' : 'บันทึกลงเครื่อง';

  @override
  Future<PdfFile> fetch(String url, {required String fileName}) async {
    fetched.add((url, fileName));
    return PdfFile(name: fileName, path: '/cache/pdf/$fileName');
  }

  @override
  Future<void> open(BuildContext context, PdfFile file) async {
    opened.add(file.name);
  }

  @override
  Future<bool> save(PdfFile file) async {
    saved.add(file.name);
    return true;
  }

  @override
  Future<void> share(PdfFile file, {required String subject}) async {
    shared.add((file.name, subject));
  }
}

List<RosterStudent> _roster(int n) => [
  for (var i = 1; i <= n; i++)
    RosterStudent(studentId: 100 + i, studentNumber: i, name: 'นักเรียน $i'),
];

Map<String, dynamic> _approved({
  String method = 'app',
  String? lockedAt,
  int versionCount = 2,
}) => examJson(
  method: method,
  keyApprovedAt: method == 'app' ? '2026-10-01T01:00:00+00:00' : null,
  keyComplete: true,
  lockedAt: lockedAt,
  versionCount: versionCount,
);

Future<(FakeExamsRepository, _PdfFiles)> _pump(
  WidgetTester tester, {
  Map<String, dynamic>? detail,
  int students = 5,
  bool web = false,
}) async {
  final files = _PdfFiles(web: web);
  final repo = await pumpExamScreen(
    tester,
    const ExamPrintScreen(examId: 40),
    repo: FakeExamsRepository(detail: detail ?? _approved()),
    overrides: [
      classroomsRepositoryProvider.overrideWithValue(
        _Classrooms(_roster(students)),
      ),
      pdfFilesProvider.overrideWithValue(files),
      examPrintDelayProvider.overrideWithValue((_) async {}),
    ],
  );
  return (repo, files);
}

Finder _key(String k) => find.byKey(ValueKey(k));

String _text(WidgetTester tester, String key) =>
    tester.widget<Text>(_key(key)).data!;

bool _enabled(WidgetTester tester, String key) =>
    tester.widget<ButtonStyleButton>(_key(key)).onPressed != null;

/// The print screen of an exam (DESIGN §22.6).
void main() {
  testWidgets('booklets per version with copies from the roster', (
    tester,
  ) async {
    await _pump(tester, students: 5);

    expect(find.text('พิมพ์ข้อสอบ'), findsOneWidget);
    expect(find.textContaining('นักเรียน 5 คน'), findsWidgets);
    expect(find.text('ชุด ก'), findsOneWidget);
    expect(find.text('ชุด ข'), findsOneWidget);
    expect(_text(tester, 'exam_copies_1'), '3');
    expect(_text(tester, 'exam_copies_2'), '2');
    expect(_text(tester, 'exam_copies_total'), 'รวม 5 เล่ม · นักเรียน 5 คน');

    await tester.tap(_key('exam_copies_minus_2'));
    await tester.pump();
    expect(_text(tester, 'exam_copies_2'), '1');
    expect(
      _text(tester, 'exam_copies_total'),
      'รวม 4 เล่ม · นักเรียน 5 คน · ไม่พอทุกคน',
    );
    await tester.tap(_key('exam_copies_plus_1'));
    await tester.pump();
    await tester.tap(_key('exam_copies_plus_1'));
    await tester.pump();
    expect(_text(tester, 'exam_copies_1'), '5');
    expect(_text(tester, 'exam_copies_total'), 'รวม 6 เล่ม · นักเรียน 5 คน');

    expect(_enabled(tester, 'exam_print_booklet_1'), isTrue);
    expect(_enabled(tester, 'exam_print_answer_sheet'), isTrue);
    expect(_enabled(tester, 'exam_print_key_sheet'), isTrue);
    expect(find.text('ทั้งห้อง (5 คน)'), findsOneWidget);
    expect(find.textContaining('คนละ 1 หน้า'), findsOneWidget);
  });

  testWidgets('first print asks about the lock, then renders, downloads '
      'and offers open, save and share', (tester) async {
    final (repo, files) = await _pump(tester, students: 5);
    final status = Completer<PrintJob>();
    repo.statusAnswer = (job) => status.future;

    expect(find.textContaining('ล็อกโครงสร้างข้อสอบ'), findsOneWidget);
    await tester.tap(_key('exam_print_booklet_2'));
    await tester.pumpAndSettle();
    expect(find.text('พิมพ์ครั้งแรกจะล็อกโครงสร้าง'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'พิมพ์'));
    await tester.pump();
    await tester.pump();

    expect(repo.args('requestPrint'), [
      {'kind': 'exam_booklet', 'version_no': 2},
    ]);
    expect(_key('exam_print_progress_booklet_2'), findsOneWidget);
    expect(find.text('อยู่ในคิว'), findsOneWidget);

    status.complete(
      const PrintJob(
        id: 900,
        status: 'ready',
        kind: 'exam_booklet',
        versionNo: 2,
        downloadUrl: '/api/v1/worksheet-prints/900/file',
      ),
    );
    await tester.pumpAndSettle();

    expect(files.fetched.single, (
      '/api/v1/worksheet-prints/900/file',
      'exam-40-booklet-2.pdf',
    ));
    expect(find.text('exam-40-booklet-2.pdf'), findsOneWidget);
    // The exam reloaded with its structure locked.
    expect(find.textContaining('โครงสร้างถูกล็อกแล้ว'), findsOneWidget);

    await tester.tap(_key('exam_pdf_open_booklet_2'));
    await tester.tap(_key('exam_pdf_save_booklet_2'));
    await tester.pumpAndSettle();
    await tester.tap(_key('exam_pdf_share_booklet_2'));
    await tester.pumpAndSettle();
    expect(files.opened, ['exam-40-booklet-2.pdf']);
    expect(files.saved, ['exam-40-booklet-2.pdf']);
    expect(find.text('บันทึก exam-40-booklet-2.pdf แล้ว'), findsOneWidget);
    expect(files.shared.single, (
      'exam-40-booklet-2.pdf',
      'สอบกลางภาค · ชุด ข · พิมพ์ 2 สำเนา',
    ));

    // "สร้างใหม่" on a locked exam goes straight to the server.
    await tester.tap(_key('exam_print_again_booklet_2'));
    await tester.pumpAndSettle();
    expect(find.text('พิมพ์ครั้งแรกจะล็อกโครงสร้าง'), findsNothing);
    expect(repo.args('requestPrint'), hasLength(2));
  });

  testWidgets('declining the lock prints nothing', (tester) async {
    final (repo, _) = await _pump(tester);
    await tapVisible(tester, _key('exam_print_key_sheet'));
    await tester.tap(find.text('ยกเลิก'));
    await tester.pumpAndSettle();
    expect(repo.args('requestPrint'), isEmpty);
    expect(_key('exam_print_key_sheet'), findsOneWidget);
  });

  testWidgets('all booklets at once on a locked exam', (tester) async {
    final (repo, files) = await _pump(
      tester,
      detail: _approved(lockedAt: '2026-10-01T02:00:00+00:00'),
    );
    await tester.tap(_key('exam_print_all_booklets'));
    await tester.pumpAndSettle();
    expect(repo.args('requestPrint'), [
      {'kind': 'exam_booklet', 'version_no': 1},
      {'kind': 'exam_booklet', 'version_no': 2},
    ]);
    expect(files.fetched.map((f) => f.$2), [
      'exam-40-booklet-1.pdf',
      'exam-40-booklet-2.pdf',
    ]);
    expect(_key('exam_pdf_share_booklet_1'), findsOneWidget);
    expect(_key('exam_pdf_share_booklet_2'), findsOneWidget);
  });

  testWidgets('answer sheets for chosen students send their ids', (
    tester,
  ) async {
    final (repo, files) = await _pump(
      tester,
      detail: _approved(lockedAt: '2026-10-01T02:00:00+00:00'),
      students: 4,
    );
    await tapVisible(tester, _key('exam_pick_students'));
    expect(find.text('เลือกนักเรียน'), findsOneWidget);
    await tester.tap(_key('exam_pick_all'));
    await tester.pump();
    expect(
      tester.widget<ButtonStyleButton>(_key('exam_pick_done')).onPressed,
      isNull,
    );
    await tester.tap(_key('exam_pick_student_103'));
    await tester.tap(_key('exam_pick_student_101'));
    await tester.pump();
    await tester.tap(_key('exam_pick_done'));
    await tester.pumpAndSettle();
    expect(_text(tester, 'exam_sheet_students'), 'เลือก 2 คน (เลขที่ 1, 3)');

    await tapVisible(tester, _key('exam_print_answer_sheet'));
    expect(repo.args('requestPrint'), [
      {
        'kind': 'answer_sheet',
        'student_ids': [101, 103],
      },
    ]);
    expect(files.fetched.single.$2, 'exam-40-answer-sheets-v3.pdf');
    await tapVisible(tester, _key('exam_pdf_share_answer_sheet'));
    expect(files.shared.single.$2, 'สอบกลางภาค · กระดาษคำตอบ · 2 คน');

    await tapVisible(tester, _key('exam_whole_class'));
    expect(_text(tester, 'exam_sheet_students'), 'ทั้งห้อง (4 คน)');
    await tapVisible(tester, _key('exam_print_again_answer_sheet'));
    expect(repo.args('requestPrint').last, {'kind': 'answer_sheet'});
  });

  testWidgets('a failed render shows the reason and retries', (tester) async {
    final (repo, _) = await _pump(
      tester,
      detail: _approved(lockedAt: '2026-10-01T02:00:00+00:00'),
    );
    repo.statusAnswer = (job) async => PrintJob(
      id: job.id,
      status: 'failed',
      error: 'ข้อ 3 สูงเกินหนึ่งหน้าแม้ย่อภาพแล้ว',
    );
    await tapVisible(tester, _key('exam_print_key_sheet'));
    expect(find.text('ข้อ 3 สูงเกินหนึ่งหน้าแม้ย่อภาพแล้ว'), findsOneWidget);

    repo.statusAnswer = null;
    await tapVisible(tester, _key('exam_print_retry_key_sheet'));
    expect(repo.args('requestPrint'), [
      {'kind': 'key_sheet'},
      {'kind': 'key_sheet'},
    ]);
    expect(find.text('exam-40-key-sheet-v3.pdf'), findsOneWidget);
  });

  testWidgets('a server refusal shows its message', (tester) async {
    final (repo, _) = await _pump(
      tester,
      detail: _approved(lockedAt: '2026-10-01T02:00:00+00:00'),
    );
    repo.failNext = apiError(503, {
      'message': 'ยังไม่ได้ตั้งค่า QR_SIGNING_KEY',
      'code': 'qr_key_missing',
    });
    await tapVisible(tester, _key('exam_print_answer_sheet'));
    expect(find.text('ยังไม่ได้ตั้งค่า QR_SIGNING_KEY'), findsOneWidget);
    expect(_key('exam_print_retry_answer_sheet'), findsOneWidget);
  });

  testWidgets('an unapproved key blocks the booklets and the sheets but '
      'not the key sheet', (tester) async {
    await _pump(tester, detail: examJson());
    expect(find.text('อนุมัติเฉลยก่อนพิมพ์เล่มให้นักเรียน'), findsOneWidget);
    expect(find.text('อนุมัติเฉลยก่อนพิมพ์กระดาษคำตอบ'), findsOneWidget);
    expect(_enabled(tester, 'exam_print_booklet_1'), isFalse);
    expect(_enabled(tester, 'exam_print_all_booklets'), isFalse);
    expect(_enabled(tester, 'exam_print_answer_sheet'), isFalse);
    expect(_enabled(tester, 'exam_print_key_sheet'), isTrue);
  });

  testWidgets('a manual exam prints only its booklet', (tester) async {
    await _pump(tester, detail: _approved(method: 'manual', versionCount: 1));
    expect(find.textContaining('พิมพ์ได้เฉพาะเล่มข้อสอบ'), findsOneWidget);
    expect(find.text('เล่มข้อสอบ'), findsWidgets);
    expect(_key('exam_print_booklet_1'), findsOneWidget);
    expect(_key('exam_print_all_booklets'), findsNothing);
    expect(_key('exam_print_answer_sheet'), findsNothing);
    expect(_key('exam_print_key_sheet'), findsNothing);
    expect(_text(tester, 'exam_copies_1'), '5');
    expect(_enabled(tester, 'exam_print_booklet_1'), isTrue);
  });

  testWidgets('an empty class cannot print answer sheets', (tester) async {
    await _pump(tester, students: 0);
    expect(find.text('ห้องนี้ยังไม่มีนักเรียน'), findsOneWidget);
    expect(_enabled(tester, 'exam_print_answer_sheet'), isFalse);
    expect(_key('exam_pick_students'), findsNothing);
    expect(_text(tester, 'exam_copies_1'), '0');
  });

  testWidgets('on the web there is no open button and save downloads', (
    tester,
  ) async {
    final (_, files) = await _pump(
      tester,
      detail: _approved(lockedAt: '2026-10-01T02:00:00+00:00'),
      web: true,
    );
    await tapVisible(tester, _key('exam_print_key_sheet'));
    expect(_key('exam_pdf_open_key_sheet'), findsNothing);
    expect(find.text('ดาวน์โหลด'), findsOneWidget);
    await tapVisible(tester, _key('exam_pdf_save_key_sheet'));
    expect(files.saved, ['exam-40-key-sheet-v3.pdf']);
  });

  testWidgets('an exam with the student-ID grid prints one shared answer '
      'sheet, also for an empty class', (tester) async {
    final detail = _approved(lockedAt: '2026-10-01T02:00:00+00:00');
    (detail['exam'] as Map)
      ..['sheet_identity'] = 'code'
      ..['student_code_digits'] = 8;
    final (repo, files) = await _pump(tester, detail: detail, students: 0);

    expect(find.text('กระดาษคำตอบ (ฝนเลขประจำตัว)'), findsOneWidget);
    expect(find.textContaining('ฝนเลขประจำตัว 8 หลัก'), findsOneWidget);
    expect(_key('exam_pick_students'), findsNothing);
    expect(_key('exam_sheet_students'), findsNothing);
    expect(_enabled(tester, 'exam_print_answer_sheet'), isTrue);

    await tapVisible(tester, _key('exam_print_answer_sheet'));
    expect(repo.args('requestPrint'), [
      {'kind': 'answer_sheet'},
    ]);
    expect(files.fetched.single.$2, 'exam-40-answer-sheets-v3.pdf');
  });
}
