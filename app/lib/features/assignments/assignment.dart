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

/// How the students' work is checked (DESIGN §19.5 `assignments.mode`).
enum AssignmentMode {
  /// The app's printed worksheet with QR and answer boxes, scanned per box.
  worksheet('worksheet', 'ใบงานของแอป'),

  /// No worksheet of the app: graded from whole-page photos or files.
  freeform('freeform', 'ไม่ใช้ใบงานของแอป');

  const AssignmentMode(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static AssignmentMode fromApi(String? value) => values.firstWhere(
    (m) => m.apiValue == value,
    orElse: () => AssignmentMode.worksheet,
  );
}

/// Where the answer key came from (`assignments.key_origin`, §19.5).
enum KeyOrigin {
  teacher('teacher'),
  document('document'),
  aiDraft('ai_draft');

  const KeyOrigin(this.apiValue);

  final String apiValue;

  static KeyOrigin? fromApi(String? value) =>
      values.where((o) => o.apiValue == value).firstOrNull;
}

/// DESIGN §8.3 assignments (+ questions when fetched by id), with the
/// Phase 8 fields of §19.5.
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
    this.needsReviewCount,
    this.googleLink,
    this.mode = AssignmentMode.worksheet,
    this.source = 'app',
    this.acceptLate = true,
    this.scoreOnly = false,
    this.keyOrigin,
    this.keyApprovedAt,
  });

  final int id;
  final int classroomId;

  /// Null only for a mirror of courseWork created on the Classroom website
  /// until the teacher picks the subject when approving its key (§19.3).
  final int? subjectId;
  final String title;
  final Strictness strictness;

  /// `draft`, `ready` or `closed`.
  final String status;
  final int? currentLayoutVersion;
  final DateTime? dueAt;
  final List<Question> questions;
  final String? classroomName;
  final String? subjectName;

  /// Responses still waiting for the teacher's review, when the list
  /// endpoint includes it (optional `needs_review_count`).
  final int? needsReviewCount;

  /// Set once the assignment was posted to Google Classroom (DESIGN §18.4
  /// `assignment_google_links`), when the server includes `google_link`.
  final AssignmentGoogleLink? googleLink;

  final AssignmentMode mode;

  /// `app`, or `classroom_web` for work created on the Classroom website.
  final String source;

  /// A mirror of courseWork the teacher created on the Classroom website
  /// (DESIGN §19.3): its hand-ins are graded here, but the app cannot set
  /// its grades in Classroom ("เปิดใน Classroom" and "คัดลอกคะแนน" instead).
  bool get fromClassroomWeb =>
      source == 'classroom_web' ||
      googleLink?.origin == AssignmentGoogleLink.originClassroomWeb;

  /// The teacher still has to pick a subject (when approving the key).
  bool get needsSubject => subjectId == null;

  /// Hand-ins after the due date are still graded (labelled late).
  final bool acceptLate;

  /// No Gemini explanation: students see the score and a template (§21.7).
  final bool scoreOnly;
  final KeyOrigin? keyOrigin;

  /// Null until the teacher approves the key; nothing is graded before.
  final DateTime? keyApprovedAt;

  bool get isDraft => status == 'draft';

  bool get isFreeform => mode == AssignmentMode.freeform;

  bool get keyApproved => keyApprovedAt != null;

  Assignment withGoogleLink(AssignmentGoogleLink? link) => Assignment(
    id: id,
    classroomId: classroomId,
    subjectId: subjectId,
    title: title,
    strictness: strictness,
    status: status,
    currentLayoutVersion: currentLayoutVersion,
    dueAt: dueAt,
    questions: questions,
    classroomName: classroomName,
    subjectName: subjectName,
    needsReviewCount: needsReviewCount,
    googleLink: link,
    mode: mode,
    source: source,
    acceptLate: acceptLate,
    scoreOnly: scoreOnly,
    keyOrigin: keyOrigin,
    keyApprovedAt: keyApprovedAt,
  );

  /// Every show_work / open question has an approved rubric (required
  /// before `POST /assignments/{id}/layout`).
  bool get rubricsApproved => questions
      .where((q) => q.type.needsRubric)
      .every((q) => q.rubricStatus == RubricStatus.approved);

  factory Assignment.fromJson(Map<String, dynamic> json) {
    final classroom = json['classroom'] as Map<String, dynamic>?;
    final subject = json['subject'] as Map<String, dynamic>?;
    final due = json['due_at'] as String?;
    final approved = json['key_approved_at'];
    return Assignment(
      id: (json['id'] as num).toInt(),
      classroomId: ((json['classroom_id'] ?? classroom?['id']) as num).toInt(),
      subjectId: ((json['subject_id'] ?? subject?['id']) as num?)?.toInt(),
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
      needsReviewCount: (json['needs_review_count'] as num?)?.toInt(),
      googleLink: json['google_link'] is Map
          ? AssignmentGoogleLink.fromJson(
              (json['google_link'] as Map).cast<String, dynamic>(),
            )
          : null,
      mode: AssignmentMode.fromApi(json['mode'] as String?),
      source: json['source'] as String? ?? 'app',
      acceptLate: json['accept_late'] != false,
      scoreOnly: json['score_only'] == true,
      keyOrigin: KeyOrigin.fromApi(json['key_origin'] as String?),
      keyApprovedAt: approved is String ? DateTime.tryParse(approved) : null,
    );
  }
}

