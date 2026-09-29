import 'package:flutter/material.dart';

import '../classrooms/classroom.dart';

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
    this.linkedClassroom,
  });

  final String courseId;
  final String name;
  final String? section;

  /// The classroom this course is already linked to (DESIGN §19.9): shown
  /// faded in the pickers and cannot be imported or linked again.
  final LinkedClassroom? linkedClassroom;

  bool get isLinked => linkedClassroom != null;

  factory GoogleCourse.fromJson(Map<String, dynamic> json) => GoogleCourse(
    courseId: (json['course_id'] ?? json['id']).toString(),
    name: (json['name'] ?? '') as String,
    section: _nonEmpty(json['section']),
    linkedClassroom: switch (json['linked_classroom']) {
      Map m => LinkedClassroom(
        id: (m['id'] as num?)?.toInt(),
        name: _nonEmpty(m['name']) ?? LinkedClassroom.otherTeacher,
      ),
      // A server without `linked_classroom` (§18.6) names only the id.
      _ => switch (json['linked_classroom_id']) {
        num id => LinkedClassroom(id: id.toInt(), name: 'ห้องเรียนของคุณ'),
        _ => null,
      },
    },
  );
}

/// `linked_classroom` of a course: [id] is null when the room belongs to
/// another teacher (a co-taught course), whose room name stays hidden.
class LinkedClassroom {
  const LinkedClassroom({required this.id, required this.name});

  static const otherTeacher = 'ห้องเรียนของครูท่านอื่น';

  final int? id;
  final String name;
}

/// One account of `GET /google/courses/{course_id}/import-preview`.
class ImportPreviewStudent {
  const ImportPreviewStudent({
    required this.googleUserId,
    required this.name,
    required this.proposedNumber,
    this.email,
  });

  final String googleUserId;
  final String name;
  final String? email;

  /// 1..N in Thai dictionary order (`ThaiNameSorter`, DESIGN §19.2).
  final int proposedNumber;

  factory ImportPreviewStudent.fromJson(Map<String, dynamic> json) =>
      ImportPreviewStudent(
        googleUserId: json['google_user_id'].toString(),
        name: (json['name'] ?? '') as String,
        email: _nonEmpty(json['email']),
        proposedNumber: (json['proposed_number'] as num).toInt(),
      );
}

/// What the server proposes before importing a course (DESIGN §19.2).
class ClassroomImportPreview {
  const ClassroomImportPreview({
    required this.courseId,
    required this.name,
    required this.suggestedName,
    required this.academicYear,
    required this.students,
    this.section,
    this.gradeLevelGuess,
  });

  final String courseId;
  final String name;
  final String? section;
  final String suggestedName;

  /// 1-12 guessed from the course name and section; null means the teacher
  /// must pick one.
  final int? gradeLevelGuess;

  /// Buddhist-era year in Asia/Bangkok.
  final int academicYear;

  /// Sorted by [ImportPreviewStudent.proposedNumber].
  final List<ImportPreviewStudent> students;

  factory ClassroomImportPreview.fromJson(Map<String, dynamic> json) {
    final rows = json['students'];
    final students = [
      if (rows is List)
        for (final r in rows)
          if (r is Map)
            ImportPreviewStudent.fromJson(r.cast<String, dynamic>()),
    ]..sort((a, b) => a.proposedNumber.compareTo(b.proposedNumber));
    final guess = (json['grade_level_guess'] as num?)?.toInt();
    final name = (json['name'] ?? '') as String;
    return ClassroomImportPreview(
      courseId: json['course_id'].toString(),
      name: name,
      section: _nonEmpty(json['section']),
      suggestedName: _nonEmpty(json['suggested_name']) ?? name,
      gradeLevelGuess: guess != null && guess >= 1 && guess <= 12
          ? guess
          : null,
      academicYear: (json['academic_year'] as num).toInt(),
      students: students,
    );
  }
}

/// Body of `POST /classrooms/import-google`. Names are not sent: the server
/// reads them from Google again (DESIGN §19.9).
class ClassroomImportRequest {
  const ClassroomImportRequest({
    required this.courseId,
    required this.name,
    required this.gradeLevel,
    required this.academicYear,
    required this.numbers,
    this.removed = const [],
  });

  final String courseId;
  final String name;
  final int gradeLevel;
  final int academicYear;

  /// Google user id -> student number, for every account kept.
  final Map<String, int> numbers;

  /// Accounts the teacher took out (kept out of later roster syncs).
  final List<String> removed;

  Map<String, dynamic> toJson() => {
    'course_id': courseId,
    'name': name,
    'grade_level': gradeLevel,
    'academic_year': academicYear,
    'students': [
      for (final e in numbers.entries)
        {'google_user_id': e.key, 'student_number': e.value},
    ],
    'removed': removed,
  };
}

