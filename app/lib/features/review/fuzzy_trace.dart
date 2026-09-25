import 'package:flutter/material.dart';

import 'review_labels.dart';

/// One rule of a fuzzy evaluation as stored in `responses.fuzzy_trace`
/// (DESIGN §11.1: input, membership and the weight of every rule).
class TraceRule {
  const TraceRule({
    required this.name,
    required this.weight,
    this.scoreZ,
    this.uZ,
    this.pZ,
    this.note,
  });

  final String name;
  final double weight;
  final double? scoreZ;
  final double? uZ;
  final double? pZ;
  final String? note;

  /// Thai reading of the rule for the teacher (§11.3–§11.5, §11.8).
  String get description =>
      _ruleDescriptions[name] ??
      (note == null || note!.isEmpty ? 'กฎ $name' : note!);
}

/// One fuzzy system's part of the trace.
class TraceSection {
  const TraceSection({
    this.inputs = const {},
    this.rules = const [],
    this.outputs = const {},
    this.degenerate = false,
  });

  final Map<String, double> inputs;
  final List<TraceRule> rules;
  final Map<String, double> outputs;
  final bool degenerate;

  /// Rules that fired (weight > 0), strongest first.
  List<TraceRule> get fired =>
      rules.where((r) => r.weight > 0).toList()
        ..sort((a, b) => b.weight.compareTo(a.weight));
}

/// `fuzzy_trace` split into scoring (system 1) and review priority
/// (system 2). Unknown shapes degrade to an empty trace instead of failing.
class FuzzyTrace {
  const FuzzyTrace({
    this.scoring,
    this.priority,
    this.method,
    this.suspicious = false,
    this.mcqFilled = const [],
    this.mcqCorrect,
  });

  final TraceSection? scoring;
  final TraceSection? priority;

  /// Set when the score did not come from fuzzy rules (`mcq`, `blank`).
  final String? method;
  final bool suspicious;

  /// mcq (§11.6): the options counted as filled and the key.
  final List<String> mcqFilled;
  final String? mcqCorrect;

  bool get isEmpty => scoring == null && priority == null && method == null;

  /// Accepts the ml/fuzzy `to_dict()` shape (`inputs`, `rules[{name,
  /// weight, then, note}]`, `outputs`) and the backend result wrappers that
  /// nest it under `trace` (GradeResult / PriorityResult `toArray()`), either
  /// split as `{scoring|grading|score, priority|review_priority}` or as one
  /// bare section.
  static FuzzyTrace parse(Object? json) {
    if (json is! Map) return const FuzzyTrace();
    final m = json.cast<String, dynamic>();
    Map<String, dynamic>? pick(List<String> keys) {
      for (final k in keys) {
        if (m[k] case final Map inner) return inner.cast<String, dynamic>();
      }
      return null;
    }

    final isPriority = m['system'] == 'review_priority';
    final bareSection = m['rules'] is List || m['trace'] is Map;
    final scoring =
        pick(['scoring', 'grading', 'grade', 'score', 'system1']) ??
        (!isPriority && bareSection ? m : null);
    final priority =
        pick(['priority', 'review_priority', 'review', 'system2']) ??
        (isPriority ? m : null);
    final system = m['system'];
    final method =
        (m['method'] ??
                m['special'] ??
                (system == 'mcq' || system == 'blank' ? system : null) ??
                scoring?['method'])
            ?.toString();
    bool flagged(Map<String, dynamic>? x) =>
        x != null &&
        (x['suspicious_instruction'] == true || x['flag'] == 'suspicious');
    return FuzzyTrace(
      scoring: scoring == null ? null : _section(scoring),
      priority: priority == null ? null : _section(priority, priority: true),
      method: method,
      suspicious: flagged(m) || flagged(priority),
      mcqFilled: [
        if (m['filled'] case final List filled)
          for (final f in filled) f.toString(),
      ],
      mcqCorrect: m['correct']?.toString(),
    );
  }

  static double? _num(Object? v) => v is num ? v.toDouble() : null;

  static TraceRule _rule(Map<String, dynamic> r, {required bool priority}) {
    final then = r['then'] is Map ? r['then'] as Map : const {};
    final z = _num(r['z']);
    return TraceRule(
      name: (r['name'] ?? r['rule'] ?? r['id'] ?? '?').toString(),
      weight: _num(r['weight'] ?? r['w']) ?? 0,
      scoreZ: _num(then['score_ratio']) ?? (priority ? null : z),
      uZ: _num(then['u']) ?? _num(r['u']),
      pZ: _num(then['p']) ?? (priority ? z : null),
      note: r['note'] as String?,
    );
  }

