import '../assignments/assignment.dart';

/// How an exam is graded (DESIGN §22.1 `assignments.grading_method`).
enum ExamGradingMethod {
  /// Answer sheets scanned with the phone and scored by code.
  app('app', 'ตรวจด้วยแอป'),

  /// The teacher grades outside the app and types the total.
  manual('manual', 'ครูตรวจเอง');

  const ExamGradingMethod(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static ExamGradingMethod fromApi(String? value) =>
      value == manual.apiValue ? manual : app;
}

/// The kind of every question of a section (DESIGN §22.2).
enum ExamSectionType {
  mcq('mcq', 'ปรนัย'),
  trueFalse('true_false', 'ถูก/ผิด'),
  numeric('numeric', 'เติมตัวเลข');

  const ExamSectionType(this.apiValue, this.label);

  final String apiValue;
  final String label;

  static ExamSectionType fromApi(String? value) => values.firstWhere(
    (t) => t.apiValue == value,
    orElse: () => ExamSectionType.mcq,
  );
}

/// Option letters on the paper, 1 = ก (original order).
const kExamOptionLabels = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ'];

/// Version labels in Thai consonant order (§22.5), 1 = ก.
const kExamVersionLabels = ['ก', 'ข', 'ค', 'ง', 'จ', 'ฉ', 'ช', 'ซ', 'ฌ', 'ญ'];

/// true_false answers: 1 = ถูก, 2 = ผิด (§22.3).
const kTrueFalseLabels = ['ถูก', 'ผิด'];

/// Server limits of `config('eduvision.exams')` the forms check early.
const kExamMaxSections = 10;
const kExamMaxQuestions = 200;
const kExamMaxBlankQuestions = 100;
const kExamMaxVersions = 4;
const kExamMaxDigits = 5;
const kExamMaxAcceptedValues = 10;
const kExamMaxDurationMinutes = 600;

String examOptionLabel(int position) =>
    position >= 1 && position <= kExamOptionLabels.length
    ? kExamOptionLabels[position - 1]
    : '$position';

String examVersionLabel(int versionNo) =>
    versionNo >= 1 && versionNo <= kExamVersionLabels.length
    ? kExamVersionLabels[versionNo - 1]
    : '$versionNo';

/// The label of an answer choice of [type] at [position] (ก… or ถูก/ผิด).
String examChoiceLabel(ExamSectionType type, int position) =>
    type == ExamSectionType.trueFalse
    ? (position >= 1 && position <= 2 ? kTrueFalseLabels[position - 1] : '?')
    : examOptionLabel(position);

/// "12" or "12.5" without a trailing ".0".
String formatPoints(double value) =>
    value == value.roundToDouble() ? value.toInt().toString() : '$value';

/// The digit block of a numeric section (§22.2, §22.7).
class NumericSpec {
  const NumericSpec({
    required this.digits,
    this.allowNegative = false,
    this.allowDecimal = false,
  });

  final int digits;
  final bool allowNegative;
  final bool allowDecimal;

  /// Columns of the block: every column has 0–9, plus one for the point.
  int get columns => digits + (allowDecimal ? 1 : 0);

  String get summary => [
    '$digits หลัก',
    if (allowNegative) 'ติดลบได้',
    if (allowDecimal) 'มีทศนิยม',
  ].join(' · ');

  factory NumericSpec.fromJson(Map<String, dynamic> json) => NumericSpec(
    digits: (json['digits'] as num?)?.toInt() ?? 1,
    allowNegative: json['allow_negative'] == true,
    allowDecimal: json['allow_decimal'] == true,
  );

  Map<String, Object?> toJson() => {
    'digits': digits,
    'allow_negative': allowNegative,
    'allow_decimal': allowDecimal,
  };
}

/// The canonical form of a numeric answer, the same rules as the server's
/// `NumericAnswer` (DESIGN §22.3): leading zeros of the integer part and
/// trailing zeros of the fraction are dropped, ".5" is "0.5", "-0" is "0".
abstract final class NumericAnswer {
  static final _pattern = RegExp(r'^(-?)(\d*)(?:\.(\d*))?$');

  /// Null when [text] is not a number of this form.
  static String? canonical(String text) {
    final m = _pattern.firstMatch(text.trim());
    if (m == null) return null;
    final negative = m.group(1) == '-';
    var integer = m.group(2) ?? '';
    var fraction = m.group(3) ?? '';
    if (integer.isEmpty && fraction.isEmpty) return null;
    integer = integer.replaceFirst(RegExp(r'^0+'), '');
    if (integer.isEmpty) integer = '0';
    fraction = fraction.replaceFirst(RegExp(r'0+$'), '');
    final value = fraction.isEmpty ? integer : '$integer.$fraction';
    return negative && value != '0' ? '-$value' : value;
  }

