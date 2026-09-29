import '../../core/util/thai_date.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/question.dart';

int? _int(Object? v) => v is num ? v.toInt() : null;

String? _text(Object? v) => v is String && v.trim().isNotEmpty ? v : null;

List<Skill> _skills(Object? v) => [
  if (v is List)
    for (final s in v)
      if (s is Map) Skill.fromJson(s.cast<String, dynamic>()),
];

List<String> _codes(Object? v) => [
  if (v is List)
    for (final c in v)
      if (c is String && c.trim().isNotEmpty) c,
];

/// "ภาค 1", "ภาค 2" or "ทั้งปี" (`courses.semester` 0 = the whole year).
String semesterLabel(int semester) =>
    semester == 0 ? 'ทั้งปี' : 'ภาคเรียนที่ $semester';

/// A date as the API sends `taught_on`: `YYYY-MM-DD`, a calendar date
/// (no time zone).
DateTime? parseApiDate(Object? v) {
  if (v is! String || v.length < 10) return null;
  final d = DateTime.tryParse(v.substring(0, 10));
  return d == null ? null : DateTime(d.year, d.month, d.day);
}

String apiDate(DateTime d) =>
    '${d.year.toString().padLeft(4, '0')}-'
    '${d.month.toString().padLeft(2, '0')}-'
    '${d.day.toString().padLeft(2, '0')}';

/// A course's classroom as the course payload carries it.
class CourseClassroom {
  const CourseClassroom({required this.id, required this.name});

  final int id;
  final String name;
}

/// รายวิชา (DESIGN §20.1, §20.6 `courses`), as `GET /courses` lists it;
/// `GET /courses/{id}` adds [indicators], [units] and [lessonPlans].
class Course {
  const Course({
    required this.id,
    required this.code,
    required this.name,
    required this.subjectId,
    required this.gradeLevel,
    required this.academicYear,
    this.subjectName,
    this.subjectCode,
    this.semester = 0,
    this.hours,
    this.description,
    this.classrooms = const [],
    this.indicatorCount = 0,
    this.unitCount = 0,
    this.lessonPlanCount = 0,
    this.assignmentCount = 0,
    this.indicators = const [],
    this.units = const [],
    this.lessonPlans = const [],
  });

  final int id;
  final String code;
  final String name;

  /// กลุ่มสาระ: the subject every assignment of the course takes.
  final int subjectId;
  final String? subjectName;
  final String? subjectCode;

  /// ป.1 = 1 … ม.6 = 12.
  final int gradeLevel;

  /// 1, 2 or 0 = the whole year.
  final int semester;

  /// Buddhist-era year.
  final int academicYear;
  final int? hours;
  final String? description;
  final List<CourseClassroom> classrooms;
  final int indicatorCount;
  final int unitCount;
  final int lessonPlanCount;

  /// Assignments that use the course: it cannot be deleted while > 0.
  final int assignmentCount;
  final List<Skill> indicators;
  final List<CourseUnit> units;
  final List<LessonPlan> lessonPlans;

  List<int> get classroomIds => [for (final c in classrooms) c.id];

  /// "ค15101 คณิตศาสตร์ 5".
  String get title => '$code $name';

  /// "ป.5 · ภาคเรียนที่ 1 · 2569".
  String get termLabel =>
      '${gradeLevelLabel(gradeLevel)} · ${semesterLabel(semester)} · $academicYear';

  /// Lesson plans of [unitId] (null = plans outside any unit), in order.
  List<LessonPlan> plansOf(int? unitId) => [
    for (final p in lessonPlans)
      if (p.unitId == unitId) p,
  ]..sort((a, b) => a.position.compareTo(b.position));