/// The Classroom `courseWork` of an assignment (DESIGN §18.2, §19.3):
/// `google_link` of an assignment and the answer of
/// `POST /assignments/{id}/google-post`. [origin] is `app` for work posted
/// by "โพสต์ลง Classroom", or `classroom_web` for courseWork the teacher
/// created on the Classroom website and the sync mirrored.
class AssignmentGoogleLink {
  const AssignmentGoogleLink({
    required this.courseWorkId,
    required this.alternateLink,
    this.hasBlankWorksheet = false,
    this.postedAt,
    this.origin = originApp,
    bool? canPushGrades,
    this.materials = const [],
    this.lastSyncedAt,
  }) : canPushGrades = canPushGrades ?? origin != originClassroomWeb;

  static const originApp = 'app';
  static const originClassroomWeb = 'classroom_web';

  final String courseWorkId;

  /// Opens the assignment in the Classroom web app.
  final String alternateLink;

  /// The anonymous spare worksheet (§18.3) is attached as material.
  final bool hasBlankWorksheet;
  final DateTime? postedAt;
  final String origin;

  /// Classroom accepts grades only for courseWork this project created
  /// (Google answers `ProjectPermissionDenied` otherwise, §19.3).
  final bool canPushGrades;

  /// Files the teacher attached to the courseWork on the website.
  final List<CourseWorkMaterial> materials;

  /// The last sync of its hand-ins and grades.
  final DateTime? lastSyncedAt;

  bool get fromClassroomWeb => origin == originClassroomWeb;

  factory AssignmentGoogleLink.fromJson(Map<String, dynamic> json) {
    final posted = json['posted_at'];
    final synced = json['last_synced_at'];
    final materials = json['materials'];
    return AssignmentGoogleLink(
      courseWorkId: json['course_work_id'].toString(),
      alternateLink: (json['alternate_link'] ?? '') as String,
      hasBlankWorksheet:
          json['has_blank_worksheet'] == true ||
          (json['drive_file_id'] is String &&
              (json['drive_file_id'] as String).isNotEmpty),
      postedAt: posted is String ? DateTime.tryParse(posted) : null,
      origin: json['origin'] as String? ?? originApp,
      canPushGrades: json['can_push_grades'] is bool
          ? json['can_push_grades'] as bool
          : null,
      materials: [
        if (materials is List)
          for (final m in materials)
            if (m is Map)
              CourseWorkMaterial.fromJson(m.cast<String, dynamic>()),
      ],
      lastSyncedAt: synced is String ? DateTime.tryParse(synced) : null,
    );
  }
}

/// A Drive file attached to courseWork on the Classroom website
/// (`google_link.materials`). Only PDFs and pictures can be read for the
/// AI draft of the key; Google Docs/Sheets/Slides cannot (§19.3).
class CourseWorkMaterial {
  const CourseWorkMaterial({
    required this.title,
    required this.mimeType,
    required this.supported,
  });

  final String title;
  final String mimeType;
  final bool supported;

  bool get isGoogleDoc => mimeType.startsWith('application/vnd.google-apps.');

  factory CourseWorkMaterial.fromJson(Map<String, dynamic> json) {
    final mime = (json['mime_type'] ?? '') as String;
    return CourseWorkMaterial(
      title: (json['title'] ?? 'ไฟล์แนบ') as String,
      mimeType: mime,
      supported: json['supported'] is bool
          ? json['supported'] as bool
          : mime == 'application/pdf' || mime.startsWith('image/'),
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
