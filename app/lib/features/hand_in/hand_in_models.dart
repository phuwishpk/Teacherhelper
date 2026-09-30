import '../assignments/answer_key_models.dart';

/// Most files (pages) one hand-in takes: the server counts every PDF page
/// and refuses more than `SUBMISSION_MAX_PAGES` (5) in all with 422
/// `too_many_pages` (DESIGN §19.6).
const kMaxHandInFiles = 5;

/// Largest file the server takes (`SUBMISSION_MAX_FILE_MB`, 10 MB).
const kMaxHandInFileBytes = 10 * 1024 * 1024;

/// Extensions a hand-in may have (JPEG/PNG/WebP/HEIC/HEIF/PDF, §19.6).
const kHandInExtensions = kDocumentExtensions;

/// One assignment of the student's "งานที่ต้องส่ง" (`GET
/// /student/assignments`, DESIGN §19.9). Only `ready` assignments of the
/// student's own classrooms come back.
class StudentAssignment {
  const StudentAssignment({
    required this.id,
    required this.title,
    this.classroomName,
    this.subjectName,
    this.dueAt,
    this.acceptLate = true,
    this.canSubmit = true,
    this.submissionId,
    this.submittedAt,
    this.late = false,
    this.status = notSubmitted,
  });

  static const notSubmitted = 'not_submitted';
  static const submitted = 'submitted';
  static const published = 'published';

  final int id;
  final String title;
  final String? classroomName;
  final String? subjectName;
  final DateTime? dueAt;
  final bool acceptLate;

  /// False once the due time passed on an assignment that refuses late
  /// work (the server answers 422 `submission_late`).
  final bool canSubmit;
  final int? submissionId;
  final DateTime? submittedAt;

  /// The hand-in came after the due time ("ส่งช้า").
  final bool late;

  /// `not_submitted`, `submitted` or `published`.
  final String status;

  bool get isSubmitted => status != notSubmitted;
  bool get isPublished => status == published;

  /// The due time passed (a hand-in now would be late, or is refused).
  bool isOverdue(DateTime now) => dueAt != null && now.isAfter(dueAt!);

  factory StudentAssignment.fromJson(Map<String, dynamic> json) {
    final classroom = json['classroom'];
    return StudentAssignment(
      id: (json['id'] as num).toInt(),
      title: json['title'] as String? ?? 'การบ้าน',
      classroomName: classroom is Map ? classroom['name'] as String? : null,
      subjectName: json['subject_name'] as String?,
      dueAt: _time(json['due_at']),
      acceptLate: json['accept_late'] != false,
      canSubmit: json['can_submit'] != false,
      submissionId: (json['submission_id'] as num?)?.toInt(),
      submittedAt: _time(json['submitted_at']),
      late: json['late'] == true,
      status: json['status'] as String? ?? notSubmitted,
    );
  }
}

/// `201` of `POST /student/assignments/{id}/submission`.
class HandInReceipt {
  const HandInReceipt({
    required this.submissionId,
    this.submittedAt,
    this.late = false,
    this.pages = 0,
  });

  final int submissionId;
  final DateTime? submittedAt;
  final bool late;
  final int pages;

  factory HandInReceipt.fromJson(Map<String, dynamic> json) => HandInReceipt(
    submissionId: (json['submission_id'] as num).toInt(),
    submittedAt: _time(json['submitted_at']),
    late: json['late'] == true,
    pages: (json['pages'] as num?)?.toInt() ?? 0,
  );
}

/// `201` of the teacher's `POST /assignments/{id}/students/{sid}/pages`.
class TeacherUploadResult {
  const TeacherUploadResult({
    required this.submissionId,
    required this.studentId,
    this.pages = 0,
    this.grading = false,
    this.waitingKey = false,
    this.regradePending = false,
  });

  final int submissionId;
  final int studentId;
  final int pages;

  /// Grading started at once (nothing was graded before).
  final bool grading;

  /// Kept until the teacher approves the answer key (§19.5).
  final bool waitingKey;

  /// The student had graded work: this hand-in waits for "ตรวจ".
  final bool regradePending;

  factory TeacherUploadResult.fromJson(Map<String, dynamic> json) =>
      TeacherUploadResult(
        submissionId: (json['submission_id'] as num).toInt(),
        studentId: (json['student_id'] as num).toInt(),
        pages: (json['pages'] as List?)?.length ?? 0,
        grading: json['grading'] == true,
        waitingKey: json['waiting_key'] == true,
        regradePending: json['regrade_pending'] == true,
      );
}

/// A student who already has a submission for the assignment (from the
/// review queue's `meta.submissions`), for the "ส่งแล้ว" label.
class HandedIn {
  const HandedIn({
    required this.studentId,
    required this.submissionId,
    required this.status,
    this.late = false,
  });

  final int studentId;
  final int submissionId;
  final String status;
  final bool late;
}

/// Why [files] cannot be sent as one hand-in, in Thai, or null when they
/// can. Checks what the app knows before uploading (count, type, size of
/// files it holds in memory); PDF page counts are checked by the server.
String? handInFilesProblem(List<PickedDocument> files) {
  if (files.isEmpty) return 'เลือกรูปหรือ PDF อย่างน้อย 1 ไฟล์';
  if (files.length > kMaxHandInFiles) {
    return 'ส่งได้ไม่เกิน $kMaxHandInFiles ไฟล์ต่อครั้ง';
  }
  for (final f in files) {
    if (f.mimeType == 'application/octet-stream') {
      return '${f.name}: ส่งได้เฉพาะรูป (JPG, PNG, WebP, HEIC) หรือ PDF '
          'ไฟล์ Word ให้บันทึกเป็น PDF ก่อน';
    }
    final size = f.bytes?.length;
    if (size != null && size > kMaxHandInFileBytes) {
      return '${f.name}: ไฟล์ใหญ่เกิน 10 MB';
    }
  }
  return null;
}

DateTime? _time(Object? value) =>
    value is String ? DateTime.tryParse(value) : null;
