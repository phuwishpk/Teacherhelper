import 'package:flutter/material.dart';

/// A student's status in one checked period (DESIGN §29.5).
enum AttendanceStatus {
  present('present', 'มา', 'มาตรงเวลา'),
  late('late', 'สาย', 'มาสาย'),
  absent('absent', 'ขาด', 'ขาด'),
  personalLeave('personal_leave', 'ลากิจ', 'ลากิจ'),
  sickLeave('sick_leave', 'ลาป่วย', 'ลาป่วย');

  const AttendanceStatus(this.wire, this.short, this.label);

  final String wire;

  /// On a chip.
  final String short;
  final String label;

  /// A leave is left out of the score.
  bool get counted => this == present || this == late || this == absent;

  static AttendanceStatus? parse(Object? wire) {
    for (final s in values) {
      if (s.wire == wire) return s;
    }
    return null;
  }

  Color color(ColorScheme scheme) => switch (this) {
    present => scheme.primary,
    late => scheme.tertiary,
    absent => scheme.error,
    personalLeave || sickLeave => scheme.secondary,
  };
}

/// How many records of each status.
class AttendanceCounts {
  const AttendanceCounts(this._byStatus);

  factory AttendanceCounts.fromJson(Object? json) {
    final map = json is Map ? json : const {};
    return AttendanceCounts({
      for (final s in AttendanceStatus.values)
        s: (map[s.wire] as num?)?.toInt() ?? 0,
    });
  }

  final Map<AttendanceStatus, int> _byStatus;

  int of(AttendanceStatus status) => _byStatus[status] ?? 0;

  int get total => _byStatus.values.fold(0, (a, b) => a + b);

  /// "มา 28 · สาย 1 · ขาด 1", statuses with a count only.
  String get summary => [
    for (final s in AttendanceStatus.values)
      if (of(s) > 0) '${s.short} ${of(s)}',
  ].join(' · ');
}

/// The value of each counted status, 0..1.
class AttendanceScores {
  const AttendanceScores({this.present = 1, this.late = 0.5, this.absent = 0});

  factory AttendanceScores.fromJson(Object? json) {
    final map = json is Map ? json : const {};
    return AttendanceScores(
      present: (map['present'] as num?)?.toDouble() ?? 1,
      late: (map['late'] as num?)?.toDouble() ?? 0.5,
      absent: (map['absent'] as num?)?.toDouble() ?? 0,
    );
  }

  final double present;
  final double late;
  final double absent;

  Map<String, dynamic> toJson() => {
    'present': present,
    'late': late,
    'absent': absent,
  };
}

/// "0.5" / "1": a status value or a score without trailing zeros.
String formatAttendanceNumber(double value) {
  final text = value.toStringAsFixed(2);
  return text.contains('.')
      ? text.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '')
      : text;
}

/// "85%" from a rate of 0..1; "–" without a counted record.
String formatAttendanceRate(double? rate) =>
    rate == null ? '–' : '${(rate * 100).round()}%';

/// "คาบ 2" or "" for a session without a period number.
String periodLabel(int? periodNo) => periodNo == null ? '' : 'คาบ $periodNo';

/// One student's status in a session; [status] null = not checked yet (the
/// student joined the classroom after the period).
class AttendanceEntry {
  const AttendanceEntry({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    required this.inClassroom,
    this.status,
    this.note,
  });

  factory AttendanceEntry.fromJson(Map<String, dynamic> json) =>
      AttendanceEntry(
        studentId: (json['student_id'] as num).toInt(),
        studentNumber: (json['student_number'] as num?)?.toInt(),
        name: json['name'] as String? ?? '',
        inClassroom: json['in_classroom'] as bool? ?? true,
        status: AttendanceStatus.parse(json['status']),
        note: json['note'] as String?,
      );

  final int studentId;
  final int? studentNumber;
  final String name;
  final bool inClassroom;
  final AttendanceStatus? status;
  final String? note;
}

/// A checked period of a course in a classroom.
class AttendanceSession {
  const AttendanceSession({
    required this.id,
    required this.courseId,
    required this.classroomId,
    required this.heldOn,
    required this.counts,
    this.periodNo,
    this.note,
    this.records = const [],
  });

  factory AttendanceSession.fromJson(Map<String, dynamic> json) =>
      AttendanceSession(
        id: (json['id'] as num).toInt(),
        courseId: (json['course_id'] as num).toInt(),
        classroomId: (json['classroom_id'] as num).toInt(),
        heldOn: parseDay(json['held_on'] as String),
        periodNo: (json['period_no'] as num?)?.toInt(),
        note: json['note'] as String?,
        counts: AttendanceCounts.fromJson(json['counts']),
        records: [
          for (final r in json['records'] as List? ?? const [])
            AttendanceEntry.fromJson(r as Map<String, dynamic>),
        ],
      );

  final int id;
  final int courseId;
  final int classroomId;

  /// A calendar day (local midnight), not an instant.
  final DateTime heldOn;
  final int? periodNo;
  final String? note;
  final AttendanceCounts counts;

  /// Filled by the session endpoints only, not by the history.
  final List<AttendanceEntry> records;
}

/// "2026-10-05" -> a local calendar day.
DateTime parseDay(String wire) {
  final parts = wire.split('-').map(int.parse).toList();
  return DateTime(parts[0], parts[1], parts[2]);
}

