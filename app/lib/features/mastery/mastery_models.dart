import 'package:flutter/material.dart';

import '../assignments/question.dart';

double? _double(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

int? _int(Object? v) => switch (v) {
  num n => n.toInt(),
  String s => int.tryParse(s),
  _ => null,
};

/// A skill as embedded in Phase 6 payloads (`{id, code, name}`); falls back
/// to `skill_id` / `skill_code` / `skill_name` columns.
Skill skillFromJson(Map<String, dynamic> json) {
  final nested = json['skill'];
  if (nested is Map) {
    final s = nested.cast<String, dynamic>();
    return Skill(
      id: _int(s['id'])!,
      code: s['code'] as String? ?? '',
      name: s['name'] as String? ?? '',
      subjectId: _int(s['subject_id']),
      gradeLevel: _int(s['grade_level']),
    );
  }
  return Skill(
    id: _int(json['skill_id'] ?? json['id'])!,
    code: (json['skill_code'] ?? json['code'] ?? '') as String,
    name: (json['skill_name'] ?? json['name'] ?? '') as String,
  );
}

/// The level shown for a mastery value: the §11.7 cut points, and "ข้อมูลยัง
/// น้อย" while `n_obs < 2` (DESIGN §14.2).
enum MasteryLevel {
  good('เข้าใจดี', Icons.check_circle_outline),
  partial('เข้าใจบางส่วน', Icons.adjust),
  notYet('ยังไม่เข้าใจ', Icons.error_outline),
  tooLittle('ข้อมูลยังน้อย', Icons.hourglass_empty);

  const MasteryLevel(this.label, this.icon);

  final String label;

  /// Shown next to the color so a level never relies on color alone.
  final IconData icon;

  static const goodFrom = 0.75;
  static const partialFrom = 0.4;
  static const minObservations = 2;

  static MasteryLevel of(double value, int nObs) {
    if (nObs < minObservations) return tooLittle;
    if (value >= goodFrom) return good;
    if (value >= partialFrom) return partial;
    return notYet;
  }

  /// Same colors as the understanding levels of the review screens.
  Color color(BuildContext context) => switch (this) {
    good => Colors.green.shade700,
    partial => Colors.orange.shade800,
    notYet => Theme.of(context).colorScheme.error,
    tooLittle => Theme.of(context).colorScheme.outline,
  };
}

/// One (student, skill) mastery value (`mastery` table, DESIGN §8.5, §14.2).
class SkillMastery {
  const SkillMastery({
    required this.skill,
    required this.value,
    required this.nObs,
    this.updatedAt,
  });

  final Skill skill;

  /// EWMA of score ratios, 0–1.
  final double value;
  final int nObs;
  final DateTime? updatedAt;

  MasteryLevel get level => MasteryLevel.of(value, nObs);

  /// Row of `GET /student/mastery` or `GET /students/{id}/mastery`:
  /// `{skill: {id, code, name}, value, n_obs, updated_at}`.
  factory SkillMastery.fromJson(Map<String, dynamic> json) {
    final updated = json['updated_at'];
    return SkillMastery(
      skill: skillFromJson(json),
      value: (_double(json['value'] ?? json['mastery']) ?? 0).clamp(0.0, 1.0),
      nObs: _int(json['n_obs']) ?? 0,
      updatedAt: updated is String ? DateTime.tryParse(updated) : null,
    );
  }

  /// Weakest first; skills with too little data after the rest (§14.3
  /// "จุดอ่อนรายคน" = the three lowest).
  static List<SkillMastery> weakestFirst(Iterable<SkillMastery> rows) {
    final list = rows.toList()
      ..sort((a, b) {
        final aLittle = a.level == MasteryLevel.tooLittle;
        final bLittle = b.level == MasteryLevel.tooLittle;
        if (aLittle != bLittle) return aLittle ? 1 : -1;
        final byValue = a.value.compareTo(b.value);
        return byValue != 0 ? byValue : a.skill.code.compareTo(b.skill.code);
      });
    return list;
  }
}

/// Mastery of a student list, from `GET /student/mastery` (with the
/// placeholder `meta.available = false` until Phase 6 runs on the server).
class MasteryList {
  const MasteryList({required this.rows, this.available = true});

  final List<SkillMastery> rows;
  final bool available;
}

/// A student row of the classroom heatmap.
class MasteryStudent {
  const MasteryStudent({
    required this.id,
    required this.name,
    this.studentNumber,
  });

  final int id;
  final String name;
  final int? studentNumber;

  factory MasteryStudent.fromJson(Map<String, dynamic> json) => MasteryStudent(
    id: _int(json['id'] ?? json['student_id'])!,
    name: json['name'] as String? ?? '',
    studentNumber: _int(json['student_number']),
  );
}

/// A cell of the student x skill heatmap.
class MasteryCell {
  const MasteryCell({required this.value, required this.nObs});

  final double value;
  final int nObs;

  MasteryLevel get level => MasteryLevel.of(value, nObs);
}

/// `GET /classrooms/{id}/mastery` (DESIGN §9.6, §14.3 "Heatmap นักเรียน ×
/// ทักษะ"): `{skills: [...], students: [...], cells: [{student_id,
/// skill_id, value, n_obs}]}`.
class ClassroomMastery {
  const ClassroomMastery({
    required this.skills,
    required this.students,
    required this.cells,
  });

  final List<Skill> skills;
  final List<MasteryStudent> students;

  /// (student id, skill id) -> cell; a missing key = no observation yet.
  final Map<(int, int), MasteryCell> cells;

  MasteryCell? cell(int studentId, int skillId) => cells[(studentId, skillId)];

  /// Class mean per skill over the students with enough data.
  double? skillMean(int skillId) {
    final values = [
      for (final s in students)
        if (cells[(s.id, skillId)] case final c?
            when c.nObs >= MasteryLevel.minObservations)
          c.value,
    ];
    if (values.isEmpty) return null;
    return values.reduce((a, b) => a + b) / values.length;
  }

  factory ClassroomMastery.fromJson(Map<String, dynamic> json) {
    final skills = [
      for (final s in (json['skills'] as List? ?? const []))
        if (s is Map) skillFromJson({'skill': s}),
    ];
    final students =
        [
          for (final s in (json['students'] as List? ?? const []))
            if (s is Map) MasteryStudent.fromJson(s.cast<String, dynamic>()),
        ]..sort(
          (a, b) => (a.studentNumber ?? 1 << 20).compareTo(
            b.studentNumber ?? 1 << 20,
          ),
        );
    final cells = <(int, int), MasteryCell>{};
    for (final c in (json['cells'] as List? ?? const [])) {
      if (c is! Map) continue;
      final student = _int(c['student_id']);
      final skill = _int(c['skill_id']);
      if (student == null || skill == null) continue;
      cells[(student, skill)] = MasteryCell(
        value: (_double(c['value']) ?? 0).clamp(0.0, 1.0),
        nObs: _int(c['n_obs']) ?? 0,
      );
    }
    return ClassroomMastery(skills: skills, students: students, cells: cells);
  }
}

/// "82%" for a 0–1 value.
String percent(double v) => '${(v * 100).round()}%';
