import 'question.dart';

enum Strictness {
  lenient('lenient', 'ผ่อนปรน'),
  normal('normal', 'ปกติ'),
  strict('strict', 'เข้มงวด');

  const Strictness(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static Strictness fromApi(String? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => Strictness.normal,
  );
}

/// DESIGN §8.3 assignments (+ questions when fetched by id).
class Assignment {
  const Assignment({
    required this.id,
    required this.classroomId,
    required this.subjectId,
    required this.title,
    this.strictness = Strictness.normal,
    this.status = 'draft',
    this.currentLayoutVersion,
    this.dueAt,
    this.questions = const [],
    this.classroomName,
    this.subjectName,
  });

  final int id;
  final int classroomId;
  final int subjectId;
  final String title;
  final Strictness strictness;

  /// `draft`, `ready` or `closed`.
  final String status;
  final int? currentLayoutVersion;
  final DateTime? dueAt;
  final List<Question> questions;
  final String? classroomName;
  final String? subjectName;

  bool get isDraft => status == 'draft';

  /// Every show_work / open question has an approved rubric (required
  /// before `POST /assignments/{id}/layout`).
  bool get rubricsApproved => questions
      .where((q) => q.type.needsRubric)
      .every((q) => q.rubricStatus == RubricStatus.approved);

  factory Assignment.fromJson(Map<String, dynamic> json) {
    final classroom = json['classroom'] as Map<String, dynamic>?;
    final subject = json['subject'] as Map<String, dynamic>?;
    final due = json['due_at'] as String?;
    return Assignment(
      id: (json['id'] as num).toInt(),
      classroomId: ((json['classroom_id'] ?? classroom?['id']) as num).toInt(),
      subjectId: ((json['subject_id'] ?? subject?['id']) as num).toInt(),
      title: json['title'] as String,
      strictness: Strictness.fromApi(json['strictness'] as String?),
      status: json['status'] as String? ?? 'draft',
      currentLayoutVersion: (json['current_layout_version'] as num?)?.toInt(),
      dueAt: due == null ? null : DateTime.tryParse(due),
      questions:
          ((json['questions'] as List?) ?? const [])
              .cast<Map<String, dynamic>>()
              .map(Question.fromJson)
              .toList()
            ..sort((a, b) => a.position.compareTo(b.position)),
      classroomName: classroom?['name'] as String?,
      subjectName: subject?['name'] as String?,
    );
  }
}

/// One layout version: `pages` holds the per-page JSON of DESIGN §5.3.
class LayoutVersion {
  const LayoutVersion({required this.version, required this.pages});

  final int version;
  final List<Map<String, dynamic>> pages;

  factory LayoutVersion.fromJson(Map<String, dynamic> json) => LayoutVersion(
    version: (json['version'] as num).toInt(),
    pages: ((json['pages'] as List?) ?? const []).cast<Map<String, dynamic>>(),
  );
}
