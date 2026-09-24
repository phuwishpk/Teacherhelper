/// A classroom (DESIGN §8.1 classrooms).
class Classroom {
  const Classroom({
    required this.id,
    required this.name,
    required this.gradeLevel,
    required this.academicYear,
    required this.classCode,
    this.studentCount,
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

  factory Classroom.fromJson(Map<String, dynamic> json) => Classroom(
    id: (json['id'] as num).toInt(),
    name: json['name'] as String,
    gradeLevel: (json['grade_level'] as num).toInt(),
    academicYear: (json['academic_year'] as num).toInt(),
    classCode: json['class_code'] as String? ?? '',
    studentCount: (json['students_count'] ?? json['student_count']) is num
        ? ((json['students_count'] ?? json['student_count']) as num).toInt()
        : null,
  );
}

/// One roster row (DESIGN §8.1 classroom_students joined with users).
class RosterStudent {
  const RosterStudent({
    required this.studentId,
    required this.studentNumber,
    required this.name,
  });

  final int studentId;
  final int studentNumber;
  final String name;

  factory RosterStudent.fromJson(Map<String, dynamic> json) => RosterStudent(
    studentId: ((json['student_id'] ?? json['id']) as num).toInt(),
    studentNumber: (json['student_number'] as num).toInt(),
    name: json['name'] as String,
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
