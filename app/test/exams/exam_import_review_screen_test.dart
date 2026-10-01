import 'package:eduvision/features/exams/exam_import_models.dart';
import 'package:eduvision/features/exams/exam_import_repository.dart';
import 'package:eduvision/features/exams/exam_import_review_screen.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_providers.dart';
import 'package:eduvision/platform/document_page_renderer.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'exam_fakes.dart';
import 'exam_import_fakes.dart';
import 'exam_test_helpers.dart';

/// "ตรวจข้อที่อ่านจากไฟล์" (DESIGN §22.4): the read's summary, rendering
/// the pages its figures wait for, the drafts with their figures, and
/// approving one question or the selected ones.
void main() {
  ExamImportResult cachedResult() => ExamImportResult.fromJson({
    'cached': true,
    'extraction': {'id': 70, 'status': 'done', 'guidance': 'ข้ามตอนที่ 3'},
    'applied': {
      'sections': 1,
      'questions': 2,
      'skipped': [
        {'number': 7, 'reason_th': 'ข้อเขียนตอบ ฝนไม่ได้'},
      ],
    },
    'figures_pending': [],
  });

  Future<(FakeExamsRepository, FakeExamImportRepository, FakePageRenderer)>
  pump(
    WidgetTester tester, {
    ExamImportResult? result,
    Map<String, dynamic>? detail,
    FakeExamsRepository? examsRepo,
    FakePageRenderer? renderer,
    FakeExamImportRepository? imports,
    Map<int, PickedDocument> localFiles = const {},
  }) async {
    final exams =
        examsRepo ?? FakeExamsRepository(detail: detail ?? importedExamJson());
    final imp = imports ?? FakeExamImportRepository(exams: exams);
    final r = renderer ?? FakePageRenderer();
    await pumpExamScreen(
      tester,
      ExamImportReviewScreen(
        examId: 40,
        result: result,
        localFiles: localFiles,
      ),
      repo: exams,
      overrides: [
        examImportRepositoryProvider.overrideWithValue(imp),
        documentPageRendererProvider.overrideWithValue(r),
        examReadPollIntervalProvider.overrideWithValue(
          const Duration(milliseconds: 10),
        ),
        examCropPollIntervalProvider.overrideWithValue(
          const Duration(milliseconds: 10),
        ),
      ],
    );
    return (exams, imp, r);
  }

  testWidgets('a cached read shows what it made and renders waiting pages', (
    tester,
  ) async {
    final exams = FakeExamsRepository(detail: importedExamJson());
    final imports = FakeExamImportRepository(exams: exams)
      ..onUpload = () {
        exams.detailJson = importedExamJson(
          pending: false,
          pages: [
            for (final (id, no) in [(801, 1), (802, 2)])
              {
                'id': id,
                'source_document_id': 501,
                'page_no': no,
                'width_px': 1000,
                'height_px': 1400,
                'available': true,
              },
          ],
        );
      };
    final (_, imp, renderer) = await pump(
      tester,
      result: cachedResult(),
      examsRepo: exams,
      imports: imports,
      localFiles: const {
        501: PickedDocument(name: 'midterm.pdf', path: '/picked/midterm.pdf'),
      },
    );
    await tester.pumpAndSettle();

    expect(
      find.text('อ่านได้ 1 ตอน 2 ข้อ (เคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย)'),
      findsOneWidget,
    );
    expect(find.text('คำแนะนำที่ใช้: ข้ามตอนที่ 3'), findsOneWidget);
    expect(find.textContaining('ไม่ได้สร้าง 1 ข้อ'), findsOneWidget);
    expect(find.text('• ข้อ 7: ข้อเขียนตอบ ฝนไม่ได้'), findsOneWidget);

    // Page 2 of the picked PDF was rendered on the phone and uploaded.
    expect(renderer.renders, [('/picked/midterm.pdf', 'application/pdf', 2)]);
    expect(imp.args('uploadPageImage'), [(501, 2, '/cache/page-2.jpg')]);
    expect(imp.args('documentFile'), isEmpty);
    expect(
      find.textContaining('สร้างภาพหน้าเอกสารแล้ว 1 หน้า'),
      findsOneWidget,
    );

    // Only the drafts read from the file are listed.
    expect(find.byKey(const ValueKey('read_review_q_41')), findsOneWidget);
    expect(find.byKey(const ValueKey('read_review_q_42')), findsOneWidget);
    expect(find.byKey(const ValueKey('read_review_q_11')), findsNothing);
    expect(find.textContaining('รูปใดเป็นสามเหลี่ยม'), findsOneWidget);
    expect(find.text('เฉลย ก'), findsOneWidget);
    expect(
      find.text('ยังไม่มีเฉลย (ไฟล์ไม่มีเฉลย หรืออ่านไม่ได้)'),
      findsOneWidget,
    );
    // The cropped prompt figure loads through the API.
    expect(exams.args('image'), contains((option: false, id: 41)));
    // Page 2 now has an image: the option figure is being cropped.
    expect(find.text('ภาพตัวเลือก ข: กำลังตัดภาพ'), findsOneWidget);
    expect(find.byKey(const ValueKey('read_review_crop_41')), findsOneWidget);
  });

  testWidgets('reloads the exam while the server crops a figure', (
    tester,
  ) async {
    final bothPages = [
      for (final (id, no) in [(801, 1), (802, 2)])
        {
          'id': id,
          'source_document_id': 501,
          'page_no': no,
          'width_px': 1000,
          'height_px': 1400,
          'available': true,
        },
    ];
    final exams = FakeExamsRepository(
      detail: importedExamJson(pending: false, pages: bothPages),
    );
    await pump(tester, examsRepo: exams);
    await tester.pumpAndSettle();
    expect(find.text('ภาพตัวเลือก ข: กำลังตัดภาพ'), findsOneWidget);

    // The worker cropped it: the next reload shows the figure.
    final cropped = importedExamJson(pending: false, pages: bothPages);
    final q41 = ((cropped['sections'] as List).last['questions'] as List).first;
    final option = (q41['options'] as List)[1] as Map<String, dynamic>;
    option['figure_pending'] = false;
    option['has_image'] = true;
    exams.detailJson = cropped;
    final gets = exams.args('get').length;
    await tester.pump(const Duration(milliseconds: 20));
    await tester.pumpAndSettle();

    expect(exams.args('get').length, greaterThan(gets));
    expect(find.text('ภาพตัวเลือก ข: กำลังตัดภาพ'), findsNothing);
    // Nothing is cropping any more, so no further reloads are scheduled.
    final settled = exams.args('get').length;
    await tester.pump(const Duration(milliseconds: 50));
    expect(exams.args('get').length, settled);
  });

  testWidgets('approves one question, then the selected ones', (tester) async {
    final (exams, _, _) = await pump(tester);

    await tapVisible(
      tester,
      find.byKey(const ValueKey('read_review_approve_41')),
    );
    expect(exams.args('approveQuestions'), [
      [41],
    ]);
    expect(find.text('อนุมัติข้อนี้แล้ว'), findsOneWidget);

    await tapVisible(
      tester,
      find.byKey(const ValueKey('read_review_select_all')),
    );
    await tapVisible(
      tester,
      find.byKey(const ValueKey('read_review_select_41')),
    );
    expect(find.text('อนุมัติที่เลือก (1)'), findsOneWidget);
    exams.detailJson = importedExamJson(q41Approved: true);
    await tapVisible(
      tester,
      find.byKey(const ValueKey('read_review_approve_selected')),
    );
    expect(exams.args('approveQuestions').last, [42]);
  });

  testWidgets('with every draft approved there is nothing to review', (
    tester,
  ) async {
    await pump(tester, detail: examJson());
    expect(find.text('ไม่มีข้อที่รออนุมัติ'), findsOneWidget);
    expect(find.text('ยังไม่มีข้อที่อ่านจากไฟล์ในข้อสอบนี้'), findsOneWidget);
    expect(
      find.byKey(const ValueKey('read_review_approve_selected')),
      findsNothing,
    );
  });

  testWidgets('a queued read is polled until done, then the exam reloads', (
    tester,
  ) async {
    final exams = FakeExamsRepository(detail: importedExamJson());
    final imports = FakeExamImportRepository(exams: exams)
      ..reads.addAll([
        const ExamRead(id: 71, status: 'queued'),
        const ExamRead(
          id: 71,
          status: 'done',
          notesTh: 'ข้อ 3 ภาพไม่ชัด',
          skipped: [ExamReadSkipped(number: 9, reason: 'ข้อเขียนตอบ')],
        ),
      ]);
    final renderer = FakePageRenderer();
    await pump(
      tester,
      result: const ExamImportResult(
        cached: false,
        read: ExamRead(id: 71, status: 'queued'),
      ),
      examsRepo: exams,
      imports: imports,
      renderer: renderer,
    );
    await tester.pumpAndSettle();

    expect(imports.args('read'), [71, 71]);
    expect(find.text('AI กำลังอ่านไฟล์ข้อสอบ...'), findsNothing);
    expect(find.text('อ่านไฟล์เสร็จแล้ว'), findsOneWidget);
    expect(find.text('หมายเหตุจาก AI: ข้อ 3 ภาพไม่ชัด'), findsOneWidget);
    expect(find.text('• ข้อ 9: ข้อเขียนตอบ'), findsOneWidget);
    expect(find.byKey(const ValueKey('read_review_q_41')), findsOneWidget);
    // Nothing picked in this session: the file is downloaded to render.
    expect(imports.args('documentFile'), [501]);
    expect(renderer.renders.single.$3, 2);
  });

  testWidgets('a failed read says so', (tester) async {
    final exams = FakeExamsRepository(detail: examJson());
    final imports = FakeExamImportRepository(exams: exams)
      ..reads.add(
        const ExamRead(id: 71, status: 'failed', error: 'ไฟล์อ่านไม่ออก'),
      );
    await pump(
      tester,
      result: const ExamImportResult(
        cached: false,
        read: ExamRead(id: 71, status: 'queued'),
      ),
      examsRepo: exams,
      imports: imports,
    );
    await tester.pump(const Duration(milliseconds: 20));
    await tester.pumpAndSettle();
    expect(find.text('อ่านไฟล์ไม่สำเร็จ'), findsOneWidget);
    expect(find.text('ไฟล์อ่านไม่ออก'), findsOneWidget);
  });

  testWidgets('pages fail to render: the reason and a retry', (tester) async {
    final renderer = FakePageRenderer()
      ..failPages[2] = const PageRenderException('document_unreadable');
    final exams = FakeExamsRepository(detail: importedExamJson());
    final imports = FakeExamImportRepository(exams: exams);
    await pump(
      tester,
      result: cachedResult(),
      renderer: renderer,
      examsRepo: exams,
      imports: imports,
    );
    await tester.pumpAndSettle();
    expect(
      find.text(
        'midterm.pdf หน้า 2: เปิดไฟล์นี้ไม่ได้ (ไฟล์เสียหรือมีรหัสผ่าน)',
      ),
      findsOneWidget,
    );
    expect(find.text('ยังไม่มีภาพประกอบ 1 ภาพ จาก 1 หน้า'), findsOneWidget);

    renderer.failPages.clear();
    await tapVisible(tester, find.byKey(const ValueKey('read_review_render')));
    expect(renderer.renders, hasLength(2));
    expect(imports.args('uploadPageImage'), hasLength(1));
  });

  testWidgets('without a page renderer the teacher attaches pictures', (
    tester,
  ) async {
    final (_, imp, _) = await pump(
      tester,
      renderer: FakePageRenderer(isSupported: false),
      result: cachedResult(),
    );
    await tester.pumpAndSettle();
    expect(
      find.byKey(const ValueKey('read_review_render_unsupported')),
      findsOneWidget,
    );
    expect(find.byKey(const ValueKey('read_review_render')), findsNothing);
    expect(imp.args('uploadPageImage'), isEmpty);
    expect(find.text('ภาพตัวเลือก ข: ยังไม่มีภาพประกอบ'), findsOneWidget);
  });

  testWidgets('a deleted file cannot be rendered', (tester) async {
    final json = importedExamJson();
    json['figures_pending'] = [
      {
        'source_document_id': 501,
        'page_no': 2,
        'original_name': 'midterm.pdf',
        'mime_type': 'application/pdf',
        'figures': 1,
        'reason': 'document_missing',
      },
    ];
    await pump(tester, detail: json);
    expect(find.textContaining('ไฟล์ midterm.pdf ถูกลบแล้ว'), findsOneWidget);
    expect(find.byKey(const ValueKey('read_review_render')), findsNothing);
  });

  testWidgets('opens the question and the box on the page image', (
    tester,
  ) async {
    await pump(tester, detail: importedExamJson(pending: false));
    await tapVisible(tester, find.byKey(const ValueKey('read_review_edit_42')));
    expect(find.text('question /exams/40/questions/42'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    await tapVisible(tester, find.byKey(const ValueKey('read_review_crop_41')));
    expect(find.text('ภาพโจทย์ข้อ 5'), findsOneWidget);
    expect(find.byKey(const ValueKey('figure_crop_canvas')), findsOneWidget);
  });

  test('ExamDetail drafts ignore approved and typed questions', () {
    final d = ExamDetail.fromJson(importedExamJson(q41Approved: true));
    expect(d.unapprovedDrafts.map((q) => q.id), [42]);
  });
}
