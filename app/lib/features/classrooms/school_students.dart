/// The students of the school as one list (DESIGN §24.4, §24.5): search
/// results, pairs that look like one child twice, and the merge preview.
library;

int _int(Object? v) => v is num ? v.toInt() : 0;

/// A classroom a student is in, with their number there.
class StudentClassroom {
  const StudentClassroom({
    required this.id,
    required this.name,
    required this.academicYear,
    required this.studentNumber,
    this.closed = false,
  });

  final int id;
  final String name;
  final int academicYear;
  final int studentNumber;

  /// A "ห้องเก่า" (DESIGN §24.6).
  final bool closed;

  /// e.g. "ป.5/2 ปี 2569 เลขที่ 12 (ห้องเก่า)".
  String get label =>
      '$name ปี $academicYear เลขที่ $studentNumber${closed ? ' (ห้องเก่า)' : ''}';

  factory StudentClassroom.fromJson(Map<String, dynamic> json) =>
      StudentClassroom(
        id: _int(json['id']),
        name: json['name'] as String? ?? '',
        academicYear: _int(json['academic_year']),
        studentNumber: _int(json['student_number']),
        closed: json['closed'] == true,
      );
}

List<StudentClassroom> _classrooms(Object? v) => [
  if (v is List)
    for (final c in v.whereType<Map>())
      StudentClassroom.fromJson(c.cast<String, dynamic>()),
];

/// One student of `GET /school-students` (DESIGN §24.4): never an email, a
/// PIN or a result.
class SchoolStudent {
  const SchoolStudent({
    required this.id,
    required this.name,
    this.studentCode,
    this.hasGoogle = false,
    this.classrooms = const [],
  });

  final int id;
  final String name;
  final String? studentCode;
  final bool hasGoogle;
  final List<StudentClassroom> classrooms;

  bool isIn(int classroomId) => classrooms.any((c) => c.id == classroomId);

  /// "เลขประจำตัว 65001 · ป.5/2 ปี 2569 เลขที่ 12".
  String get details => [
    if (studentCode case final code?) 'เลขประจำตัว $code',
    if (classrooms.isEmpty) 'ยังไม่อยู่ในห้องใด',
    for (final c in classrooms) c.label,
  ].join(' · ');

  factory SchoolStudent.fromJson(Map<String, dynamic> json) => SchoolStudent(
    id: _int(json['id']),
    name: json['name'] as String? ?? '',
    studentCode: json['student_code'] as String?,
    hasGoogle: json['has_google'] == true,
    classrooms: _classrooms(json['classrooms']),
  );
}

/// Why two accounts look like one child (`reasons` of
/// `GET /students/duplicate-candidates`).
String duplicateReasonLabel(String reason) => switch (reason) {
  'google_user' => 'บัญชี Google เดียวกัน',
  'email' => 'อีเมล Google เดียวกัน',
  'name' => 'ชื่อเดียวกัน',
  _ => reason,
};

/// A pair of accounts that may be one child (DESIGN §24.4): a suggestion
/// only, nothing is merged until the teacher does it.
class DuplicateCandidate {
  const DuplicateCandidate({
    required this.a,
    required this.b,
    this.reasons = const [],
  });

  final SchoolStudent a;
  final SchoolStudent b;
  final List<String> reasons;

  bool involves(Set<int> studentIds) =>
      studentIds.contains(a.id) || studentIds.contains(b.id);

  factory DuplicateCandidate.fromJson(Map<String, dynamic> json) =>
      DuplicateCandidate(
        a: SchoolStudent.fromJson((json['a'] as Map).cast<String, dynamic>()),
        b: SchoolStudent.fromJson((json['b'] as Map).cast<String, dynamic>()),
        reasons: [
          if (json['reasons'] is List)
            for (final r in json['reasons'] as List) r.toString(),
        ],
      );
}

