/// Answers read from exam answer sheets, as the review screens get them
/// (DESIGN §22.11): the `exam` block of `GET /responses/{id}` and the
/// `exam_answer` of a review-queue row. Scored by code, never by Gemini.
library;

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

List<int> _ints(Object? v) => [
  if (v is List)
    for (final n in v) ?_int(n),
];

List<String> _strings(Object? v) => [
  if (v is List)
    for (final s in v)
      if (s != null) '$s',
];

/// Doubts of §22.3 that send an answer to review.
const kExamAnswerReviewDoubts = {
  'double_mark',
  'ambiguous_mark',
  'invalid_number',
};

/// Thai label of an answer doubt (§22.3, §22.11).
String examDoubtLabel(String doubt) => switch (doubt) {
  'double_mark' => 'ฝนหลายวง',
  'ambiguous_mark' => 'รอยฝนไม่ชัด',
  'invalid_number' => 'ตัวเลขอ่านไม่ได้',
  'version_unknown' => 'ไม่ทราบชุด',
  'version_waiting_page_one' => 'รอหน้า 1',
  'version_doubtful' => 'วงชุดไม่ชัด',
  _ => doubt,
};

/// The teacher's reading of the marks (`exam_answer.resolved`).
class ExamResolved {
  const ExamResolved({required this.selected, this.value, this.by, this.at});

  /// Positions ON THE SHEET of the student's version (1 = first bubble).
  final List<int> selected;
  final String? value;
  final int? by;
  final DateTime? at;

  static ExamResolved? fromJson(Object? json) {
    if (json is! Map) return null;
    final at = json['at'];
    return ExamResolved(
      selected: _ints(json['selected']),
      value: json['value'] as String?,
      by: _int(json['by']),
      at: at is String ? DateTime.tryParse(at) : null,
    );
  }
}

/// The `exam` block of `GET /responses/{id}` (ExamAnswerView::teacher):
/// what the sheet showed, where on the warped page, and the teacher's
/// reading if any.
class ExamAnswerView {
  const ExamAnswerView({
    required this.sheetNo,
    required this.versionNo,
    required this.versionLabel,
    required this.labels,
    required this.selected,
    required this.doubts,
    this.value,
    this.resolved,
    this.scanId,
    this.pageNo,
    this.rect,
  });

  /// The number printed on the student's sheet.
  final int sheetNo;
  final int versionNo;
  final String versionLabel;

  /// Bubble labels by position on the sheet (ก ข ค ง, or ถ ผ); empty for a
  /// number.
  final List<String> labels;

  /// Marked positions on the sheet as read by code.
  final List<int> selected;

  /// The number read by code (canonical form), null when blank or invalid.
  final String? value;
  final List<String> doubts;
  final ExamResolved? resolved;

  /// The warped page (`GET /scans/{scan_id}/page`), deleted after publishing.
  final int? scanId;
  final int? pageNo;

  /// `{x, y, w, h}` of the row or digit block, normalized to the page.
  final ({double x, double y, double w, double h})? rect;

  bool get isNumeric => labels.isEmpty;
  bool get isResolved => resolved != null;
  List<String> get reviewDoubts =>
      doubts.where(kExamAnswerReviewDoubts.contains).toList();

  /// [rect] as `[ymin, xmin, ymax, xmax]` in 0–1000, the box format the
  /// page painter draws; null when there is no usable rect.
  List<double>? get box {
    final r = rect;
    if (r == null || r.w <= 0 || r.h <= 0) return null;
    double k(double v) => (v * 1000).clamp(0, 1000).toDouble();
    return [k(r.y), k(r.x), k(r.y + r.h), k(r.x + r.w)];
  }

  /// "ก, ข" of [positions] (positions on the sheet), "ไม่ได้ฝน" when empty.
  String describe(List<int> positions) {
    if (positions.isEmpty) return 'ไม่ได้ฝน';
    return [
      for (final p in positions)
        p >= 1 && p <= labels.length ? labels[p - 1] : '$p',
    ].join(', ');
  }

  /// What code read, in words.
  String get readText => isNumeric
      ? (value ??
            (doubts.contains('invalid_number') ? 'อ่านไม่ได้' : 'ไม่ได้ตอบ'))
      : describe(selected);

  /// The teacher's reading in words, or null.
  String? get resolvedText {
    final r = resolved;
    if (r == null) return null;
    return isNumeric ? (r.value ?? 'ไม่ได้ตอบ') : describe(r.selected);
  }

  static ExamAnswerView? fromJson(Object? json) {
    if (json is! Map) return null;
    final m = json.cast<String, dynamic>();
    final rect = m['rect'];
    ({double x, double y, double w, double h})? parsedRect;
    if (rect is Map) {
      final x = _double(rect['x']);
      final y = _double(rect['y']);
      final w = _double(rect['w']);
      final h = _double(rect['h']);
      if (x != null && y != null && w != null && h != null) {
        parsedRect = (x: x, y: y, w: w, h: h);
      }
    }
    final versionNo = _int(m['version_no']) ?? 1;
    return ExamAnswerView(
      sheetNo: _int(m['sheet_no']) ?? 0,
      versionNo: versionNo,
      versionLabel: m['version_label'] as String? ?? '',
      labels: _strings(m['labels']),
      selected: _ints(m['selected']),
      value: m['value'] as String?,
      doubts: _strings(m['doubts']),
      resolved: ExamResolved.fromJson(m['resolved']),
      scanId: _int(m['scan_id']),
      pageNo: _int(m['page_no']),
      rect: parsedRect,
    );
  }
}

/// The short `exam_answer` of a review-queue row (ExamAnswerView::queue).
class ExamQueueAnswer {
  const ExamQueueAnswer({
    required this.sheetNo,
    required this.doubts,
    required this.resolved,
    this.versionNo,
  });

  final int sheetNo;
  final int? versionNo;
  final List<String> doubts;

  /// The teacher already read the marks (resolve).
  final bool resolved;

  List<String> get reviewDoubts =>
      doubts.where(kExamAnswerReviewDoubts.contains).toList();

  static ExamQueueAnswer? fromJson(Object? json) {
    if (json is! Map) return null;
    return ExamQueueAnswer(
      sheetNo: _int(json['sheet_no']) ?? 0,
      versionNo: _int(json['version_no']),
      doubts: _strings(json['doubts']),
      resolved: json['resolved'] == true,
    );
  }
}

/// What the teacher sends to `POST /exam-responses/{id}/resolve`: the
/// positions on the sheet the student meant, or the number (null = no
/// answer).
class ExamResolution {
  const ExamResolution.options(List<int> this.options) : value = null;
  const ExamResolution.number(this.value) : options = null;

  final List<int>? options;
  final String? value;

  Map<String, dynamic> toJson() =>
      options != null ? {'options': options} : {'value': value};
}
