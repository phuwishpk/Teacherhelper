import 'fuzzy_trace.dart';
import 'review_labels.dart';

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

DateTime? _date(Object? v) => v is String ? DateTime.tryParse(v) : null;

bool _bool(Object? v) => v == true || v == 1 || v == '1' || v == 'true';

Map<String, dynamic>? _map(Object? v) =>
    v is Map ? v.cast<String, dynamic>() : null;

/// `{id, name, student_number}` of a student row.
class StudentRef {
  const StudentRef({required this.id, required this.name, this.studentNumber});

  final int id;
  final String name;
  final int? studentNumber;

  /// "เลขที่ 12 ด.ญ. สมหญิง".
  String get label =>
      studentNumber == null ? name : 'เลขที่ $studentNumber $name';

  static StudentRef? fromJson(Object? json) {
    final m = _map(json);
    if (m == null || m['id'] == null) return null;
    return StudentRef(
      id: _int(m['id'])!,
      name: m['name'] as String? ?? 'นักเรียน #${m['id']}',
      studentNumber: _int(m['student_number']),
    );
  }
}

/// Flags that change how a response is reviewed. The API may send them as
/// booleans or as a `flags: [...]` list; both are accepted. They are also
/// read from where the backend stores them when a payload carries the
/// stored JSON but not the top-level field (none of them is a §8.4 column):
/// `extraction.suspicious_instruction` (§10.3), the review-priority `flag`
/// inside `fuzzy_trace` (§11.8) and an `identity_mismatch` kept in
/// `fuzzy_trace` (§18.4). Missing the prompt-injection flag would hide the
/// "น่าสงสัย" warning from the teacher.
Set<String> _flags(Map<String, dynamic> json) {
  final extraction = _map(json['extraction']);
  final trace = _map(json['fuzzy_trace']);
  return {
    for (final source in [json, ?trace])
      if (source['flags'] case final List list)
        for (final f in list)
          if (f is String) f,
    if (_bool(json['suspicious']) ||
        _bool(json['suspicious_instruction']) ||
        _bool(extraction?['suspicious_instruction']) ||
        FuzzyTrace.parse(trace).suspicious)
      'suspicious',
    if (_bool(json['identity_mismatch']) || _bool(trace?['identity_mismatch']))
      'identity_mismatch',
    if (_bool(json['has_open_appeal']) || _bool(json['appeal_open']))
      'appeal_open',
  };
}

/// `manual_reason` at the top level, or where the backend stores it
/// (`fuzzy_trace.manual_reason`).
String? _manualReason(Map<String, dynamic> json) =>
    (json['manual_reason'] ?? _map(json['fuzzy_trace'])?['manual_reason'])
        as String?;

/// One row of `GET /assignments/{id}/review-queue` (DESIGN §9.5).
class ReviewItem {
  const ReviewItem({
    required this.id,
    required this.submissionId,
    required this.questionPosition,
    required this.questionType,
    required this.maxPoints,
    required this.gradingState,
    this.questionId,
    this.student,
    this.manualReason,
    this.band,
    this.reviewPriority,
    this.flags = const {},
    this.aiScore,
    this.finalScore,
    this.aiUnderstanding,
    this.finalUnderstanding,
    this.reviewedAt,
    this.submissionStatus,
  });

  /// The response id.
  final int id;
  final int submissionId;
  final int? questionId;
  final int questionPosition;
  final String questionType;
  final double maxPoints;
  final StudentRef? student;

  /// `queued` / `extracted` / `scored` / `failed` / `manual` (§8.4).
  final String gradingState;
  final String? manualReason;
  final PriorityBand? band;
  final double? reviewPriority;
  final Set<String> flags;
  final double? aiScore;
  final double? finalScore;
  final Understanding? aiUnderstanding;
  final Understanding? finalUnderstanding;
  final DateTime? reviewedAt;
  final String? submissionStatus;

  bool get isManual => gradingState == 'manual';
  bool get isSuspicious => flags.contains('suspicious');
  bool get identityMismatch => flags.contains('identity_mismatch');
  bool get hasOpenAppeal => flags.contains('appeal_open');
  bool get isReviewed => reviewedAt != null;
  bool get isPublished => submissionStatus == 'published';
  bool get missingAiKey => isManual && manualReason == 'ai_key_missing';

  /// A flag the teacher must look at: a possible prompt injection (§10.3)
  /// or a Classroom scan whose QR names another student than the one who
  /// handed it in (§18.3). Neither need raise review_priority (§11.8), so
  /// the app places these rows itself.
  bool get isFlagged => isSuspicious || identityMismatch;

