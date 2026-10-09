import 'dart:convert';

import '../../platform/answer_sheet_pipeline.dart';
import 'exam_models.dart';

/// Parsed answer-sheet QR `EVX1.{assignment}.{student}.{page}.{layout}.{sig}`
/// (DESIGN §22.8). Student 0 is the teacher's key sheet.
///
/// The shared sheet of §22.19 is `EVC1.{assignment}.0.{page}.{layout}.{sig}`
/// ([shared]): its QR names no student, the ID grid on the page does.
/// [forStudent] gives the QR of such a page once its student is known, so
/// the rest of the scan flow treats both kinds alike.
class ExamQr {
  const ExamQr({
    required this.assignmentId,
    required this.studentId,
    required this.page,
    required this.layoutVersion,
    required this.signature,
    this.shared = false,
  });

  static const prefix = 'EVX1';
  static const sharedPrefix = 'EVC1';

  final int assignmentId;
  final int studentId;
  final int page;
  final int layoutVersion;
  final String signature;

  /// A shared sheet with the student-ID grid.
  final bool shared;

  bool get isKeySheet => !shared && studentId == 0;

  /// A shared sheet whose student is not known yet.
  bool get needsStudent => shared && studentId == 0;

  ExamQr forStudent(int studentId) => ExamQr(
    assignmentId: assignmentId,
    studentId: studentId,
    page: page,
    layoutVersion: layoutVersion,
    signature: signature,
    shared: shared,
  );

  static ExamQr? tryParse(String? payload) {
    if (payload == null) return null;
    final parts = payload.trim().split('.');
    if (parts.length != 6) return null;
    final shared = parts[0] == sharedPrefix;
    if (!shared && parts[0] != prefix) return null;
    final nums = parts.sublist(1, 5).map(int.tryParse).toList();
    if (nums.any((n) => n == null)) return null;
    if (shared && nums[1] != 0) return null;
    return ExamQr(
      assignmentId: nums[0]!,
      studentId: nums[1]!,
      page: nums[2]!,
      layoutVersion: nums[3]!,
      signature: parts[5],
      shared: shared,
    );
  }
}

/// Reads the student ID filled in on a shared answer sheet (DESIGN §22.19)
/// from the fill of its grid: the same rules as the server's
/// `StudentCodeReader`; both run test/fixtures/exam_scoring/codes.json.
///
/// A column is a digit when exactly one bubble is filled. Columns left of
/// the first digit may be empty; an empty column after it or two filled
/// bubbles in a column are [invalid]; any faint mark is [unclear] (nothing
/// is guessed); no mark at all is [blank]. Leading zeros are kept.
abstract final class StudentCodeReader {
  static const blank = 'blank';
  static const invalid = 'invalid';
  static const unclear = 'unclear';

  /// Sheet number of the ID grid in the layout and in `digits`.
  static const sheetNo = 0;

  static ({String? code, String? problem}) read(DigitFill? block) {
    var isUnclear = false;
    var isInvalid = false;
    final chars = <String>[];
    for (final column in block?.columns ?? const <Map<String, double>>[]) {
      final marked = <String>[];
      for (final e in column.entries) {
        if (e.value >= ExamSheetScorer.filledFrom) {
          marked.add(e.key);
        } else if (e.value >= ExamSheetScorer.ambiguousFrom) {
          isUnclear = true;
        }
      }
      if (marked.length > 1) isInvalid = true;
      chars.add(marked.length == 1 ? marked.single : '');
    }

    final first = chars.indexWhere((c) => c.isNotEmpty);
    final digits = first < 0 ? const <String>[] : chars.sublist(first);
    if (digits.any((c) => c.isEmpty)) isInvalid = true;
    final code = digits.join();
    if (code.isNotEmpty && !RegExp(r'^\d+$').hasMatch(code)) isInvalid = true;

    final problem = isInvalid
        ? invalid
        : isUnclear
        ? unclear
        : code.isEmpty
        ? blank
        : null;
    return (code: problem == null ? code : null, problem: problem);
  }
}

/// One question of a version's key by the number on the sheet (§22.9):
/// mcq / true_false options are the DISPLAYED positions of that version.
class ExamKeyItem {
  const ExamKeyItem({
    required this.sheetNo,
    required this.questionId,
    required this.type,
    required this.points,
    this.acceptedOptions = const [],
    this.acceptedValues = const [],
  });