  factory Course.fromJson(Map<String, dynamic> json) {
    final subject = json['subject'];
    return Course(
      id: (json['id'] as num).toInt(),
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      subjectId:
          _int(json['subject_id']) ??
          (subject is Map ? _int(subject['id']) : null) ??
          0,
      subjectName: subject is Map ? subject['name'] as String? : null,
      subjectCode: subject is Map ? subject['code'] as String? : null,
      gradeLevel: _int(json['grade_level']) ?? 1,
      semester: _int(json['semester']) ?? 0,
      academicYear: _int(json['academic_year']) ?? currentThaiYear(),
      hours: _int(json['hours']),
      description: _text(json['description']),
      classrooms: _classrooms(json),
      indicatorCount:
          _int(json['indicator_count']) ?? _skills(json['indicators']).length,
      unitCount: _int(json['unit_count']) ?? 0,
      lessonPlanCount: _int(json['lesson_plan_count']) ?? 0,
      assignmentCount: _int(json['assignment_count']) ?? 0,
      indicators: _skills(json['indicators']),
      units: [
        if (json['units'] is List)
          for (final u in json['units'] as List)
            if (u is Map) CourseUnit.fromJson(u.cast<String, dynamic>()),
      ]..sort((a, b) => a.position.compareTo(b.position)),
      lessonPlans: [
        if (json['lesson_plans'] is List)
          for (final p in json['lesson_plans'] as List)
            if (p is Map) LessonPlan.fromJson(p.cast<String, dynamic>()),
      ]..sort((a, b) => a.position.compareTo(b.position)),
    );
  }
}

List<CourseClassroom> _classrooms(Map<String, dynamic> json) {
  final rows = json['classrooms'];
  if (rows is List) {
    return [
      for (final c in rows)
        if (c is Map)
          CourseClassroom(
            id: _int(c['id']) ?? 0,
            name: c['name'] as String? ?? '',
          ),
    ];
  }
  final ids = json['classroom_ids'];
  return [
    if (ids is List)
      for (final id in ids)
        if (id is num) CourseClassroom(id: id.toInt(), name: 'ห้อง #$id'),
  ];
}

/// หน่วยการเรียนรู้ (`units`).
class CourseUnit {
  const CourseUnit({
    required this.id,
    required this.courseId,
    required this.position,
    required this.title,
    this.hours,
    this.description,
    this.indicators = const [],
  });

  final int id;
  final int courseId;
  final int position;
  final String title;
  final int? hours;
  final String? description;
  final List<Skill> indicators;

  /// "หน่วยที่ 2 เศษส่วน".
  String get label => 'หน่วยที่ $position $title';

  UnitDraft toDraft() => UnitDraft(
    title: title,
    hours: hours,
    description: description,
    indicators: indicators,
  );

  factory CourseUnit.fromJson(Map<String, dynamic> json) => CourseUnit(
    id: (json['id'] as num).toInt(),
    courseId: _int(json['course_id']) ?? 0,
    position: _int(json['position']) ?? 0,
    title: json['title'] as String? ?? '',
    hours: _int(json['hours']),
    description: _text(json['description']),
    indicators: _skills(json['indicators']),
  );
}

/// แผนการจัดการเรียนรู้ (`lesson_plans`).
class LessonPlan {
  const LessonPlan({
    required this.id,
    required this.courseId,
    required this.position,
    required this.title,
    this.unitId,
    this.hours,
    this.objectives,
    this.content,
    this.activities,
    this.assessment,
    this.taughtOn,
    this.indicators = const [],
  });

  final int id;
  final int courseId;
  final int? unitId;
  final int position;
  final String title;
  final int? hours;
  final String? objectives;
  final String? content;
  final String? activities;
  final String? assessment;

  /// Marked taught by the teacher (a calendar date, chart 5 of §20.4).
  final DateTime? taughtOn;
  final List<Skill> indicators;

  /// "แผนที่ 3 การบวกเศษส่วน".
  String get label => 'แผนที่ $position $title';

  PlanDraft toDraft() => PlanDraft(
    title: title,
    unit: unitId == null ? null : UnitRef.existing(unitId!),
    hours: hours,
    objectives: objectives,
    content: content,
    activities: activities,
    assessment: assessment,
    taughtOn: taughtOn,
    indicators: indicators,
  );