  /// AI has not produced a score yet (still in the grading queue).
  bool get isGrading =>
      gradingState == 'queued' ||
      gradingState == 'extracted' ||
      (gradingState == 'failed' && aiScore == null);

  /// Manual, not-yet-scored and flagged rows sit on the "ต้องตรวจ" tab
  /// (§11.8, §18.3), whatever band the priority system gave them.
  PriorityBand get tab =>
      isManual || isFlagged || band == null ? PriorityBand.check : band!;

  double? get currentScore => finalScore ?? aiScore;
  Understanding? get currentUnderstanding =>
      finalUnderstanding ?? aiUnderstanding;

  /// Only confident, unflagged, scored rows go through bulk approval (§13):
  /// a suspicious or identity-mismatch row always needs the teacher's eyes.
  bool get bulkApprovable =>
      !isReviewed &&
      !isManual &&
      !isFlagged &&
      !hasOpenAppeal &&
      band == PriorityBand.confident &&
      aiScore != null;

  /// Queue order: manual first, then flagged (suspicious or identity
  /// mismatch), then review_priority descending.
  int compareQueueOrder(ReviewItem other) {
    int rank(ReviewItem i) => i.isManual ? 0 : (i.isFlagged ? 1 : 2);
    final r = rank(this).compareTo(rank(other));
    if (r != 0) return r;
    return (other.reviewPriority ?? 0).compareTo(reviewPriority ?? 0);
  }

  factory ReviewItem.fromJson(Map<String, dynamic> json) {
    final question = _map(json['question']);
    final submission = _map(json['submission']);
    return ReviewItem(
      id: _int(json['id'] ?? json['response_id'])!,
      submissionId: _int(json['submission_id'] ?? submission?['id'])!,
      questionId: _int(json['question_id'] ?? question?['id']),
      questionPosition:
          _int(json['question_position'] ?? question?['position']) ?? 0,
      questionType:
          (json['question_type'] ?? question?['type']) as String? ?? 'short',
      maxPoints: _double(json['max_points'] ?? question?['max_points']) ?? 0,
      student: StudentRef.fromJson(json['student'] ?? submission?['student']),
      gradingState: json['grading_state'] as String? ?? 'scored',
      manualReason: _manualReason(json),
      band: PriorityBand.fromApi(json['priority_band']),
      reviewPriority: _double(json['review_priority']),
      flags: _flags(json),
      aiScore: _double(json['ai_score']),
      finalScore: _double(json['final_score']),
      aiUnderstanding: Understanding.fromApi(json['ai_understanding']),
      finalUnderstanding: Understanding.fromApi(json['final_understanding']),
      reviewedAt: _date(json['reviewed_at']),
      submissionStatus:
          (json['submission_status'] ?? submission?['status']) as String?,
    );
  }
}

/// A rescan of a published page waiting for the teacher (§9.4
/// `pending_confirm`).
class PendingScan {
  const PendingScan({
    required this.scanId,
    required this.submissionId,
    this.pageNo,
    this.scannedAt,
    this.student,
  });

  final int scanId;
  final int submissionId;
  final int? pageNo;
  final DateTime? scannedAt;
  final StudentRef? student;

  factory PendingScan.fromJson(Map<String, dynamic> json) => PendingScan(
    scanId: _int(json['scan_id'] ?? json['id'])!,
    submissionId: _int(json['submission_id'])!,
    pageNo: _int(json['page_no']),
    scannedAt: _date(json['scanned_at']),
    student: StudentRef.fromJson(json['student']),
  );
}

/// Per-student progress for publishing one submission at a time.
class SubmissionSummary {
  const SubmissionSummary({
    required this.id,
    required this.status,
    required this.responseCount,
    required this.reviewedCount,
    this.student,
    this.totalScore,
  });

  final int id;

  /// `awaiting_scan` / `grading` / `needs_review` / `reviewed` / `published`.
  final String status;
  final int responseCount;
  final int reviewedCount;
  final StudentRef? student;
  final double? totalScore;

  bool get isPublished => status == 'published';
  bool get canPublish =>
      !isPublished && responseCount > 0 && reviewedCount >= responseCount;

  factory SubmissionSummary.fromJson(Map<String, dynamic> json) =>
      SubmissionSummary(
        id: _int(json['id'] ?? json['submission_id'])!,
        status: json['status'] as String? ?? 'needs_review',
        responseCount: _int(json['response_count']) ?? 0,
        reviewedCount: _int(json['reviewed_count']) ?? 0,
        student: StudentRef.fromJson(json['student']),
        totalScore: _double(json['total_score']),
      );