  final int sheetNo;
  final int questionId;
  final ExamSectionType type;
  final double points;
  final List<int> acceptedOptions;
  final List<String> acceptedValues;

  factory ExamKeyItem.fromJson(Map<String, dynamic> json) => ExamKeyItem(
    sheetNo: (json['sheet_no'] as num).toInt(),
    questionId: (json['question_id'] as num).toInt(),
    type: ExamSectionType.fromApi(json['type'] as String?),
    points: (json['points'] as num?)?.toDouble() ?? 1,
    acceptedOptions: [
      for (final o in (json['accepted_options'] as List? ?? const []))
        (o as num).toInt(),
    ],
    acceptedValues: [
      for (final v in (json['accepted_values'] as List? ?? const [])) '$v',
    ],
  );
}

class ExamKitVersion {
  const ExamKitVersion({
    required this.versionNo,
    required this.label,
    required this.key,
  });

  final int versionNo;
  final String label;

  /// Sheet number -> key item.
  final Map<int, ExamKeyItem> key;
}

class ExamKitStudent {
  const ExamKitStudent({
    required this.studentId,
    required this.studentNumber,
    required this.name,
    this.studentCode,
  });

  final int studentId;
  final int studentNumber;
  final String name;

  /// The student's ID (เลขประจำตัว), null when they have none (§22.19).
  final String? studentCode;
}

/// `GET /exams/{id}/scan-kit` (DESIGN §22.9 step 1): what the phone needs
/// to read and score the answer sheets of one exam offline.
class ExamScanKit {
  const ExamScanKit({
    required this.assignmentId,
    required this.title,
    required this.layoutVersion,
    required this.pageCount,
    required this.versionCount,
    required this.kitHash,
    required this.versions,
    required this.layouts,
    required this.roster,
    required this.json,
    this.codeSheets = false,
    this.studentCodeDigits,
  });

  final int assignmentId;
  final String title;

  /// Null before the first answer-sheet or key-sheet print.
  final int? layoutVersion;
  final int pageCount;
  final int versionCount;
  final String kitHash;
  final Map<int, ExamKitVersion> versions;

  /// Layout JSON pages (§22.8), in page order.
  final List<Map<String, dynamic>> layouts;
  final List<ExamKitStudent> roster;

  /// The kit as received, cached in drift.
  final Map<String, dynamic> json;

  /// The exam uses the shared sheet with the student-ID grid (§22.19).
  final bool codeSheets;
  final int? studentCodeDigits;

  bool get printed => layoutVersion != null && layouts.isNotEmpty;

  factory ExamScanKit.fromJson(Map<String, dynamic> json) {
    final versions = <int, ExamKitVersion>{};
    for (final v in (json['versions'] as List? ?? const [])) {
      final m = v as Map<String, dynamic>;
      final no = (m['version_no'] as num).toInt();
      versions[no] = ExamKitVersion(
        versionNo: no,
        label: m['label'] as String? ?? examVersionLabel(no),
        key: {
          for (final item in (m['key'] as List? ?? const []))
            (item['sheet_no'] as num).toInt(): ExamKeyItem.fromJson(
              item as Map<String, dynamic>,
            ),
        },
      );
    }
    return ExamScanKit(
      assignmentId: (json['assignment_id'] as num).toInt(),
      title: json['title'] as String? ?? '',
      layoutVersion: (json['layout_version'] as num?)?.toInt(),
      pageCount: (json['page_count'] as num?)?.toInt() ?? 0,
      versionCount: (json['version_count'] as num?)?.toInt() ?? 1,
      kitHash: json['kit_hash'] as String? ?? '',
      versions: versions,
      layouts: [
        for (final p in (json['layouts'] as List? ?? const []))
          (p as Map).cast<String, dynamic>(),
      ],
      roster: [
        for (final s in (json['roster'] as List? ?? const []))
          ExamKitStudent(
            studentId: (s['student_id'] as num).toInt(),
            studentNumber: (s['student_number'] as num?)?.toInt() ?? 0,
            name: s['name'] as String? ?? '',
            studentCode: s['student_code'] as String?,
          ),
      ],
      json: json,
      codeSheets: json['sheet_identity'] == 'code',
      studentCodeDigits: (json['student_code_digits'] as num?)?.toInt(),
    );
  }

