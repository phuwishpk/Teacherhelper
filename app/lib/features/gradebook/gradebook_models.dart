/// The gradebook of a course (DESIGN §23): settings shared by the course's
/// classrooms, the live grid of one classroom, and a student's own
/// published grade. Everything is computed on the server; the app only
/// shows it and sends what the teacher typed.
library;

import 'dart:typed_data';

/// `[80, 75, 70, 65, 60, 55, 50]`: the lowest total of grade 4 … 1 (§23.2).
const kDefaultCutoffs = [80, 75, 70, 65, 60, 55, 50];

/// The grade each of the 7 cutoffs stands for.
const kCutoffGrades = ['4', '3.5', '3', '2.5', '2', '1.5', '1'];

/// "ตัดคะแนนต่ำสุด k รายการ": 0–5 (§23.2).
const kMaxDropLowest = 5;

/// A course has at most 10 categories (§23.2).
const kMaxCategories = 10;

/// `PUT .../scores` takes at most 100 rows per request (§23.11).
const kMaxScoreRows = 100;

double? _double(Object? v) => v is num ? v.toDouble() : null;

int? _int(Object? v) => v is num ? v.toInt() : null;

DateTime? _date(Object? v) => v is String ? DateTime.tryParse(v) : null;

/// A number for the grid: at most [decimals] decimals, no trailing zeros
/// ("8", "8.5", "73.33").
String formatGbNumber(num? value, {int decimals = 2}) {
  if (value == null) return '–';
  final fixed = value.toDouble().toStringAsFixed(decimals);
  if (!fixed.contains('.')) return fixed;
  return fixed.replaceFirst(RegExp(r'\.?0+$'), '');
}

/// ร (`r`, รอการตัดสิน) and มส (`ms`, ไม่มีสิทธิ์สอบ), §23.6.
String? specialLabel(String? special) => switch (special) {
  'r' => 'ร',
  'ms' => 'มส',
  _ => null,
};

/// "4", "3.5", …, "0", "ร", "มส" (§23.6); an empty string without a grade.
String gradeLabel(num? grade, String? special) =>
    specialLabel(special) ?? (grade == null ? '' : formatGbNumber(grade));

/// Weights as whole hundredths, so 33.33 + 33.33 + 33.34 is exactly 100.
int weightCents(Iterable<double> weights) =>
    weights.fold(0, (sum, w) => sum + (w * 100).round());

/// Why 7 cutoffs are not valid (the server's rule, §23.2), or null.
String? cutoffsProblem(List<int?> cutoffs) {
  if (cutoffs.length != kCutoffGrades.length) {
    return 'เกณฑ์เกรดต้องมี 7 ค่า';
  }
  if (cutoffs.any((c) => c == null)) return 'กรอกเกณฑ์เกรดให้ครบ 7 ค่า';
  if (cutoffs.any((c) => c! < 1 || c > 100)) {
    return 'เกณฑ์เกรดต้องอยู่ในช่วง 1–100';
  }
  for (var i = 1; i < cutoffs.length; i++) {
    if (cutoffs[i]! >= cutoffs[i - 1]!) {
      return 'เกณฑ์เกรดต้องลดหลั่นจากเกรด 4 ลงไปเกรด 1 และห้ามซ้ำกัน';
    }
  }
  return null;
}

/// Why [value] cannot be a score out of [fullMarks] (0 … full marks, at
/// most 2 decimals, §23.3), or null.
String? scoreProblem(double? value, double fullMarks) {
  if (value == null) return 'ไม่ใช่ตัวเลข';
  if (value < 0) return 'ติดลบ';
  if (value > fullMarks + 1e-9) {
    return 'เกินคะแนนเต็ม (${formatGbNumber(fullMarks)})';
  }
  if (((value * 100) - (value * 100).roundToDouble()).abs() > 1e-6) {
    return 'ทศนิยมเกิน 2 ตำแหน่ง';
  }
  return null;
}

/// A typed score: "8", "8.5", or "8,5" from a Thai keyboard or Excel.
double? parseScore(String text) =>
    double.tryParse(text.trim().replaceAll(',', '.'));

/// One line of "วางคะแนนจาก Excel" (§23.3): the first tab-separated field
/// of a line; a blank line changes nothing.
class PastedScore {
  const PastedScore({required this.raw, this.value, this.error});

  final String raw;
  final double? value;
  final String? error;

