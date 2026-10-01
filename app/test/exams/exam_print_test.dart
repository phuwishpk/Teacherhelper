import 'dart:async';

import 'package:dio/dio.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_print.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:eduvision/features/worksheets/pdf_actions.dart';
import 'package:eduvision/features/worksheets/pdf_files.dart';
import 'package:eduvision/features/worksheets/print_job.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';

class _PdfFiles extends PdfFiles {
  _PdfFiles() : super(Dio(), PdfDownloader(Dio()));

  @override
  Future<PdfFile> fetch(String url, {required String fileName}) async =>
      PdfFile(name: fileName, path: '/cache/$fileName');

  @override
  Future<void> open(BuildContext context, PdfFile file) async {}
}

/// The print request, file names, booklet copies and the readiness hints
/// of the print screen (DESIGN §22.6).
void main() {
  ExamDetail detail({
    String method = 'app',
    bool approved = true,
    List<Map<String, dynamic>>? sections,
    List<Map<String, dynamic>> bookletIncomplete = const [],
    bool overflow = false,
  }) {
    final json = examJson(
      method: method,
      keyApprovedAt: approved ? '2026-10-01T01:00:00+00:00' : null,
      keyComplete: approved,
      sections: sections,
    );
    json['booklet_incomplete_questions'] = bookletIncomplete;
    json['sheet'] = {'pages': overflow ? 3 : 1, 'overflow': overflow};
    return ExamDetail.fromJson(json);
  }

  test('requests carry only the fields of their kind', () {
    expect(const ExamPrintRequest.booklet(3).toJson(), {
      'kind': 'exam_booklet',
      'version_no': 3,
    });
    expect(const ExamPrintRequest.answerSheets().toJson(), {
      'kind': 'answer_sheet',
    });
    expect(const ExamPrintRequest.answerSheets(studentIds: [4]).toJson(), {
      'kind': 'answer_sheet',
      'student_ids': [4],
    });
    expect(const ExamPrintRequest.keySheet().toJson(), {'kind': 'key_sheet'});
    expect(const ExamPrintRequest.booklet(2).target, (
      kind: ExamPrintKind.booklet,
      versionNo: 2,
    ));
    expect(const ExamPrintRequest.keySheet().target, (
      kind: ExamPrintKind.keySheet,
      versionNo: null,
    ));
  });

  test('file names follow the server download names', () {
    const booklet = PrintJob(id: 1, status: 'ready', versionNo: 2);
    const sheets = PrintJob(id: 2, status: 'ready', layoutVersion: 5);
    expect(
      const ExamPrintRequest.booklet(2).fileName(40, booklet),
      'exam-40-booklet-2.pdf',
    );
    expect(
      const ExamPrintRequest.booklet(
        1,
      ).fileName(40, const PrintJob(id: 1, status: 'ready')),
      'exam-40-booklet-1.pdf',
    );
    expect(
      const ExamPrintRequest.answerSheets().fileName(40, sheets),
      'exam-40-answer-sheets-v5.pdf',
    );
    expect(
      const ExamPrintRequest.keySheet().fileName(40, sheets),
      'exam-40-key-sheet-v5.pdf',
    );
    expect(
      const ExamPrintRequest.keySheet().fileName(
        40,
        const PrintJob(id: 3, status: 'ready'),
      ),
      'exam-40-key-sheet.pdf',
    );
  });

  test('booklet copies split the class evenly, first versions first', () {
    expect(examBookletCopies(35, 2), [18, 17]);
    expect(examBookletCopies(10, 4), [3, 3, 2, 2]);
    expect(examBookletCopies(12, 1), [12]);
    expect(examBookletCopies(0, 3), [0, 0, 0]);
    expect(examBookletCopies(-1, 2), [0, 0]);
    expect(examBookletCopies(5, 0), isEmpty);
  });

  test('booklet readiness: questions, key approval, booklet problems', () {
    expect(ExamPrintReadiness.booklet(detail()), isNull);
    expect(
      ExamPrintReadiness.booklet(detail(sections: const [])),
      contains('ยังไม่มีข้อ'),
    );
    expect(
      ExamPrintReadiness.booklet(detail(approved: false)),
      'อนุมัติเฉลยก่อนพิมพ์เล่มให้นักเรียน',
    );
    // A manual exam has no key gate (§22.1).
    expect(
      ExamPrintReadiness.booklet(detail(method: 'manual', approved: false)),
      isNull,
    );
    expect(
      ExamPrintReadiness.booklet(
        detail(
          method: 'manual',
          approved: false,
          bookletIncomplete: [
            {
              'question_id': 12,
              'position': 2,
              'reasons': ['no_prompt'],
            },
            {
              'question_id': 31,
              'position': 4,
              'reasons': ['not_approved'],
            },
          ],
        ),
      ),
      'ข้อ 2, 4 ยังพิมพ์ในเล่มไม่ได้ (ต้องอนุมัติข้อและมีโจทย์)',
    );
  });

  test('answer-sheet and key-sheet readiness', () {
    expect(ExamPrintReadiness.answerSheets(detail(), students: 30), isNull);
    expect(ExamPrintReadiness.answerSheets(detail()), isNull);
    expect(
      ExamPrintReadiness.answerSheets(detail(), students: 0),
      'ห้องนี้ยังไม่มีนักเรียน',
    );
    expect(
      ExamPrintReadiness.answerSheets(detail(approved: false), students: 30),
      'อนุมัติเฉลยก่อนพิมพ์กระดาษคำตอบ',
    );
    expect(
      ExamPrintReadiness.answerSheets(detail(overflow: true)),
      contains('เกินกระดาษคำตอบ 2 หน้า'),
    );
    expect(
      ExamPrintReadiness.answerSheets(detail(method: 'manual')),
      contains('ไม่มีกระดาษคำตอบ'),
    );
    expect(
      ExamPrintReadiness.answerSheets(detail(sections: const [])),
      'ยังไม่มีข้อ',
    );

    // The key sheet needs no approval.
    expect(ExamPrintReadiness.keySheet(detail(approved: false)), isNull);
    expect(
      ExamPrintReadiness.keySheet(detail(sections: const [])),
      contains('ยังไม่มีข้อ'),
    );
    expect(
      ExamPrintReadiness.keySheet(detail(overflow: true)),
      contains('เกินกระดาษคำตอบ'),
    );
    expect(
      ExamPrintReadiness.keySheet(detail(method: 'manual')),
      contains('ไม่มีกระดาษเฉลย'),
    );
  });

  test('state labels follow the phase and the job status', () {
    const r = ExamPrintRequest.keySheet();
    expect(
      const ExamPrintState(phase: ExamPrintPhase.requesting, request: r).label,
      'กำลังส่งคำขอ…',
    );
    expect(
      const ExamPrintState(
        phase: ExamPrintPhase.rendering,
        request: r,
        job: PrintJob(id: 1, status: 'rendering'),
      ).label,
      'กำลังสร้าง PDF',
    );
    expect(
      const ExamPrintState(phase: ExamPrintPhase.rendering, request: r).label,
      'อยู่ในคิว',
    );
    expect(
      const ExamPrintState(phase: ExamPrintPhase.downloading, request: r).label,
      'กำลังดาวน์โหลด…',
    );
    const failed = ExamPrintState(phase: ExamPrintPhase.failed, request: r);
    expect(failed.label, 'สร้าง PDF ไม่สำเร็จ');
    expect(failed.busy, isFalse);
    expect(failed.copyWith(error: 'x').label, 'x');
    expect(
      const ExamPrintState(phase: ExamPrintPhase.ready, request: r).busy,
      isFalse,
    );
  });

  test('a running print survives leaving the screen and a second run of '
      'the same target is ignored', () async {
    final repo = FakeExamsRepository(
      detail: examJson(lockedAt: '2026-10-01T02:00:00+00:00'),
    );
    final status = Completer<PrintJob>();
    repo.statusAnswer = (_) => status.future;
    final container = ProviderContainer(
      overrides: [
        examsRepositoryProvider.overrideWithValue(repo),
        pdfFilesProvider.overrideWithValue(_PdfFiles()),
        examPrintDelayProvider.overrideWithValue((_) async {}),
      ],
    );
    addTearDown(container.dispose);

    final sub = container.listen(examPrintsProvider(40), (_, _) {});
    final notifier = container.read(examPrintsProvider(40).notifier);
    final run = notifier.run(const ExamPrintRequest.keySheet());
    await Future<void>.delayed(Duration.zero);
    await notifier.run(const ExamPrintRequest.keySheet());
    expect(repo.args('requestPrint'), hasLength(1));

    sub.close(); // the teacher leaves the print screen
    await Future<void>.delayed(Duration.zero);
    status.complete(
      const PrintJob(
        id: 900,
        status: 'ready',
        kind: 'key_sheet',
        layoutVersion: 2,
        downloadUrl: '/api/v1/worksheet-prints/900/file',
      ),
    );
    await run;

    final state = container.read(examPrintsProvider(40));
    final key = state[(kind: ExamPrintKind.keySheet, versionNo: null)]!;
    expect(key.phase, ExamPrintPhase.ready);
    expect(key.file?.name, 'exam-40-key-sheet-v2.pdf');
  });
}
