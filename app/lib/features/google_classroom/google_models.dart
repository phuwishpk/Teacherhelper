import 'package:flutter/material.dart';

import '../classrooms/classroom.dart';
import '../classrooms/school_students.dart';
import 'google_config.dart';

/// `GET /google/status` (DESIGN §18.6).
class GoogleStatus {
  const GoogleStatus({
    required this.connected,
    this.email,
    this.scopes = const [],
    this.needsReconnect = false,
    this.reconnectMessage,
    this.configured = true,
  });

  static const disconnected = GoogleStatus(connected: false);

  final bool connected;
  final String? email;
  final List<String> scopes;

  /// The refresh token stopped working (`invalid_grant`: revoked, or the
  /// 7-day limit of an OAuth app in Testing mode, §18.5).
  final bool needsReconnect;

  /// Why [needsReconnect] is true, in Thai (`reconnect_message`, DESIGN
  /// §19.7), e.g. the account lacks the announcements scope; null from a
  /// server without the field.
  final String? reconnectMessage;

  /// The server has its Google OAuth client (GOOGLE_OAUTH_CLIENT_ID /
  /// SECRET). The app shows its Google Classroom UI only when true. An
  /// answer without the field (`POST /google/connect`) counts as true.
  final bool configured;

  /// Connected and usable right now.
  bool get ready => connected && !needsReconnect;

  /// Connected before the app asked for [announcementsScope]: results cannot
  /// reach the students in Classroom until the teacher connects again
  /// (Phase 8 build step 6). False when the server did not list the scopes.
  bool get lacksAnnouncementsScope =>
      connected && scopes.isNotEmpty && !scopes.contains(announcementsScope);

  /// The same status, marked as needing a new connection.
  GoogleStatus needingReconnect([String? message]) => GoogleStatus(
    connected: connected,
    email: email,
    scopes: scopes,
    needsReconnect: true,
    reconnectMessage: message ?? reconnectMessage,
    configured: configured,
  );