/// One side of `GET /students/merge-preview` (DESIGN §24.5, §24.18).
class MergeAccount {
  const MergeAccount({
    required this.id,
    required this.name,
    this.studentCode,
    this.status = 'active',
    this.classrooms = const [],
    this.submissionsTotal = 0,
    this.submissionsPublished = 0,
    this.gradebookEntries = 0,
    this.specialGrades = 0,
    this.publishedGrades = 0,
    this.practiceAttempts = 0,
    this.observations = 0,
    this.masterySkills = 0,
    this.analyses = 0,
    this.googleEmails = const [],
  });

  final int id;
  final String name;
  final String? studentCode;
  final String status;
  final List<StudentClassroom> classrooms;
  final int submissionsTotal;
  final int submissionsPublished;
  final int gradebookEntries;
  final int specialGrades;
  final int publishedGrades;
  final int practiceAttempts;
  final int observations;
  final int masterySkills;
  final int analyses;
  final List<String> googleEmails;

  /// The figures the teacher compares, as (label, value) rows.
  List<(String, String)> get facts => [
    ('เลขประจำตัว', studentCode ?? '-'),
    ('งานที่ส่ง', '$submissionsTotal (เผยแพร่แล้ว $submissionsPublished)'),
    ('คะแนนในสมุดคะแนน', '$gradebookEntries'),
    ('ร/มส', '$specialGrades'),
    ('เกรดที่ประกาศ', '$publishedGrades'),
    ('แบบฝึก', '$practiceAttempts'),
    ('ผลประเมินทักษะ', '$observations ($masterySkills ทักษะ)'),
    ('การวิเคราะห์รายคน', '$analyses'),
    ('บัญชี Google', googleEmails.isEmpty ? '-' : googleEmails.join(', ')),
  ];

  factory MergeAccount.fromJson(Map<String, dynamic> json) {
    final submissions = json['submissions'] is Map
        ? (json['submissions'] as Map).cast<String, dynamic>()
        : const <String, dynamic>{};
    return MergeAccount(
      id: _int(json['id']),
      name: json['name'] as String? ?? '',
      studentCode: json['student_code'] as String?,
      status: json['status'] as String? ?? 'active',
      classrooms: _classrooms(json['classrooms']),
      submissionsTotal: _int(submissions['total']),
      submissionsPublished: _int(submissions['published']),
      gradebookEntries: _int(json['gradebook_entries']),
      specialGrades: _int(json['special_grades']),
      publishedGrades: _int(json['published_grades']),
      practiceAttempts: _int(json['practice_attempts']),
      observations: _int(json['observations']),
      masterySkills: _int(json['mastery_skills']),
      analyses: _int(json['analyses']),
      googleEmails: [
        if (json['google_emails'] is List)
          for (final e in json['google_emails'] as List) e.toString(),
      ],
    );
  }
}

/// Why two accounts cannot be merged (DESIGN §24.5).
class MergeConflict {
  const MergeConflict({required this.type, required this.message});

  final String type;
  final String message;

  factory MergeConflict.fromJson(Map<String, dynamic> json) => MergeConflict(
    type: json['type'] as String? ?? '',
    message: json['message'] as String? ?? '',
  );
}

/// `GET /students/merge-preview?keep_id=&merge_id=` (DESIGN §24.5).
class MergePreview {
  const MergePreview({
    required this.keep,
    required this.merge,
    this.conflicts = const [],
    required this.canMerge,
  });

  /// The account that stays (K).
  final MergeAccount keep;

  /// The account merged into [keep] and disabled (D).
  final MergeAccount merge;
  final List<MergeConflict> conflicts;
  final bool canMerge;

  factory MergePreview.fromJson(Map<String, dynamic> json) => MergePreview(
    keep: MergeAccount.fromJson((json['keep'] as Map).cast<String, dynamic>()),
    merge: MergeAccount.fromJson(
      (json['merge'] as Map).cast<String, dynamic>(),
    ),
    conflicts: [
      if (json['conflicts'] is List)
        for (final c in (json['conflicts'] as List).whereType<Map>())
          MergeConflict.fromJson(c.cast<String, dynamic>()),
    ],
    canMerge: json['can_merge'] == true,
  );
}