  /// Fallback when the queue has no `meta.submissions`: derived from rows.
  static List<SubmissionSummary> fromItems(List<ReviewItem> items) {
    final bySubmission = <int, List<ReviewItem>>{};
    for (final i in items) {
      bySubmission.putIfAbsent(i.submissionId, () => []).add(i);
    }
    final list = [
      for (final MapEntry(key: id, value: rows) in bySubmission.entries)
        SubmissionSummary(
          id: id,
          status:
              rows.first.submissionStatus ??
              (rows.every((r) => r.isReviewed) ? 'reviewed' : 'needs_review'),
          responseCount: rows.length,
          reviewedCount: rows.where((r) => r.isReviewed).length,
          student: rows.first.student,
          totalScore: rows.every((r) => r.currentScore != null)
              ? rows.fold<double>(0, (s, r) => s + r.currentScore!)
              : null,
        ),
    ];
    list.sort(
      (a, b) => (a.student?.studentNumber ?? 1 << 20).compareTo(
        b.student?.studentNumber ?? 1 << 20,
      ),
    );
    return list;
  }
}

/// The whole review queue of one assignment plus the `meta` block.
class ReviewQueue {
  const ReviewQueue({
    required this.items,
    this.missingAiKeyCount = 0,
    this.pendingScans = const [],
    this.submissions = const [],
  });

  final List<ReviewItem> items;
  final int missingAiKeyCount;
  final List<PendingScan> pendingScans;
  final List<SubmissionSummary> submissions;

  /// Rows of one tab in queue order.
  List<ReviewItem> tab(PriorityBand band) =>
      items.where((i) => i.tab == band).toList()
        ..sort((a, b) => a.compareQueueOrder(b));

  int unreviewedIn(PriorityBand band) =>
      items.where((i) => i.tab == band && !i.isReviewed).length;

  List<ReviewItem> get bulkApprovable =>
      items.where((i) => i.bulkApprovable).toList();

  int get publishableCount => submissions.where((s) => s.canPublish).length;

  /// [meta] is the `meta` object of the first page (counts for the banner,
  /// scans waiting for confirm-replace, per-submission progress).
  factory ReviewQueue.fromParts(
    List<ReviewItem> items,
    Map<String, dynamic>? meta,
  ) {
    final missing =
        _int(meta?['missing_ai_key_count']) ??
        items.where((i) => i.missingAiKey && !i.isReviewed).length;
    final scans = [
      if (meta?['pending_confirm_scans'] case final List list)
        for (final s in list)
          if (s is Map) PendingScan.fromJson(s.cast<String, dynamic>()),
    ];
    final submissions = meta?['submissions'] is List
        ? [
            for (final s in meta!['submissions'] as List)
              if (s is Map) SubmissionSummary.fromJson(s.cast()),
          ]
        : SubmissionSummary.fromItems(items);
    return ReviewQueue(
      items: items,
      missingAiKeyCount: missing,
      pendingScans: scans,
      submissions: submissions,
    );
  }
}

/// A rubric criterion as shown next to Gemini's `criteria` levels.
class CriterionRef {
  const CriterionRef({
    required this.id,
    required this.description,
    this.points,
    this.isCore = false,
  });

  final int id;
  final String description;
  final double? points;
  final bool isCore;

  factory CriterionRef.fromJson(Map<String, dynamic> json) => CriterionRef(
    id: _int(json['id'])!,
    description: json['description'] as String? ?? '',
    points: _double(json['points']),
    isCore: _bool(json['is_core']),
  );
}

/// The question part of a response detail.
class ReviewQuestion {
  const ReviewQuestion({
    required this.position,
    required this.type,
    required this.maxPoints,
    this.id,
    this.promptText = '',
    this.answerKey,
    this.criteria = const [],
  });

  final int? id;
  final int position;
  final String type;
  final double maxPoints;
  final String promptText;
  final Map<String, dynamic>? answerKey;
  final List<CriterionRef> criteria;

  /// One line summary of the teacher's key for the review pane.
  String? get keySummary {
    final key = answerKey;
    if (key == null) return null;
    if (key['correct'] case final String c) return 'ตัวเลือกที่ถูก: $c';
    final finalKey = _map(key['final']) ?? key;
    if (finalKey['accepted'] case final List accepted
        when accepted.isNotEmpty) {
      return 'คำตอบที่ยอมรับ: ${accepted.join(', ')}';
    }
    return null;
  }