  /// Whether a canonical value can be bubbled in [spec]'s block (§22.3
  /// "ใส่ลงช่องได้"); a value below 1 may drop its leading "0".
  static bool fits(String canonical, NumericSpec spec) =>
      problem(canonical, spec) == null;

  /// Why [canonical] does not fit [spec] (Thai), or null when it does.
  static String? problem(String canonical, NumericSpec spec) {
    final negative = canonical.startsWith('-');
    if (negative && !spec.allowNegative) {
      return 'ค่า $canonical ติดลบ แต่ตอนนี้ไม่มีช่องเครื่องหมายลบ';
    }
    final unsigned = negative ? canonical.substring(1) : canonical;
    final dot = unsigned.indexOf('.');
    var integer = dot < 0 ? unsigned : unsigned.substring(0, dot);
    final fraction = dot < 0 ? '' : unsigned.substring(dot + 1);
    if (fraction.isNotEmpty && !spec.allowDecimal) {
      return 'ค่า $canonical มีทศนิยม แต่ตอนนี้ไม่มีช่องจุดทศนิยม';
    }
    if (fraction.isNotEmpty && integer == '0') integer = '';
    final needed =
        integer.length + (fraction.isEmpty ? 0 : 1 + fraction.length);
    if (needed < 1 || needed > spec.columns) {
      return 'ค่า $canonical ยาวเกินช่องตัวเลข ${spec.digits} หลักของตอนนี้';
    }
    return null;
  }

  /// Splits a comma list typed in the key grid ("0.5, .5, 1/2" allows
  /// spaces and Thai commas) into its entries.
  static List<String> split(String text) => [
    for (final part in text.split(RegExp(r'[,，、]')))
      if (part.trim().isNotEmpty) part.trim(),
  ];

  /// The canonical values of [text] for [spec], or the first problem.
  static ({List<String> values, String? error}) parseList(
    String text,
    NumericSpec spec,
  ) {
    final out = <String>[];
    final parts = split(text);
    if (parts.length > kExamMaxAcceptedValues) {
      return (
        values: const [],
        error: 'ค่าที่ยอมรับมีได้ไม่เกิน $kExamMaxAcceptedValues ค่า',
      );
    }
    for (final part in parts) {
      final c = canonical(part);
      if (c == null) {
        return (
          values: const [],
          error: '"$part" ไม่ใช่ตัวเลข ใช้รูปแบบเช่น 12, -3 หรือ 0.5',
        );
      }
      final problem = NumericAnswer.problem(c, spec);
      if (problem != null) return (values: const [], error: problem);
      if (!out.contains(c)) out.add(c);
    }
    return (values: out, error: null);
  }
}

/// Suggests "ห้ามสลับตัวเลือก" (DESIGN §22.5) as the server's
/// `LockOptionsDetector` does, so the question form can show the
/// suggestion while the teacher types. Only a suggestion.
abstract final class LockOptionsDetector {
  static const _phrases = [
    'ถูกทุกข้อ',
    'ผิดทุกข้อ',
    'ถูกหมดทุกข้อ',
    'ผิดหมดทุกข้อ',
    'ถูกทั้งหมด',
    'ผิดทั้งหมด',
    'ไม่มีข้อใดถูก',
    'ไม่มีข้อใดผิด',
    'ไม่มีข้อถูก',
    'ไม่มีข้อที่ถูก',
    'ไม่มีคำตอบที่ถูก',
    'ทุกข้อที่กล่าวมา',
    'ทุกข้อข้างต้น',
    'ข้อที่กล่าวมาทั้งหมด',
    'ถูกทั้ง',
    'ผิดทั้ง',
    'alloftheabove',
    'noneoftheabove',
    'alloftheseabove',
    'noneofthese',
    'allofthese',
  ];

  static final _thaiLetters = RegExp(
    r'^(?:ทั้ง\s*)?(?:ข้อ\s*)?[กขคงจฉ][.)]?'
    r'(?:(?:\s*(?:,|และ|หรือ)\s*|\s+)(?:ข้อ\s*)?[กขคงจฉ][.)]?)+'
    r'\s*(?:ถูก(?:ต้อง)?|ผิด)?\s*\.?$',
    unicode: true,
  );

  static final _latinLetters = RegExp(
    r'^(?:both\s+)?\(?[a-f]\)?(?:\s*(?:,|and|&|or)\s*\(?[a-f]\)?)+'
    r'(?:\s+(?:only|are\s+(?:correct|true)))?\s*\.?$',
    caseSensitive: false,
  );

  static bool suggests(Iterable<String?> optionTexts) =>
      optionTexts.any((t) => t != null && refersToOthers(t));

