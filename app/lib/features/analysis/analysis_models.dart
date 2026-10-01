import '../../core/api/teacher_guidance.dart';
import '../assignments/question.dart';

DateTime? _time(Object? v) => v is String ? DateTime.tryParse(v) : null;

double _double(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s) ?? 0,
  _ => 0,
};

int _int(Object? v) => switch (v) {
  num n => n.toInt(),
  String s => int.tryParse(s) ?? 0,
  _ => 0,
};

List<Map<String, dynamic>> _maps(Object? v) => [
  if (v is List)
    for (final e in v)
      if (e is Map) e.cast<String, dynamic>(),
];

Skill? _skillOf(Map<String, dynamic> json) => switch (json['skill']) {
  Map m => Skill.fromJson(m.cast<String, dynamic>()),
  _ => null,
};

/// `student_analyses.status` (DESIGN §20.6).
enum AnalysisStatus {
  /// Strengths and areas only; no text yet.
  computed('computed', 'ยังไม่มีข้อความ'),

  /// Sent in tonight's batch.
  queued('queued', 'รอรอบกลางคืน'),

  /// Gemini wrote the texts.
  drafted('drafted', 'มีข้อความแล้ว'),

  /// The last attempt to write the texts failed.
  failed('failed', 'เขียนข้อความไม่สำเร็จ');

  const AnalysisStatus(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static AnalysisStatus fromApi(Object? v) => values.firstWhere(
    (s) => s.apiValue == v,
    orElse: () => AnalysisStatus.computed,
  );
}

/// One strength or area: computed by code from mastery, never by AI
/// (DESIGN §20.5). [tooLittle] = `n_obs < 2` "ข้อมูลยังน้อย".
class AnalysisItem {
  const AnalysisItem({
    required this.skill,
    required this.value,
    required this.nObs,
    required this.tooLittle,
  });

  final Skill skill;

  /// Mastery 0–1.
  final double value;
  final int nObs;
  final bool tooLittle;

  static List<AnalysisItem> listFromJson(Object? json) => [
    for (final m in _maps(json))
      if (_skillOf(m) case final skill?)
        AnalysisItem(
          skill: skill,
          value: _double(m['value']),
          nObs: _int(m['n_obs']),
          tooLittle: m['too_little'] == true,
        ),
  ];
}

List<Skill> _nextSteps(Object? json) => [
  for (final m in _maps(json)) ?_skillOf(m),
];

/// The fields the classroom list and the teacher's payload share
/// (`StudentAnalysisPayload::summary`, DESIGN §20.5).
class AnalysisSummary {
  const AnalysisSummary({
    required this.id,
    required this.studentId,
    required this.classroomId,
    required this.status,
    required this.strengths,
    required this.areas,
    required this.hasText,
    required this.shared,
    required this.stale,
    required this.awaitingApproval,
    this.generatedVia,
    this.generatedAt,
    this.sharedAt,
  });

  final int id;
  final int studentId;
  final int classroomId;
  final AnalysisStatus status;
  final List<AnalysisItem> strengths;
  final List<AnalysisItem> areas;

  /// Gemini (or the teacher) wrote a text.
  final bool hasText;

  /// The student sees a text (approved now or earlier, or auto-shared).
  final bool shared;

  /// The texts were written from older mastery than the current one.
  final bool stale;

  /// The current student draft is not what the student sees yet.
  final bool awaitingApproval;

  /// `batch` (the nightly round) or `now` ("วิเคราะห์ตอนนี้").
  final String? generatedVia;
  final DateTime? generatedAt;
  final DateTime? sharedAt;

  factory AnalysisSummary.fromJson(Map<String, dynamic> json) =>
      AnalysisSummary(
        id: _int(json['id']),
        studentId: _int(json['student_id']),
        classroomId: _int(json['classroom_id']),
        status: AnalysisStatus.fromApi(json['status']),
        strengths: AnalysisItem.listFromJson(json['strengths']),
        areas: AnalysisItem.listFromJson(json['areas']),
        hasText: json['has_text'] == true,
        shared: json['shared'] == true,
        stale: json['stale'] == true,
        awaitingApproval: json['awaiting_approval'] == true,
        generatedVia: json['generated_via'] as String?,
        generatedAt: _time(json['generated_at']),
        sharedAt: _time(json['shared_at']),
      );
}

/// The teacher's payload of `GET /students/{id}/analysis` and of every
/// write (DESIGN §20.5): both texts, the next steps and what is shared.
class StudentAnalysis extends AnalysisSummary {
  const StudentAnalysis({
    required super.id,
    required super.studentId,
    required super.classroomId,
    required super.status,
    required super.strengths,
    required super.areas,
    required super.stale,
    required super.awaitingApproval,
    super.generatedVia,
    super.generatedAt,
    super.sharedAt,
    this.teacherText,
    this.studentText,
    this.nextSteps = const [],
    this.sharedStudentText,
    this.approvedBy,
    this.updatedAt,
    this.guidance,
  }) : super(
         hasText: teacherText != null || studentText != null,
         shared: sharedStudentText != null,
       );