  factory ExamScanKit.decode(String text) =>
      ExamScanKit.fromJson(jsonDecode(text) as Map<String, dynamic>);

  String encode() => jsonEncode(json);

  /// The layout page [page] (1-based), or null.
  Map<String, dynamic>? layoutPage(int page) {
    for (var i = 0; i < layouts.length; i++) {
      final number = (layouts[i]['page'] as num?)?.toInt() ?? i + 1;
      if (number == page) return layouts[i];
    }
    return null;
  }

  ExamKitStudent? student(int studentId) {
    for (final s in roster) {
      if (s.studentId == studentId) return s;
    }
    return null;
  }

  /// The only student whose ID is [code], or null (none, or several).
  ExamKitStudent? studentByCode(String code) {
    ExamKitStudent? found;
    for (final s in roster) {
      if (s.studentCode != code) continue;
      if (found != null) return null;
      found = s;
    }
    return found;
  }

  /// Students the ID grid can never name: no ID, an ID with letters, or
  /// one longer than the grid. The teacher picks them by hand.
  int get studentsWithoutUsableCode {
    final digits = studentCodeDigits ?? 0;
    return roster.where((s) {
      final code = s.studentCode;
      return code == null ||
          !RegExp(r'^\d+$').hasMatch(code) ||
          code.length > digits;
    }).length;
  }

  /// Sheet numbers of the questions printed on [page] (rows and digit
  /// blocks; not the student-ID grid, which is sheet number 0).
  Set<int> sheetNumbersOn(int page) => {
    for (final r in (layoutPage(page)?['regions'] as List? ?? const []))
      if (r['sheet_no'] != null &&
          (r['sheet_no'] as num).toInt() != StudentCodeReader.sheetNo)
        (r['sheet_no'] as num).toInt(),
  };
}

/// Answer doubts of §22.3 that send an answer to review.
const kExamReviewDoubts = {'double_mark', 'ambiguous_mark', 'invalid_number'};

/// How the version of a page was decided (§22.9 step 3).
class ExamVersionDecision {
  const ExamVersionDecision(
    this.versionNo,
    this.source, {
    this.doubtful = false,
  });

  const ExamVersionDecision.unknown() : this(null, null);

  final int? versionNo;

  /// single | bubble | page_one, null while unknown.
  final String? source;
  final bool doubtful;
}

class ExamItemScore {
  const ExamItemScore({
    required this.sheetNo,
    required this.selected,
    required this.value,
    required this.score,
    required this.max,
    required this.doubts,
  });

  final int sheetNo;

  /// Marked displayed positions (rows); empty for digit blocks.
  final List<int> selected;

  /// Canonical number of a digit block, null when blank or invalid.
  final String? value;
  final double score;
  final double max;
  final List<String> doubts;

  bool get needsReview => doubts.any(kExamReviewDoubts.contains);
}

class ExamPageScore {
  const ExamPageScore({
    required this.items,
    required this.score,
    required this.maxScore,
  });

  final List<ExamItemScore> items;
  final double score;
  final double maxScore;

  int get reviewCount => items.where((i) => i.needsReview).length;
}

/// Scores an answer-sheet page on the phone (DESIGN §22.3, §22.9 step 3).
/// The same rules as the server's `ExamSheetScorer`; both run the golden
/// fixtures in test/fixtures/exam_scoring. The server's score is the one
/// that counts; this one is shown at once.
abstract final class ExamSheetScorer {
  static const filledFrom = 0.45;
  static const ambiguousFrom = 0.20;

  static ExamVersionDecision version(
    int versionCount,
    int page,
    Map<String, double>? versionFill,
    int? pageOneVersion,
  ) {
    if (versionCount <= 1) return const ExamVersionDecision(1, 'single');
    if (page > 1) {
      return pageOneVersion == null
          ? const ExamVersionDecision.unknown()
          : ExamVersionDecision(pageOneVersion, 'page_one');
    }
    final (marked, unclear) = _marks(versionFill ?? const {});
    final inRange = [
      for (final v in marked)
        if (v >= 1 && v <= versionCount) v,
    ];
    if (inRange.length != 1) return const ExamVersionDecision.unknown();
    return ExamVersionDecision(
      inRange.single,
      'bubble',
      doubtful: unclear.isNotEmpty,
    );
  }

