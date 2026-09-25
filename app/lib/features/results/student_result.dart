import '../review/review_labels.dart';
import '../review/review_models.dart';

/// A published submission as listed by `GET /student/results` (DESIGN §9.7).
class StudentResult {
  const StudentResult({
    required this.submissionId,
    required this.title,
    this.subjectName,
    this.totalScore,
    this.maxScore,
    this.publishedAt,
  });

  final int submissionId;
  final String title;
  final String? subjectName;
  final double? totalScore;
  final double? maxScore;
  final DateTime? publishedAt;

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

/// `GET /student/results/{submission_id}`.
class StudentResultDetail {
  const StudentResultDetail({
    required this.summary,
    required this.answers,
    this.retakeReason,
  });

  final StudentResult summary;
  final List<StudentAnswer> answers;

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
            ),
      answers: answers,
    );
  }
}
