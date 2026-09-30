import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_providers.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/answer_key_screen.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/document_read_screen.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/scan/scan_camera.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'answer_key_fixtures.dart';

class _Assignments extends Fake implements AssignmentsRepository {
  @override
  Future<Assignment> get(int id) async => Assignment(
    id: id,
    classroomId: 7,
    subjectId: 1,
    title: 'เรียงความ',
    mode: AssignmentMode.freeform,
  );
}

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 7,
      name: 'ป.5/2',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
    ),
  ];
}

class _Camera implements ScanCamera {
  _Camera({this.initError});

  final ScanCameraException? initError;
  int shots = 0;

  @override
  Future<void> initialize() async {
    if (initError case final e?) throw e;
  }

  @override
  double get previewAspectRatio => 3 / 4;

  @override
  Widget buildPreview() => const ColoredBox(color: Colors.grey);

  @override
  Future<String> takePicture() async => '/cache/shot${++shots}.jpg';

  @override
  Future<void> setTorch(bool on) async {}

  @override
  Future<void> dispose() async {}
}

const _pdf = SourceDocument(
  id: 31,
  originalName: 'เฉลย.pdf',
  mimeType: 'application/pdf',
  pageCount: 3,
  sizeBytes: 300 * 1024,
);

const _book = SourceDocument(
  id: 32,
  originalName: 'หนังสือ.pdf',
  mimeType: 'application/pdf',
  pageCount: 42,
  sizeBytes: 3 * 1024 * 1024,
  needsPageRange: true,
);

final _filled = [
  questionJson(1, 'mcq', answerKey: {'correct': 'C'}),
  questionJson(
    2,
    'short',
    answerKey: {
      'accepted': ['42', 'สี่สิบสอง'],
    },
  ),
  questionJson(
    3,
    'open',
    modelAnswer: 'ใบไม้มีคลอโรฟิลล์',
    rubric: 'draft',
    complete: false,
  ),
];