/// `201` of `POST /classrooms/import-google`: the new room and each
/// student's one-time PIN.
class ClassroomImportResult {
  const ClassroomImportResult({
    required this.classroom,
    required this.students,
  });

  final Classroom classroom;
  final List<EnrolledStudent> students;

  factory ClassroomImportResult.fromJson(Map<String, dynamic> json) {
    final rows = json['students'];
    return ClassroomImportResult(
      classroom: Classroom.fromJson(
        (json['classroom'] as Map).cast<String, dynamic>(),
      ),
      students: [
        if (rows is List)
          for (final r in rows)
            if (r is Map) EnrolledStudent.fromJson(r.cast<String, dynamic>()),
      ],
    );
  }
}

/// `POST /classrooms/{id}/google-roster/sync` (DESIGN §19.2).
class RosterSyncResult {
  const RosterSyncResult({
    this.added = const [],
    this.left = const [],
    this.rematched = const [],
  });

  /// New accounts appended to the room, with their one-time PINs.
  final List<EnrolledStudent> added;

  /// Students whose account left the course (kept, match cleared).
  final List<RosterStudent> left;

  /// Existing students matched to an account by e-mail or full name.
  final List<RosterStudent> rematched;

  bool get unchanged => added.isEmpty && left.isEmpty && rematched.isEmpty;

  factory RosterSyncResult.fromJson(Map<String, dynamic> json) {
    List<Map<String, dynamic>> rows(String key) => [
      if (json[key] case final List list)
        for (final r in list)
          if (r is Map) r.cast<String, dynamic>(),
    ];
    return RosterSyncResult(
      added: rows('added').map(EnrolledStudent.fromJson).toList(),
      left: rows('left').map(RosterStudent.fromJson).toList(),
      rematched: rows('rematched').map(RosterStudent.fromJson).toList(),
    );
  }
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

/// `classroom_submission_imports.state` (DESIGN §18.4, §19.8). Since
/// Phase 8 the server downloads the files and grades them from the whole
/// page (§19.4); these are the sync side of a row, the grading side comes
/// from the submission (review queue).
enum SubmissionImportState {
  newSubmission('new', 'รอดาวน์โหลด'),
  imported('imported', 'รับไฟล์แล้ว'),
  waitingKey('waiting_key', 'รออนุมัติเฉลย'),
  needsRetake('needs_retake', 'ต้องถ่ายใหม่'),
  returnedForRetake('returned_for_retake', 'ตีกลับให้ถ่ายใหม่แล้ว'),
  unsupported('unsupported', 'ไฟล์ใช้ไม่ได้'),
  rejectedLate('rejected_late', 'ส่งช้า ไม่รับ'),
  graded('graded', 'ส่งคะแนนกลับแล้ว'),
  gradeFailed('grade_failed', 'ส่งคะแนนกลับไม่สำเร็จ');

  const SubmissionImportState(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static SubmissionImportState fromApi(Object? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => SubmissionImportState.newSubmission,
  );

  /// The server holds the files (grading follows the submission).
  bool get hasFiles =>
      this == imported || this == graded || this == gradeFailed;

  /// The teacher may still send it back for a new photo (the server's
  /// RETURNABLE_STATES).
  bool get canReturnForRetake =>
      this == newSubmission || this == imported || this == needsRetake;

  Color color(ColorScheme scheme) => switch (this) {
    newSubmission || waitingKey => scheme.primary,
    imported || graded => Colors.green.shade700,
    needsRetake || gradeFailed || unsupported => scheme.error,
    returnedForRetake || rejectedLate => scheme.tertiary,
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

  /// Pictures and PDFs; Google Docs/Sheets and other files cannot be graded
  /// (the server marks such a row `unsupported`).
  bool get isSupported =>
      _mime.startsWith('image/') ||
      _mime == 'application/pdf' ||
      // Drive does not always know the type of an uploaded photo.
      _mime == 'application/octet-stream' ||
      _mime.isEmpty;

  bool get isPdf => _mime == 'application/pdf';

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
    this.late = false,
    this.updatedAt,
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

  /// Why sending the grade back failed (`grade_failed`), why the files
  /// cannot be graded (`unsupported`), or a note on a `new` row that waits
  /// (account to reconnect, file deleted).
  final String? lastError;

  /// Classroom marked the hand-in late.
  final bool late;
  final DateTime? updatedAt;

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
      late: json['late'] == true,
      updatedAt: json['updated_at'] is String
          ? DateTime.tryParse(json['updated_at'] as String)
          : null,
    );
  }
}

String? _nonEmpty(Object? value) =>
    value is String && value.trim().isNotEmpty ? value : null;