  factory LessonPlan.fromJson(Map<String, dynamic> json) => LessonPlan(
    id: (json['id'] as num).toInt(),
    courseId: _int(json['course_id']) ?? 0,
    unitId: _int(json['unit_id']),
    position: _int(json['position']) ?? 0,
    title: json['title'] as String? ?? '',
    hours: _int(json['hours']),
    objectives: _text(json['objectives']),
    content: _text(json['content']),
    activities: _text(json['activities']),
    assessment: _text(json['assessment']),
    taughtOn: parseApiDate(json['taught_on']),
    indicators: _skills(json['indicators']),
  );
}

/// The course fields of the form (`POST /courses`, `PATCH /courses/{id}`,
/// the `course` of `POST /courses/import`).
class CourseDraft {
  const CourseDraft({
    required this.code,
    required this.name,
    required this.subjectId,
    required this.gradeLevel,
    required this.academicYear,
    this.semester = 0,
    this.hours,
    this.description,
  });

  final String code;
  final String name;
  final int subjectId;
  final int gradeLevel;
  final int semester;
  final int academicYear;
  final int? hours;
  final String? description;

  Map<String, dynamic> toJson() => {
    'code': code,
    'name': name,
    'subject_id': subjectId,
    'grade_level': gradeLevel,
    'semester': semester,
    'academic_year': academicYear,
    'hours': hours,
    'description': description,
  };
}

/// A unit being created or edited (in the app or in an import).
class UnitDraft {
  const UnitDraft({
    required this.title,
    this.hours,
    this.description,
    this.indicators = const [],
    this.pendingCodes = const [],
  });

  final String title;
  final int? hours;
  final String? description;
  final List<Skill> indicators;

  /// Indicator codes read from a document that match no skill yet (import
  /// only): they are dropped unless the teacher resolves them.
  final List<String> pendingCodes;

  UnitDraft copyWith({List<Skill>? indicators, List<String>? pendingCodes}) =>
      UnitDraft(
        title: title,
        hours: hours,
        description: description,
        indicators: indicators ?? this.indicators,
        pendingCodes: pendingCodes ?? this.pendingCodes,
      );

  Map<String, dynamic> toJson() => {
    'title': title,
    'hours': hours,
    'description': description,
    'skill_ids': [for (final s in indicators) s.id],
  };
}

/// Which unit a lesson plan belongs to: an existing unit of the course
/// (`unit_id`) or a unit created in the same import (`unit_index`).
class UnitRef {
  const UnitRef.existing(int id) : unitId = id, draftIndex = null;

  const UnitRef.draft(int index) : unitId = null, draftIndex = index;

  final int? unitId;
  final int? draftIndex;

  @override
  bool operator ==(Object other) =>
      other is UnitRef &&
      other.unitId == unitId &&
      other.draftIndex == draftIndex;

  @override
  int get hashCode => Object.hash(unitId, draftIndex);
}

/// A lesson plan being created or edited (in the app or in an import).
class PlanDraft {
  const PlanDraft({
    required this.title,
    this.unit,
    this.hours,
    this.objectives,
    this.content,
    this.activities,
    this.assessment,
    this.taughtOn,
    this.indicators = const [],
    this.pendingCodes = const [],
  });

  final String title;
  final UnitRef? unit;
  final int? hours;
  final String? objectives;
  final String? content;
  final String? activities;
  final String? assessment;
  final DateTime? taughtOn;
  final List<Skill> indicators;

  /// See [UnitDraft.pendingCodes].
  final List<String> pendingCodes;

  PlanDraft copyWith({
    UnitRef? unit,
    bool clearUnit = false,
    DateTime? taughtOn,
    bool clearTaughtOn = false,
    List<Skill>? indicators,
    List<String>? pendingCodes,
  }) => PlanDraft(
    title: title,
    unit: clearUnit ? null : unit ?? this.unit,
    hours: hours,
    objectives: objectives,
    content: content,
    activities: activities,
    assessment: assessment,
    taughtOn: clearTaughtOn ? null : taughtOn ?? this.taughtOn,
    indicators: indicators ?? this.indicators,
    pendingCodes: pendingCodes ?? this.pendingCodes,
  );

