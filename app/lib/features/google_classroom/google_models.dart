import 'package:flutter/material.dart';

/// `GET /google/status` (DESIGN §18.6).
class GoogleStatus {
  const GoogleStatus({
    required this.connected,
    this.email,
    this.scopes = const [],
    this.needsReconnect = false,
    this.configured = true,
  });

  static const disconnected = GoogleStatus(connected: false);

  final bool connected;
  final String? email;
  final List<String> scopes;

  /// The refresh token stopped working (`invalid_grant`: revoked, or the
  /// 7-day limit of an OAuth app in Testing mode, §18.5).
  final bool needsReconnect;

  /// The server has its Google OAuth client (GOOGLE_OAUTH_CLIENT_ID /
  /// SECRET). The app shows its Google Classroom UI only when true. An
  /// answer without the field (`POST /google/connect`) counts as true.
  final bool configured;

  /// Connected and usable right now.
  bool get ready => connected && !needsReconnect;

  factory GoogleStatus.fromJson(Map<String, dynamic> json) => GoogleStatus(
    // `POST /google/connect` answers only {email, scopes}.
    connected: json.containsKey('connected')
        ? json['connected'] == true
        : json['email'] != null,
    email: json['email'] as String?,
    scopes: _scopes(json['scopes']),
    needsReconnect: json['needs_reconnect'] == true,
    configured: switch (json['configured'] ?? json['server_configured']) {
      bool b => b,
      _ => true,
    },
  );

  static List<String> _scopes(Object? value) => switch (value) {
    String s => s.split(RegExp(r'\s+')).where((e) => e.isNotEmpty).toList(),
    List<dynamic> l => l.whereType<String>().toList(),
    _ => const [],
  };
}

/// A course the teacher teaches (`GET /google/courses`).
class GoogleCourse {
  const GoogleCourse({
    required this.courseId,
    required this.name,
    this.section,
  });

  final String courseId;
  final String name;
  final String? section;

  factory GoogleCourse.fromJson(Map<String, dynamic> json) => GoogleCourse(
    courseId: (json['course_id'] ?? json['id']).toString(),
    name: (json['name'] ?? '') as String,
    section: switch (json['section']) {
      String s when s.trim().isNotEmpty => s,
      _ => null,
    },
  );
}

/// A student of the linked course with the suggested pair
/// (`GET /classrooms/{id}/google-roster`).
class GoogleRosterEntry {
  const GoogleRosterEntry({
    required this.googleUserId,
    required this.name,
    this.email,
    this.suggestedStudentId,
    this.matchedStudentId,
  });

  final String googleUserId;
  final String name;
  final String? email;

  /// Proposed from the normalised name; a starting point for the teacher.
  final int? suggestedStudentId;

  /// Saved by the teacher earlier.
  final int? matchedStudentId;

  /// What the matching screen starts with.
  int? get initialStudentId => matchedStudentId ?? suggestedStudentId;

  factory GoogleRosterEntry.fromJson(Map<String, dynamic> json) =>
      GoogleRosterEntry(
        googleUserId: json['google_user_id'].toString(),
        name: (json['name'] ?? '') as String,
        email: json['email'] as String?,
        suggestedStudentId: (json['suggested_student_id'] as num?)?.toInt(),
        matchedStudentId: (json['matched_student_id'] as num?)?.toInt(),
      );
}

/// `classroom_submission_imports.state` (DESIGN §18.4).
enum SubmissionImportState {
  newSubmission('new', 'ส่งแล้ว รอสแกน'),
  imported('imported', 'สแกนแล้ว'),
  needsRetake('needs_retake', 'ต้องถ่ายใหม่'),
  returnedForRetake('returned_for_retake', 'ตีกลับให้ถ่ายใหม่แล้ว'),
  graded('graded', 'ส่งคะแนนกลับแล้ว'),
  gradeFailed('grade_failed', 'ส่งคะแนนกลับไม่สำเร็จ');