  static Map<String, double> _numbers(Object? map) => {
    if (map is Map)
      for (final MapEntry(:key, :value) in map.entries)
        if (value is num) key.toString(): value.toDouble(),
  };

  static TraceSection _section(
    Map<String, dynamic> m, {
    bool priority = false,
  }) {
    // Result wrappers keep the engine trace under `trace` and the final
    // numbers (score_ratio, u, p) at their own top level.
    final inner = m['trace'] is Map
        ? (m['trace'] as Map).cast<String, dynamic>()
        : const <String, dynamic>{};
    final rulesJson = m['rules'] ?? inner['rules'];
    return TraceSection(
      inputs: {..._numbers(inner['inputs']), ..._numbers(m['inputs'])},
      rules: [
        if (rulesJson is List)
          for (final r in rulesJson)
            if (r is Map) _rule(r.cast<String, dynamic>(), priority: priority),
      ],
      outputs: {
        ..._numbers(inner['outputs']),
        ..._numbers(m['outputs']),
        for (final k in const ['score_ratio', 'u', 'p'])
          if (m[k] is num) k: (m[k] as num).toDouble(),
      },
      degenerate: m['degenerate'] == true || inner['degenerate'] == true,
    );
  }
}

const _inputLabels = {
  'F': 'คำตอบสุดท้ายถูก',
  'S': 'สัดส่วนขั้นตอนที่ถูก',
  'M': 'ความตรงกับเฉลย',
  'K': 'เกณฑ์หลัก',
  'R': 'เกณฑ์อื่น',
  'D': 'ผู้อ่านไม่ตรงกัน',
  'L': 'ลายมืออ่านยาก',
  'B': 'ใกล้เส้นแบ่งระดับ',
};

const _ruleDescriptions = {
  'R1': 'คำตอบสุดท้ายถูก และขั้นตอนถูกเกือบทั้งหมด',
  'R2': 'คำตอบสุดท้ายถูก ขั้นตอนถูกปานกลาง',
  'R3': 'คำตอบสุดท้ายถูก แต่ขั้นตอนถูกน้อย (อาจเดาหรือลอกมา)',
  'R4': 'คำตอบสุดท้ายผิด แต่ขั้นตอนถูกเกือบทั้งหมด (พลาดตอนท้าย)',
  'R5': 'คำตอบสุดท้ายผิด ขั้นตอนถูกปานกลาง',
  'R6': 'คำตอบสุดท้ายผิด และขั้นตอนถูกน้อย',
  'S1': 'คำตอบไม่ตรงกับเฉลย',
  'S2': 'คำตอบใกล้เคียงเฉลย',
  'S3': 'คำตอบตรงกับเฉลย',
  'O1': 'ได้แก่นของคำตอบ และเกณฑ์อื่นครบเกือบทั้งหมด',
  'O2': 'ได้แก่นของคำตอบ เกณฑ์อื่นปานกลาง',
  'O3': 'ได้แก่นของคำตอบ แต่รายละเอียดน้อย',
  'O4': 'ตอบองค์ประกอบครบ แต่พลาดแก่นของคำตอบ',
  'O5': 'พลาดแก่นของคำตอบ เกณฑ์อื่นปานกลาง',
  'O6': 'พลาดแก่นของคำตอบ และเกณฑ์อื่นน้อย',
  'P1': 'CNN กับ Gemini อ่านไม่ตรงกัน หรือฝนกำกวม',
  'P2': 'ลายมืออ่านยาก',
  'P3': 'คะแนนความเข้าใจอยู่ใกล้เส้นแบ่งระดับ',
  'P4': 'ไม่มีสัญญาณที่น่าสงสัย',
};

String _pct(double v) => '${(v * 100).round()}%';

String _num2(double v) => v.toStringAsFixed(2);

/// "เหตุผลของคะแนน" (DESIGN §13): which fuzzy rules fired and how they
/// combined into the AI score and the review band.
class ScoreReasoningView extends StatelessWidget {
  const ScoreReasoningView({
    super.key,
    required this.trace,
    required this.maxPoints,
    this.aiScore,
    this.aiUnderstanding,
    this.band,
    this.reviewPriority,
  });

