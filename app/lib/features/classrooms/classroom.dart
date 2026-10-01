/// A classroom (DESIGN §8.1 classrooms).
class Classroom {
  const Classroom({
    required this.id,
    required this.name,
    required this.gradeLevel,
    required this.academicYear,
    required this.classCode,
    this.studentCount,
    this.googleLink,
    this.closedAt,
    this.myRole = ClassroomRole.homeroom,
    this.homeroomTeacher,
  });

  final int id;
  final String name;

  /// ป.1 = 1 … ม.6 = 12.
  final int gradeLevel;

  /// Buddhist-era year, e.g. 2569.
  final int academicYear;

  /// 6-character code students type for PIN login.
  final String classCode;
  final int? studentCount;

  /// The Google Classroom course this room is linked to (DESIGN §18.4
  /// `classroom_google_links`), when the server includes `google_link`.
  final ClassroomGoogleLink? googleLink;

  /// Set once the room was closed: a "ห้องเก่า", read-only (DESIGN §24.6).
  final DateTime? closedAt;

  /// The signed-in teacher's role in this room (DESIGN §24.8 `my_role`).
  final ClassroomRole myRole;

  /// The owner of the room (DESIGN §24.20 `homeroom_teacher`), shown on a
  /// room the signed-in teacher teaches as a subject teacher.
  final TeacherRef? homeroomTeacher;

  bool get isClosed => closedAt != null;

  bool get isHomeroom => myRole == ClassroomRole.homeroom;

  /// A shared homeroom the teacher teaches an own course in (§24.7): the
  /// roster is read-only and only their own course's results show.
  bool get isSubject => myRole == ClassroomRole.subject;

  /// The homeroom teacher of an open room edits the roster and the
  /// students' data (DESIGN §24.2, §24.8).
  bool get canManageStudents => isHomeroom && !isClosed;

  Classroom withGoogleLink(ClassroomGoogleLink? link) => Classroom(
    id: id,
    name: name,
    gradeLevel: gradeLevel,
    academicYear: academicYear,
    classCode: classCode,
    studentCount: studentCount,
    googleLink: link,
    closedAt: closedAt,
    myRole: myRole,
    homeroomTeacher: homeroomTeacher,
  );

  factory Classroom.fromJson(Map<String, dynamic> json) => Classroom(
    id: (json['id'] as num).toInt(),
    name: json['name'] as String,
    gradeLevel: (json['grade_level'] as num).toInt(),
    academicYear: (json['academic_year'] as num).toInt(),
    classCode: json['class_code'] as String? ?? '',
    studentCount: (json['students_count'] ?? json['student_count']) is num
        ? ((json['students_count'] ?? json['student_count']) as num).toInt()
        : null,
    googleLink: json['google_link'] is Map
        ? ClassroomGoogleLink.fromJson(
            (json['google_link'] as Map).cast<String, dynamic>(),
          )
        : null,
    closedAt: switch (json['closed_at']) {
      String s => DateTime.tryParse(s),
      _ => null,
    },
    myRole: json['my_role'] == 'subject'
        ? ClassroomRole.subject
        : ClassroomRole.homeroom,
    homeroomTeacher: TeacherRef.maybe(json['homeroom_teacher']),
  );
}

/// `{id, name}` of a teacher as the shared-homeroom payloads carry it
/// (DESIGN §24.12 B, §24.20).
class TeacherRef {
  const TeacherRef({required this.id, required this.name});

  final int id;
  final String name;

  /// Null unless [json] is a `{id, name}` map.
  static TeacherRef? maybe(Object? json) => json is Map && json['id'] is num
      ? TeacherRef(
          id: (json['id'] as num).toInt(),
          name: json['name'] as String? ?? '',
        )
      : null;
}

/// `my_role` of a classroom (DESIGN §24.2): the homeroom teacher owns the
/// room, a subject teacher teaches a course in it.
enum ClassroomRole {
  homeroom('ครูประจำชั้น'),
  subject('ครูประจำวิชา');

  const ClassroomRole(this.label);

  final String label;
}

/// `google_link` of a classroom and the answer of
/// `POST /classrooms/{id}/google-link` (DESIGN §18.4, §18.6).
class ClassroomGoogleLink {
  const ClassroomGoogleLink({
    required this.courseId,
    required this.courseName,
    this.linkedAt,
    this.rosterSyncedAt,
    this.workSyncedAt,
  });

  final String courseId;
  final String courseName;
  final DateTime? linkedAt;

  /// The last "ซิงก์รายชื่อ" (DESIGN §19.2).
  final DateTime? rosterSyncedAt;

  /// The last sync round of work, hand-ins and grades: every 5 minutes by
  /// the cron, or "ซิงก์ตอนนี้" (DESIGN §19.3).
  final DateTime? workSyncedAt;

