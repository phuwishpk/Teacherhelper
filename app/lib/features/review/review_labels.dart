import 'package:flutter/material.dart';

/// Review bands of fuzzy system 2 (DESIGN §11.8 `priority_band`).
enum PriorityBand {
  check('check', 'ต้องตรวจ'),
  look('look', 'ควรดู'),
  confident('confident', 'มั่นใจ');

  const PriorityBand(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static PriorityBand? fromApi(Object? value) {
    for (final b in values) {
      if (b.apiValue == value) return b;
    }
    return null;
  }
}

/// Understanding levels (DESIGN §11.7).
enum Understanding {
  good('good', 'เข้าใจดี'),
  partial('partial', 'เข้าใจบางส่วน'),
  notYet('not_yet', 'ยังไม่เข้าใจ');

  const Understanding(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static Understanding? fromApi(Object? value) {
    for (final u in values) {
      if (u.apiValue == value) return u;
    }
    return null;
  }

  Color color(BuildContext context) => switch (this) {
    Understanding.good => Colors.green.shade700,
    Understanding.partial => Colors.orange.shade800,
    Understanding.notYet => Theme.of(context).colorScheme.error,
  };
}

/// The shared `error_types` vocabulary (DESIGN §10.3), in schema order.
enum ErrorType {
  concept('concept', 'เข้าใจแนวคิดผิด'),
  procedure('procedure', 'ใช้วิธีหรือขั้นตอนผิด'),
  calculation('calculation', 'คำนวณพลาด'),
  careless('careless', 'สะเพร่าหรือคัดลอกผิด'),
  incomplete('incomplete', 'ตอบไม่ครบ'),
  misreadQuestion('misread_question', 'ตีความโจทย์ผิด'),
  spellingGrammar('spelling_grammar', 'สะกดคำหรือไวยากรณ์ผิด'),
  noAnswer('no_answer', 'ไม่ได้ตอบ'),
  other('other', 'อื่นๆ');

  const ErrorType(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static ErrorType? fromApi(Object? value) {
    for (final e in values) {
      if (e.apiValue == value) return e;
    }
    return null;
  }

  /// Unknown values are dropped rather than failing the whole payload.
  static List<ErrorType> listFromJson(Object? json) => [
    if (json is List)
      for (final v in json) ?fromApi(v),
  ];
}

/// Why the teacher changed the AI score (DESIGN §13: pick from a list,
/// optionally add text). Stored as text in `score_events.reason`.
enum OverrideReason {
  aiMisread('AI อ่านลายมือผิด'),
  tooStrict('เกณฑ์เข้มเกินไป'),
  tooLenient('เกณฑ์หย่อนเกินไป'),
  alternativeMethod('นักเรียนใช้วิธีอื่นที่ถูกต้อง'),
  other('เหตุผลอื่น');

  const OverrideReason(this.label);

  final String label;
}

/// Thai label of a question type (`questions.type`).
String questionTypeLabel(String type) => switch (type) {
  'mcq' => 'ปรนัย',
  'short' => 'ตอบสั้น',
  'show_work' => 'แสดงวิธีทำ',
  'open' => 'อัตนัย',
  _ => type,
};

/// Why a response sits in the queue as `manual` ("ตรวจเอง").
String manualReasonLabel(String? reason) => switch (reason) {
  'ai_key_missing' => 'ยังไม่ได้ใส่ Gemini API key',
  'ai_key_invalid' => 'Gemini API key ใช้ไม่ได้',
  'ai_failed' || 'ai_error' => 'AI ตรวจไม่สำเร็จ',
  'invalid_output' => 'AI ตอบผิดรูปแบบ',
  'fuzzy_degenerate' => 'กฎการให้คะแนนไม่ทำงาน',
  'answer_not_found' => 'หาคำตอบข้อนี้ในภาพไม่เจอ',
  _ => 'ตรวจด้วยตัวเอง',
};

/// Formats a score without a trailing ".0" (2.5 -> "2.5", 3.0 -> "3").
String formatScore(num? value) {
  if (value == null) return '-';
  final d = value.toDouble();
  if (d == d.roundToDouble()) return d.toInt().toString();
  final s = d.toStringAsFixed(2);
  return s.replaceFirst(RegExp(r'0+$'), '').replaceFirst(RegExp(r'\.$'), '');
}

/// A colored chip for an understanding level.
class UnderstandingChip extends StatelessWidget {
  const UnderstandingChip({super.key, required this.understanding});

  final Understanding understanding;

  @override
  Widget build(BuildContext context) {
    final c = understanding.color(context);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(
        color: c.withValues(alpha: 0.15),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Text(
        understanding.label,
        style: Theme.of(context).textTheme.labelMedium?.copyWith(
          color: c,
          fontWeight: FontWeight.w600,
        ),
      ),
    );
  }
}