  final FuzzyTrace trace;
  final double maxPoints;
  final double? aiScore;
  final Understanding? aiUnderstanding;
  final PriorityBand? band;
  final double? reviewPriority;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final scoring = trace.scoring;
    final priority = trace.priority;
    final children = <Widget>[];

    if (trace.method == 'mcq') {
      children.add(
        const Text('ข้อปรนัย ตรวจตามเฉลยจากการฝนวงกลมโดยตรง (ไม่ใช้ fuzzy)'),
      );
      children.add(
        Text(
          'ฝน: ${trace.mcqFilled.isEmpty ? 'ไม่ได้ฝน' : trace.mcqFilled.join(', ')}'
          '${trace.mcqCorrect == null ? '' : ' · เฉลย: ${trace.mcqCorrect}'}'
          '${trace.mcqFilled.length > 1 ? ' (ฝนหลายวง ได้ 0)' : ''}',
        ),
      );
    } else if (trace.method == 'blank') {
      children.add(const Text('ไม่ได้ตอบ จึงได้ 0 คะแนนโดยไม่ผ่านกฎ fuzzy'));
    }

    if (scoring != null) {
      if (scoring.inputs.isNotEmpty) {
        children.add(
          Wrap(
            spacing: 8,
            runSpacing: 4,
            children: [
              for (final MapEntry(:key, :value) in scoring.inputs.entries)
                Chip(
                  visualDensity: VisualDensity.compact,
                  label: Text('${_inputLabels[key] ?? key} ${_pct(value)}'),
                ),
            ],
          ),
        );
      }
      final fired = scoring.fired;
      if (scoring.degenerate || fired.isEmpty) {
        children.add(
          const Text('ไม่มีกฎที่ทำงาน ข้อนี้ต้องให้คะแนนด้วยตัวเอง'),
        );
      }
      for (final r in fired) {
        children.add(
          ListTile(
            dense: true,
            contentPadding: EdgeInsets.zero,
            leading: CircleAvatar(radius: 16, child: Text(r.name)),
            title: Text(r.description),
            subtitle: Text(
              'น้ำหนัก ${_num2(r.weight)}'
              '${r.scoreZ == null ? '' : ' · ให้ ${_pct(r.scoreZ!)} ของคะแนน'}'
              '${r.uZ == null ? '' : ' · ความเข้าใจ ${_num2(r.uZ!)}'}',
            ),
          ),
        );
      }
      final ratio = scoring.outputs['score_ratio'];
      if (ratio != null) {
        final raw = ratio * maxPoints;
        children.add(
          Text(
            'สัดส่วนคะแนน ${_num2(ratio)} × ${formatScore(maxPoints)} คะแนน '
            '= ${_num2(raw)}'
            '${aiScore == null ? '' : ' → ปัดเป็น ${formatScore(aiScore)}'}',
          ),
        );
      }
      final u = scoring.outputs['u'];
      if (u != null) {
        children.add(
          Text(
            'ค่าความเข้าใจ ${_num2(u)}'
            '${aiUnderstanding == null ? '' : ' → ${aiUnderstanding!.label}'}',
          ),
        );
      }
    }

    if (priority != null || band != null) {
      children.add(const Divider());
      final p = priority?.outputs['p'] ?? reviewPriority;
      children.add(
        Text(
          'ลำดับการตรวจ'
          '${p == null ? '' : ' ${_num2(p)}'}'
          '${band == null ? '' : ' (${band!.label})'}',
          style: theme.textTheme.titleSmall,
        ),
      );
      if (trace.suspicious) {
        children.add(
          const Text('ลายมือมีข้อความที่สั่งผู้ตรวจ จึงขึ้นบนสุดของคิว'),
        );
      }
      for (final r in priority?.fired ?? const <TraceRule>[]) {
        if (r.name == 'P4' && (priority!.fired.length > 1)) continue;
        children.add(
          Text('• ${r.description} (น้ำหนัก ${_num2(r.weight)})', style: muted),
        );
      }
    }

    if (children.isEmpty) {
      children.add(Text('ยังไม่มีเหตุผลของคะแนนสำหรับข้อนี้', style: muted));
    }
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final c in children)
          Padding(padding: const EdgeInsets.only(bottom: 6), child: c),
      ],
    );
  }
}