  static bool refersToOthers(String text) {
    final t = text.replaceAll(RegExp(r'\s+'), ' ').trim();
    if (t.isEmpty) return false;
    final compact = t.replaceAll(RegExp(r'[\s.]+'), '').toLowerCase();
    if (_phrases.any(compact.contains)) return true;
    return _thaiLetters.hasMatch(t) || _latinLetters.hasMatch(t);
  }
}

/// The master key of one exam question in the original option order
/// (DESIGN §22.3): options 1..n (true_false 1 = ถูก, 2 = ผิด) or the
/// canonical numeric values.
class ExamKey {
  const ExamKey({this.options = const [], this.values = const []});

  final List<int> options;
  final List<String> values;

  bool get isEmpty => options.isEmpty && values.isEmpty;

  static ExamKey? fromJson(Object? json) {
    if (json is! Map) return null;
    final options = json['accepted_options'];
    final values = json['accepted_values'];
    final key = ExamKey(
      options: [
        if (options is List)
          for (final o in options)
            if (o is num) o.toInt(),
      ]..sort(),
      values: [
        if (values is List)
          for (final v in values)
            if (v != null) v.toString(),
      ],
    );
    return key.isEmpty ? null : key;
  }

  /// The body of the key for [type] (null clears it).
  Map<String, Object?>? toJson(ExamSectionType type) {
    if (isEmpty) return null;
    return type == ExamSectionType.numeric
        ? {'accepted_values': values}
        : {'accepted_options': options};
  }

  /// "ค", "ข, ง", "ถูก" or "0.5 / 0.50".
  String describe(ExamSectionType type) => type == ExamSectionType.numeric
      ? values.join(' หรือ ')
      : options.map((o) => examChoiceLabel(type, o)).join(', ');

  @override
  bool operator ==(Object other) =>
      other is ExamKey &&
      _listEquals(other.options, options) &&
      _listEquals(other.values, values);

  @override
  int get hashCode =>
      Object.hash(Object.hashAll(options), Object.hashAll(values));
}

bool _listEquals<T>(List<T> a, List<T> b) {
  if (a.length != b.length) return false;
  for (var i = 0; i < a.length; i++) {
    if (a[i] != b[i]) return false;
  }
  return true;
}

/// One mcq option (`question_options`), position 1..6 = ก..ฉ.
class ExamOption {
  const ExamOption({
    required this.id,
    required this.position,
    this.text,
    this.hasImage = false,
    this.figureSource,
    this.figurePending = false,
  });

  final int id;
  final int position;
  final String? text;
  final bool hasImage;

  /// Where the picture was cropped from the exam file (§22.4); null for a
  /// picture the teacher attached.
  final FigureSource? figureSource;

  /// The figure waits for its page image ("ยังไม่มีภาพประกอบ").
  final bool figurePending;

  String get label => examOptionLabel(position);

  factory ExamOption.fromJson(Map<String, dynamic> json) => ExamOption(
    id: (json['id'] as num).toInt(),
    position: (json['position'] as num).toInt(),
    text: json['text'] as String?,
    hasImage: json['has_image'] == true,
    figureSource: FigureSource.maybe(json['figure_source']),
    figurePending: json['figure_pending'] == true,
  );
}

/// One question of an exam (`GET /exams/{id}` question, §22.15).
class ExamQuestion {
  const ExamQuestion({
    required this.id,
    required this.sectionId,
    required this.position,
    required this.type,
    this.promptText = '',
    this.hasPromptImage = false,
    this.maxPoints = 1,
    this.options = const [],
    this.key,
    this.approvedAt,
    this.origin,
    this.blank = false,
    this.lockOptions = false,
    this.lockOptionsSuggested = false,
    this.keyComplete = false,
    this.hasPrompt = false,
    this.updatedAt,
    this.figureSource,
    this.figurePending = false,
    this.skillIds = const [],
  });

  final int id;
  final int sectionId;

  /// The number on version ก, continuous over the exam.
  final int position;
  final ExamSectionType type;
  final String promptText;
  final bool hasPromptImage;
  final double maxPoints;
  final List<ExamOption> options;
  final ExamKey? key;
  final DateTime? approvedAt;

  /// `teacher`, `document` or `copied`.
  final String? origin;

  /// Created empty and not filled in yet ("ยังไม่ได้กรอก", §22.4).
  final bool blank;
  final bool lockOptions;

  /// The server suggests "ห้ามสลับตัวเลือก" for this question.
  final bool lockOptionsSuggested;
  final bool keyComplete;
  final bool hasPrompt;
  final String? updatedAt;

  /// Where the prompt picture was cropped from the exam file (§22.4).
  final FigureSource? figureSource;

  /// Indicators confirmed for this question (`question_skill`, §22.13).
  final List<int> skillIds;

  /// The prompt figure waits for its page image.
  final bool figurePending;

  bool get approved => approvedAt != null;

  /// Read from the teacher's exam file (a draft until approved).
  bool get fromDocument => origin == 'document';