  static ExamPageScore scorePage(
    Map<int, ExamKeyItem> key,
    Map<int, Map<String, double>> rows,
    Map<int, DigitFill> digits,
  ) {
    final items = <ExamItemScore>[
      for (final e in rows.entries)
        if (key[e.key] case final item?) scoreRow(e.key, item, e.value),
      for (final e in digits.entries)
        if (key[e.key] case final item?) scoreDigits(e.key, item, e.value),
    ]..sort((a, b) => a.sheetNo.compareTo(b.sheetNo));
    return ExamPageScore(
      items: items,
      score: _round(items.fold(0.0, (sum, i) => sum + i.score)),
      maxScore: _round(items.fold(0.0, (sum, i) => sum + i.max)),
    );
  }

  static ExamItemScore scoreRow(
    int sheetNo,
    ExamKeyItem key,
    Map<String, double> fill,
  ) {
    final reading = readRow(fill);
    final max = _round(key.points);
    final right =
        reading.selected.length == 1 &&
        key.acceptedOptions.contains(reading.selected.single);
    return ExamItemScore(
      sheetNo: sheetNo,
      selected: reading.selected,
      value: null,
      score: right ? max : 0,
      max: max,
      doubts: reading.doubts,
    );
  }

  static ExamItemScore scoreDigits(
    int sheetNo,
    ExamKeyItem key,
    DigitFill block,
  ) {
    final reading = readDigits(block);
    final max = _round(key.points);
    final right =
        reading.value != null && key.acceptedValues.contains(reading.value);
    return ExamItemScore(
      sheetNo: sheetNo,
      selected: const [],
      value: reading.value,
      score: right ? max : 0,
      max: max,
      doubts: reading.doubts,
    );
  }

  static ({List<int> selected, List<String> doubts}) readRow(
    Map<String, double> fill,
  ) {
    final (marked, unclear) = _marks(fill);
    return (
      selected: marked,
      doubts: [
        if (marked.length > 1) 'double_mark',
        if (unclear.isNotEmpty) 'ambiguous_mark',
      ],
    );
  }

  static ({String? value, List<String> doubts}) readDigits(DigitFill block) {
    var unclear = false;
    var invalid = false;
    final sign = block.sign;
    var negative = false;
    if (sign != null) {
      negative = sign >= filledFrom;
      unclear = !negative && sign >= ambiguousFrom;
    }

    final chars = <String>[];
    for (final column in block.columns) {
      final marked = <String>[];
      for (final e in column.entries) {
        if (e.value >= filledFrom) {
          marked.add(e.key);
        } else if (e.value >= ambiguousFrom) {
          unclear = true;
        }
      }
      if (marked.length > 1) invalid = true;
      chars.add(marked.length == 1 ? marked.single : '');
    }

    final filled = [
      for (var i = 0; i < chars.length; i++)
        if (chars[i].isNotEmpty) i,
    ];
    final text = chars.join();
    final blank = filled.isEmpty && !negative;
    if (!blank) {
      if (filled.isNotEmpty &&
          filled.length != filled.last - filled.first + 1) {
        invalid = true;
      }
      if ('.'.allMatches(text).length > 1 || !RegExp(r'\d').hasMatch(text)) {
        invalid = true;
      }
    }
    String? value;
    if (!blank && !invalid) {
      value = NumericAnswer.canonical('${negative ? '-' : ''}$text');
      invalid = value == null;
    }
    return (
      value: invalid ? null : value,
      doubts: [if (invalid) 'invalid_number', if (unclear) 'ambiguous_mark'],
    );
  }

  static (List<int>, List<int>) _marks(Map<String, double> fill) {
    final marked = <int>[];
    final unclear = <int>[];
    for (final e in fill.entries) {
      final position = int.tryParse(e.key);
      if (position == null) continue;
      if (e.value >= filledFrom) {
        marked.add(position);
      } else if (e.value >= ambiguousFrom) {
        unclear.add(position);
      }
    }
    marked.sort();
    unclear.sort();
    return (marked, unclear);
  }

  static double _round(double v) => (v * 100).roundToDouble() / 100;
}