  factory ClassroomGoogleLink.fromJson(Map<String, dynamic> json) {
    DateTime? time(String key) => switch (json[key]) {
      String s => DateTime.tryParse(s),
      _ => null,
    };
    return ClassroomGoogleLink(
      courseId: json['course_id'].toString(),
      courseName: (json['course_name'] ?? json['name'] ?? '') as String,
      linkedAt: time('linked_at'),
      rosterSyncedAt: time('roster_synced_at'),
      workSyncedAt: time('work_synced_at'),
    );
  }
}

/// One roster row (DESIGN §8.1 classroom_students joined with users).
class RosterStudent {
  const RosterStudent({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    this.studentCode,
    this.leftCourseAt,
    this.pinPending = false,
    this.googleLinked,
  });

  final int studentId;
  final int studentNumber;
  final String name;

  /// A Google account for sign-in is linked (DESIGN §24.9, §24.22); null
  /// for a subject teacher, who does not see it.
  final bool? googleLinked;

  /// เลขประจำตัวนักเรียน of the school (DESIGN §24.4), if known.
  final String? studentCode;

  /// Added by the background roster sync (DESIGN §19.2): nobody has seen
  /// the student's first PIN yet, shown as "ยังไม่ได้รับ PIN".
  final bool pinPending;

  /// When the student's Google account left the linked course (DESIGN
  /// §19.2): the student stays, shown as "ไม่อยู่ใน Classroom แล้ว".
  final DateTime? leftCourseAt;

  bool get leftCourse => leftCourseAt != null;

  factory RosterStudent.fromJson(Map<String, dynamic> json) => RosterStudent(
    studentId: ((json['student_id'] ?? json['id']) as num).toInt(),
    studentNumber: (json['student_number'] as num).toInt(),
    name: json['name'] as String,
    studentCode: json['student_code'] as String?,
    leftCourseAt: switch (json['left_course_at']) {
      String s => DateTime.tryParse(s),
      _ => null,
    },
    pinPending: json['pin_pending'] == true,
    googleLinked: json['google_linked'] as bool?,
  );
}

/// A row of the `201` answer of `POST /classrooms/{id}/students`
/// (DESIGN §9.2, §24.4): the student plus the initial PIN. The server keeps
/// only the PIN's hash, so this is the ONLY time the app ever sees it. An
/// existing student keeps their PIN ([existing], [pin] empty) unless the
/// teacher asked for a new one.
class EnrolledStudent {
  const EnrolledStudent({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    required this.pin,
    this.existing = false,
  });

  final int studentId;
  final int studentNumber;
  final String name;

  /// Empty when no PIN was issued (an existing student keeping theirs).
  final String pin;
  final bool existing;

  bool get hasPin => pin.isNotEmpty;

  factory EnrolledStudent.fromJson(Map<String, dynamic> json) =>
      EnrolledStudent(
        studentId: (json['student_id'] as num).toInt(),
        studentNumber: (json['student_number'] as num).toInt(),
        name: json['name'] as String,
        pin: switch (json['pin']) {
          null => '',
          final Object pin => pin.toString(),
        },
        existing: json['existing'] == true,
      );
}

/// A row of `POST /classrooms/{id}/students` (DESIGN §24.4): a new student
/// account or a student of the school who already has one.
sealed class StudentEnrolment {
  const StudentEnrolment();

  int get studentNumber;

  Map<String, dynamic> toJson();
}

/// A new student: the server creates the account and issues a PIN.
class NewStudent extends StudentEnrolment {
  const NewStudent({
    required this.studentNumber,
    required this.name,
    this.studentCode,
  });

  @override
  final int studentNumber;
  final String name;

  /// เลขประจำตัวนักเรียน (optional, DESIGN §24.4).
  final String? studentCode;

  @override
  Map<String, dynamic> toJson() => {
    'student_number': studentNumber,
    'name': name,
    'student_code': ?studentCode,
  };

  @override
  bool operator ==(Object other) =>
      other is NewStudent &&
      other.studentNumber == studentNumber &&
      other.name == name &&
      other.studentCode == studentCode;

  @override
  int get hashCode => Object.hash(studentNumber, name, studentCode);

  @override
  String toString() => 'NewStudent($studentNumber, $name, $studentCode)';
}

/// A student of the school joining this room with their account, PIN and
/// QR card; [reissuePin] gives them a new PIN (the old one stops working).
class ExistingStudentEnrolment extends StudentEnrolment {
  const ExistingStudentEnrolment({
    required this.studentId,
    required this.studentNumber,
    this.reissuePin = false,
  });

  final int studentId;
  @override
  final int studentNumber;
  final bool reissuePin;

  @override
  Map<String, dynamic> toJson() => {
    'student_id': studentId,
    'student_number': studentNumber,
    if (reissuePin) 'reissue_pin': true,
  };

  @override
  bool operator ==(Object other) =>
      other is ExistingStudentEnrolment &&
      other.studentId == studentId &&
      other.studentNumber == studentNumber &&
      other.reissuePin == reissuePin;

  @override
  int get hashCode => Object.hash(studentId, studentNumber, reissuePin);

  @override
  String toString() =>
      'ExistingStudentEnrolment($studentId, $studentNumber, $reissuePin)';
}
