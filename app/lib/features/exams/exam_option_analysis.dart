import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';

int? _int(Object? v) => switch (v) {
  num n => n.toInt(),
  String s => int.tryParse(s),
  _ => null,
};

double? _double(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

/// The warnings of a wrong option (DESIGN §22.13), shown from
/// [ExamOptionAnalysis.minCountForR] published students up.
enum OptionFlag {
  /// Nobody chose this distractor.
  unusedDistractor('unused_distractor', 'ตัวลวงที่ไม่มีใครเลือก'),

  /// The top 27% chose this distractor more often than the bottom 27%.
  reversedDistractor(
    'reversed_distractor',
    'ตัวลวงที่กลุ่มสูงเลือกมากกว่ากลุ่มต่ำ',
  );

  const OptionFlag(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static OptionFlag? fromApi(Object? value) =>
      values.where((f) => f.apiValue == value).firstOrNull;
}

/// `{count, pct}`: how many published students, and their share (one
/// decimal; null when nobody is published yet).
class CountPct {
  const CountPct({this.count = 0, this.pct});

  final int count;
  final double? pct;

  factory CountPct.fromJson(Object? json) => json is Map
      ? CountPct(count: _int(json['count']) ?? 0, pct: _double(json['pct']))
      : const CountPct();
}

/// One option of a master question: who chose it, over every version.
class OptionStat {
  const OptionStat({
    required this.position,
    required this.label,
    this.correct = false,
    this.count = 0,
    this.pct,
    this.top,
    this.bottom,
    this.flags = const [],
  });

  /// The original position (1 = ก, or ถูก of a true/false question).
  final int position;
  final String label;

  /// In the current master key.
  final bool correct;
  final int count;
  final double? pct;

  /// How many of the top / bottom 27% chose it; null below the minimum.
  final int? top;
  final int? bottom;
  final List<OptionFlag> flags;

  factory OptionStat.fromJson(Map<String, dynamic> json) {
    final position = _int(json['position']) ?? 0;
    return OptionStat(
      position: position,
      label: json['label'] as String? ?? '$position',
      correct: json['correct'] == true,
      count: _int(json['count']) ?? 0,
      pct: _double(json['pct']),
      top: _int(json['top']),
      bottom: _int(json['bottom']),
      flags: [
        for (final f in (json['flags'] as List?) ?? const [])
          ?OptionFlag.fromApi(f),
      ],
    );
  }
}

/// The option statistics of one master question (all versions together).
class QuestionOptionStats {
  const QuestionOptionStats({
    required this.questionId,
    required this.position,
    required this.type,
    this.sectionId,
    this.promptText = '',
    this.p,
    this.r,
    this.options = const [],
    this.blank = const CountPct(),
    this.multiple = const CountPct(),
  });

  final int questionId;

  /// The number on version ก.
  final int position;
  final int? sectionId;

  /// `mcq`, `true_false` or `numeric`.
  final String type;
  final String promptText;
  final double? p;
  final double? r;

  /// Empty for a numeric question.
  final List<OptionStat> options;

  /// No mark (or no readable value of a numeric question).
  final CountPct blank;

  /// Several marks on a one-answer question.
  final CountPct multiple;

  bool get hasOptions => options.isNotEmpty;

  int get flagCount => options.fold(0, (n, o) => n + o.flags.length);

  factory QuestionOptionStats.fromJson(Map<String, dynamic> json) =>
      QuestionOptionStats(
        questionId: _int(json['question_id']) ?? 0,
        position: _int(json['position']) ?? 0,
        sectionId: _int(json['section_id']),
        type: json['type'] as String? ?? 'mcq',
        promptText: json['prompt_text'] as String? ?? '',
        p: _double(json['p']),
        r: _double(json['r']),
        options: [
          for (final o in (json['options'] as List?) ?? const [])
            if (o is Map) OptionStat.fromJson(o.cast<String, dynamic>()),
        ]..sort((a, b) => a.position.compareTo(b.position)),
        blank: CountPct.fromJson(json['blank']),
        multiple: CountPct.fromJson(json['multiple']),
      );
}

/// `GET /exams/{id}/option-analysis` (DESIGN §22.13): computed on read
/// from published submissions only.
class ExamOptionAnalysis {
  const ExamOptionAnalysis({
    required this.examId,
    this.publishedCount = 0,
    this.groupsReady = false,
    this.minCountForR = 20,
    this.groupSize,
    this.questions = const [],
  });

  final int examId;
  final int publishedCount;

  /// The 27% groups (and so the flags) exist from [minCountForR] up.
  final bool groupsReady;
  final int minCountForR;

  /// Students in each of the top and bottom groups.
  final int? groupSize;
  final List<QuestionOptionStats> questions;

  List<QuestionOptionStats> get flagged => [
    for (final q in questions)
      if (q.flagCount > 0) q,
  ];

  factory ExamOptionAnalysis.fromJson(Map<String, dynamic> json) =>
      ExamOptionAnalysis(
        examId: _int(json['exam_id']) ?? 0,
        publishedCount: _int(json['published_count']) ?? 0,
        groupsReady: json['groups_ready'] == true,
        minCountForR: _int(json['min_count_for_r']) ?? 20,
        groupSize: _int(json['group_size']),
        questions: [
          for (final q in (json['questions'] as List?) ?? const [])
            if (q is Map)
              QuestionOptionStats.fromJson(q.cast<String, dynamic>()),
        ]..sort((a, b) => a.position.compareTo(b.position)),
      );
}

/// The option analysis of an exam (build 6).
abstract class ExamAnalysisRepository {
  /// `GET /exams/{id}/option-analysis`.
  Future<ExamOptionAnalysis> optionAnalysis(int examId);
}

class ApiExamAnalysisRepository implements ExamAnalysisRepository {
  ApiExamAnalysisRepository(this._dio);

  final Dio _dio;

  @override
  Future<ExamOptionAnalysis> optionAnalysis(int examId) async {
    final res = await _dio.get<Object?>('/exams/$examId/option-analysis');
    return ExamOptionAnalysis.fromJson(unwrapJson(res.data));
  }
}

final examAnalysisRepositoryProvider = Provider<ExamAnalysisRepository>(
  (ref) => ApiExamAnalysisRepository(ref.watch(dioProvider)),
);

final examOptionAnalysisProvider = FutureProvider.autoDispose
    .family<ExamOptionAnalysis, int>((ref, examId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(examAnalysisRepositoryProvider).optionAnalysis(examId);
    }, retry: apiRetry);