  /// The prompt or an option has a figure still waiting for its page.
  bool get anyFigurePending =>
      figurePending || options.any((o) => o.figurePending);

  factory ExamQuestion.fromJson(Map<String, dynamic> json) {
    final approved = json['approved_at'];
    return ExamQuestion(
      id: (json['id'] as num).toInt(),
      sectionId: (json['section_id'] as num?)?.toInt() ?? 0,
      position: (json['position'] as num?)?.toInt() ?? 0,
      type: ExamSectionType.fromApi(json['type'] as String?),
      promptText: json['prompt_text'] as String? ?? '',
      hasPromptImage: json['has_prompt_image'] == true,
      maxPoints: (json['max_points'] as num?)?.toDouble() ?? 1,
      options:
          ((json['options'] as List?) ?? const [])
              .cast<Map<String, dynamic>>()
              .map(ExamOption.fromJson)
              .toList()
            ..sort((a, b) => a.position.compareTo(b.position)),
      key: ExamKey.fromJson(json['answer_key']),
      approvedAt: approved is String ? DateTime.tryParse(approved) : null,
      origin: json['origin'] as String?,
      blank: json['blank'] == true,
      lockOptions: json['lock_options'] == true,
      lockOptionsSuggested: json['lock_options_suggested'] == true,
      keyComplete: json['key_complete'] == true,
      hasPrompt: json['has_prompt'] == true,
      updatedAt: json['updated_at'] as String?,
      figureSource: FigureSource.maybe(json['figure_source']),
      figurePending: json['figure_pending'] == true,
      skillIds: [
        for (final id in (json['skill_ids'] as List?) ?? const [])
          if (id is num) id.toInt(),
      ],
    );
  }
}

/// One section of an exam (`exam_sections`, §22.2).
class ExamSection {
  const ExamSection({
    required this.id,
    required this.position,
    required this.type,
    this.title,
    this.instructions,
    this.optionCount,
    this.numeric,
    this.defaultPoints = 1,
    this.questions = const [],
    this.firstNumber,
    this.lastNumber,
  });

  final int id;
  final int position;
  final ExamSectionType type;
  final String? title;
  final String? instructions;

  /// mcq only: 2–6.
  final int? optionCount;

  /// numeric only.
  final NumericSpec? numeric;
  final double defaultPoints;
  final List<ExamQuestion> questions;
  final int? firstNumber;
  final int? lastNumber;

  /// Answer choices per question: option count, 2 for true_false, 0 for
  /// numeric.
  int get choiceCount => switch (type) {
    ExamSectionType.mcq => optionCount ?? 4,
    ExamSectionType.trueFalse => 2,
    ExamSectionType.numeric => 0,
  };

  /// "ตอนที่ 2" plus the teacher's title when there is one.
  String get heading {
    final t = title?.trim() ?? '';
    return t.isEmpty ? 'ตอนที่ $position' : 'ตอนที่ $position $t';
  }

  /// "ปรนัย 4 ตัวเลือก", "ถูก/ผิด" or "เติมตัวเลข 3 หลัก · มีทศนิยม".
  String get typeSummary => switch (type) {
    ExamSectionType.mcq => 'ปรนัย ${optionCount ?? 4} ตัวเลือก',
    ExamSectionType.trueFalse => 'ถูก/ผิด',
    ExamSectionType.numeric =>
      'เติมตัวเลข ${(numeric ?? const NumericSpec(digits: 1)).summary}',
  };

  /// "ข้อ 1–20", or null for an empty section.
  String? get numberRange {
    if (firstNumber == null || lastNumber == null) return null;
    return firstNumber == lastNumber
        ? 'ข้อ $firstNumber'
        : 'ข้อ $firstNumber–$lastNumber';
  }

  factory ExamSection.fromJson(Map<String, dynamic> json) {
    final numeric = json['numeric'];
    return ExamSection(
      id: (json['id'] as num).toInt(),
      position: (json['position'] as num?)?.toInt() ?? 1,
      type: ExamSectionType.fromApi(json['type'] as String?),
      title: json['title'] as String?,
      instructions: json['instructions'] as String?,
      optionCount: (json['option_count'] as num?)?.toInt(),
      numeric: numeric is Map
          ? NumericSpec.fromJson(numeric.cast<String, dynamic>())
          : null,
      defaultPoints: _toDouble(json['default_points']) ?? 1,
      questions:
          ((json['questions'] as List?) ?? const [])
              .cast<Map<String, dynamic>>()
              .map(ExamQuestion.fromJson)
              .toList()
            ..sort((a, b) => a.position.compareTo(b.position)),
      firstNumber: (json['first_number'] as num?)?.toInt(),
      lastNumber: (json['last_number'] as num?)?.toInt(),
    );
  }
}

/// A decimal the server may send as a number or a string ("1.00").
double? _toDouble(Object? v) => switch (v) {
  num n => n.toDouble(),
  String s => double.tryParse(s),
  _ => null,
};

/// A question that blocks the key approval or the booklet (§22.15).
class ExamIncomplete {
  const ExamIncomplete({
    required this.questionId,
    required this.position,
    this.reasons = const [],
  });