  bool get blank => raw.isEmpty;
  bool get valid => !blank && error == null;
}

/// Splits clipboard text into one value per line. Trailing empty lines
/// (Excel ends a copy with a line break) are dropped.
List<PastedScore> parsePastedScores(String text, double fullMarks) {
  final lines = text.split(RegExp(r'\r\n|\r|\n'));
  while (lines.isNotEmpty && lines.last.trim().isEmpty) {
    lines.removeLast();
  }
  return [
    for (final line in lines)
      () {
        final raw = line.split('\t').first.trim();
        if (raw.isEmpty) return const PastedScore(raw: '');
        final value = parseScore(raw);
        return PastedScore(
          raw: raw,
          value: value,
          error: scoreProblem(value, fullMarks),
        );
      }(),
  ];
}

/// `GET /gradebook/templates` (§23.2).
class GradebookTemplate {
  const GradebookTemplate({
    required this.key,
    required this.name,
    this.categories = const [],
  });

  final String key;
  final String name;
  final List<CategoryDraft> categories;

  factory GradebookTemplate.fromJson(Map<String, dynamic> json) =>
      GradebookTemplate(
        key: json['key'] as String,
        name: json['name'] as String? ?? '',
        categories: [
          for (final c in (json['categories'] as List? ?? const []))
            CategoryDraft.fromJson((c as Map).cast<String, dynamic>()),
        ],
      );
}

/// A category as the settings editor holds it: [id] null for a new one.
class CategoryDraft {
  const CategoryDraft({
    this.id,
    required this.name,
    required this.weight,
    this.dropLowest = 0,
    this.isHomeworkDefault = false,
    this.itemCount = 0,
  });

  final int? id;
  final String name;

  /// Percent of the course total; all categories sum to 100 (§23.2).
  final double weight;
  final int dropLowest;
  final bool isHomeworkDefault;

  /// Assignments and items in it (the delete warning of §23.2).
  final int itemCount;

  factory CategoryDraft.fromJson(Map<String, dynamic> json) => CategoryDraft(
    id: _int(json['id']),
    name: json['name'] as String? ?? '',
    weight: _double(json['weight']) ?? 0,
    dropLowest: _int(json['drop_lowest']) ?? 0,
    isHomeworkDefault: json['is_homework_default'] == true,
    itemCount: _int(json['item_count']) ?? 0,
  );

  Map<String, Object?> toJson() => {
    'id': ?id,
    'name': name.trim(),
    'weight': weight,
    'drop_lowest': dropLowest,
    'is_homework_default': isHomeworkDefault,
  };

  bool sameAs(CategoryDraft o) =>
      id == o.id &&
      name.trim() == o.name.trim() &&
      weightCents([weight]) == weightCents([o.weight]) &&
      dropLowest == o.dropLowest &&
      isHomeworkDefault == o.isHomeworkDefault;
}

/// `GET /courses/{id}/gradebook/settings` (§23.11).
class GradebookSettings {
  const GradebookSettings({
    required this.configured,
    this.template,
    this.categories = const [],
    this.cutoffs = kDefaultCutoffs,
    this.defaultCutoffs = kDefaultCutoffs,
    this.uncategorisedCount = 0,
  });

  final bool configured;
  final String? template;
  final List<CategoryDraft> categories;
  final List<int> cutoffs;
  final List<int> defaultCutoffs;

  /// Assignments and items of the course without a category (not counted).
  final int uncategorisedCount;

  CategoryDraft? get homeworkDefault =>
      categories.where((c) => c.isHomeworkDefault).firstOrNull;

  factory GradebookSettings.fromJson(Map<String, dynamic> json) {
    List<int> ints(Object? v) => [
      if (v is List)
        for (final n in v)
          if (n is num) n.toInt(),
    ];
    final cutoffs = ints(json['cutoffs']);
    final defaults = ints(json['default_cutoffs']);
    return GradebookSettings(
      configured: json['configured'] == true,
      template: json['template'] as String?,
      categories: [
        for (final c in (json['categories'] as List? ?? const []))
          CategoryDraft.fromJson((c as Map).cast<String, dynamic>()),
      ],
      cutoffs: cutoffs.length == 7 ? cutoffs : kDefaultCutoffs,
      defaultCutoffs: defaults.length == 7 ? defaults : kDefaultCutoffs,
      uncategorisedCount: _int(json['uncategorised_count']) ?? 0,
    );
  }
}

