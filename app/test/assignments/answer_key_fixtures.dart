import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/class_regrade.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';

/// A question as the API returns it (QuestionResource).
Map<String, dynamic> questionJson(
  int position,
  String type, {
  Map<String, dynamic>? answerKey,
  String? modelAnswer,
  String rubric = 'not_needed',
  bool complete = true,
  String? prompt,
}) => {
  'id': 500 + position,
  'assignment_id': 12,
  'position': position,
  'type': type,
  'prompt_text': prompt ?? 'โจทย์ข้อ $position',
  'max_points': 2,
  'answer_lines': type == 'open' || type == 'show_work' ? 5 : null,
  'is_numeric': false,
  'match_mode': 'flexible',
  'answer_key': answerKey,
  'model_answer': modelAnswer,
  'key_complete': complete,
  'rubric_status': rubric,
};

/// `GET /assignments/{id}/answer-key`.
Map<String, dynamic> answerKeyJson({
  String mode = 'freeform',
  String status = 'draft',
  String? keyOrigin,
  String? approvedAt,
  Map<String, dynamic>? extraction,
  bool complete = false,
  List<int> incomplete = const [],
  List<Map<String, dynamic>> questions = const [],
}) => {
  'assignment_id': 12,
  'mode': mode,
  'status': status,
  'key_origin': keyOrigin,
  'key_approved_at': approvedAt,
  'key_approved_by': approvedAt == null ? null : 1,
  'extraction_status': extraction?['status'],
  'extraction': extraction,
  'key_complete': complete,
  'incomplete_questions': incomplete,
  'questions': questions,
};

AnswerKeyState answerKeyState({
  String mode = 'freeform',
  String status = 'draft',
  String? keyOrigin,
  String? approvedAt,
  Map<String, dynamic>? extraction,
  bool complete = false,
  List<int> incomplete = const [],
  List<Map<String, dynamic>> questions = const [],
}) => AnswerKeyState.fromJson(
  answerKeyJson(
    mode: mode,
    status: status,
    keyOrigin: keyOrigin,
    approvedAt: approvedAt,
    extraction: extraction,
    complete: complete,
    incomplete: incomplete,
    questions: questions,
  ),
);

/// Records what the screens ask for; answers come from the fields.
class FakeAnswerKeys implements AnswerKeyRepository {
  FakeAnswerKeys(this.state);

  /// What `GET /answer-key` returns next (a list pops one per call).
  AnswerKeyState state;
  final List<AnswerKeyState> nextStates = [];

  List<SourceDocument> uploadResult = const [];
  KeyEstimate estimateResult = const KeyEstimate(
    pages: 1,
    cached: false,
    estimate: CostEstimate(inputTokens: 2060, outputTokens: 750, thb: 0.09),
  );
  KeyRequestResult? requestResult;
  Object? requestError;
  Object? estimateError;

  final uploads = <List<PickedDocument>>[];
  final estimates = <Map<String, Object?>>[];
  final requests = <Map<String, Object?>>[];
  int gets = 0;
  int approvals = 0;

  @override
  Future<AnswerKeyState> answerKey(int assignmentId) async {
    gets++;
    if (nextStates.isNotEmpty) state = nextStates.removeAt(0);
    return state;
  }

  @override
  Future<List<SourceDocument>> upload(List<PickedDocument> files) async {
    uploads.add(files);
    return uploadResult;
  }

  @override
  Future<KeyEstimate> estimate(
    int assignmentId, {
    required KeyRequestKind kind,
    List<int> documentIds = const [],
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    estimates.add({
      'kind': kind.apiValue,
      'document_ids': documentIds,
      'page_from': pageFrom,
      'page_to': pageTo,
      'guidance': guidance,
    });
    if (estimateError case final e?) throw e;
    return estimateResult;
  }

  @override
  Future<KeyRequestResult> request(
    int assignmentId, {
    required KeyRequestKind kind,
    List<int> documentIds = const [],
    int? pageFrom,
    int? pageTo,
    String? guidance,
  }) async {
    requests.add({
      'kind': kind.apiValue,
      'document_ids': documentIds,
      'page_from': pageFrom,
      'page_to': pageTo,
      'guidance': guidance,
    });
    if (requestError case final e?) throw e;
    return requestResult!;
  }

  /// `course_id` sent with each approval (null when none was sent).
  final approvedCourses = <int?>[];

  @override
  Future<AnswerKeyState> approve(int assignmentId, {int? courseId}) async {
    approvals++;
    approvedCourses.add(courseId);
    return state;
  }
}

class FakeDocumentPicker implements DocumentFilePicker {
  FakeDocumentPicker([this.files = const []]);

  List<PickedDocument> files;
  final calls = <bool>[];

  @override
  Future<List<PickedDocument>> pick({
    bool imagesOnly = false,
    String? dialogTitle,
  }) async {
    calls.add(imagesOnly);
    return files;
  }
}

/// `POST /assignments/{id}/regrade/estimate` as the server answers it.
Map<String, dynamic> regradeEstimateJson({
  int submissions = 0,
  int queued = 0,
  int mcq = 0,
  int pages = 0,
  int overridden = 0,
  int inProgressCount = 0,
  int missing = 0,
  int published = 0,
  bool inProgress = false,
  double? thb = 0.35,
}) => {
  'submissions': submissions,
  'queued_responses': queued,
  'mcq_by_code': mcq,
  'whole_page_pages': pages,
  'skipped_overridden': overridden,
  'skipped_in_progress': inProgressCount,
  'skipped_missing_image': missing,
  'published_submissions': published,
  'in_progress': inProgress,
  'estimate': {'input_tokens': 12000, 'output_tokens': 3500, 'thb': thb},
};

/// Records "ตรวจใหม่ทั้งห้อง" calls; answers come from the fields.
class FakeClassRegrade implements ClassRegradeRepository {
  FakeClassRegrade({RegradeEstimate? estimate, RegradeEstimate? included})
    : estimateResult = estimate ?? const RegradeEstimate(),
      includedResult = included;

  /// The estimate without overridden answers.
  RegradeEstimate estimateResult;

  /// The estimate with `include_overridden` (defaults to [estimateResult]).
  RegradeEstimate? includedResult;
  RegradeOutcome outcome = const RegradeOutcome();
  Object? estimateError;
  Object? regradeError;

  /// `include_overridden` of each call.
  final estimates = <bool>[];
  final regrades = <bool>[];

  @override
  Future<RegradeEstimate> estimate(
    int assignmentId, {
    bool includeOverridden = false,
  }) async {
    estimates.add(includeOverridden);
    if (estimateError case final e?) throw e;
    return includeOverridden
        ? includedResult ?? estimateResult
        : estimateResult;
  }

  @override
  Future<RegradeOutcome> regrade(
    int assignmentId, {
    bool includeOverridden = false,
  }) async {
    regrades.add(includeOverridden);
    if (regradeError case final e?) throw e;
    return outcome;
  }
}
