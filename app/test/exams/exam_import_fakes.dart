import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/exams/exam_import_models.dart';
import 'package:eduvision/features/exams/exam_import_repository.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:eduvision/platform/document_page_renderer.dart';

import 'exam_fakes.dart';

/// [examJson] after a read of a two-page PDF (source document 501): a new
/// section 4 with two draft questions from the file. q41 has a prompt
/// figure on page 1 (already cropped when [cropped]) and an option figure
/// waiting for page 2; q42 has no figure and no key. [pending] lists page 2
/// in `figures_pending` (needs_render); [pages] adds page images.
Map<String, dynamic> importedExamJson({
  bool cropped = true,
  bool pending = true,
  bool q41Approved = false,
  List<Map<String, dynamic>>? pages,
  String? lockedAt,
}) {
  final base = examJson(lockedAt: lockedAt);
  final q41 = {
    ...questionJson(
      id: 41,
      sectionId: 4,
      position: 5,
      prompt: 'รูปใดเป็นสามเหลี่ยม',
      options: ['รูป ก', null, 'รูป ค', 'รูป ง'],
      key: {
        'accepted_options': [1],
      },
      approved: q41Approved,
      image: cropped,
    ),
    'origin': 'document',
    'figure_source': {
      'page_image_id': cropped ? 801 : null,
      'source_document_id': 501,
      'page_no': 1,
      'box_2d': [100, 200, 400, 800],
    },
    'figure_pending': !cropped,
  };
  final options = [
    for (final o in q41['options'] as List)
      {
        ...(o as Map<String, dynamic>),
        'figure_source': o['position'] == 2
            ? {
                'page_image_id': null,
                'source_document_id': 501,
                'page_no': 2,
                'box_2d': [500, 100, 600, 300],
              }
            : null,
        'figure_pending': o['position'] == 2,
      },
  ];
  q41['options'] = options;
  final q42 = {
    ...questionJson(
      id: 42,
      sectionId: 4,
      position: 6,
      prompt: '5 × 6 เท่ากับเท่าใด',
      options: ['11', '30', '56', '65'],
      approved: false,
    ),
    'origin': 'document',
    'figure_source': null,
    'figure_pending': false,
  };
  return {
    ...base,
    'sections': [
      ...(base['sections'] as List),
      {
        'id': 4,
        'assignment_id': 40,
        'position': 4,
        'title': 'ตอนที่ 1 จากไฟล์',
        'instructions': null,
        'type': 'mcq',
        'option_count': 4,
        'numeric': null,
        'default_points': 1,
        'question_count': 2,
        'first_number': 5,
        'last_number': 6,
        'questions': [q41, q42],
      },
    ],
    'page_images':
        pages ??
        [
          {
            'id': 801,
            'source_document_id': 501,
            'page_no': 1,
            'width_px': 1000,
            'height_px': 1400,
            'available': true,
          },
        ],
    'figures_pending': pending
        ? [
            {
              'source_document_id': 501,
              'page_no': 2,
              'original_name': 'midterm.pdf',
              'mime_type': 'application/pdf',
              'figures': 1,
              'reason': 'needs_render',
            },
          ]
        : [],
  };
}

/// One library row (`GET /teacher/exam-questions`).
Map<String, dynamic> libraryJson({
  required int id,
  required int examId,
  required String examTitle,
  required int sectionId,
  String type = 'mcq',
  int position = 1,
  String prompt = 'โจทย์',
  Map<String, dynamic>? key,
}) => {
  ...questionJson(
    id: id,
    sectionId: sectionId,
    position: position,
    type: type,
    prompt: prompt,
    options: type == 'mcq' ? ['1', '2', '3', '4'] : const [],
    key: key,
  ),
  'exam': {'id': examId, 'title': examTitle, 'course_id': 3},
  'section': {
    'id': sectionId,
    'title': 'ปรนัย',
    'type': type,
    'option_count': type == 'mcq' ? 4 : null,
    'numeric': null,
  },
};

/// In-memory [ExamImportRepository]; records calls as `name` → argument.
class FakeExamImportRepository implements ExamImportRepository {
  FakeExamImportRepository({this.exams});

  /// Where `setFigure` and `copyQuestions` read the exam from.
  final FakeExamsRepository? exams;
  final calls = <(String, Object?)>[];