  final int questionId;
  final int position;

  /// `not_approved`, `no_key` or `no_prompt`.
  final List<String> reasons;

  static String reasonLabel(String reason) => switch (reason) {
    'not_approved' => 'ยังไม่อนุมัติ',
    'no_key' => 'ยังไม่มีเฉลย',
    'no_prompt' => 'ยังไม่มีโจทย์',
    _ => reason,
  };

  String get summary => 'ข้อ $position: ${reasons.map(reasonLabel).join(', ')}';

  factory ExamIncomplete.fromJson(Map<String, dynamic> json) => ExamIncomplete(
    questionId: (json['question_id'] as num).toInt(),
    position: (json['position'] as num?)?.toInt() ?? 0,
    reasons: [
      for (final r in (json['reasons'] as List?) ?? const []) r.toString(),
    ],
  );
}

/// `GET /exams/{id}`: the exam with its sections, questions and the state
/// of its key (DESIGN §22.15).
class ExamDetail {
  const ExamDetail({
    required this.exam,
    this.sections = const [],
    this.keyComplete = false,
    this.incomplete = const [],
    this.bookletIncomplete = const [],
    this.versionsReady = false,
    this.structureLockedAt,
    this.sheetPages = 0,
    this.sheetOverflow = false,
    this.pageImages = const [],
    this.figuresPending = const [],
  });

  final Assignment exam;
  final List<ExamSection> sections;

  /// Every question approved with a key that fits (§22.3 "เฉลยครบ").
  final bool keyComplete;
  final List<ExamIncomplete> incomplete;
  final List<ExamIncomplete> bookletIncomplete;
  final bool versionsReady;
  final DateTime? structureLockedAt;

  /// Answer-sheet pages the questions need (at most 2, §22.7).
  final int sheetPages;
  final bool sheetOverflow;

  /// Pages of the exam file figures are cropped from (build 5, §22.4).
  final List<ExamPageImage> pageImages;

  /// Pages whose figures wait for the app to render them.
  final List<FigurePending> figuresPending;

  /// Page images the teacher can draw a box on.
  List<ExamPageImage> get availablePages => [
    for (final p in pageImages)
      if (p.available) p,
  ];

  /// Questions read from a file that the teacher has not approved yet.
  List<ExamQuestion> get unapprovedDrafts => [
    for (final q in questions)
      if (q.fromDocument && !q.approved) q,
  ];

  ExamGradingMethod get gradingMethod =>
      ExamGradingMethod.fromApi(exam.gradingMethod);

  bool get isManual => gradingMethod == ExamGradingMethod.manual;

  bool get structureLocked => structureLockedAt != null;

  bool get keyApproved => exam.keyApproved;

  List<ExamQuestion> get questions => [
    for (final s in sections) ...s.questions,
  ];

  int get questionCount => sections.fold(0, (n, s) => n + s.questions.length);

  /// Questions without an indicator: their results are not counted in the
  /// charts (§20.3, a warning only).
  int get unmappedCount => questions.where((q) => q.skillIds.isEmpty).length;

  double get totalPoints => questions.fold(0.0, (sum, q) => sum + q.maxPoints);

  ExamSection? section(int id) => sections.where((s) => s.id == id).firstOrNull;

  ExamQuestion? question(int id) =>
      questions.where((q) => q.id == id).firstOrNull;

  /// The section of the question with [questionId].
  ExamSection? sectionOf(int questionId) => sections
      .where((s) => s.questions.any((q) => q.id == questionId))
      .firstOrNull;

  factory ExamDetail.fromJson(Map<String, dynamic> json) {
    final sheet = json['sheet'];
    final locked = json['structure_locked_at'];
    List<ExamIncomplete> incompleteOf(Object? list) => [
      if (list is List)
        for (final row in list)
          if (row is Map) ExamIncomplete.fromJson(row.cast<String, dynamic>()),
    ];
    return ExamDetail(
      exam: Assignment.fromJson((json['exam'] as Map).cast<String, dynamic>()),
      sections:
          ((json['sections'] as List?) ?? const [])
              .cast<Map<String, dynamic>>()
              .map(ExamSection.fromJson)
              .toList()
            ..sort((a, b) => a.position.compareTo(b.position)),
      keyComplete: json['key_complete'] == true,
      incomplete: incompleteOf(json['incomplete_questions']),
      bookletIncomplete: incompleteOf(json['booklet_incomplete_questions']),
      versionsReady: json['versions_ready'] == true,
      structureLockedAt: locked is String ? DateTime.tryParse(locked) : null,
      sheetPages: sheet is Map ? (sheet['pages'] as num?)?.toInt() ?? 0 : 0,
      sheetOverflow: sheet is Map && sheet['overflow'] == true,
      pageImages: [
        for (final p in (json['page_images'] as List?) ?? const [])
          if (p is Map) ExamPageImage.fromJson(p.cast<String, dynamic>()),
      ],
      figuresPending: FigurePending.listOf(json['figures_pending']),
    );
  }
}

/// `figure_source` of a question or option (DESIGN §22.4): the page image
/// and box a figure was cropped from; [pageImageId] null = the figure waits
/// for the page image of ([sourceDocumentId], [pageNo]).
class FigureSource {
  const FigureSource({
    this.pageImageId,
    this.sourceDocumentId,
    this.pageNo,
    this.box,
  });