  /// The body of `POST /courses/{id}/lesson-plans` and `PATCH
  /// /lesson-plans/{id}` (a unit made in an import is sent by the importer).
  Map<String, dynamic> toJson() => {
    'title': title,
    'unit_id': unit?.unitId,
    'hours': hours,
    'objectives': objectives,
    'content': content,
    'activities': activities,
    'assessment': assessment,
    'taught_on': taughtOn == null ? null : apiDate(taughtOn!),
    'skill_ids': [for (final s in indicators) s.id],
  };

  /// One row of `lesson_plans[]` of `POST /courses/import`.
  Map<String, dynamic> toImportJson() => {
    'title': title,
    if (unit?.draftIndex != null) 'unit_index': unit!.draftIndex,
    if (unit?.unitId != null) 'unit_id': unit!.unitId,
    'hours': hours,
    'objectives': objectives,
    'content': content,
    'activities': activities,
    'assessment': assessment,
    if (taughtOn != null) 'taught_on': apiDate(taughtOn!),
    'skill_ids': [for (final s in indicators) s.id],
  };
}

/// What a course document is read for (`purpose` of `POST
/// /courses/extract`).
enum CourseDocumentPurpose {
  /// คำอธิบายรายวิชา / โครงสร้างรายวิชา: a new course with its units.
  course('course', 'คำอธิบายหรือโครงสร้างรายวิชา'),

  /// แผนการสอน: lesson plans added to an existing course.
  lessonPlan('lesson_plan', 'แผนการจัดการเรียนรู้');

  const CourseDocumentPurpose(this.apiValue, this.label);

  final String apiValue;
  final String label;
}

/// `course` of a read document; every field may be missing.
class ReadCourse {
  const ReadCourse({
    this.code,
    this.name,
    this.subjectCode,
    this.gradeLevel,
    this.semester,
    this.academicYear,
    this.hours,
    this.description,
  });

  final String? code;
  final String? name;
  final String? subjectCode;
  final int? gradeLevel;
  final int? semester;
  final int? academicYear;
  final int? hours;
  final String? description;

  factory ReadCourse.fromJson(Map<String, dynamic> json) => ReadCourse(
    code: _text(json['code']),
    name: _text(json['name']),
    subjectCode: _text(json['subject_code']),
    gradeLevel: _int(json['grade_level']),
    semester: _int(json['semester']),
    academicYear: _int(json['academic_year']),
    hours: _int(json['hours']),
    description: _text(json['description']),
  );
}

class ReadIndicator {
  const ReadIndicator({required this.code, this.text});

  final String code;
  final String? text;
}

class ReadUnit {
  const ReadUnit({
    required this.position,
    required this.title,
    this.hours,
    this.description,
    this.indicatorCodes = const [],
  });

  final int position;
  final String title;
  final int? hours;
  final String? description;
  final List<String> indicatorCodes;
}

class ReadPlan {
  const ReadPlan({
    required this.position,
    required this.title,
    this.unitPosition,
    this.hours,
    this.objectives,
    this.content,
    this.activities,
    this.assessment,
    this.indicatorCodes = const [],
  });

  final int position;
  final int? unitPosition;
  final String title;
  final int? hours;
  final String? objectives;
  final String? content;
  final String? activities;
  final String? assessment;
  final List<String> indicatorCodes;
}

/// What Gemini read from a course document (`document_extractions.result`
/// of DESIGN §20.1). Nothing is saved until the teacher confirms it.
class CourseDocumentResult {
  const CourseDocumentResult({
    required this.kind,
    this.notesTh = '',
    this.course = const ReadCourse(),
    this.indicators = const [],
    this.units = const [],
    this.lessonPlans = const [],
  });