/// The state of one cell (§23.4, `cells.*.state`).
enum CellState {
  scored('scored'),
  missing('missing'),
  notDue('not_due'),
  pending('pending'),
  notCounted('not_counted'),
  excused('excused');

  const CellState(this.apiValue);

  final String apiValue;

  static CellState fromApi(Object? value) => values.firstWhere(
    (s) => s.apiValue == value,
    orElse: () => CellState.notCounted,
  );
}

/// What kind of column: an app-graded assignment, a manual exam, or an
/// item the teacher added (§23.3).
enum ColumnType {
  assignment('assignment'),
  manualExam('manual_exam'),
  custom('custom');

  const ColumnType(this.apiValue);

  final String apiValue;

  static ColumnType fromApi(Object? value) => values.firstWhere(
    (t) => t.apiValue == value,
    orElse: () => ColumnType.assignment,
  );
}

class GradebookColumn {
  const GradebookColumn({
    required this.key,
    required this.type,
    required this.id,
    required this.name,
    this.kind,
    this.categoryId,
    this.fullMarks = 0,
    this.counted = false,
    this.dueAt,
    this.excludedFromGrade = false,
    this.isAttendance = false,
    this.editable = false,
  });

  /// `a{assignment id}` or `i{item id}`.
  final String key;
  final ColumnType type;
  final int id;
  final String name;

  /// `homework` or `exam` for assignments.
  final String? kind;
  final int? categoryId;
  final double fullMarks;

  /// Counted in the classroom's formula yet (§23.4).
  final bool counted;
  final DateTime? dueAt;
  final bool excludedFromGrade;
  final bool isAttendance;

  /// Scores are typed in the grid (manual exams and items).
  final bool editable;

  bool get isExam => kind == 'exam';

  /// App-graded work whose full marks are 0 (a mirror without questions):
  /// never counted (§23.3).
  bool get zeroFullMarks => fullMarks <= 0;

  /// Why the column is shown faint, if it is never counted.
  String? get notCountedReason {
    if (excludedFromGrade) return 'ไม่นับเกรด';
    if (zeroFullMarks) return 'คะแนนเต็มเป็น 0 ไม่นับเกรด';
    if (categoryId == null) return 'ยังไม่ระบุหมวด (ไม่นับ)';
    return null;
  }

  factory GradebookColumn.fromJson(Map<String, dynamic> json) =>
      GradebookColumn(
        key: json['key'] as String,
        type: ColumnType.fromApi(json['type']),
        id: _int(json['id']) ?? 0,
        name: json['name'] as String? ?? '',
        kind: json['kind'] as String?,
        categoryId: _int(json['category_id']),
        fullMarks: _double(json['full_marks']) ?? 0,
        counted: json['counted'] == true,
        dueAt: _date(json['due_at']),
        excludedFromGrade: json['excluded_from_grade'] == true,
        isAttendance: json['is_attendance'] == true,
        editable: json['editable'] == true,
      );
}

class GradebookCell {
  const GradebookCell({
    this.score,
    this.percent,
    this.state = CellState.notCounted,
    this.dropped = false,
    this.submissionId,
  });

  final double? score;
  final double? percent;
  final CellState state;

  /// "ตัดออก" by drop-lowest-k (§23.2).
  final bool dropped;

  /// App-graded work: the submission to open.
  final int? submissionId;

  factory GradebookCell.fromJson(Map<String, dynamic> json) => GradebookCell(
    score: _double(json['score']),
    percent: _double(json['percent']),
    state: CellState.fromApi(json['state']),
    dropped: json['dropped'] == true,
    submissionId: _int(json['submission_id']),
  );
}

/// A category of the grid: `has_items` is false while nothing in it is
/// counted in the classroom (the total is then "ระหว่างภาค").
class GridCategory {
  const GridCategory({
    required this.id,
    required this.name,
    required this.weight,
    this.dropLowest = 0,
    this.hasItems = false,
  });

  final int id;
  final String name;
  final double weight;
  final int dropLowest;
  final bool hasItems;

  factory GridCategory.fromJson(Map<String, dynamic> json) => GridCategory(
    id: _int(json['id']) ?? 0,
    name: json['name'] as String? ?? '',
    weight: _double(json['weight']) ?? 0,
    dropLowest: _int(json['drop_lowest']) ?? 0,
    hasItems: json['has_items'] == true,
  );
}

