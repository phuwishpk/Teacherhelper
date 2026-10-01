import '../review/review_labels.dart';
import '../review/review_models.dart';

/// Shown when the total was taken from Google Classroom (DESIGN §19.3).
const totalOverriddenNote = 'คะแนนรวมปรับตามที่ครูรับจาก Classroom';

/// A published submission as listed by `GET /student/results` (DESIGN §9.7).
class StudentResult {
  const StudentResult({
    required this.submissionId,
    required this.title,
    this.subjectName,
    this.totalScore,
    this.maxScore,
    this.publishedAt,
    this.totalOverridden = false,
    this.kind = 'homework',
  });

  final int submissionId;
  final String title;

  /// `homework` or `exam` (DESIGN §22.12).
  final String kind;

  bool get isExam => kind == 'exam';
  final String? subjectName;

  /// The effective total (`COALESCE(total_override, total_score)`, §19.3).
  final double? totalScore;
  final double? maxScore;
  final DateTime? publishedAt;

  /// The teacher took the total from Google Classroom (`total_overridden`,
  /// DESIGN §19.3), so it may differ from the sum of the per-question scores.
  final bool totalOverridden;

  factory StudentResult.fromJson(Map<String, dynamic> json) {
    final assignment = json['assignment'] as Map<String, dynamic>?;
    final subject = assignment?['subject'] as Map<String, dynamic>?;
    final published = json['published_at'] as String?;
    return StudentResult(
      submissionId: ((json['submission_id'] ?? json['id']) as num).toInt(),
      title: (json['title'] ?? assignment?['title'] ?? 'การบ้าน') as String,
      subjectName: (json['subject_name'] ?? subject?['name']) as String?,
      totalScore: (json['total_score'] as num?)?.toDouble(),
      maxScore: (json['max_score'] as num?)?.toDouble(),
      publishedAt: published == null ? null : DateTime.tryParse(published),
      totalOverridden: json['total_overridden'] == true,
      kind: json['kind'] as String? ?? 'homework',
    );
  }
}