  const SubmissionImportState(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static SubmissionImportState fromApi(Object? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => SubmissionImportState.newSubmission,
  );

  /// Waiting to be downloaded and scanned (what "ดาวน์โหลดและสแกนทั้งหมด"
  /// picks up).
  bool get awaitsScan => this == newSubmission || this == needsRetake;

  /// The phone may download and scan it (again). Not once it was returned
  /// for a retake (Classroom still holds the photos the teacher rejected,
  /// until the student hands in new ones) nor once graded (a rescan of
  /// published work waits for the teacher's confirmation, §9.4).
  bool get canScan =>
      this == newSubmission || this == imported || this == needsRetake;

  /// The teacher may still send it back for a new photo.
  bool get canReturnForRetake =>
      this == newSubmission || this == imported || this == needsRetake;

  Color color(ColorScheme scheme) => switch (this) {
    newSubmission => scheme.primary,
    imported => Colors.green.shade700,
    graded => Colors.green.shade700,
    needsRetake || gradeFailed => scheme.error,
    returnedForRetake => scheme.tertiary,
  };
}

/// A file a student attached (`attachments` of an import row).
class GoogleAttachment {
  const GoogleAttachment({
    required this.driveFileId,
    required this.title,
    required this.mimeType,
  });

  final String driveFileId;
  final String title;
  final String mimeType;

  String get _mime => mimeType.toLowerCase();

  /// Pictures and PDFs; Google Docs/Sheets and other files cannot be scanned.
  bool get isSupported =>
      _mime.startsWith('image/') ||
      _mime == 'application/pdf' ||
      // Drive does not always know the type of an uploaded photo.
      _mime == 'application/octet-stream' ||
      _mime.isEmpty;

  bool get isPdf => _mime == 'application/pdf';

  /// JPEG and PNG go straight to the scan pipeline (OpenCV reads them and
  /// applies the EXIF orientation); anything else is converted first.
  bool get needsRasterize =>
      !(_mime == 'image/jpeg' || _mime == 'image/jpg' || _mime == 'image/png');

  factory GoogleAttachment.fromJson(Map<String, dynamic> json) =>
      GoogleAttachment(
        driveFileId: json['drive_file_id'].toString(),
        title: (json['title'] ?? 'ไฟล์แนบ') as String,
        mimeType: (json['mime_type'] ?? '') as String,
      );
}

/// A student in a submission row (null when the Google account is not
/// matched to anyone in the room yet).
class SubmissionStudent {
  const SubmissionStudent({
    required this.id,
    required this.name,
    this.studentNumber,
  });

  final int id;
  final String name;
  final int? studentNumber;

  String get label =>
      studentNumber == null ? name : '$name (เลขที่ $studentNumber)';
}

/// One row of `GET /assignments/{id}/google-submissions` (DESIGN §18.6).
class GoogleSubmission {
  const GoogleSubmission({
    required this.id,
    required this.googleSubmissionId,
    required this.state,
    this.student,
    this.attachments = const [],
    this.alternateLink,
    this.retakeReason,
    this.lastError,
  });

  /// `classroom_submission_imports.id` (used by `/google-submissions/{id}`).
  final int id;
  final String googleSubmissionId;
  final SubmissionImportState state;
  final SubmissionStudent? student;
  final List<GoogleAttachment> attachments;

  /// Opens this submission in the Classroom web app.
  final String? alternateLink;
  final String? retakeReason;

  /// Why sending the grade back failed (`grade_failed`).
  final String? lastError;

  String get studentLabel => student?.label ?? 'ยังไม่ได้จับคู่นักเรียน';

  factory GoogleSubmission.fromJson(Map<String, dynamic> json) {
    final student = json['student'];
    final attachments = json['attachments'];
    return GoogleSubmission(
      id: (json['id'] as num).toInt(),
      googleSubmissionId: json['google_submission_id'].toString(),
      state: SubmissionImportState.fromApi(json['state']),
      student: student is Map
          ? SubmissionStudent(
              id: (student['id'] as num).toInt(),
              name: (student['name'] ?? '') as String,
              studentNumber: (student['student_number'] as num?)?.toInt(),
            )
          : null,
      attachments: [
        if (attachments is List)
          for (final a in attachments)
            if (a is Map) GoogleAttachment.fromJson(a.cast<String, dynamic>()),
      ],
      alternateLink: json['alternate_link'] as String?,
      retakeReason: _nonEmpty(json['retake_reason']),
      lastError: _nonEmpty(json['last_error']),
    );
  }
}

String? _nonEmpty(Object? value) =>
    value is String && value.trim().isNotEmpty ? value : null;