  KeyEstimate estimateResult = const KeyEstimate(
    pages: 2,
    cached: false,
    estimate: CostEstimate(inputTokens: 1800, outputTokens: 16384, thb: 0.42),
  );
  ExamImportResult? importResult;

  /// `GET /document-extractions/{id}` answers, one per poll (last repeats).
  final reads = <ExamRead>[];

  /// `figures_pending` answered by each upload.
  List<FigurePending> afterUpload = const [];

  /// Runs on each upload (e.g. to change what `GET /exams/{id}` answers).
  void Function()? onUpload;

  /// Library pages by cursor (null = first page).
  final pages = <String?, LibraryPage>{};
  ExamCopyResult? copyResult;

  /// Thrown by the next call named here.
  final failures = <String, Object>{};

  void _check(String name) {
    final f = failures.remove(name);
    if (f != null) throw f;
  }

  List<Object?> args(String name) => [
    for (final c in calls)
      if (c.$1 == name) c.$2,
  ];

  @override
  Future<KeyEstimate> estimate(
    int examId, {
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    calls.add((
      'estimate',
      {'ids': documentIds, 'from': pageFrom, 'to': pageTo, 'g': guidance},
    ));
    _check('estimate');
    return estimateResult;
  }

  @override
  Future<ExamImportResult> import(
    int examId, {
    required List<int> documentIds,
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    calls.add((
      'import',
      {'ids': documentIds, 'from': pageFrom, 'to': pageTo, 'g': guidance},
    ));
    _check('import');
    return importResult!;
  }

  @override
  Future<ExamRead> read(int extractionId) async {
    calls.add(('read', extractionId));
    _check('read');
    return reads.length > 1 ? reads.removeAt(0) : reads.single;
  }

  @override
  Future<Uint8List> pageImage(int pageImageId) async {
    calls.add(('pageImage', pageImageId));
    _check('pageImage');
    return FakeExamsRepository.pixel;
  }

  @override
  Future<Uint8List> documentFile(int examId, int documentId) async {
    calls.add(('documentFile', documentId));
    _check('documentFile');
    return Uint8List.fromList(const [0x25, 0x50, 0x44, 0x46]);
  }

  @override
  Future<List<FigurePending>> uploadPageImage(
    int examId, {
    required int sourceDocumentId,
    required int pageNo,
    required String jpegPath,
  }) async {
    calls.add(('uploadPageImage', (sourceDocumentId, pageNo, jpegPath)));
    _check('uploadPageImage');
    onUpload?.call();
    return afterUpload;
  }

  @override
  Future<ExamQuestion> setFigure(
    ExamImageKey target, {
    required int pageImageId,
    required List<int> box,
  }) async {
    calls.add(('setFigure', (target, pageImageId, box)));
    _check('setFigure');
    return ExamQuestion(
      id: target.id,
      sectionId: 4,
      position: 5,
      type: ExamSectionType.mcq,
    );
  }

  @override
  Future<LibraryPage> library({
    int? courseId,
    int? examId,
    String? query,
    int? excludeExam,
    String? cursor,
  }) async {
    calls.add((
      'library',
      {
        'course_id': courseId,
        'exam_id': examId,
        'q': query,
        'exclude_exam': excludeExam,
        'cursor': cursor,
      },
    ));
    _check('library');
    return pages[cursor] ?? const LibraryPage();
  }

  @override
  Future<ExamCopyResult> copyQuestions(
    int examId, {
    required List<int> questionIds,
    int? sectionId,
  }) async {
    calls.add(('copyQuestions', {'ids': questionIds, 'section': sectionId}));
    _check('copyQuestions');
    return copyResult!;
  }
}

/// Renders nothing: answers a fake JPEG path per page and records calls.
class FakePageRenderer implements DocumentPageRenderer {
  FakePageRenderer({this.isSupported = true});

  @override
  final bool isSupported;
  final renders = <(String, String, int)>[];
  final saved = <String>[];

  /// Thrown for a page number here.
  final failPages = <int, PageRenderException>{};

  @override
  Future<String> renderPage(String path, String mimeType, int pageNo) async {
    renders.add((path, mimeType, pageNo));
    final f = failPages[pageNo];
    if (f != null) throw f;
    return '/cache/page-$pageNo.jpg';
  }

  @override
  Future<String> saveTemp(String name, Uint8List bytes) async {
    saved.add(name);
    return '/cache/files/$name';
  }
}