  factory ReviewQuestion.fromJson(Map<String, dynamic>? json) {
    final m = json ?? const <String, dynamic>{};
    final criteria = m['rubric_criteria'] ?? m['criteria'];
    return ReviewQuestion(
      id: _int(m['id']),
      position: _int(m['position']) ?? 0,
      type: m['type'] as String? ?? 'short',
      maxPoints: _double(m['max_points']) ?? 0,
      promptText: m['prompt_text'] as String? ?? '',
      answerKey: _map(m['answer_key']),
      criteria: [
        if (criteria is List)
          for (final c in criteria)
            if (c is Map) CriterionRef.fromJson(c.cast()),
      ],
    );
  }
}

/// `GET /responses/{id}` (DESIGN §9.5): extraction, fuzzy trace and
/// explanation of one answer.
class ResponseDetail {
  const ResponseDetail({
    required this.id,
    required this.submissionId,
    required this.question,
    required this.gradingState,
    this.student,
    this.submissionStatus,
    this.manualReason,
    this.band,
    this.reviewPriority,
    this.flags = const {},
    this.extraction,
    this.fuzzyTrace,
    this.aiScore,
    this.aiUnderstanding,
    this.aiErrorTypes = const [],
    this.finalScore,
    this.finalUnderstanding,
    this.finalErrorTypes,
    this.explanation,
    this.nextStep,
    this.explanationEdited = false,
    this.reviewedAt,
    this.cnnText,
    this.cnnConfidence,
    this.inkRatio,
    this.mcqFill,
    this.hasCrop = true,
    this.hasFinalCrop = false,
    this.appeal,
  });

  final int id;
  final int submissionId;
  final ReviewQuestion question;
  final String gradingState;
  final StudentRef? student;
  final String? submissionStatus;
  final String? manualReason;
  final PriorityBand? band;
  final double? reviewPriority;
  final Set<String> flags;
  final Map<String, dynamic>? extraction;
  final Object? fuzzyTrace;
  final double? aiScore;
  final Understanding? aiUnderstanding;
  final List<ErrorType> aiErrorTypes;
  final double? finalScore;
  final Understanding? finalUnderstanding;

  /// Null when the teacher has not set them (the AI values apply).
  final List<ErrorType>? finalErrorTypes;
  final String? explanation;
  final String? nextStep;
  final bool explanationEdited;
  final DateTime? reviewedAt;
  final String? cnnText;
  final double? cnnConfidence;
  final double? inkRatio;
  final Map<String, double>? mcqFill;
  final bool hasCrop;
  final bool hasFinalCrop;
  final Appeal? appeal;

  bool get isManual => gradingState == 'manual';
  bool get isSuspicious => flags.contains('suspicious');
  bool get identityMismatch => flags.contains('identity_mismatch');
  bool get isReviewed => reviewedAt != null;
  bool get isPublished => submissionStatus == 'published';
  double get maxPoints => question.maxPoints;

  /// Values the review form starts from: the teacher's if set, else the AI's.
  double? get startScore => finalScore ?? aiScore;
  Understanding? get startUnderstanding =>
      finalUnderstanding ?? aiUnderstanding;
  List<ErrorType> get startErrorTypes => finalErrorTypes ?? aiErrorTypes;

  factory ResponseDetail.fromJson(Map<String, dynamic> json) {
    final question = ReviewQuestion.fromJson(_map(json['question']));
    final submission = _map(json['submission']);
    final fill = _map(json['mcq_fill']);
    final finalTypes = json['final_error_types'];
    return ResponseDetail(
      id: _int(json['id'])!,
      submissionId: _int(json['submission_id'] ?? submission?['id'])!,
      question: question,
      gradingState: json['grading_state'] as String? ?? 'scored',
      student: StudentRef.fromJson(json['student'] ?? submission?['student']),
      submissionStatus:
          (json['submission_status'] ?? submission?['status']) as String?,
      manualReason: _manualReason(json),
      band: PriorityBand.fromApi(json['priority_band']),
      reviewPriority: _double(json['review_priority']),
      flags: _flags(json),
      extraction: _map(json['extraction']),
      fuzzyTrace: json['fuzzy_trace'],
      aiScore: _double(json['ai_score']),
      aiUnderstanding: Understanding.fromApi(json['ai_understanding']),
      aiErrorTypes: ErrorType.listFromJson(json['ai_error_types']),
      finalScore: _double(json['final_score']),
      finalUnderstanding: Understanding.fromApi(json['final_understanding']),
      finalErrorTypes: finalTypes == null
          ? null
          : ErrorType.listFromJson(finalTypes),
      explanation: json['explanation'] as String?,
      nextStep: (json['next_step'] ?? json['next_step_th']) as String?,
      explanationEdited: _bool(json['explanation_edited']),
      reviewedAt: _date(json['reviewed_at']),
      cnnText: json['cnn_text'] as String?,
      cnnConfidence: _double(json['cnn_confidence']),
      inkRatio: _double(json['ink_ratio']),
      mcqFill: fill?.map((k, v) => MapEntry(k, _double(v) ?? 0)),
      hasCrop: json.containsKey('has_crop') ? _bool(json['has_crop']) : true,
      hasFinalCrop:
          _bool(json['has_final_crop']) || json['final_crop_url'] != null,
      appeal: _map(json['appeal']) == null
          ? null
          : Appeal.fromJson(_map(json['appeal'])!),
    );
  }
}

