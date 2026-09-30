import '../../core/api/teacher_guidance.dart';
import '../assignments/answer_key_models.dart';
import 'exam_models.dart';

/// `document_extractions.purpose` of an exam read (DESIGN §22.4); a file
/// with it in `cached_purposes` was read before in this school.
const kExamReadPurpose = 'exam';

/// What the read put into the exam (`applied` of `POST /exams/{id}/import`).
class ExamImportApplied {
  const ExamImportApplied({
    this.sections = 0,
    this.questions = 0,
    this.skipped = const [],
  });

  final int sections;
  final int questions;

  /// Questions that were not created: not bubble-able (written answers) or
  /// past the exam limits.
  final List<ExamReadSkipped> skipped;

  static ExamImportApplied? maybe(Object? json) => json is Map
      ? ExamImportApplied(
          sections: (json['sections'] as num?)?.toInt() ?? 0,
          questions: (json['questions'] as num?)?.toInt() ?? 0,
          skipped: ExamReadSkipped.listOf(json['skipped']),
        )
      : null;
}

/// `{number, reason_th}` of a question the read left out.
class ExamReadSkipped {
  const ExamReadSkipped({this.number, required this.reason});

  final int? number;
  final String reason;

  String get label => number == null ? reason : 'ข้อ $number: $reason';

  static List<ExamReadSkipped> listOf(Object? json) => [
    if (json is List)
      for (final row in json)
        if (row is Map)
          ExamReadSkipped(
            number: (row['number'] as num?)?.toInt(),
            reason: row['reason_th'] as String? ?? '',
          ),
  ];
}

/// The state of one read (`extraction` of the import, or `GET
/// /document-extractions/{id}` while it is queued).
class ExamRead {
  const ExamRead({
    required this.id,
    required this.status,
    this.error,
    this.guidance,
    this.notesTh,
    this.skipped = const [],
  });

  final int id;

  /// `queued`, `done` or `failed`.
  final String status;
  final String? error;

  /// "คำแนะนำถึง AI" sent with the read (§21.12).
  final String? guidance;

  /// Gemini's notes for the teacher, once done.
  final String? notesTh;

  /// Questions Gemini did not read as bubble-able, once done.
  final List<ExamReadSkipped> skipped;

  bool get queued => status == 'queued';
  bool get done => status == 'done';
  bool get failed => status == 'failed';

  factory ExamRead.fromJson(Map<String, dynamic> json) {
    final result = json['result'];
    final notes = result is Map ? result['notes_th'] as String? : null;
    return ExamRead(
      id: (json['id'] as num).toInt(),
      status: json['status'] as String? ?? 'queued',
      error: json['error'] as String?,
      guidance: normalizeGuidance(json['guidance'] as String?),
      notesTh: notes == null || notes.trim().isEmpty ? null : notes.trim(),
      skipped: result is Map
          ? ExamReadSkipped.listOf(result['skipped'])
          : const [],
    );
  }
}

/// The answer of `POST /exams/{id}/import`: `200` when the school read the
/// files before (applied at once), `202` while the read is queued.
class ExamImportResult {
  const ExamImportResult({
    required this.cached,
    required this.read,
    this.estimate,
    this.applied,
    this.figuresPending = const [],
  });

  final bool cached;
  final ExamRead read;
  final CostEstimate? estimate;

  /// Null while queued: poll the read, then reload the exam.
  final ExamImportApplied? applied;
  final List<FigurePending> figuresPending;

  factory ExamImportResult.fromJson(Map<String, dynamic> json) =>
      ExamImportResult(
        cached: json['cached'] == true,
        read: ExamRead.fromJson(
          (json['extraction'] as Map).cast<String, dynamic>(),
        ),
        estimate: CostEstimate.maybe(json['estimate']),
        applied: ExamImportApplied.maybe(json['applied']),
        figuresPending: FigurePending.listOf(json['figures_pending']),
      );
}

/// The exam a library question comes from.
class LibraryExam {
  const LibraryExam({required this.id, required this.title, this.courseId});

  final int id;
  final String title;
  final int? courseId;
}

/// The section a library question comes from.
class LibrarySection {
  const LibrarySection({
    required this.id,
    required this.type,
    this.title,
    this.optionCount,
    this.numeric,
  });

  final int id;
  final ExamSectionType type;
  final String? title;
  final int? optionCount;
  final NumericSpec? numeric;

  /// "ปรนัย 4 ตัวเลือก" etc., as [ExamSection.typeSummary].
  String get typeSummary => ExamSection(
    id: id,
    position: 1,
    type: type,
    optionCount: optionCount,
    numeric: numeric,
  ).typeSummary;
}

/// A question of the teacher's earlier exams (`GET /teacher/exam-questions`).
class LibraryQuestion {
  const LibraryQuestion({
    required this.question,
    required this.exam,
    required this.section,
  });

  final ExamQuestion question;
  final LibraryExam exam;
  final LibrarySection section;

  int get id => question.id;