  /// Direct: strengths, areas and next steps.
  final String? teacherText;

  /// The encouraging draft for the student (no "อ่อน").
  final String? studentText;

  /// Indicators with approved practice the texts point to.
  final List<Skill> nextSteps;

  /// What the student sees now; may be an older approved draft.
  final String? sharedStudentText;

  /// The teacher who approved; null with auto-share.
  final int? approvedBy;
  final DateTime? updatedAt;

  /// "คำแนะนำถึง AI" of the last "วิเคราะห์ตอนนี้" (DESIGN §21.12); the
  /// nightly batch writes null.
  final String? guidance;

  /// The student still sees an earlier approved text.
  bool get showsOlderShared =>
      sharedStudentText != null && sharedStudentText != studentText;

  factory StudentAnalysis.fromJson(Map<String, dynamic> json) =>
      StudentAnalysis(
        id: _int(json['id']),
        studentId: _int(json['student_id']),
        classroomId: _int(json['classroom_id']),
        status: AnalysisStatus.fromApi(json['status']),
        strengths: AnalysisItem.listFromJson(json['strengths']),
        areas: AnalysisItem.listFromJson(json['areas']),
        stale: json['stale'] == true,
        awaitingApproval: json['awaiting_approval'] == true,
        generatedVia: json['generated_via'] as String?,
        generatedAt: _time(json['generated_at']),
        sharedAt: _time(json['shared_at']),
        teacherText: json['teacher_text'] as String?,
        studentText: json['student_text'] as String?,
        nextSteps: _nextSteps(json['next_steps']),
        sharedStudentText: json['shared_student_text'] as String?,
        approvedBy: (json['approved_by'] as num?)?.toInt(),
        updatedAt: _time(json['updated_at']),
        guidance: normalizeGuidance(json['guidance'] as String?),
      );
}

/// One row of `GET /classrooms/{id}/analyses`.
class ClassroomAnalysisRow {
  const ClassroomAnalysisRow({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    this.analysis,
  });

  final int studentId;
  final int studentNumber;
  final String name;

  /// Null = nothing assessed in this classroom yet.
  final AnalysisSummary? analysis;

  factory ClassroomAnalysisRow.fromJson(Map<String, dynamic> json) {
    final student = (json['student'] as Map).cast<String, dynamic>();
    final analysis = json['analysis'];
    return ClassroomAnalysisRow(
      studentId: _int(student['id']),
      studentNumber: _int(student['student_number']),
      name: student['name'] as String? ?? '',
      analysis: analysis is Map
          ? AnalysisSummary.fromJson(analysis.cast<String, dynamic>())
          : null,
    );
  }
}

/// `GET /classrooms/{id}/analyses`: every student in student-number order.
class ClassroomAnalyses {
  const ClassroomAnalyses({
    required this.classroomId,
    required this.autoShare,
    required this.students,
  });

  final int classroomId;

  /// `classrooms.auto_share_analysis`.
  final bool autoShare;
  final List<ClassroomAnalysisRow> students;

  int get awaitingCount =>
      students.where((s) => s.analysis?.awaitingApproval ?? false).length;

  ClassroomAnalyses withAutoShare(bool value) => ClassroomAnalyses(
    classroomId: classroomId,
    autoShare: value,
    students: students,
  );

  factory ClassroomAnalyses.fromJson(Map<String, dynamic> json) =>
      ClassroomAnalyses(
        classroomId: _int(json['classroom_id']),
        autoShare: json['auto_share_analysis'] == true,
        students: [
          for (final m in _maps(json['students']))
            ClassroomAnalysisRow.fromJson(m),
        ],
      );
}

/// One row of the student's `GET /student/analysis`: only the text the
/// teacher shared, never the teacher's version, strengths or class data
/// (DESIGN §20.9).
class MyAnalysis {
  const MyAnalysis({
    required this.classroomId,
    required this.classroomName,
    required this.text,
    this.sharedAt,
    this.nextSteps = const [],
  });

  final int classroomId;
  final String classroomName;
  final String text;
  final DateTime? sharedAt;

  /// Indicators to practise next, each with approved practice.
  final List<Skill> nextSteps;

  factory MyAnalysis.fromJson(Map<String, dynamic> json) {
    final room = json['classroom'] is Map
        ? (json['classroom'] as Map).cast<String, dynamic>()
        : const <String, dynamic>{};
    return MyAnalysis(
      classroomId: _int(room['id']),
      classroomName: room['name'] as String? ?? '',
      text: json['text'] as String? ?? '',
      sharedAt: _time(json['shared_at']),
      nextSteps: _nextSteps(json['next_steps']),
    );
  }
}