  factory GoogleStatus.fromJson(Map<String, dynamic> json) => GoogleStatus(
    // `POST /google/connect` answers only {email, scopes}.
    connected: json.containsKey('connected')
        ? json['connected'] == true
        : json['email'] != null,
    email: json['email'] as String?,
    scopes: _scopes(json['scopes']),
    needsReconnect: json['needs_reconnect'] == true,
    reconnectMessage: _nonEmpty(json['reconnect_message']),
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

/// How the server matched a Classroom account to a student the school
/// already has (`matched_by`, DESIGN §24.10 `SchoolStudentMatcher`).
enum ImportMatchKind {
  google('google', 'เข้าสู่ระบบแอปด้วยบัญชี Google นี้'),
  classroomUser('classroom_user', 'บัญชี Google เดียวกันในห้องอื่น'),
  email('email', 'อีเมล Google เดียวกัน'),
  name('name', 'ชื่อตรงกัน ตรวจให้แน่ใจว่าเป็นคนเดียวกัน');

  const ImportMatchKind(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static ImportMatchKind fromApi(Object? value) => values.firstWhere(
    (k) => k.apiValue == value,
    orElse: () => ImportMatchKind.name,
  );
}

/// `students[].match` of the import preview: the student of the school this
/// Classroom account is (DESIGN §24.10). Importing with it enrols that
/// account instead of creating another one.
class ImportMatch {
  const ImportMatch({
    required this.studentId,
    required this.name,
    required this.matchedBy,
    this.classes = const [],
  });

  final int studentId;
  final String name;
  final ImportMatchKind matchedBy;

  /// The rooms the student is in now (same shape as `GET /school-students`).
  final List<StudentClassroom> classes;

  /// "ป.4/2 ปี 2568 เลขที่ 4 (ห้องเก่า) · …", or "ยังไม่อยู่ในห้องใด".
  String get classesLabel => classes.isEmpty
      ? 'ยังไม่อยู่ในห้องใด'
      : classes.map((c) => c.label).join(' · ');

  static ImportMatch? maybe(Object? json) {
    if (json is! Map || json['student_id'] is! num) return null;
    return ImportMatch(
      studentId: (json['student_id'] as num).toInt(),
      name: (json['name'] ?? '') as String,
      matchedBy: ImportMatchKind.fromApi(json['matched_by']),
      classes: [
        if (json['classes'] case final List list)
          for (final c in list.whereType<Map>())
            StudentClassroom.fromJson(c.cast<String, dynamic>()),
      ],
    );
  }
}

/// One account of `GET /google/courses/{course_id}/import-preview`.
class ImportPreviewStudent {
  const ImportPreviewStudent({
    required this.googleUserId,
    required this.name,
    required this.proposedNumber,
    this.email,
    this.match,
  });

  final String googleUserId;
  final String name;
  final String? email;

  /// 1..N in Thai dictionary order (`ThaiNameSorter`, DESIGN §19.2).
  final int proposedNumber;

  /// The student of the school this account is, if the server found one
  /// (DESIGN §24.10).
  final ImportMatch? match;

  factory ImportPreviewStudent.fromJson(Map<String, dynamic> json) =>
      ImportPreviewStudent(
        googleUserId: json['google_user_id'].toString(),
        name: (json['name'] ?? '') as String,
        email: _nonEmpty(json['email']),
        proposedNumber: (json['proposed_number'] as num).toInt(),
        match: ImportMatch.maybe(json['match']),
      );
}

/// `suggested_classroom` of the import preview (DESIGN §24.10, §24.24): an
/// open room of the school that already holds most of the course's
/// students, offered before "สร้างห้องใหม่".
class SuggestedClassroom {
  const SuggestedClassroom({
    required this.id,
    required this.name,
    required this.academicYear,
    required this.coverage,
    required this.matched,
    required this.ownedByMe,
    this.homeroomTeacher,
  });

  final int id;
  final String name;
  final int academicYear;
  final TeacherRef? homeroomTeacher;

  /// Share of the course's accounts that are students of this room (0..1).
  final double coverage;

  /// How many of the course's accounts are students of this room.
  final int matched;

  /// The signed-in teacher is the room's homeroom teacher: linking needs
  /// nobody's approval.
  final bool ownedByMe;

  /// "85%".
  String get coverageLabel => '${(coverage * 100).round()}%';

  static SuggestedClassroom? maybe(Object? json) {
    if (json is! Map || json['id'] is! num) return null;
    return SuggestedClassroom(
      id: (json['id'] as num).toInt(),
      name: (json['name'] ?? '') as String,
      academicYear: (json['academic_year'] as num?)?.toInt() ?? 0,
      homeroomTeacher: TeacherRef.maybe(json['homeroom_teacher']),
      coverage: _double(json['coverage']) ?? 0,
      matched: (json['matched'] as num?)?.toInt() ?? 0,
      ownedByMe: json['owned_by_me'] == true,
    );
  }
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
    this.suggestedClassroom,
  });

  final String courseId;
  final String name;
  final String? section;
  final String suggestedName;

  /// An existing room to link the course to instead of creating one
  /// (DESIGN §24.10); null when none holds 70% of the course.
  final SuggestedClassroom? suggestedClassroom;

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
      suggestedClassroom: SuggestedClassroom.maybe(json['suggested_classroom']),
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
    this.existing = const {},
  });

  final String courseId;
  final String name;
  final int gradeLevel;
  final int academicYear;

  /// Google user id -> student number, for every account kept.
  final Map<String, int> numbers;

  /// Accounts the teacher took out (kept out of later roster syncs).
  final List<String> removed;

  /// Google user id -> the existing student of the school to enrol for it
  /// (DESIGN §24.10): no new account and no new PIN.
  final Map<String, int> existing;

  Map<String, dynamic> toJson() => {
    'course_id': courseId,
    'name': name,
    'grade_level': gradeLevel,
    'academic_year': academicYear,
    'students': [
      for (final e in numbers.entries)
        {
          'google_user_id': e.key,
          'student_number': e.value,
          'student_id': ?existing[e.key],
        },
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

/// The answer of `POST /google/courses/{course_id}/link-existing`
/// (DESIGN §24.10, §24.24).
sealed class LinkExistingResult {
  const LinkExistingResult();
}

/// 200: the course is linked to the room and its roster was synced.
class LinkedExisting extends LinkExistingResult {
  const LinkedExisting({
    required this.classroom,
    this.roster,
    this.rosterError,
  });

  final Classroom classroom;

  /// What the roster sync after linking changed; null when it failed.
  final RosterSyncResult? roster;

  /// Why that sync failed (`roster_error.message`); the link stays and
  /// "ซิงก์รายชื่อ" tries again.
  final String? rosterError;
}

/// 202: a course request (`origin = classroom_import`) waits for the
/// homeroom teacher, who links the course on approval.
class LinkRequested extends LinkExistingResult {
  const LinkRequested({this.requestId});

  final int? requestId;
}

/// A Classroom account a subject teacher's sync found that is not a student
/// of the room (`not_in_classroom`, DESIGN §24.24).
class NotInClassroomAccount {
  const NotInClassroomAccount({required this.name, this.email});

  final String name;
  final String? email;

  factory NotInClassroomAccount.fromJson(Map<String, dynamic> json) =>
      NotInClassroomAccount(
        name: (json['name'] ?? '') as String,
        email: _nonEmpty(json['email']),
      );
}

/// `POST /classrooms/{id}/google-roster/sync` (DESIGN §19.2, §24.24).
class RosterSyncResult {
  const RosterSyncResult({
    this.added = const [],
    this.left = const [],
    this.rematched = const [],
    this.enrolled = const [],
    this.notInClassroom = const [],
  });

  /// New accounts appended to the room, with their one-time PINs.
  final List<EnrolledStudent> added;

  /// Students whose account left the course (kept, match cleared).
  final List<RosterStudent> left;

  /// Existing students matched to an account by e-mail or full name.
  final List<RosterStudent> rematched;

  /// Students the school already had who joined the room with their own
  /// account; a PIN only for one who never had a way to sign in.
  final List<EnrolledStudent> enrolled;

  /// Accounts of a subject teacher's course that are not students of the
  /// room: the homeroom teacher adds them.
  final List<NotInClassroomAccount> notInClassroom;

  /// Every row that comes with a one-time PIN.
  List<EnrolledStudent> get withPins => [
    for (final s in [...added, ...enrolled])
      if (s.hasPin) s,
  ];

  bool get unchanged =>
      added.isEmpty &&
      left.isEmpty &&
      rematched.isEmpty &&
      enrolled.isEmpty &&
      notInClassroom.isEmpty;

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
      enrolled: rows('enrolled').map(EnrolledStudent.fromJson).toList(),
      notInClassroom: rows(
        'not_in_classroom',
      ).map(NotInClassroomAccount.fromJson).toList(),
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
    this.pushedGrade,
    this.classroomGrade,
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

  /// The grade the app sent to Classroom last (app courseWork only).
  final double? pushedGrade;

  /// Classroom's `assignedGrade` at the last sync; null = empty there.
  final double? classroomGrade;

  /// The assignment refused it as late; the teacher may take it anyway.
  bool get canAcceptLate => state == SubmissionImportState.rejectedLate;

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
      pushedGrade: _double(json['pushed_grade']),
      classroomGrade: _double(json['classroom_grade']),
    );
  }
}