  final int? pageImageId;
  final int? sourceDocumentId;
  final int? pageNo;

  /// `box_2d`: [ymin, xmin, ymax, xmax] in 0–1000 of the page.
  final List<int>? box;

  static FigureSource? maybe(Object? json) {
    if (json is! Map) return null;
    final box = json['box_2d'];
    return FigureSource(
      pageImageId: (json['page_image_id'] as num?)?.toInt(),
      sourceDocumentId: (json['source_document_id'] as num?)?.toInt(),
      pageNo: (json['page_no'] as num?)?.toInt(),
      box: box is List && box.length == 4
          ? [for (final v in box) (v as num).toInt()]
          : null,
    );
  }
}

/// A page of the exam file kept for cropping figures (`page_images`).
class ExamPageImage {
  const ExamPageImage({
    required this.id,
    required this.sourceDocumentId,
    required this.pageNo,
    this.widthPx = 0,
    this.heightPx = 0,
    this.available = true,
  });

  final int id;
  final int sourceDocumentId;
  final int pageNo;

  /// 0 until the server decoded a photo it keeps itself.
  final int widthPx;
  final int heightPx;

  /// False once the file was deleted with the documents (30 days).
  final bool available;

  /// Width / height, A4 portrait while the size is unknown.
  double get aspectRatio =>
      widthPx > 0 && heightPx > 0 ? widthPx / heightPx : 210 / 297;

  factory ExamPageImage.fromJson(Map<String, dynamic> json) => ExamPageImage(
    id: (json['id'] as num).toInt(),
    sourceDocumentId: (json['source_document_id'] as num?)?.toInt() ?? 0,
    pageNo: (json['page_no'] as num?)?.toInt() ?? 1,
    widthPx: (json['width_px'] as num?)?.toInt() ?? 0,
    heightPx: (json['height_px'] as num?)?.toInt() ?? 0,
    available: json['available'] != false,
  );
}

/// A page whose figures wait for its page image (`figures_pending`).
class FigurePending {
  const FigurePending({
    required this.sourceDocumentId,
    required this.pageNo,
    this.originalName,
    this.mimeType,
    this.figures = 0,
    this.reason = 'needs_render',
  });

  final int sourceDocumentId;
  final int pageNo;
  final String? originalName;
  final String? mimeType;

  /// Figures (question and option pictures) cropped from this page.
  final int figures;

  /// `needs_render` (the app renders and uploads it) or
  /// `document_missing` (the file was deleted: attach pictures by hand).
  final String reason;

  bool get needsRender => reason == 'needs_render';

  static List<FigurePending> listOf(Object? json) => [
    if (json is List)
      for (final row in json)
        if (row is Map)
          FigurePending(
            sourceDocumentId: (row['source_document_id'] as num).toInt(),
            pageNo: (row['page_no'] as num?)?.toInt() ?? 1,
            originalName: row['original_name'] as String?,
            mimeType: row['mime_type'] as String?,
            figures: (row['figures'] as num?)?.toInt() ?? 0,
            reason: row['reason'] as String? ?? 'needs_render',
          ),
  ];
}

/// One question of one version (`GET /exams/{id}/versions` item).
class ExamVersionItem {
  const ExamVersionItem({
    required this.sheetNo,
    required this.questionId,
    required this.originalPosition,
    required this.sectionId,
    required this.type,
    this.points = 1,
    this.optionOrder,
    this.acceptedOptions = const [],
    this.acceptedValues = const [],
  });

  /// The number printed on this version's booklet and sheet.
  final int sheetNo;
  final int questionId;

  /// The number on version ก.
  final int originalPosition;
  final int sectionId;
  final ExamSectionType type;
  final double points;

  /// Original option positions by displayed position; null = not shuffled.
  final List<int>? optionOrder;

  /// Displayed positions of this version.
  final List<int> acceptedOptions;
  final List<String> acceptedValues;

  bool get shuffled =>
      optionOrder != null &&
      optionOrder!.asMap().entries.any((e) => e.value != e.key + 1);