/// A student's value in one category: null while it has no counted item.
class CategoryValue {
  const CategoryValue({this.percent, this.points});

  final double? percent;
  final double? points;
}

class GradebookRow {
  const GradebookRow({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    this.leftCourse = false,
    this.cells = const {},
    this.categories = const {},
    this.total,
    this.totalRounded,
    this.grade,
    this.special,
    this.specialNote,
    this.attendanceWarning = false,
    this.inProgress = false,
    this.countedWeight = 0,
  });

  final int studentId;
  final int studentNumber;
  final String name;
  final bool leftCourse;
  final Map<String, GradebookCell> cells;
  final Map<int, CategoryValue> categories;

  /// The live total (renormalised while in progress, §23.4).
  final double? total;
  final int? totalRounded;
  final double? grade;

  /// `r` or `ms`, replacing the numeric grade.
  final String? special;
  final String? specialNote;

  /// "อาจติด มส" (attendance below 80 %), a warning only.
  final bool attendanceWarning;
  final bool inProgress;

  /// The weight of the categories this student has a value in.
  final double countedWeight;

  GradebookCell cell(String key) => cells[key] ?? const GradebookCell();

  String get gradeText => gradeLabel(grade, special);

  /// Categories with a value ("คิดจาก x จาก y หมวด").
  int get valuedCategoryCount =>
      categories.values.where((v) => v.percent != null).length;

  factory GradebookRow.fromJson(Map<String, dynamic> json) {
    final cells = json['cells'];
    final categories = json['categories'];
    return GradebookRow(
      studentId: _int(json['student_id']) ?? 0,
      studentNumber: _int(json['student_number']) ?? 0,
      name: json['name'] as String? ?? '',
      leftCourse: json['left_course'] == true,
      cells: {
        if (cells is Map)
          for (final e in cells.entries)
            if (e.value is Map)
              e.key.toString(): GradebookCell.fromJson(
                (e.value as Map).cast<String, dynamic>(),
              ),
      },
      categories: {
        if (categories is Map)
          for (final e in categories.entries)
            if (e.value is Map && int.tryParse(e.key.toString()) != null)
              int.parse(e.key.toString()): CategoryValue(
                percent: _double((e.value as Map)['percent']),
                points: _double((e.value as Map)['points']),
              ),
      },
      total: _double(json['total']),
      totalRounded: _int(json['total_rounded']),
      grade: _double(json['grade']),
      special: json['special'] as String?,
      specialNote: json['special_note'] as String?,
      attendanceWarning: json['attendance_warning'] == true,
      inProgress: json['in_progress'] == true,
      countedWeight: _double(json['counted_weight']) ?? 0,
    );
  }
}

/// The current publication of the classroom; [stale] when the live values
/// differ from what the students see (§23.7).
class GradebookPublication {
  const GradebookPublication({
    required this.id,
    required this.publishedAt,
    this.stale = false,
  });

  final int id;
  final DateTime publishedAt;
  final bool stale;
}

/// `GET /courses/{id}/gradebook?classroom_id=` (§23.11).
class GradebookGrid {
  const GradebookGrid({
    required this.courseId,
    required this.classroomId,
    this.courseCode = '',
    this.courseName = '',
    this.classroomName = '',
    this.configured = false,
    this.complete = false,
    this.missingCategories = const [],
    this.countedWeight = 0,
    this.categories = const [],
    this.columns = const [],
    this.rows = const [],
    this.publication,
  });

  final int courseId;
  final int classroomId;
  final String courseCode;
  final String courseName;
  final String classroomName;
  final bool configured;

  /// Every category has a counted item: totals are final and graded.
  final bool complete;

  /// Names of the categories without a counted item yet.
  final List<String> missingCategories;
  final double countedWeight;
  final List<GridCategory> categories;
  final List<GradebookColumn> columns;
  final List<GradebookRow> rows;
  final GradebookPublication? publication;

  GradebookColumn? column(String key) =>
      columns.where((c) => c.key == key).firstOrNull;

  GridCategory? category(int? id) =>
      id == null ? null : categories.where((c) => c.id == id).firstOrNull;

  /// Columns that are not "ไม่นับเกรด" and have no category.
  List<GradebookColumn> get uncategorisedColumns => [
    for (final c in columns)
      if (c.categoryId == null && !c.excludedFromGrade) c,
  ];