/// `grade_conflicts.status` (DESIGN §19.8).
enum GradeConflictStatus {
  open('open', 'ยังไม่ได้เลือก'),
  pushedApp('pushed_app', 'ส่งคะแนนจากแอปแล้ว'),
  acceptedClassroom('accepted_classroom', 'ใช้คะแนนจาก Classroom แล้ว'),
  dismissed('dismissed', 'ไม่สนใจแล้ว');

  const GradeConflictStatus(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static GradeConflictStatus fromApi(Object? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => GradeConflictStatus.open,
  );
}

/// How the teacher settles one "คะแนนไม่ตรงกัน" row
/// (`POST /grade-conflicts/{id}/resolve`, DESIGN §19.3).
enum GradeConflictAction {
  /// Set Classroom's grade to the app's effective total (app courseWork).
  pushApp('push_app'),

  /// Take Classroom's grade as the submission's total (`total_override`).
  acceptClassroom('accept_classroom'),

  /// Change neither side; the row stays settled until a score changes.
  dismiss('dismiss');

  const GradeConflictAction(this.apiValue);

  final String apiValue;
}

/// One row of `GET /assignments/{id}/grade-conflicts`: the teacher changed
/// a grade on the Classroom website, so it no longer matches the app.
class GradeConflict {
  const GradeConflict({
    required this.id,
    required this.submissionId,
    required this.status,
    this.importId,
    this.student,
    this.appScore,
    this.classroomScore,
    this.reason,
    this.detectedAt,
    this.resolvedAt,
    this.canPushApp = true,
    this.alternateLink,
  });

  final int id;
  final int submissionId;
  final int? importId;
  final SubmissionStudent? student;

  /// The app's effective total when the difference was found or settled.
  final double? appScore;
  final double? classroomScore;
  final GradeConflictStatus status;