Future<void> _pumpKeyScreen(
  WidgetTester tester,
  FakeAnswerKeys keys, {
  FakeDocumentPicker? picker,
  ScanCamera? camera,
  List<GoRoute> extraRoutes = const [],
}) async {
  tester.view.physicalSize = const Size(1080, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  await pumpScreen(
    tester,
    const AnswerKeyScreen(assignmentId: 12),
    overrides: [
      answerKeyRepositoryProvider.overrideWithValue(keys),
      assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
      classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
      documentFilePickerProvider.overrideWithValue(
        picker ?? FakeDocumentPicker(),
      ),
      keyPhotoCameraFactoryProvider.overrideWithValue(
        () => camera ?? _Camera(),
      ),
      answerKeyPollIntervalProvider.overrideWithValue(
        const Duration(seconds: 3),
      ),
    ],
    extraRoutes: extraRoutes,
  );
}

/// Lets a route transition finish while a progress bar keeps animating
/// (pumpAndSettle would never return).
Future<void> _settleRoute(WidgetTester tester) async {
  for (var i = 0; i < 15; i++) {
    await tester.pump(const Duration(milliseconds: 100));
  }
}

void main() {
  testWidgets('shows the AI-draft label and what is still missing', (
    tester,
  ) async {
    final keys = FakeAnswerKeys(
      answerKeyState(
        keyOrigin: 'ai_draft',
        incomplete: [3],
        questions: _filled,
      ),
    );
    await _pumpKeyScreen(tester, keys);

    expect(find.byType(KeyOriginBanner), findsOneWidget);
    expect(find.text('AI ร่าง ไม่มีคำตอบของครู'), findsOneWidget);
    expect(find.text('ยังไม่อนุมัติเฉลย'), findsOneWidget);
    expect(find.text('ตอบ C'), findsOneWidget);
    expect(find.text('ตอบ 42 / สี่สิบสอง'), findsOneWidget);
    expect(find.text('คำตอบตัวอย่าง: ใบไม้มีคลอโรฟิลล์'), findsOneWidget);
    expect(find.text('AI ร่าง'), findsNWidgets(3));
    expect(find.text('ยังไม่ครบ'), findsOneWidget);
    expect(find.textContaining('ยังไม่ครบ: ข้อ 3'), findsOneWidget);
    final approve = tester.widget<FilledButton>(
      find.byKey(const ValueKey('approve_key')),
    );
    expect(approve.onPressed, isNull);

    await unmountScreen(tester);
  });

  testWidgets('approves a complete key', (tester) async {
    final keys = FakeAnswerKeys(
      answerKeyState(
        keyOrigin: 'document',
        complete: true,
        questions: [_filled[0], _filled[1]],
      ),
    );
    await _pumpKeyScreen(tester, keys);
    expect(find.text('AI อ่านจากเอกสารของครู'), findsOneWidget);
    expect(find.text('เฉลยครบทุกข้อแล้ว'), findsOneWidget);

    keys.state = answerKeyState(
      status: 'ready',
      keyOrigin: 'document',
      complete: true,
      approvedAt: '2026-09-30T03:00:00Z',
      questions: [_filled[0], _filled[1]],
    );
    await tester.tap(find.byKey(const ValueKey('approve_key')));
    await tester.pumpAndSettle();
    expect(find.textContaining('เริ่มตรวจงานที่ส่งมา'), findsOneWidget);
    await tester.tap(find.widgetWithText(FilledButton, 'อนุมัติ'));
    await tester.pumpAndSettle();

    expect(keys.approvals, 1);
    expect(find.text('อนุมัติเฉลยแล้ว'), findsWidgets);
    expect(find.textContaining('อนุมัติเมื่อ'), findsOneWidget);
    final approve = tester.widget<FilledButton>(
      find.byKey(const ValueKey('approve_key')),
    );
    expect(approve.onPressed, isNull);

    await unmountScreen(tester);
  });

  testWidgets('attaches a file, shows the cost and polls until it is read', (
    tester,
  ) async {
    final picker = FakeDocumentPicker([
      PickedDocument(name: 'เฉลย.pdf', bytes: Uint8List.fromList([1])),
    ]);
    final keys = FakeAnswerKeys(answerKeyState())
      ..uploadResult = const [_pdf]
      ..requestResult = KeyRequestResult(
        cached: false,
        answerKey: answerKeyState(
          extraction: {'id': 4, 'status': 'queued', 'kind': 'answer_key_read'},
        ),
      );
    await _pumpKeyScreen(tester, keys, picker: picker);
    expect(find.textContaining('ยังไม่มีข้อ'), findsOneWidget);
    keys.nextStates.add(
      answerKeyState(
        keyOrigin: 'document',
        incomplete: [3],
        extraction: {
          'id': 4,
          'status': 'done',
          'kind': 'answer_key_read',
          'notes_th': 'ข้อ 3 ลายมืออ่านยาก',
        },
        questions: _filled,
      ),
    );

    await tester.tap(find.widgetWithText(OutlinedButton, 'แนบไฟล์'));
    await tester.pumpAndSettle();

    expect(picker.calls, [false]);
    expect(keys.uploads.single.single.name, 'เฉลย.pdf');
    expect(find.byType(DocumentReadScreen), findsOneWidget);
    expect(find.text('เฉลย.pdf'), findsOneWidget);
    expect(find.text('3 หน้า · 300 KB'), findsOneWidget);
    expect(find.textContaining('ประมาณ 0.09 บาท'), findsOneWidget);
    expect(keys.estimates.single, {
      'kind': 'read',
      'document_ids': [31],
      'page_from': null,
      'page_to': null,
    });

    await tester.tap(find.byKey(const ValueKey('send_key_request')));
    await _settleRoute(tester);

    expect(keys.requests.single['kind'], 'read');
    expect(find.byType(DocumentReadScreen), findsNothing);
    expect(find.text('AI กำลังอ่านเฉลย...'), findsOneWidget);
    final approve = tester.widget<FilledButton>(
      find.byKey(const ValueKey('approve_key')),
    );
    expect(approve.onPressed, isNull);

    // The poll finds the read done: the questions are filled.
    await tester.pump(const Duration(seconds: 2));
    await tester.pumpAndSettle();
    expect(find.text('AI กำลังอ่านเฉลย...'), findsNothing);
    expect(find.text('ตรวจทานเฉลย (3 ข้อ)'), findsOneWidget);
    expect(find.text('ข้อ 3 ลายมืออ่านยาก'), findsOneWidget);
    expect(find.text('AI อ่านจากเอกสารของครู'), findsOneWidget);

    await unmountScreen(tester);
  });

  testWidgets('a file read before is filled at once without cost', (
    tester,
  ) async {
    final picker = FakeDocumentPicker([
      PickedDocument(name: 'เฉลย.pdf', bytes: Uint8List.fromList([1])),
    ]);
    final keys = FakeAnswerKeys(answerKeyState())
      ..uploadResult = const [
        SourceDocument(
          id: 31,
          originalName: 'เฉลย.pdf',
          mimeType: 'application/pdf',
          pageCount: 3,
          cachedPurposes: ['answer_key'],
        ),
      ]
      ..estimateResult = const KeyEstimate(
        pages: 3,
        cached: true,
        estimate: CostEstimate(inputTokens: 3180, outputTokens: 2250),
      )
      ..requestResult = KeyRequestResult(
        cached: true,
        applied: const AppliedSummary(
          filled: 1,
          skipped: [SkippedQuestion(questionNo: 4, reason: 'type_mismatch')],
        ),
        answerKey: answerKeyState(
          keyOrigin: 'document',
          extraction: {'id': 4, 'status': 'done', 'kind': 'answer_key_read'},
          questions: _filled,
        ),
      );
    await _pumpKeyScreen(tester, keys, picker: picker);

    await tester.tap(find.widgetWithText(OutlinedButton, 'แนบไฟล์'));
    await tester.pumpAndSettle();
    expect(find.textContaining('เคยอ่านไฟล์นี้แล้ว'), findsWidgets);
    expect(find.text('เคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('send_key_request')));
    await tester.pumpAndSettle();
    expect(find.text('เติมเฉลย 1 ข้อ'), findsOneWidget);
    expect(find.text('ข้อ 4 ประเภทคำถามไม่ตรงกับในการบ้าน'), findsOneWidget);

    await unmountScreen(tester);
  });

  testWidgets('photographs the key page by page', (tester) async {
    final camera = _Camera();
    final keys = FakeAnswerKeys(answerKeyState())
      ..uploadResult = const [
        SourceDocument(
          id: 41,
          originalName: 'key-photo-1.jpg',
          mimeType: 'image/jpeg',
          pageCount: 1,
        ),
        SourceDocument(
          id: 42,
          originalName: 'key-photo-2.jpg',
          mimeType: 'image/jpeg',
          pageCount: 1,
        ),
      ];
    await _pumpKeyScreen(tester, keys, camera: camera);

    await tester.tap(find.widgetWithText(OutlinedButton, 'ถ่ายรูป'));
    await tester.pumpAndSettle();
    expect(find.text('ถ่ายรูปเฉลย'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('key_photo_shutter')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('key_photo_shutter')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('key_photo_shutter')));
    await tester.pumpAndSettle();
    // Drop the blurred third page.
    await tester.tap(find.byTooltip('ลบรูปนี้').last);
    await tester.pumpAndSettle();
    await tester.tap(find.text('เสร็จ (2)'));
    await tester.pumpAndSettle();

    final sent = keys.uploads.single;
    expect(sent.map((f) => f.name), ['key-photo-1.jpg', 'key-photo-2.jpg']);
    expect(sent.map((f) => f.path), ['/cache/shot1.jpg', '/cache/shot2.jpg']);
    expect(sent.first.mimeType, 'image/jpeg');
    // Photos: no page range, the estimate covers both files.
    expect(find.text('เลือกเฉพาะช่วงหน้า'), findsNothing);
    expect(keys.estimates.single['document_ids'], [41, 42]);

    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(keys.requests, isEmpty);

    await unmountScreen(tester);
  });

  testWidgets('without a camera the teacher picks photos instead', (
    tester,
  ) async {
    final picker = FakeDocumentPicker([
      PickedDocument(name: 'IMG_1.HEIC', bytes: Uint8List.fromList([1])),
    ]);
    final keys = FakeAnswerKeys(answerKeyState())
      ..uploadResult = const [
        SourceDocument(
          id: 43,
          originalName: 'IMG_1.HEIC',
          mimeType: 'image/heic',
          pageCount: 1,
        ),
      ];
    await _pumpKeyScreen(
      tester,
      keys,
      picker: picker,
      camera: _Camera(
        initError: const ScanCameraException(
          'แอปยังไม่ได้รับอนุญาตให้ใช้กล้อง',
        ),
      ),
    );

    await tester.tap(find.widgetWithText(OutlinedButton, 'ถ่ายรูป'));
    await tester.pumpAndSettle();
    expect(find.text('แอปยังไม่ได้รับอนุญาตให้ใช้กล้อง'), findsOneWidget);
    await tester.tap(find.text('เลือกรูปจากเครื่องแทน'));
    await tester.pumpAndSettle();

    expect(picker.calls, [true]);
    expect(keys.uploads.single.single.mimeType, 'image/heic');
    expect(find.byType(DocumentReadScreen), findsOneWidget);

    await unmountScreen(tester);
  });

  testWidgets('AI drafts the key from the typed questions', (tester) async {
    final keys = FakeAnswerKeys(answerKeyState(questions: [_filled[0]]))
      ..estimateResult = const KeyEstimate(
        pages: 0,
        cached: false,
        estimate: CostEstimate(inputTokens: 1500, outputTokens: 150),
      )
      ..requestResult = KeyRequestResult(
        cached: false,
        answerKey: answerKeyState(
          keyOrigin: 'ai_draft',
          extraction: {'id': 5, 'status': 'queued', 'kind': 'answer_key_draft'},
          questions: [_filled[0]],
        ),
      );
    await _pumpKeyScreen(tester, keys);

    await tester.tap(find.text('ไม่มีเฉลย ให้ AI ร่าง'));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ร่างจากโจทย์ที่พิมพ์ไว้'));
    await tester.pumpAndSettle();

    expect(find.textContaining('ร่างเฉลยจากโจทย์ที่พิมพ์ไว้'), findsOneWidget);
    expect(find.textContaining('แสดงเฉพาะจำนวน token'), findsOneWidget);
    expect(keys.estimates.single, {
      'kind': 'draft',
      'document_ids': <int>[],
      'page_from': null,
      'page_to': null,
    });
    await tester.tap(find.byKey(const ValueKey('send_key_request')));
    await _settleRoute(tester);

    expect(keys.requests.single['kind'], 'draft');
    expect(find.text('AI กำลังร่างเฉลย...'), findsOneWidget);
    expect(find.text('AI ร่าง ไม่มีคำตอบของครู'), findsOneWidget);

    await unmountScreen(tester);
  });

  testWidgets('a failed read is shown with its reason', (tester) async {
    final keys = FakeAnswerKeys(
      answerKeyState(
        extraction: {
          'id': 6,
          'status': 'failed',
          'kind': 'answer_key_read',
          'error': 'Gemini ตอบไม่ครบ',
        },
      ),
    );
    await _pumpKeyScreen(tester, keys);
    expect(
      find.textContaining('อ่านเฉลยไม่สำเร็จ: Gemini ตอบไม่ครบ'),
      findsOneWidget,
    );
    expect(find.text('เพิ่มข้อก่อนอนุมัติเฉลย'), findsOneWidget);
    await unmountScreen(tester);
  });

  testWidgets('a question opens its editor and the key reloads after', (
    tester,
  ) async {
    final keys = FakeAnswerKeys(answerKeyState(questions: [_filled[1]]));
    await _pumpKeyScreen(
      tester,
      keys,
      extraRoutes: [
        GoRoute(
          path: '/assignments/:id/questions/:qid/edit',
          builder: (_, state) => Scaffold(
            appBar: AppBar(),
            body: Text('edit ${state.pathParameters['qid']}'),
          ),
        ),
      ],
    );
    final before = keys.gets;
    await tester.tap(find.byKey(const ValueKey('key_question_2')));
    await tester.pumpAndSettle();
    expect(find.text('edit 502'), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();
    expect(keys.gets, greaterThan(before));
    await unmountScreen(tester);
  });

  group('DocumentReadScreen', () {
    Future<FakeAnswerKeys> pumpRead(
      WidgetTester tester, {
      List<SourceDocument> documents = const [_book],
      KeyEstimate? estimate,
      Object? estimateError,
    }) async {
      tester.view.physicalSize = const Size(1080, 2400);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final keys = FakeAnswerKeys(answerKeyState())
        ..estimateError = estimateError
        ..requestResult = KeyRequestResult(
          cached: false,
          answerKey: answerKeyState(),
        );
      if (estimate != null) keys.estimateResult = estimate;
      await pumpScreen(
        tester,
        DocumentReadScreen(
          assignmentId: 12,
          kind: KeyRequestKind.read,
          documents: documents,
          debounce: const Duration(milliseconds: 10),
        ),
        overrides: [answerKeyRepositoryProvider.overrideWithValue(keys)],
      );
      return keys;
    }

    testWidgets('a long PDF needs a range of at most 30 pages', (tester) async {
      final keys = await pumpRead(
        tester,
        estimate: const KeyEstimate(
          pages: 5,
          cached: false,
          estimate: CostEstimate(
            inputTokens: 4300,
            outputTokens: 3750,
            thb: 0.44,
          ),
        ),
      );
      expect(find.textContaining('ไฟล์นี้ยาว 42 หน้า'), findsOneWidget);
      expect(keys.estimates.single['page_from'], 1);
      expect(keys.estimates.single['page_to'], 30);

      await tester.enterText(find.byKey(const ValueKey('page_to')), '35');
      await tester.pumpAndSettle();
      expect(find.text('เลือกได้ไม่เกิน 30 หน้าต่อครั้ง'), findsOneWidget);
      expect(keys.estimates, hasLength(1), reason: 'no estimate while invalid');
      var send = tester.widget<FilledButton>(
        find.byKey(const ValueKey('send_key_request')),
      );
      expect(send.onPressed, isNull);

      await tester.enterText(find.byKey(const ValueKey('page_to')), '50');
      await tester.pumpAndSettle();
      expect(find.text('ไฟล์นี้มี 42 หน้า'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('page_from')), '8');
      await tester.enterText(find.byKey(const ValueKey('page_to')), '7');
      await tester.pumpAndSettle();
      expect(find.text('หน้าสุดท้ายต้องไม่น้อยกว่าหน้าแรก'), findsOneWidget);
      await tester.enterText(find.byKey(const ValueKey('page_from')), '');
      await tester.pumpAndSettle();
      expect(find.text('กรอกเลขหน้าให้ครบ'), findsOneWidget);

      await tester.enterText(find.byKey(const ValueKey('page_from')), '3');
      await tester.pumpAndSettle();
      expect(keys.estimates.last['page_from'], 3);
      expect(keys.estimates.last['page_to'], 7);
      expect(find.textContaining('ประมาณ 0.44 บาท'), findsOneWidget);
      expect(find.textContaining('AI อ่าน 5 หน้า'), findsOneWidget);

      send = tester.widget<FilledButton>(
        find.byKey(const ValueKey('send_key_request')),
      );
      expect(send.onPressed, isNotNull);
      await tester.tap(find.byKey(const ValueKey('send_key_request')));
      await tester.pumpAndSettle();
      expect(keys.requests.single, {
        'kind': 'read',
        'document_ids': [32],
        'page_from': 3,
        'page_to': 7,
      });
      expect(find.text('stub-home'), findsOneWidget);
    });

    testWidgets('a short PDF may be cut to a range', (tester) async {
      final keys = await pumpRead(tester, documents: const [_pdf]);
      expect(find.byKey(const ValueKey('page_from')), findsNothing);
      await tester.tap(find.text('เลือกเฉพาะช่วงหน้า'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('page_to')), '2');
      await tester.pumpAndSettle();
      expect(keys.estimates.last['page_from'], 1);
      expect(keys.estimates.last['page_to'], 2);
    });

    testWidgets('several files over 30 pages cannot be sent together', (
      tester,
    ) async {
      final keys = await pumpRead(
        tester,
        documents: const [
          _pdf,
          SourceDocument(
            id: 33,
            originalName: 'b.pdf',
            mimeType: 'application/pdf',
            pageCount: 29,
          ),
        ],
      );
      expect(find.textContaining('ไฟล์รวมกัน 32 หน้า'), findsOneWidget);
      expect(keys.estimates, isEmpty);
    });

    testWidgets('server errors are shown and nothing can be sent', (
      tester,
    ) async {
      final options = RequestOptions(path: '/x');
      final keys = await pumpRead(
        tester,
        documents: const [_pdf],
        estimateError: DioException(
          requestOptions: options,
          response: Response(
            requestOptions: options,
            statusCode: 422,
            data: {
              'message': 'ไม่พบไฟล์ที่เลือก แนบไฟล์ใหม่อีกครั้ง',
              'errors': {},
              'code': 'validation_failed',
            },
          ),
        ),
      );
      expect(
        find.text('ไม่พบไฟล์ที่เลือก แนบไฟล์ใหม่อีกครั้ง'),
        findsOneWidget,
      );
      final send = tester.widget<FilledButton>(
        find.byKey(const ValueKey('send_key_request')),
      );
      expect(send.onPressed, isNull);
      expect(keys.requests, isEmpty);
    });

    testWidgets('a refused read stays on the screen with the reason', (
      tester,
    ) async {
      final options = RequestOptions(path: '/x');
      final keys = await pumpRead(tester, documents: const [_pdf]);
      keys.requestError = DioException(
        requestOptions: options,
        response: Response(
          requestOptions: options,
          statusCode: 422,
          data: {
            'message': 'ยังไม่มี Gemini API key ให้ใช้',
            'errors': {},
            'code': 'ai_key_missing',
          },
        ),
      );
      await tester.tap(find.byKey(const ValueKey('send_key_request')));
      await tester.pumpAndSettle();
      expect(find.text('ยังไม่มี Gemini API key ให้ใช้'), findsOneWidget);
      expect(find.byType(DocumentReadScreen), findsOneWidget);
    });
  });

  test('key summaries of every question type', () {
    Question q(Map<String, dynamic> json) => Question.fromJson(json);
    expect(keySummary(q(questionJson(1, 'mcq'))), 'ยังไม่มีเฉลย');
    expect(keySummary(q(questionJson(2, 'short'))), 'ยังไม่มีเฉลย');
    expect(keySummary(q(questionJson(3, 'show_work'))), 'ยังไม่มีคำตอบสุดท้าย');
    expect(
      keySummary(
        q(
          questionJson(
            3,
            'show_work',
            answerKey: {
              'final': {
                'accepted': ['12 บาท'],
              },
              'reference_steps': ['3 × 4', '= 12'],
            },
          ),
        ),
      ),
      'คำตอบสุดท้าย 12 บาท · ขั้นตอนอ้างอิง 2 ขั้น',
    );
    expect(
      keySummary(q(questionJson(4, 'open'))),
      'ยังไม่มีคำตอบตัวอย่าง (ตรวจตาม rubric)',
    );
  });
}