  /// How many cells are in [state] across the grid.
  int countCells(CellState state) {
    var n = 0;
    for (final r in rows) {
      for (final c in r.cells.values) {
        if (c.state == state) n++;
      }
    }
    return n;
  }

  factory GradebookGrid.fromJson(Map<String, dynamic> json) {
    final course = (json['course'] as Map?)?.cast<String, dynamic>() ?? {};
    final classroom =
        (json['classroom'] as Map?)?.cast<String, dynamic>() ?? {};
    final publication = json['publication'];
    List<Map<String, dynamic>> maps(Object? v) => [
      if (v is List)
        for (final m in v)
          if (m is Map) m.cast<String, dynamic>(),
    ];
    return GradebookGrid(
      courseId: _int(course['id']) ?? 0,
      courseCode: course['code'] as String? ?? '',
      courseName: course['name'] as String? ?? '',
      classroomId: _int(classroom['id']) ?? 0,
      classroomName: classroom['name'] as String? ?? '',
      configured: json['configured'] == true,
      complete: json['complete'] == true,
      missingCategories: [
        for (final n in (json['missing_categories'] as List? ?? const []))
          n.toString(),
      ],
      countedWeight: _double(json['counted_weight']) ?? 0,
      categories: maps(json['categories']).map(GridCategory.fromJson).toList(),
      columns: maps(json['columns']).map(GradebookColumn.fromJson).toList(),
      rows: maps(json['rows']).map(GradebookRow.fromJson).toList(),
      publication: publication is Map
          ? GradebookPublication(
              id: _int(publication['id']) ?? 0,
              publishedAt: _date(publication['published_at']) ?? DateTime(1970),
              stale: publication['stale'] == true,
            )
          : null,
    );
  }
}

/// An item the teacher added (`gradebook_items`, §23.3).
class GradebookItem {
  const GradebookItem({
    required this.id,
    required this.classroomId,
    required this.name,
    required this.maxPoints,
    this.categoryId,
    this.isAttendance = false,
  });

  final int id;
  final int classroomId;
  final int? categoryId;
  final String name;
  final double maxPoints;
  final bool isAttendance;

  factory GradebookItem.fromJson(Map<String, dynamic> json) => GradebookItem(
    id: _int(json['id']) ?? 0,
    classroomId: _int(json['classroom_id']) ?? 0,
    categoryId: _int(json['category_id']),
    name: json['name'] as String? ?? '',
    maxPoints: _double(json['max_points']) ?? 0,
    isAttendance: json['is_attendance'] == true,
  );
}

/// "เพิ่มรายการคะแนน" / "แก้รายการ".
class GradebookItemDraft {
  const GradebookItemDraft({
    required this.name,
    required this.maxPoints,
    required this.categoryId,
    this.isAttendance = false,
    this.classroomIds = const [],
  });

  final String name;
  final double maxPoints;
  final int categoryId;
  final bool isAttendance;

  /// One item per classroom (create only).
  final List<int> classroomIds;

  Map<String, Object?> toCreateJson() => {
    'classroom_ids': classroomIds,
    'category_id': categoryId,
    'name': name.trim(),
    'max_points': maxPoints,
    'is_attendance': isAttendance,
  };

  Map<String, Object?> toUpdateJson() => {
    'category_id': categoryId,
    'name': name.trim(),
    'max_points': maxPoints,
    'is_attendance': isAttendance,
  };
}

/// One row of `PUT .../scores`: [score] is sent only with [setScore]
/// (null clears it), [excused] only when set.
class ScoreChange {
  const ScoreChange({
    required this.studentId,
    this.score,
    this.setScore = false,
    this.excused,
  });

  const ScoreChange.score(this.studentId, this.score)
    : setScore = true,
      excused = null;

  const ScoreChange.excused(this.studentId, bool this.excused)
    : score = null,
      setScore = false;

  final int studentId;
  final double? score;
  final bool setScore;
  final bool? excused;

  Map<String, Object?> toJson() => {
    'student_id': studentId,
    if (setScore) 'score': score,
    'excused': ?excused,
  };
}

/// `201 {publication_id, published_at, student_count}` of "ประกาศเกรด".
class PublishResult {
  const PublishResult({
    required this.publicationId,
    required this.studentCount,
    this.publishedAt,
  });

  final int publicationId;
  final int studentCount;
  final DateTime? publishedAt;