  /// "ค ก ง ข": the original letters in the order printed on this version.
  String? get orderLabel =>
      optionOrder?.map((o) => examOptionLabel(o)).join(' ');

  /// The key as printed on this version ("ง", "ถูก", "0.5").
  String get keyLabel {
    if (type == ExamSectionType.numeric) {
      return acceptedValues.isEmpty ? '–' : acceptedValues.join(' หรือ ');
    }
    return acceptedOptions.isEmpty
        ? '–'
        : acceptedOptions.map((o) => examChoiceLabel(type, o)).join(', ');
  }

  factory ExamVersionItem.fromJson(Map<String, dynamic> json) {
    final order = json['option_order'];
    return ExamVersionItem(
      sheetNo: (json['sheet_no'] as num).toInt(),
      questionId: (json['question_id'] as num).toInt(),
      originalPosition: (json['original_position'] as num?)?.toInt() ?? 0,
      sectionId: (json['section_id'] as num?)?.toInt() ?? 0,
      type: ExamSectionType.fromApi(json['type'] as String?),
      points: _toDouble(json['points']) ?? 1,
      optionOrder: order is List
          ? [for (final o in order) (o as num).toInt()]
          : null,
      acceptedOptions: [
        for (final o in (json['accepted_options'] as List?) ?? const [])
          (o as num).toInt(),
      ],
      acceptedValues: [
        for (final v in (json['accepted_values'] as List?) ?? const [])
          v.toString(),
      ],
    );
  }
}

class ExamVersion {
  const ExamVersion({
    required this.versionNo,
    required this.label,
    this.items = const [],
  });

  final int versionNo;
  final String label;
  final List<ExamVersionItem> items;

  factory ExamVersion.fromJson(Map<String, dynamic> json) {
    final no = (json['version_no'] as num).toInt();
    return ExamVersion(
      versionNo: no,
      label: json['label'] as String? ?? examVersionLabel(no),
      items:
          ((json['items'] as List?) ?? const [])
              .cast<Map<String, dynamic>>()
              .map(ExamVersionItem.fromJson)
              .toList()
            ..sort((a, b) => a.sheetNo.compareTo(b.sheetNo)),
    );
  }
}

/// `GET /exams/{id}/versions` (§22.5, §22.15).
class ExamVersions {
  const ExamVersions({
    required this.versionCount,
    this.versions = const [],
    this.structureLockedAt,
    this.versionsReady = false,
  });

  final int versionCount;
  final List<ExamVersion> versions;
  final DateTime? structureLockedAt;
  final bool versionsReady;

  factory ExamVersions.fromJson(Map<String, dynamic> json) {
    final locked = json['structure_locked_at'];
    return ExamVersions(
      versionCount: (json['version_count'] as num?)?.toInt() ?? 1,
      versions:
          ((json['versions'] as List?) ?? const [])
              .cast<Map<String, dynamic>>()
              .map(ExamVersion.fromJson)
              .toList()
            ..sort((a, b) => a.versionNo.compareTo(b.versionNo)),
      structureLockedAt: locked is String ? DateTime.tryParse(locked) : null,
      versionsReady: json['versions_ready'] == true,
    );
  }
}

/// A new exam (`POST /assignments` with `kind = exam`, §22.15), or the
/// settings changed on one (`PATCH /assignments/{id}`).
class ExamSettingsDraft {
  const ExamSettingsDraft({
    required this.title,
    required this.examDate,
    this.classroomId,
    this.courseId,
    this.lessonPlanId,
    this.durationMinutes,
    this.gradingMethod = ExamGradingMethod.app,
    this.versionCount = 1,
    this.showKeyToStudents = false,
    this.manualFullMarks,
    this.gradebookCategoryId,
    this.excludedFromGrade = false,
  });

  final String title;
  final DateTime examDate;
  final int? classroomId;
  final int? courseId;
  final int? lessonPlanId;
  final int? durationMinutes;
  final ExamGradingMethod gradingMethod;
  final int versionCount;
  final bool showKeyToStudents;
  final double? manualFullMarks;

  /// The gradebook category (DESIGN §23.3): required once the course's
  /// gradebook is set up.
  final int? gradebookCategoryId;

  /// "ไม่นับเกรด".
  final bool excludedFromGrade;

  /// The create body: every exam field plus the classroom and course.
  Map<String, Object?> toCreateJson() => {
    'kind': Assignment.kindExam,
    'classroom_id': classroomId,
    'course_id': courseId,
    'lesson_plan_id': ?lessonPlanId,
    'title': title,
    'due_at': examDate.toUtc().toIso8601String(),
    'mode': 'worksheet',
    'grading_method': gradingMethod.apiValue,
    'version_count': versionCount,
    'duration_minutes': durationMinutes,
    'show_key_to_students': showKeyToStudents,
    if (gradingMethod == ExamGradingMethod.manual)
      'manual_full_marks': manualFullMarks,
    'gradebook_category_id': ?gradebookCategoryId,
    if (excludedFromGrade) 'excluded_from_grade': true,
  };