/// What the teacher decided for one response (`PATCH /responses/{id}`).
class ReviewDecision {
  const ReviewDecision({
    required this.finalScore,
    required this.understanding,
    required this.errorTypes,
    this.explanation,
    this.reason,
  });

  final double finalScore;
  final Understanding understanding;
  final List<ErrorType> errorTypes;

  /// Null = unchanged (not sent, so `explanation_edited` stays as it is).
  final String? explanation;

  /// Required by the server when [finalScore] differs from `ai_score`.
  final String? reason;

  Map<String, dynamic> toJson() => {
    'final_score': finalScore,
    'final_understanding': understanding.apiValue,
    'final_error_types': [for (final e in errorTypes) e.apiValue],
    'explanation': ?explanation,
    'reason': ?reason,
  };
}

/// `appeals` row (DESIGN §8.4) with the context the lists need.
class Appeal {
  const Appeal({
    required this.id,
    required this.status,
    this.responseId,
    this.reason,
    this.teacherNote,
    this.createdAt,
    this.resolvedAt,
    this.student,
    this.assignmentId,
    this.assignmentTitle,
    this.submissionId,
    this.questionPosition,
    this.maxPoints,
    this.currentScore,
  });

  final int id;

  /// `open` / `accepted` / `rejected`.
  final String status;
  final int? responseId;
  final String? reason;
  final String? teacherNote;
  final DateTime? createdAt;
  final DateTime? resolvedAt;
  final StudentRef? student;
  final int? assignmentId;
  final String? assignmentTitle;
  final int? submissionId;
  final int? questionPosition;
  final double? maxPoints;
  final double? currentScore;

  bool get isOpen => status == 'open';

  String get statusLabel => switch (status) {
    'open' => 'รอครูตรวจ',
    'accepted' => 'ครูปรับผลแล้ว',
    'rejected' => 'ครูยืนยันผลเดิม',
    _ => status,
  };

  factory Appeal.fromJson(Map<String, dynamic> json) {
    final response = _map(json['response']);
    final assignment = _map(json['assignment']);
    final question = _map(json['question'] ?? response?['question']);
    return Appeal(
      id: _int(json['id'])!,
      status: json['status'] as String? ?? 'open',
      responseId: _int(json['response_id'] ?? response?['id']),
      reason: json['reason'] as String?,
      teacherNote: json['teacher_note'] as String?,
      createdAt: _date(json['created_at']),
      resolvedAt: _date(json['resolved_at']),
      student: StudentRef.fromJson(json['student']),
      assignmentId: _int(json['assignment_id'] ?? assignment?['id']),
      assignmentTitle:
          (json['assignment_title'] ?? assignment?['title']) as String?,
      submissionId: _int(json['submission_id'] ?? response?['submission_id']),
      questionPosition: _int(
        json['question_position'] ?? question?['position'],
      ),
      maxPoints: _double(json['max_points'] ?? question?['max_points']),
      currentScore: _double(
        json['final_score'] ??
            response?['final_score'] ??
            json['current_score'],
      ),
    );
  }
}

/// Outcome of `POST /assignments/{id}/publish`.
class PublishResult {
  const PublishResult({required this.published, this.skipped = 0});

  final int published;
  final int skipped;

  factory PublishResult.fromJson(Map<String, dynamic> json) => PublishResult(
    published: _int(json['published'] ?? json['published_count']) ?? 0,
    skipped: _int(json['skipped'] ?? json['skipped_count']) ?? 0,
  );
}

/// Reads an integer count from a `{key: n}` body (bulk actions answer with
/// e.g. `{approved: 12}` or `{requeued: 3}`).
int countFrom(Map<String, dynamic> json, List<String> keys) {
  for (final k in keys) {
    final v = _int(json[k]);
    if (v != null) return v;
  }
  return 0;
}