  factory PublishResult.fromJson(Map<String, dynamic> json) => PublishResult(
    publicationId: _int(json['publication_id']) ?? 0,
    studentCount: _int(json['student_count']) ?? 0,
    publishedAt: _date(json['published_at']),
  );
}

/// The downloaded CSV of §23.8.
class CsvExport {
  const CsvExport({required this.fileName, required this.bytes});

  final String fileName;
  final Uint8List bytes;
}

/// One course of "เกรดของฉัน" (`GET /student/grades`).
class StudentGradeSummary {
  const StudentGradeSummary({
    required this.courseId,
    required this.courseCode,
    required this.courseName,
    this.classroomId,
    this.publishedAt,
    this.grade,
    this.special,
    this.totalRounded,
  });

  final int courseId;
  final String courseCode;
  final String courseName;
  final int? classroomId;
  final DateTime? publishedAt;
  final double? grade;
  final String? special;
  final int? totalRounded;

  String get courseTitle =>
      [courseCode, courseName].where((s) => s.isNotEmpty).join(' ');

  String get gradeText => gradeLabel(grade, special);

  factory StudentGradeSummary.fromJson(Map<String, dynamic> json) {
    final course = (json['course'] as Map?)?.cast<String, dynamic>() ?? {};
    return StudentGradeSummary(
      courseId: _int(course['id']) ?? 0,
      courseCode: course['code'] as String? ?? '',
      courseName: course['name'] as String? ?? '',
      classroomId: _int(json['classroom_id']),
      publishedAt: _date(json['published_at']),
      grade: _double(json['grade']),
      special: json['special'] as String?,
      totalRounded: _int(json['total_rounded']),
    );
  }
}

/// An item of a category in the student's published breakdown.
class BreakdownItem {
  const BreakdownItem({
    required this.name,
    this.score,
    this.max = 0,
    this.percent,
    this.state = CellState.scored,
    this.dropped = false,
  });

  final String name;
  final double? score;
  final double max;
  final double? percent;
  final CellState state;
  final bool dropped;

  factory BreakdownItem.fromJson(Map<String, dynamic> json) => BreakdownItem(
    name: json['name'] as String? ?? '',
    score: _double(json['score']),
    max: _double(json['max']) ?? 0,
    percent: _double(json['percent']),
    state: CellState.fromApi(json['state']),
    dropped: json['dropped'] == true,
  );
}

class BreakdownCategory {
  const BreakdownCategory({
    required this.name,
    required this.weight,
    this.percent,
    this.points,
    this.items = const [],
  });

  final String name;
  final double weight;
  final double? percent;
  final double? points;
  final List<BreakdownItem> items;

  factory BreakdownCategory.fromJson(Map<String, dynamic> json) =>
      BreakdownCategory(
        name: json['name'] as String? ?? '',
        weight: _double(json['weight']) ?? 0,
        percent: _double(json['percent']),
        points: _double(json['points']),
        items: [
          for (final i in (json['items'] as List? ?? const []))
            if (i is Map) BreakdownItem.fromJson(i.cast<String, dynamic>()),
        ],
      );
}

/// `GET /student/courses/{id}/grade`: the student's own row of the latest
/// publication (no class average, no ranking, §23.12).
class StudentGradeDetail {
  const StudentGradeDetail({
    required this.summary,
    this.total,
    this.breakdown = const [],
  });

  final StudentGradeSummary summary;
  final double? total;
  final List<BreakdownCategory> breakdown;

  factory StudentGradeDetail.fromJson(Map<String, dynamic> json) =>
      StudentGradeDetail(
        summary: StudentGradeSummary.fromJson(json),
        total: _double(json['total']),
        breakdown: [
          for (final c in (json['breakdown'] as List? ?? const []))
            if (c is Map) BreakdownCategory.fromJson(c.cast<String, dynamic>()),
        ],
      );
}

/// The Thai label of a cell state, for the grid and the student's view.
String cellStateLabel(CellState state, ColumnType type) => switch (state) {
  CellState.missing => type == ColumnType.assignment ? 'ไม่ส่ง' : 'ไม่มีคะแนน',
  CellState.notDue => 'ยังไม่ถึงกำหนด',
  CellState.pending => 'รอประกาศผล',
  CellState.excused => 'ยกเว้น',
  CellState.notCounted => 'ไม่นับ',
  CellState.scored => '',
};