  /// The PATCH body: only what differs from [current]. version_count is
  /// structural (409 `exam_structure_locked` once printed), so it is sent
  /// only when it changed.
  Map<String, Object?> toUpdateJson(Assignment current) {
    final method = gradingMethod.apiValue;
    return {
      if (title != current.title) 'title': title,
      if (current.dueAt == null || !examDate.isAtSameMomentAs(current.dueAt!))
        'due_at': examDate.toUtc().toIso8601String(),
      if (courseId != null && courseId != current.courseId)
        'course_id': courseId,
      if (lessonPlanId != current.lessonPlanId) 'lesson_plan_id': lessonPlanId,
      if (durationMinutes != current.durationMinutes)
        'duration_minutes': durationMinutes,
      if (showKeyToStudents != current.showKeyToStudents)
        'show_key_to_students': showKeyToStudents,
      if (versionCount != current.versionCount) 'version_count': versionCount,
      if (gradingMethod == ExamGradingMethod.manual &&
          manualFullMarks != current.manualFullMarks)
        'manual_full_marks': manualFullMarks,
      if (method != current.gradingMethod) 'grading_method': method,
      if (gradebookCategoryId != null &&
          gradebookCategoryId != current.gradebookCategoryId)
        'gradebook_category_id': gradebookCategoryId,
      if (excludedFromGrade != current.excludedFromGrade)
        'excluded_from_grade': excludedFromGrade,
    };
  }
}

/// `POST /exams/{id}/sections` / `PATCH /exam-sections/{id}` (§22.15).
class ExamSectionDraft {
  const ExamSectionDraft({
    required this.type,
    this.title,
    this.instructions,
    this.optionCount,
    this.numeric,
    this.defaultPoints = 1,
    this.questionCount = 0,
  });

  final ExamSectionType type;
  final String? title;
  final String? instructions;
  final int? optionCount;
  final NumericSpec? numeric;
  final double defaultPoints;

  /// Blank questions created with a new section (0–100).
  final int questionCount;

  Map<String, Object?> toCreateJson() => {
    'title': _blankToNull(title),
    'instructions': _blankToNull(instructions),
    'type': type.apiValue,
    if (type == ExamSectionType.mcq) 'option_count': optionCount ?? 4,
    if (type == ExamSectionType.numeric)
      'numeric': (numeric ?? const NumericSpec(digits: 1)).toJson(),
    'default_points': defaultPoints,
    'question_count': questionCount,
  };

  /// The PATCH body against [current]: the type never changes, and the
  /// structural fields are sent only when they changed.
  Map<String, Object?> toUpdateJson(ExamSection current) {
    final n = numeric;
    final cn = current.numeric;
    return {
      if (_blankToNull(title) != _blankToNull(current.title))
        'title': _blankToNull(title),
      if (_blankToNull(instructions) != _blankToNull(current.instructions))
        'instructions': _blankToNull(instructions),
      if (defaultPoints != current.defaultPoints)
        'default_points': defaultPoints,
      if (type == ExamSectionType.mcq && optionCount != current.optionCount)
        'option_count': optionCount,
      if (type == ExamSectionType.numeric &&
          n != null &&
          (cn == null ||
              n.digits != cn.digits ||
              n.allowNegative != cn.allowNegative ||
              n.allowDecimal != cn.allowDecimal))
        'numeric': n.toJson(),
    };
  }
}

String? _blankToNull(String? s) {
  final t = s?.trim() ?? '';
  return t.isEmpty ? null : t;
}

/// `POST /exam-sections/{id}/questions` / `PATCH /questions/{id}` of an
/// exam question (§22.15). [options] are the mcq texts in original order.
class ExamQuestionDraft {
  const ExamQuestionDraft({
    required this.type,
    this.promptText = '',
    this.options,
    this.maxPoints,
    this.key,
    this.lockOptions,
    this.approve = false,
  });

  final ExamSectionType type;
  final String promptText;
  final List<String>? options;
  final double? maxPoints;
  final ExamKey? key;
  final bool? lockOptions;

  /// "อนุมัติข้อนี้" (PATCH only).
  final bool approve;

  Map<String, Object?> toJson({bool create = false}) => {
    'prompt_text': promptText.trim(),
    if (type == ExamSectionType.mcq && options != null)
      'options': [
        for (final o in options!) {'text': o.trim().isEmpty ? null : o.trim()},
      ],
    'max_points': ?maxPoints,
    'answer_key': key?.toJson(type),
    if (type == ExamSectionType.mcq && lockOptions != null)
      'lock_options': lockOptions,
    if (approve && !create) 'approve': true,
  };
}
