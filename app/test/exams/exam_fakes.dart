import 'dart:typed_data';

import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/exams/exam_models.dart';
import 'package:eduvision/features/exams/exam_print.dart';
import 'package:eduvision/features/exams/exams_repository.dart';
import 'package:eduvision/features/worksheets/print_job.dart';

/// `GET /exams/{id}` as the server answers it (ExamPayload::of): an app
/// exam with an mcq section (q11 keyed ค, q12 blank), a true_false
/// section (q21 keyed ผิด) and a numeric section of 2 digits with a
/// decimal point (q31 keyed 0.5).
Map<String, dynamic> examJson({
  int id = 40,
  String method = 'app',
  String status = 'draft',
  String? keyApprovedAt,
  String? lockedAt,
  int versionCount = 2,
  bool keyComplete = false,
  bool q11Suggested = false,
  bool q11Locked = false,
  bool q11Image = false,
  List<Map<String, dynamic>>? sections,
}) => {
  'exam': {
    'id': id,
    'classroom_id': 7,
    'subject_id': 1,
    'course_id': 3,
    'title': 'สอบกลางภาค',
    'status': status,
    'due_at': '2026-10-15T16:59:00+00:00',
    'mode': 'worksheet',
    'kind': 'exam',
    'grading_method': method,
    'version_count': versionCount,
    'duration_minutes': 60,
    'show_key_to_students': false,
    'manual_full_marks': method == 'manual' ? 30 : null,
    'key_approved_at': keyApprovedAt,
    'structure_locked_at': lockedAt,
    'classroom': {'id': 7, 'name': 'ป.5/2'},
    'course': {'id': 3, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
  },
  'sections':
      sections ??
      [
        {
          'id': 1,
          'assignment_id': id,
          'position': 1,
          'title': 'ปรนัย',
          'instructions': 'เลือกคำตอบที่ถูกที่สุด',
          'type': 'mcq',
          'option_count': 4,
          'numeric': null,
          'default_points': '1.00',
          'question_count': 2,
          'first_number': 1,
          'last_number': 2,
          'questions': [
            questionJson(
              id: 11,
              sectionId: 1,
              position: 1,
              prompt: '2 + 2 เท่ากับเท่าใด',
              options: ['2', '3', '4', 'ถูกทุกข้อ'],
              key: {
                'accepted_options': [3],
              },
              suggested: q11Suggested,
              locked: q11Locked,
              image: q11Image,
            ),
            questionJson(
              id: 12,
              sectionId: 1,
              position: 2,
              options: [null, null, null, null],
              blank: true,
              approved: false,
            ),
          ],
        },
        {
          'id': 2,
          'assignment_id': id,
          'position': 2,
          'title': null,
          'instructions': null,
          'type': 'true_false',
          'option_count': null,
          'numeric': null,
          'default_points': 1,
          'question_count': 1,
          'first_number': 3,
          'last_number': 3,
          'questions': [
            questionJson(
              id: 21,
              sectionId: 2,
              position: 3,
              type: 'true_false',
              prompt: 'ดวงอาทิตย์ขึ้นทางทิศตะวันตก',
              key: {
                'accepted_options': [2],
              },
            ),
          ],
        },
        {
          'id': 3,
          'assignment_id': id,
          'position': 3,
          'title': 'เติมตัวเลข',
          'instructions': null,
          'type': 'numeric',
          'option_count': null,
          'numeric': {
            'digits': 2,
            'allow_negative': false,
            'allow_decimal': true,
          },
          'default_points': 2,
          'question_count': 1,
          'first_number': 4,
          'last_number': 4,
          'questions': [
            questionJson(
              id: 31,
              sectionId: 3,
              position: 4,
              type: 'numeric',
              prompt: '1 ÷ 2 =',
              points: 2,
              key: {
                'accepted_values': ['0.5'],
              },
            ),
          ],
        },
      ],
  'key_complete': keyComplete,
  'incomplete_questions': keyComplete
      ? []
      : [
          {
            'question_id': 12,
            'position': 2,
            'reasons': ['not_approved', 'no_key'],
          },
        ],
  'booklet_incomplete_questions': [],
  'versions_ready': true,
  'structure_locked_at': lockedAt,
  'sheet': {'pages': 1, 'overflow': false},
};

Map<String, dynamic> questionJson({
  required int id,
  required int sectionId,
  required int position,
  String type = 'mcq',
  String prompt = '',
  List<String?> options = const [],
  Map<String, dynamic>? key,
  bool blank = false,
  bool approved = true,
  bool suggested = false,
  bool locked = false,
  bool image = false,
  double points = 1,
}) => {
  'id': id,
  'assignment_id': 40,
  'section_id': sectionId,
  'position': position,
  'type': type,
  'prompt_text': prompt,
  'has_prompt_image': image,
  'max_points': points,
  'options': [
    for (var i = 0; i < options.length; i++)
      {
        'id': id * 10 + i + 1,
        'position': i + 1,
        'label': kExamOptionLabels[i],
        'text': options[i],
        'has_image': false,
      },
  ],
  'answer_key': key,
  'approved_at': approved ? '2026-09-30T10:00:00+00:00' : null,
  'origin': 'teacher',
  'blank': blank,
  'lock_options': locked,
  'lock_options_suggested': suggested,
  'skill_ids': [],
  'key_complete': key != null,
  'has_prompt': prompt.isNotEmpty || image,
  'updated_at': '2026-09-30T10:00:00+00:00',
};

/// `GET /exams/{id}/versions` for [examJson]: version ข swaps q11/q12 and
/// shows q11's options as ค ก ง ข.
Map<String, dynamic> versionsJson({int count = 2, String? lockedAt}) => {
  'version_count': count,
  'shuffle_nonce': 0,
  'structure_locked_at': lockedAt,
  'versions_ready': true,
  'versions': [
    {
      'version_no': 1,
      'label': 'ก',
      'seed': '0011223344556677',
      'question_order': [11, 12, 21, 31],
      'items': [
        _item(1, 11, 1, 1, 'mcq', null, [3]),
        _item(2, 12, 2, 1, 'mcq', null, []),
        _item(3, 21, 3, 2, 'true_false', null, [2]),
        {
          ..._item(4, 31, 4, 3, 'numeric', null, null),
          'accepted_values': ['0.5'],
        },
      ],
    },
    if (count > 1)
      {
        'version_no': 2,
        'label': 'ข',
        'seed': '8899aabbccddeeff',
        'question_order': [12, 11, 21, 31],
        'items': [
          _item(1, 12, 2, 1, 'mcq', [2, 1, 4, 3], []),
          _item(2, 11, 1, 1, 'mcq', [3, 1, 4, 2], [1]),
          _item(3, 21, 3, 2, 'true_false', null, [2]),
          {
            ..._item(4, 31, 4, 3, 'numeric', null, null),
            'accepted_values': ['0.5'],
          },
        ],
      },
  ],
};

Map<String, dynamic> _item(
  int sheetNo,
  int questionId,
  int original,
  int sectionId,
  String type,
  List<int>? order,
  List<int>? accepted,
) => {
  'sheet_no': sheetNo,
  'question_id': questionId,
  'original_position': original,
  'section_id': sectionId,
  'type': type,
  'points': 1.0,
  'option_order': order,
  'accepted_options': ?accepted,
};

/// In-memory [ExamsRepository]: answers from [detailJson]/[versions] and
/// records every call as `name` → argument.
class FakeExamsRepository implements ExamsRepository {
  FakeExamsRepository({Map<String, dynamic>? detail, this.versionsBody})
    : detailJson = detail ?? examJson();

  Map<String, dynamic> detailJson;
  Map<String, dynamic>? versionsBody;
  final calls = <(String, Object?)>[];

  /// Thrown by the next mutating call instead of answering.
  Object? failNext;

  void _check() {
    final f = failNext;
    if (f != null) {
      failNext = null;
      throw f;
    }
  }

  ExamDetail get _detail => ExamDetail.fromJson(detailJson);

  ExamQuestion _question(int id) =>
      _detail.question(id) ??
      ExamQuestion(
        id: id,
        sectionId: 1,
        position: 1,
        type: ExamSectionType.mcq,
      );

  @override
  Future<Assignment> create(ExamSettingsDraft draft) async {
    calls.add(('create', draft.toCreateJson()));
    _check();
    return Assignment(
      id: 77,
      classroomId: draft.classroomId ?? 7,
      subjectId: 1,
      title: draft.title,
      kind: Assignment.kindExam,
    );
  }

  @override
  Future<Assignment> updateSettings(
    int examId,
    Map<String, Object?> changes,
  ) async {
    calls.add(('updateSettings', changes));
    _check();
    return _detail.exam;
  }

  @override
  Future<ExamDetail> get(int examId) async {
    calls.add(('get', examId));
    return _detail;
  }

  @override
  Future<ExamSection> addSection(int examId, ExamSectionDraft draft) async {
    calls.add(('addSection', draft.toCreateJson()));
    _check();
    return ExamSection(id: 9, position: 4, type: draft.type);
  }

  @override
  Future<ExamSection> updateSection(
    int sectionId,
    Map<String, Object?> body,
  ) async {
    calls.add(('updateSection', {'id': sectionId, ...body}));
    _check();
    return _detail.section(sectionId)!;
  }

  @override
  Future<void> deleteSection(int sectionId) async {
    calls.add(('deleteSection', sectionId));
    _check();
  }

  @override
  Future<ExamQuestion> addQuestion(
    int sectionId,
    ExamQuestionDraft draft,
  ) async {
    calls.add((
      'addQuestion',
      {'section': sectionId, ...draft.toJson(create: true)},
    ));
    _check();
    return ExamQuestion.fromJson(
      questionJson(
        id: 99,
        sectionId: sectionId,
        position: 5,
        options: draft.options ?? const [],
      ),
    );
  }

  @override
  Future<ExamQuestion> updateQuestion(
    int questionId,
    ExamQuestionDraft draft,
  ) async {
    calls.add(('updateQuestion', {'id': questionId, ...draft.toJson()}));
    _check();
    return _question(questionId);
  }

  @override
  Future<void> deleteQuestion(int questionId) async {
    calls.add(('deleteQuestion', questionId));
    _check();
  }

  @override
  Future<ExamQuestion> uploadQuestionImage(
    int questionId,
    PickedDocument f,
  ) async {
    calls.add(('uploadQuestionImage', (questionId, f.name)));
    _check();
    return _question(questionId);
  }

  @override
  Future<ExamQuestion> deleteQuestionImage(int questionId) async {
    calls.add(('deleteQuestionImage', questionId));
    _check();
    return _question(questionId);
  }

  @override
  Future<ExamQuestion> uploadOptionImage(int optionId, PickedDocument f) async {
    calls.add(('uploadOptionImage', (optionId, f.name)));
    _check();
    return _question(optionId ~/ 10);
  }

  @override
  Future<ExamQuestion> deleteOptionImage(int optionId) async {
    calls.add(('deleteOptionImage', optionId));
    _check();
    return _question(optionId ~/ 10);
  }

  /// A 1×1 PNG.
  static final pixel = Uint8List.fromList(const [
    0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A, 0x00, 0x00, 0x00, 0x0D, //
    0x49, 0x48, 0x44, 0x52, 0x00, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x01,
    0x08, 0x06, 0x00, 0x00, 0x00, 0x1F, 0x15, 0xC4, 0x89, 0x00, 0x00, 0x00,
    0x0D, 0x49, 0x44, 0x41, 0x54, 0x78, 0x9C, 0x63, 0xF8, 0xCF, 0xC0, 0xF0,
    0x1F, 0x00, 0x05, 0x00, 0x01, 0xFF, 0x89, 0x99, 0x3D, 0x1D, 0x00, 0x00,
    0x00, 0x00, 0x49, 0x45, 0x4E, 0x44, 0xAE, 0x42, 0x60, 0x82,
  ]);

  @override
  Future<Uint8List> image(ExamImageKey key) async {
    calls.add(('image', key));
    return pixel;
  }

  @override
  Future<ExamDetail> approveQuestions(int examId, List<int> questionIds) async {
    calls.add(('approveQuestions', questionIds));
    _check();
    return _detail;
  }

  @override
  Future<ExamDetail> saveAnswerKey(
    int examId,
    List<({int questionId, ExamSectionType type, ExamKey? key})> answers,
  ) async {
    calls.add((
      'saveAnswerKey',
      [
        for (final a in answers)
          {'question_id': a.questionId, 'key': a.key?.toJson(a.type)},
      ],
    ));
    _check();
    return _detail;
  }

  @override
  Future<ExamDetail> approveKey(int examId) async {
    calls.add(('approveKey', examId));
    _check();
    detailJson = {
      ...detailJson,
      'exam': {
        ...(detailJson['exam'] as Map<String, dynamic>),
        'status': 'ready',
        'key_approved_at': '2026-09-30T12:00:00+00:00',
      },
    };
    return _detail;
  }

  @override
  Future<ExamVersions> versions(int examId) async {
    calls.add(('versions', examId));
    return ExamVersions.fromJson(versionsBody ?? versionsJson());
  }

  @override
  Future<ExamVersions> reshuffle(int examId) async {
    calls.add(('reshuffle', examId));
    _check();
    return ExamVersions.fromJson(versionsBody ?? versionsJson());
  }

  @override
  Future<ExamDetail> unlockStructure(int examId) async {
    calls.add(('unlockStructure', examId));
    _check();
    detailJson = {...detailJson, 'structure_locked_at': null};
    return _detail;
  }

  var _nextPrintId = 900;

  /// Overrides the `202` answer of `requestPrint` (default: queued).
  PrintJob Function(ExamPrintRequest request)? printAnswer;

  /// Overrides the poll answer (default: ready with a download URL).
  Future<PrintJob> Function(PrintJob job)? statusAnswer;

  @override
  Future<PrintJob> requestPrint(int examId, ExamPrintRequest request) async {
    calls.add(('requestPrint', request.toJson()));
    _check();
    // Like the server: the first print of any kind locks the structure.
    detailJson = {
      ...detailJson,
      'structure_locked_at':
          detailJson['structure_locked_at'] ?? '2026-10-01T02:00:00+00:00',
    };
    return printAnswer?.call(request) ??
        PrintJob(
          id: _nextPrintId++,
          status: 'queued',
          kind: request.kind.apiValue,
          versionNo: request.versionNo,
        );
  }

  @override
  Future<PrintJob> printStatus(PrintJob job) async {
    calls.add(('printStatus', job.id));
    final answer = statusAnswer;
    if (answer != null) return answer(job);
    return PrintJob(
      id: job.id,
      status: 'ready',
      downloadUrl: '/api/v1/worksheet-prints/${job.id}/file',
      kind: job.kind,
      versionNo: job.versionNo,
      layoutVersion: job.kind == 'exam_booklet' ? null : 3,
    );
  }

  /// The arguments of every call named [name].
  List<Object?> args(String name) => [
    for (final c in calls)
      if (c.$1 == name) c.$2,
  ];
}