  factory LibraryQuestion.fromJson(Map<String, dynamic> json) {
    final exam = (json['exam'] as Map).cast<String, dynamic>();
    final section = (json['section'] as Map).cast<String, dynamic>();
    final numeric = section['numeric'];
    return LibraryQuestion(
      question: ExamQuestion.fromJson(json),
      exam: LibraryExam(
        id: (exam['id'] as num).toInt(),
        title: exam['title'] as String? ?? '',
        courseId: (exam['course_id'] as num?)?.toInt(),
      ),
      section: LibrarySection(
        id: (section['id'] as num).toInt(),
        type: ExamSectionType.fromApi(section['type'] as String?),
        title: section['title'] as String?,
        optionCount: (section['option_count'] as num?)?.toInt(),
        numeric: numeric is Map
            ? NumericSpec.fromJson(numeric.cast<String, dynamic>())
            : null,
      ),
    );
  }
}

/// One page of the library (cursor pagination).
class LibraryPage {
  const LibraryPage({this.items = const [], this.nextCursor});

  final List<LibraryQuestion> items;

  /// Null on the last page.
  final String? nextCursor;

  factory LibraryPage.fromJson(Map<String, dynamic> json) {
    final meta = json['meta'];
    return LibraryPage(
      items: [
        for (final row in (json['data'] as List?) ?? const [])
          if (row is Map) LibraryQuestion.fromJson(row.cast<String, dynamic>()),
      ],
      nextCursor: meta is Map ? meta['next_cursor'] as String? : null,
    );
  }
}

/// A question the copy left out (`skipped` of `POST .../copy-questions`).
class CopySkipped {
  const CopySkipped({
    required this.questionId,
    required this.reason,
    this.reasonTh,
  });

  final int questionId;

  /// `type_mismatch` or `option_count_mismatch`.
  final String reason;
  final String? reasonTh;

  String get label =>
      reasonTh ??
      switch (reason) {
        'type_mismatch' => 'ชนิดของข้อไม่ตรงกับตอนปลายทาง',
        'option_count_mismatch' => 'จำนวนตัวเลือกไม่ตรงกับตอนปลายทาง',
        _ => reason,
      };
}

/// `POST /exams/{id}/copy-questions` (201).
class ExamCopyResult {
  const ExamCopyResult({
    required this.created,
    required this.exam,
    this.questionIds = const [],
    this.skipped = const [],
  });

  final int created;
  final List<int> questionIds;
  final List<CopySkipped> skipped;

  /// The whole exam after the copy.
  final ExamDetail exam;

  factory ExamCopyResult.fromJson(Map<String, dynamic> json) => ExamCopyResult(
    created: (json['created'] as num?)?.toInt() ?? 0,
    questionIds: [
      for (final id in (json['question_ids'] as List?) ?? const [])
        (id as num).toInt(),
    ],
    skipped: [
      for (final row in (json['skipped'] as List?) ?? const [])
        if (row is Map)
          CopySkipped(
            questionId: (row['question_id'] as num).toInt(),
            reason: row['reason'] as String? ?? '',
            reasonTh: row['reason_th'] as String?,
          ),
    ],
    exam: ExamDetail.fromJson((json['exam'] as Map).cast<String, dynamic>()),
  );
}

/// A figure box in the page's 0–1000 coordinates (`box_2d`: [ymin, xmin,
/// ymax, xmax]), as the teacher draws it on the page image.
class FigureBox {
  const FigureBox(this.ymin, this.xmin, this.ymax, this.xmax);

  /// Smallest side the server takes (DESIGN §22.4: 5 units).
  static const minSide = 5;

  final double ymin;
  final double xmin;
  final double ymax;
  final double xmax;

  static FigureBox? fromList(List<int>? box) => box == null || box.length != 4
      ? null
      : FigureBox(
          box[0].toDouble(),
          box[1].toDouble(),
          box[2].toDouble(),
          box[3].toDouble(),
        );

  /// The box spanned by two points given in 0–1000 units (any order).
  factory FigureBox.span(double x1, double y1, double x2, double y2) =>
      FigureBox(
        _clamp(y1 < y2 ? y1 : y2),
        _clamp(x1 < x2 ? x1 : x2),
        _clamp(y1 < y2 ? y2 : y1),
        _clamp(x1 < x2 ? x2 : x1),
      );

  double get width => xmax - xmin;
  double get height => ymax - ymin;

  bool get valid => width >= minSide && height >= minSide;

  bool contains(double x, double y) =>
      x >= xmin && x <= xmax && y >= ymin && y <= ymax;

  /// Moved by (dx, dy) and kept inside the page.
  FigureBox moved(double dx, double dy) {
    final mx = dx.clamp(-xmin, 1000 - xmax).toDouble();
    final my = dy.clamp(-ymin, 1000 - ymax).toDouble();
    return FigureBox(ymin + my, xmin + mx, ymax + my, xmax + mx);
  }

  /// `box_2d` for `PUT .../figure`.
  List<int> toList() => [
    ymin.round(),
    xmin.round(),
    ymax.round(),
    xmax.round(),
  ];

  static double _clamp(double v) => v.clamp(0, 1000).toDouble();

  @override
  bool operator ==(Object other) =>
      other is FigureBox &&
      other.ymin == ymin &&
      other.xmin == xmin &&
      other.ymax == ymax &&
      other.xmax == xmax;

  @override
  int get hashCode => Object.hash(ymin, xmin, ymax, xmax);

  @override
  String toString() => 'FigureBox${toList()}';
}
