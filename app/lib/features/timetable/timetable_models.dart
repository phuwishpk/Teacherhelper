/// The weekly timetable (DESIGN §29.12).
library;

const weekdayNames = [
  'จันทร์',
  'อังคาร',
  'พุธ',
  'พฤหัสบดี',
  'ศุกร์',
  'เสาร์',
  'อาทิตย์',
];

class TimetableSlot {
  const TimetableSlot({
    required this.id,
    required this.weekday,
    this.courseId,
    this.courseCode,
    this.courseName,
    this.classroomId,
    this.classroomName,
    this.periodNo,
    this.startsAt,
    this.endsAt,
    this.own = true,
    this.teacherName,
  });

  factory TimetableSlot.fromJson(Map<String, dynamic> json) {
    final course = json['course'] as Map<String, dynamic>?;
    final classroom = json['classroom'] as Map<String, dynamic>?;
    return TimetableSlot(
      id: json['id'] as int,
      weekday: (json['weekday'] as num).toInt(),
      courseId: course?['id'] as int?,
      courseCode: course?['code'] as String?,
      courseName: course?['name'] as String?,
      classroomId: classroom?['id'] as int?,
      classroomName: classroom?['name'] as String?,
      periodNo: (json['period_no'] as num?)?.toInt(),
      startsAt: json['starts_at'] as String?,
      endsAt: json['ends_at'] as String?,
      own: json['own'] != false,
      teacherName: json['teacher_name'] as String?,
    );
  }

  final int id;

  /// 1 = Monday … 7 = Sunday.
  final int weekday;
  final int? courseId;
  final String? courseCode;
  final String? courseName;
  final int? classroomId;
  final String? classroomName;
  final int? periodNo;

  /// "08:30".
  final String? startsAt;
  final String? endsAt;

  /// False for a busy block of a colleague in a shared classroom.
  final bool own;
  final String? teacherName;

  String get timeLabel => [?startsAt, ?endsAt].join('–');
}

class TimetableCancellation {
  const TimetableCancellation({
    required this.id,
    required this.slotId,
    required this.heldOn,
    this.note,
  });

  factory TimetableCancellation.fromJson(Map<String, dynamic> json) =>
      TimetableCancellation(
        id: json['id'] as int,
        slotId: json['slot_id'] as int,
        heldOn: DateTime.parse(json['held_on'] as String),
        note: json['note'] as String?,
      );

  final int id;
  final int slotId;
  final DateTime heldOn;
  final String? note;
}

class CourseTerm {
  const CourseTerm({
    required this.courseId,
    required this.code,
    this.startsOn,
    this.endsOn,
  });

  factory CourseTerm.fromJson(Map<String, dynamic> json) => CourseTerm(
    courseId: json['course_id'] as int,
    code: json['code'] as String? ?? '',
    startsOn: _date(json['term_starts_on']),
    endsOn: _date(json['term_ends_on']),
  );

  final int courseId;
  final String code;
  final DateTime? startsOn;
  final DateTime? endsOn;
}

DateTime? _date(Object? v) => v is String ? DateTime.tryParse(v) : null;

/// One week of the timetable (`GET /timetable`, `GET /student/timetable`).
class TimetableWeek {
  const TimetableWeek({
    required this.weekStart,
    this.slots = const [],
    this.busy = const [],
    this.cancellations = const [],
    this.terms = const [],
  });

  factory TimetableWeek.fromJson(Map<String, dynamic> json) => TimetableWeek(
    weekStart: DateTime.parse(json['week_start'] as String),
    slots: [
      for (final s in json['slots'] as List? ?? const [])
        TimetableSlot.fromJson(s as Map<String, dynamic>),
    ],
    busy: [
      for (final s in json['busy'] as List? ?? const [])
        TimetableSlot.fromJson(s as Map<String, dynamic>),
    ],
    cancellations: [
      for (final c in json['cancellations'] as List? ?? const [])
        TimetableCancellation.fromJson(c as Map<String, dynamic>),
    ],
    terms: [
      for (final t in json['terms'] as List? ?? const [])
        CourseTerm.fromJson(t as Map<String, dynamic>),
    ],
  );

  /// The Monday of the week.
  final DateTime weekStart;
  final List<TimetableSlot> slots;
  final List<TimetableSlot> busy;
  final List<TimetableCancellation> cancellations;
  final List<CourseTerm> terms;

  /// The date of a weekday (1–7) in this week.
  DateTime dateOf(int weekday) => weekStart.add(Duration(days: weekday - 1));

  bool isCancelled(TimetableSlot slot) => cancellations.any(
    (c) =>
        c.slotId == slot.id &&
        c.heldOn.year == dateOf(slot.weekday).year &&
        c.heldOn.month == dateOf(slot.weekday).month &&
        c.heldOn.day == dateOf(slot.weekday).day,
  );

  TimetableCancellation? cancellationOf(TimetableSlot slot) {
    for (final c in cancellations) {
      if (c.slotId == slot.id) return c;
    }
    return null;
  }

  /// The weekdays shown: Monday to Friday, plus a weekend day with a slot.
  List<int> get weekdays => [
    1,
    2,
    3,
    4,
    5,
    for (final d in [6, 7])
      if ([...slots, ...busy].any((s) => s.weekday == d)) d,
  ];

  /// The period numbers shown: 1 up to the highest in use (at least 8),
  /// then null for unnumbered slots.
  List<int?> get periods {
    var max = 8;
    var unnumbered = false;
    for (final s in [...slots, ...busy]) {
      if (s.periodNo == null) {
        unnumbered = true;
      } else if (s.periodNo! > max) {
        max = s.periodNo!;
      }
    }
    return [for (var i = 1; i <= max; i++) i, if (unnumbered) null];
  }
}

/// "13 ต.ค." of a date.
String shortThaiDate(DateTime d) =>
    '${d.day} ${const ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'][d.month - 1]}';

String isoDate(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';