/// A local calendar day -> "2026-10-05".
String formatDay(DateTime day) =>
    '${day.year.toString().padLeft(4, '0')}-'
    '${day.month.toString().padLeft(2, '0')}-'
    '${day.day.toString().padLeft(2, '0')}';

/// One student's totals in a course and classroom.
class AttendanceStudentTotal {
  const AttendanceStudentTotal({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    required this.inClassroom,
    required this.counts,
    required this.counted,
    this.rate,
  });

  factory AttendanceStudentTotal.fromJson(Map<String, dynamic> json) =>
      AttendanceStudentTotal(
        studentId: (json['student_id'] as num).toInt(),
        studentNumber: (json['student_number'] as num?)?.toInt(),
        name: json['name'] as String? ?? '',
        inClassroom: json['in_classroom'] as bool? ?? true,
        counts: AttendanceCounts.fromJson(json['counts']),
        counted: (json['counted'] as num?)?.toInt() ?? 0,
        rate: (json['rate'] as num?)?.toDouble(),
      );

  final int studentId;
  final int? studentNumber;
  final String name;
  final bool inClassroom;
  final AttendanceCounts counts;

  /// Records that count toward [rate] (leaves do not).
  final int counted;

  /// 0..1, null without a counted record.
  final double? rate;
}

/// The "การเข้าเรียน" gradebook item written from the records.
class AttendanceAutoItem {
  const AttendanceAutoItem({
    required this.id,
    required this.maxPoints,
    this.categoryId,
  });

  final int id;
  final int? categoryId;
  final double maxPoints;
}

/// `GET /courses/{id}/attendance?classroom_id=`.
class AttendanceOverview {
  const AttendanceOverview({
    required this.scores,
    required this.sessions,
    required this.students,
    this.autoItem,
  });

  factory AttendanceOverview.fromJson(Map<String, dynamic> json) {
    final item = json['auto_item'] as Map<String, dynamic>?;
    return AttendanceOverview(
      scores: AttendanceScores.fromJson(json['scores']),
      autoItem: item == null
          ? null
          : AttendanceAutoItem(
              id: (item['id'] as num).toInt(),
              categoryId: (item['category_id'] as num?)?.toInt(),
              maxPoints: (item['max_points'] as num).toDouble(),
            ),
      sessions: [
        for (final s in json['sessions'] as List? ?? const [])
          AttendanceSession.fromJson(s as Map<String, dynamic>),
      ],
      students: [
        for (final s in json['students'] as List? ?? const [])
          AttendanceStudentTotal.fromJson(s as Map<String, dynamic>),
      ],
    );
  }

  final AttendanceScores scores;
  final AttendanceAutoItem? autoItem;

  /// Newest first.
  final List<AttendanceSession> sessions;
  final List<AttendanceStudentTotal> students;
}

/// What the session form sends.
class AttendanceDraft {
  const AttendanceDraft({
    required this.heldOn,
    required this.records,
    this.periodNo,
    this.note,
  });

  final DateTime heldOn;
  final int? periodNo;
  final String? note;
  final Map<int, ({AttendanceStatus status, String? note})> records;

  Map<String, dynamic> toJson({int? classroomId}) => {
    'classroom_id': ?classroomId,
    'held_on': formatDay(heldOn),
    'period_no': periodNo,
    'note': note,
    'records': [
      for (final e in records.entries)
        {
          'student_id': e.key,
          'status': e.value.status.wire,
          'note': e.value.note,
        },
    ],
  };
}

/// Student: their own status in one period.
class MyAttendanceEntry {
  const MyAttendanceEntry({
    required this.heldOn,
    required this.status,
    this.periodNo,
    this.note,
  });

  final DateTime heldOn;
  final int? periodNo;
  final AttendanceStatus status;
  final String? note;
}

/// Student: one course of `GET /student/attendance`.
class MyAttendanceCourse {
  const MyAttendanceCourse({
    required this.courseId,
    required this.courseTitle,
    required this.classroomName,
    required this.counts,
    required this.counted,
    required this.sessions,
    this.rate,
  });

  factory MyAttendanceCourse.fromJson(Map<String, dynamic> json) {
    final course = json['course'] as Map<String, dynamic>? ?? const {};
    final room = json['classroom'] as Map<String, dynamic>? ?? const {};
    return MyAttendanceCourse(
      courseId: (course['id'] as num?)?.toInt() ?? 0,
      courseTitle: [
        course['code'],
        course['name'],
      ].whereType<String>().where((s) => s.isNotEmpty).join(' '),
      classroomName: room['name'] as String? ?? '',
      counts: AttendanceCounts.fromJson(json['counts']),
      counted: (json['counted'] as num?)?.toInt() ?? 0,
      rate: (json['rate'] as num?)?.toDouble(),
      sessions: [
        for (final s in json['sessions'] as List? ?? const [])
          if (AttendanceStatus.parse((s as Map)['status']) case final status?)
            MyAttendanceEntry(
              heldOn: parseDay(s['held_on'] as String),
              periodNo: (s['period_no'] as num?)?.toInt(),
              status: status,
              note: s['note'] as String?,
            ),
      ],
    );
  }

  final int courseId;
  final String courseTitle;
  final String classroomName;
  final AttendanceCounts counts;
  final int counted;
  final double? rate;

  /// Newest first.
  final List<MyAttendanceEntry> sessions;
}