  final String kind;
  final String notesTh;
  final ReadCourse course;
  final List<ReadIndicator> indicators;
  final List<ReadUnit> units;
  final List<ReadPlan> lessonPlans;

  factory CourseDocumentResult.fromJson(Map<String, dynamic> json) {
    final course = json['course'];
    return CourseDocumentResult(
      kind: json['kind'] as String? ?? 'course',
      notesTh: json['notes_th'] as String? ?? '',
      course: course is Map
          ? ReadCourse.fromJson(course.cast<String, dynamic>())
          : const ReadCourse(),
      indicators: [
        if (json['indicators'] is List)
          for (final i in json['indicators'] as List)
            if (i is Map && _text(i['code']) != null)
              ReadIndicator(code: i['code'] as String, text: _text(i['text'])),
      ],
      units: [
        if (json['units'] is List)
          for (final u in json['units'] as List)
            if (u is Map)
              ReadUnit(
                position: _int(u['position']) ?? 0,
                title: u['title'] as String? ?? '',
                hours: _int(u['hours']),
                description: _text(u['description']),
                indicatorCodes: _codes(u['indicator_codes']),
              ),
      ],
      lessonPlans: [
        if (json['lesson_plans'] is List)
          for (final p in json['lesson_plans'] as List)
            if (p is Map)
              ReadPlan(
                position: _int(p['position']) ?? 0,
                unitPosition: _int(p['unit_position']),
                title: p['title'] as String? ?? '',
                hours: _int(p['hours']),
                objectives: _text(p['objectives']),
                content: _text(p['content']),
                activities: _text(p['activities']),
                assessment: _text(p['assessment']),
                indicatorCodes: _codes(p['indicator_codes']),
              ),
      ],
    );
  }
}

/// One read code and the indicator of the school it matched (or null).
class IndicatorMatch {
  const IndicatorMatch({required this.code, this.skill});

  final String code;
  final Skill? skill;
}

/// `POST /courses/extract` and `GET /document-extractions/{id}` for a
/// course or lesson-plan read.
class CourseExtraction {
  const CourseExtraction({
    required this.id,
    required this.status,
    this.error,
    this.cached = false,
    this.estimate,
    this.result,
    this.matches = const [],
  });

  final int id;

  /// `queued`, `done` or `failed`.
  final String status;
  final String? error;

  /// The school read the same files before: free, answered at once.
  final bool cached;
  final CostEstimate? estimate;
  final CourseDocumentResult? result;
  final List<IndicatorMatch> matches;

  bool get done => status == 'done' && result != null;

  bool get failed => status == 'failed';

  /// The matched skill of each read code.
  Map<String, Skill?> get matchMap => {
    for (final m in matches) m.code: m.skill,
  };

  /// Either `{cached, estimate, extraction: {...}, result, indicator_matches}`
  /// (extract) or `{id, status, error, result, indicator_matches}` (poll).
  factory CourseExtraction.fromJson(Map<String, dynamic> json) {
    final inner = json['extraction'] is Map
        ? (json['extraction'] as Map).cast<String, dynamic>()
        : json;
    final result = json['result'];
    return CourseExtraction(
      id: (inner['id'] as num).toInt(),
      status: inner['status'] as String? ?? 'queued',
      error: _text(inner['error']),
      cached: json['cached'] == true,
      estimate: CostEstimate.maybe(json['estimate']),
      result: result is Map
          ? CourseDocumentResult.fromJson(result.cast<String, dynamic>())
          : null,
      matches: [
        if (json['indicator_matches'] is List)
          for (final m in json['indicator_matches'] as List)
            if (m is Map && m['code'] is String)
              IndicatorMatch(
                code: m['code'] as String,
                skill: m['skill'] is Map
                    ? Skill.fromJson(
                        (m['skill'] as Map).cast<String, dynamic>(),
                      )
                    : null,
              ),
      ],
    );
  }
}
