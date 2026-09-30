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

  Classroom withGoogleLink(ClassroomGoogleLink? link) => Classroom(
    id: id,
    name: name,
    gradeLevel: gradeLevel,
    academicYear: academicYear,
    classCode: classCode,
    studentCount: studentCount,
    googleLink: link,
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
  );
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
    this.leftCourseAt,
    this.pinPending = false,
  });

  final int studentId;
  final int studentNumber;
  final String name;

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
    leftCourseAt: switch (json['left_course_at']) {
      String s => DateTime.tryParse(s),
      _ => null,
    },
    pinPending: json['pin_pending'] == true,
  );
}

/// A row of the `201` answer of `POST /classrooms/{id}/students`
/// (DESIGN §9.2): the new student plus the initial PIN. The server keeps only
/// the PIN's hash, so this is the ONLY time the app ever sees it.
class EnrolledStudent {
  const EnrolledStudent({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    required this.pin,
  });

  final int studentId;
  final int studentNumber;
  final String name;
  final String pin;

  factory EnrolledStudent.fromJson(Map<String, dynamic> json) =>
      EnrolledStudent(
        studentId: (json['student_id'] as num).toInt(),
        studentNumber: (json['student_number'] as num).toInt(),
        name: json['name'] as String,
        pin: json['pin'].toString(),
      );
}

/// Input row for `POST /classrooms/{id}/students`.
class NewStudent {
  const NewStudent({required this.studentNumber, required this.name});

  final int studentNumber;
  final String name;

  Map<String, dynamic> toJson() => {
    'student_number': studentNumber,
    'name': name,
  };

  @override
  bool operator ==(Object other) =>
      other is NewStudent &&
      other.studentNumber == studentNumber &&
      other.name == name;

  @override
  int get hashCode => Object.hash(studentNumber, name);

  @override
  String toString() => 'NewStudent($studentNumber, $name)';
}