  /// 'รับคะแนนจาก Classroom' once accepted.
  final String? reason;
  final DateTime? detectedAt;
  final DateTime? resolvedAt;

  /// False for courseWork created on the Classroom website: the app
  /// cannot set its grades (409 `coursework_not_owned`).
  final bool canPushApp;

  /// The student's hand-in in the Classroom web app.
  final String? alternateLink;

  bool get isOpen => status == GradeConflictStatus.open;

  String get studentLabel => student?.label ?? 'นักเรียน';

  factory GradeConflict.fromJson(Map<String, dynamic> json) {
    final student = json['student'];
    DateTime? time(String key) => switch (json[key]) {
      String s => DateTime.tryParse(s),
      _ => null,
    };
    return GradeConflict(
      id: (json['id'] as num).toInt(),
      submissionId: (json['submission_id'] as num).toInt(),
      importId: (json['import_id'] as num?)?.toInt(),
      student: student is Map
          ? SubmissionStudent(
              id: (student['id'] as num).toInt(),
              name: (student['name'] ?? '') as String,
              studentNumber: (student['student_number'] as num?)?.toInt(),
            )
          : null,
      appScore: _double(json['app_score']),
      classroomScore: _double(json['classroom_score']),
      status: GradeConflictStatus.fromApi(json['status']),
      reason: _nonEmpty(json['reason']),
      detectedAt: time('detected_at'),
      resolvedAt: time('resolved_at'),
      canPushApp: json['can_push_app'] != false,
      alternateLink: _nonEmpty(json['alternate_link']),
    );
  }
}

/// `classroom_feedback_posts.state` (DESIGN §19.7, §19.8): the private
/// announcement with a student's result.
enum FeedbackPostState {
  queued('queued', 'รอส่ง'),
  posted('posted', 'ส่งแล้ว'),
  failed('failed', 'ส่งไม่สำเร็จ');

  const FeedbackPostState(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static FeedbackPostState fromApi(Object? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => FeedbackPostState.queued,
  );

  Color color(ColorScheme scheme) => switch (this) {
    queued => scheme.primary,
    posted => Colors.green.shade700,
    failed => scheme.error,
  };
}

/// One row of `GET /assignments/{id}/google-feedback`: the announcement of
/// a student's latest publish (DESIGN §19.9).
class ClassroomFeedbackPost {
  const ClassroomFeedbackPost({
    required this.id,
    required this.submissionId,
    required this.state,
    this.student,
    this.publishedAt,
    this.lastError,
    this.postedAt,
    this.announcementId,
  });

  final int id;
  final int submissionId;
  final FeedbackPostState state;
  final SubmissionStudent? student;
  final DateTime? publishedAt;

  /// Why it failed, in Thai (student not matched, account to reconnect).
  final String? lastError;
  final DateTime? postedAt;
  final String? announcementId;

  bool get failed => state == FeedbackPostState.failed;

  String get studentLabel => student?.label ?? 'นักเรียน';

  factory ClassroomFeedbackPost.fromJson(Map<String, dynamic> json) {
    final student = json['student'];
    DateTime? time(String key) => switch (json[key]) {
      String s => DateTime.tryParse(s),
      _ => null,
    };
    return ClassroomFeedbackPost(
      id: (json['id'] as num).toInt(),
      submissionId: (json['submission_id'] as num).toInt(),
      state: FeedbackPostState.fromApi(json['state']),
      student: student is Map && student['id'] is num
          ? SubmissionStudent(
              id: (student['id'] as num).toInt(),
              name: (student['name'] ?? '') as String,
              studentNumber: (student['student_number'] as num?)?.toInt(),
            )
          : null,
      publishedAt: time('published_at'),
      lastError: _nonEmpty(json['last_error']),
      postedAt: time('posted_at'),
      announcementId: _nonEmpty(json['announcement_id']?.toString()),
    );
  }
}

/// Scores are DECIMAL columns: Laravel may send them as strings ("7.50").
double? _double(Object? value) => switch (value) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

/// A score as the teacher reads it: no ".0", at most two decimals.
String formatScore(double value) => value == value.roundToDouble()
    ? value.toInt().toString()
    : value.toStringAsFixed(2).replaceFirst(RegExp(r'0$'), '');

String? _nonEmpty(Object? value) =>
    value is String && value.trim().isNotEmpty ? value : null;