double? _double(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

/// One answer inside `GET /student/results/{submission_id}` (DESIGN §9.7):
/// only the teacher-approved (final) values are ever shown to students.
class StudentAnswer {
  const StudentAnswer({
    required this.responseId,
    required this.position,
    required this.type,
    required this.maxPoints,
    this.promptText = '',
    this.score,
    this.understanding,
    this.errorTypes = const [],
    this.explanation,
    this.nextStep,
    this.hasCrop = true,
    this.hasFinalCrop = false,
    this.appeal,
    this.canAppeal = true,
  });

  final int responseId;
  final int position;
  final String type;
  final double maxPoints;
  final String promptText;
  final double? score;
  final Understanding? understanding;
  final List<ErrorType> errorTypes;
  final String? explanation;
  final String? nextStep;
  final bool hasCrop;
  final bool hasFinalCrop;
  final Appeal? appeal;

  /// False once an appeal exists (one per question) or the server says no.
  final bool canAppeal;

  bool get fullMarks => score != null && score! >= maxPoints;

  factory StudentAnswer.fromJson(Map<String, dynamic> json) {
    final question = json['question'] is Map
        ? (json['question'] as Map).cast<String, dynamic>()
        : const <String, dynamic>{};
    final appeal = json['appeal'] is Map
        ? Appeal.fromJson((json['appeal'] as Map).cast<String, dynamic>())
        : null;
    return StudentAnswer(
      responseId: ((json['id'] ?? json['response_id']) as num).toInt(),
      position: ((json['position'] ?? question['position'] ?? 0) as num)
          .toInt(),
      type: (json['type'] ?? question['type'] ?? 'short') as String,
      maxPoints: _double(json['max_points'] ?? question['max_points']) ?? 0,
      promptText:
          (json['prompt_text'] ?? question['prompt_text'] ?? '') as String,
      score: _double(json['final_score'] ?? json['score']),
      understanding: Understanding.fromApi(
        json['final_understanding'] ?? json['understanding'],
      ),
      errorTypes: ErrorType.listFromJson(
        json['final_error_types'] ?? json['error_types'],
      ),
      explanation: json['explanation'] as String?,
      nextStep: (json['next_step'] ?? json['next_step_th']) as String?,
      hasCrop: json.containsKey('has_crop') ? json['has_crop'] == true : true,
      hasFinalCrop: json['has_final_crop'] == true,
      appeal: appeal,
      canAppeal: appeal == null && json['can_appeal'] != false,
    );
  }
}

/// The teacher sent a Google Classroom submission back for a new photo
/// (DESIGN §18.2 "ตีกลับให้ถ่ายใหม่"): a row of
/// `GET /student/retake-requests`.
class RetakeRequest {
  const RetakeRequest({
    required this.id,
    required this.title,
    required this.reason,
    this.assignmentId,
    this.requestedAt,
    this.alternateLink,
  });

  final int id;
  final int? assignmentId;
  final String title;
  final String reason;
  final DateTime? requestedAt;

  /// Opens the work in Google Classroom, where the student sends again.
  final String? alternateLink;

  factory RetakeRequest.fromJson(Map<String, dynamic> json) {
    final assignment = json['assignment'] is Map
        ? (json['assignment'] as Map).cast<String, dynamic>()
        : const <String, dynamic>{};
    final requested = json['requested_at'] ?? json['updated_at'];
    return RetakeRequest(
      id: (json['id'] as num).toInt(),
      assignmentId: ((json['assignment_id'] ?? assignment['id']) as num?)
          ?.toInt(),
      title: (json['title'] ?? assignment['title'] ?? 'การบ้าน') as String,
      reason: (json['reason'] ?? json['retake_reason'] ?? '') as String,
      requestedAt: requested is String ? DateTime.tryParse(requested) : null,
      alternateLink: json['alternate_link'] as String?,
    );
  }
}

List<String>? _strings(Object? v) =>
    v is List ? [for (final s in v) '$s'] : null;

/// One question of a published exam as its student sees it (DESIGN
/// §22.12), only when the teacher shows the key: numbered and labelled as
/// the student's own version printed it. No image and no AI explanation.
class ExamResultItem {
  const ExamResultItem({
    required this.responseId,
    required this.number,
    required this.type,
    required this.maxPoints,
    this.sectionTitle,
    this.promptText = '',
    this.score,
    this.marked,
    this.markedValue,
    this.correct,
    this.correctValues,
    this.appeal,
    this.canAppeal = false,
  });

  final int responseId;

  /// The number on the student's own sheet.
  final int number;
  final String type;
  final double maxPoints;
  final String? sectionTitle;
  final String promptText;
  final double? score;

  /// Labels the student marked (ก, ข …), null for a number.
  final List<String>? marked;
  final String? markedValue;

  /// Labels of the right answers, null for a number.
  final List<String>? correct;
  final List<String>? correctValues;
  final Appeal? appeal;
  final bool canAppeal;

  bool get isNumeric => type == 'numeric';
  bool get fullMarks => score != null && score! >= maxPoints;

  /// What the student answered, in words.
  String get markedText {
    if (isNumeric) return markedValue ?? 'ไม่ได้ตอบ';
    final m = marked ?? const [];
    return m.isEmpty ? 'ไม่ได้ฝน' : m.join(', ');
  }

  /// The right answer(s), in words.
  String get correctText {
    final c = (isNumeric ? correctValues : correct) ?? const [];
    return c.isEmpty ? '-' : c.join(' หรือ ');
  }

  factory ExamResultItem.fromJson(Map<String, dynamic> json) {
    final appeal = json['appeal'] is Map
        ? Appeal.fromJson((json['appeal'] as Map).cast<String, dynamic>())
        : null;
    return ExamResultItem(
      responseId: (json['response_id'] as num).toInt(),
      number: (json['number'] as num?)?.toInt() ?? 0,
      type: json['type'] as String? ?? 'mcq',
      maxPoints: _double(json['max_points']) ?? 0,
      sectionTitle: json['section_title'] as String?,
      promptText: json['prompt_text'] as String? ?? '',
      score: _double(json['score']),
      marked: _strings(json['marked']),
      markedValue: json['marked_value'] as String?,
      correct: _strings(json['correct']),
      correctValues: _strings(json['correct_values']),
      appeal: appeal,
      canAppeal: appeal == null && json['can_appeal'] == true,
    );
  }
}

/// The exam part of `GET /student/results/{submission_id}` (DESIGN
/// §22.12): total and per-section scores; [items] only when the teacher
/// turned on "ให้นักเรียนดูเฉลย".
class ExamStudentResult {
  const ExamStudentResult({
    required this.sections,
    this.versionLabel,
    this.total,
    this.max,
    this.items,
  });

  /// Null when the exam has a single version.
  final String? versionLabel;
  final double? total;
  final double? max;
  final List<({String title, double score, double max})> sections;

  /// Null while the key is hidden from students.
  final List<ExamResultItem>? items;

  static ExamStudentResult fromJson(Map<String, dynamic> json) {
    final items = json['items'];
    return ExamStudentResult(
      versionLabel: json['version_label'] as String?,
      total: _double(json['total']),
      max: _double(json['max']),
      sections: [
        for (final s in (json['sections'] as List? ?? const []))
          if (s is Map)
            (
              title: '${s['title'] ?? ''}',
              score: _double(s['score']) ?? 0,
              max: _double(s['max']) ?? 0,
            ),
      ],
      items: items is List
          ? ([
              for (final i in items)
                if (i is Map)
                  ExamResultItem.fromJson(i.cast<String, dynamic>()),
            ]..sort((a, b) => a.number.compareTo(b.number)))
          : null,
    );
  }
}

/// `GET /student/results/{submission_id}`.
class StudentResultDetail {
  const StudentResultDetail({
    required this.summary,
    required this.answers,
    this.retakeReason,
    this.exam,
  });

  final StudentResult summary;
  final List<StudentAnswer> answers;

  /// The exam result (kind `exam`), else null.
  final ExamStudentResult? exam;

  /// Set when the teacher asked for a new photo of this assignment after
  /// the result was published (optional `retake_reason`).
  final String? retakeReason;

  factory StudentResultDetail.fromJson(Map<String, dynamic> json) {
    final rows = json['responses'] ?? json['answers'] ?? json['questions'];
    final answers = [
      if (rows is List)
        for (final r in rows)
          if (r is Map) StudentAnswer.fromJson(r.cast<String, dynamic>()),
    ]..sort((a, b) => a.position.compareTo(b.position));
    final summary = StudentResult.fromJson(json);
    final retake = json['retake_reason'];
    final exam = summary.isExam ? ExamStudentResult.fromJson(json) : null;
    if (exam != null) {
      return StudentResultDetail(
        summary: StudentResult(
          submissionId: summary.submissionId,
          title: summary.title,
          subjectName: summary.subjectName,
          totalScore: exam.total ?? summary.totalScore,
          maxScore: exam.max ?? summary.maxScore,
          publishedAt: summary.publishedAt,
          totalOverridden: summary.totalOverridden,
          kind: summary.kind,
        ),
        answers: const [],
        exam: exam,
      );
    }
    return StudentResultDetail(
      retakeReason: retake is String && retake.trim().isNotEmpty
          ? retake
          : null,
      summary: summary.maxScore != null || answers.isEmpty
          ? summary
          : StudentResult(
              submissionId: summary.submissionId,
              title: summary.title,
              subjectName: summary.subjectName,
              totalScore: summary.totalScore,
              maxScore: answers.fold<double>(0, (s, a) => s + a.maxPoints),
              publishedAt: summary.publishedAt,
              totalOverridden: summary.totalOverridden,
              kind: summary.kind,
            ),
      answers: answers,
    );
  }
}
