import 'dart:typed_data';

import 'assignment.dart';
import 'question.dart';

/// Pages Gemini reads in one call; a longer PDF needs a page range
/// (DESIGN §19.5, server `eduvision.documents.max_pages`).
const kMaxDocumentPages = 30;

/// A file the teacher picked or photographed, before `POST /documents`.
class PickedDocument {
  const PickedDocument({required this.name, this.path, this.bytes})
    : assert(path != null || bytes != null);

  final String name;

  /// Local file (Android); the web runner hands over [bytes] instead.
  final String? path;
  final Uint8List? bytes;

  /// Word and Google Docs are refused by the server (422
  /// `unsupported_file_type`); this only picks the multipart type.
  String get mimeType => documentMimeType(name);
}

/// MIME type by extension for the files the server accepts.
String documentMimeType(String name) {
  final dot = name.lastIndexOf('.');
  final ext = dot < 0 ? '' : name.substring(dot + 1).toLowerCase();
  return switch (ext) {
    'pdf' => 'application/pdf',
    'jpg' || 'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'heic' => 'image/heic',
    'heif' => 'image/heif',
    _ => 'application/octet-stream',
  };
}

/// Extensions offered by "แนบไฟล์" (DESIGN §19.5: PDF, JPEG, PNG, HEIC,
/// WebP; no Word or Google Docs).
const kDocumentExtensions = [
  'pdf',
  'jpg',
  'jpeg',
  'png',
  'heic',
  'heif',
  'webp',
];

/// `{input_tokens, output_tokens, thb}`: thb is null while the server has
/// no prices in its .env.
class CostEstimate {
  const CostEstimate({
    required this.inputTokens,
    required this.outputTokens,
    this.thb,
  });

  final int inputTokens;
  final int outputTokens;
  final double? thb;

  static CostEstimate? maybe(Object? json) => json is Map
      ? CostEstimate(
          inputTokens: (json['input_tokens'] as num?)?.toInt() ?? 0,
          outputTokens: (json['output_tokens'] as num?)?.toInt() ?? 0,
          thb: (json['thb'] as num?)?.toDouble(),
        )
      : null;

  /// "ประมาณ 0.52 บาท" (or tokens only without prices).
  String get label {
    final tokens =
        'ขาเข้า ${_group(inputTokens)} token · ขาออกไม่เกิน ${_group(outputTokens)} token';
    final baht = thb;
    if (baht == null) return tokens;
    final text = baht < 0.01 ? 'ไม่ถึง 0.01' : baht.toStringAsFixed(2);
    return 'ประมาณ $text บาท ($tokens)';
  }

  static String _group(int n) {
    final s = n.toString();
    final out = StringBuffer();
    for (var i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) out.write(',');
      out.write(s[i]);
    }
    return out.toString();
  }
}

/// One uploaded file (`POST /documents`, DESIGN §19.9).
class SourceDocument {
  const SourceDocument({
    required this.id,
    required this.originalName,
    required this.mimeType,
    required this.pageCount,
    this.sizeBytes = 0,
    this.needsPageRange = false,
    this.cachedPurposes = const [],
    this.estimate,
  });

  final int id;
  final String originalName;
  final String mimeType;
  final int pageCount;
  final int sizeBytes;

  /// Longer than [kMaxDocumentPages]: a range must be picked.
  final bool needsPageRange;

  /// Read before in this school (free again), e.g. `['answer_key']`.
  final List<String> cachedPurposes;
  final CostEstimate? estimate;

  bool get isPdf => mimeType == 'application/pdf';

  bool get keyReadBefore => cachedPurposes.contains('answer_key');

  factory SourceDocument.fromJson(Map<String, dynamic> json) => SourceDocument(
    id: (json['id'] as num).toInt(),
    originalName: json['original_name'] as String? ?? '',
    mimeType: json['mime_type'] as String? ?? '',
    pageCount: (json['page_count'] as num?)?.toInt() ?? 1,
    sizeBytes: (json['size_bytes'] as num?)?.toInt() ?? 0,
    needsPageRange: json['needs_page_range'] == true,
    cachedPurposes: ((json['cached_purposes'] as List?) ?? const [])
        .map((e) => e.toString())
        .toList(),
    estimate: CostEstimate.maybe(json['estimate']),
  );
}

/// Read the teacher's key from files, or let AI draft it (§19.5).
enum KeyRequestKind {
  read('read'),
  draft('draft');

  const KeyRequestKind(this.apiValue);

  final String apiValue;
}

/// `POST /assignments/{id}/answer-key/estimate`: the cost of the files and
/// range the teacher picked, and whether the school read it before.
class KeyEstimate {
  const KeyEstimate({
    required this.pages,
    required this.cached,
    required this.estimate,
  });

  final int pages;
  final bool cached;
  final CostEstimate estimate;

  factory KeyEstimate.fromJson(Map<String, dynamic> json) => KeyEstimate(
    pages: (json['pages'] as num?)?.toInt() ?? 0,
    cached: json['cached'] == true,
    estimate:
        CostEstimate.maybe(json['estimate']) ??
        const CostEstimate(inputTokens: 0, outputTokens: 0),
  );
}

/// `extraction` of `GET /answer-key`: the latest read or draft.
class KeyExtraction {
  const KeyExtraction({
    required this.id,
    required this.status,
    this.error,
    this.kind,
    this.notesTh,
  });

  final int id;

  /// `queued`, `done` or `failed`.
  final String status;
  final String? error;

  /// `answer_key_read` or `answer_key_draft`.
  final String? kind;

  /// Gemini's notes for the teacher (e.g. an unreadable question).
  final String? notesTh;

  bool get isQueued => status == 'queued';

  bool get isFailed => status == 'failed';

  bool get isDraft => kind == 'answer_key_draft';

  factory KeyExtraction.fromJson(Map<String, dynamic> json) => KeyExtraction(
    id: (json['id'] as num).toInt(),
    status: json['status'] as String? ?? 'queued',
    error: json['error'] as String?,
    kind: json['kind'] as String?,
    notesTh: json['notes_th'] as String?,
  );
}

/// `GET /assignments/{id}/answer-key` (DESIGN §19.9).
class AnswerKeyState {
  const AnswerKeyState({
    required this.assignmentId,
    required this.mode,
    required this.status,
    required this.questions,
    this.keyOrigin,
    this.keyApprovedAt,
    this.extraction,
    this.keyComplete = false,
    this.incompleteQuestions = const [],
  });

  final int assignmentId;
  final AssignmentMode mode;
  final String status;
  final KeyOrigin? keyOrigin;
  final DateTime? keyApprovedAt;
  final KeyExtraction? extraction;
  final bool keyComplete;

  /// Positions still missing an answer or an approved rubric.
  final List<int> incompleteQuestions;
  final List<Question> questions;

  bool get approved => keyApprovedAt != null;

  bool get reading => extraction?.isQueued ?? false;

  bool get closed => status == 'closed';

  factory AnswerKeyState.fromJson(Map<String, dynamic> json) {
    final approved = json['key_approved_at'];
    final extraction = json['extraction'];
    return AnswerKeyState(
      assignmentId: (json['assignment_id'] as num).toInt(),
      mode: AssignmentMode.fromApi(json['mode'] as String?),
      status: json['status'] as String? ?? 'draft',
      keyOrigin: KeyOrigin.fromApi(json['key_origin'] as String?),
      keyApprovedAt: approved is String ? DateTime.tryParse(approved) : null,
      extraction: extraction is Map
          ? KeyExtraction.fromJson(extraction.cast<String, dynamic>())
          : null,
      keyComplete: json['key_complete'] == true,
      incompleteQuestions: ((json['incomplete_questions'] as List?) ?? const [])
          .map((e) => (e as num).toInt())
          .toList(),
      questions:
          ((json['questions'] as List?) ?? const [])
              .cast<Map<String, dynamic>>()
              .map(Question.fromJson)
              .toList()
            ..sort((a, b) => a.position.compareTo(b.position)),
    );
  }
}

/// A question of the read the server did not fill (`applied.skipped`).
class SkippedQuestion {
  const SkippedQuestion({required this.questionNo, required this.reason});

  final int questionNo;

  /// `no_such_question`, `type_mismatch` or `no_answer`.
  final String reason;

  String get label => switch (reason) {
    'no_such_question' => 'ข้อ $questionNo ไม่มีในการบ้านนี้',
    'type_mismatch' => 'ข้อ $questionNo ประเภทคำถามไม่ตรงกับในการบ้าน',
    'no_answer' => 'ข้อ $questionNo AI หาคำตอบไม่เจอ',
    _ => 'ข้อ $questionNo ไม่ได้เติมเฉลย',
  };
}

/// `applied` of extract/draft: what was written into the questions.
class AppliedSummary {
  const AppliedSummary({
    this.created = 0,
    this.filled = 0,
    this.skipped = const [],
  });

  final int created;
  final int filled;
  final List<SkippedQuestion> skipped;

  static AppliedSummary? maybe(Object? json) {
    if (json is! Map) return null;
    return AppliedSummary(
      created: (json['created'] as num?)?.toInt() ?? 0,
      filled: (json['filled'] as num?)?.toInt() ?? 0,
      skipped: ((json['skipped'] as List?) ?? const [])
          .whereType<Map>()
          .map(
            (m) => SkippedQuestion(
              questionNo: (m['question_no'] as num?)?.toInt() ?? 0,
              reason: m['reason'] as String? ?? '',
            ),
          )
          .toList(),
    );
  }
}

/// The answer of `POST .../answer-key/extract` and `.../draft`.
class KeyRequestResult {
  const KeyRequestResult({
    required this.cached,
    required this.answerKey,
    this.estimate,
    this.applied,
  });

  /// Read before in this school: filled at once, no cost.
  final bool cached;
  final CostEstimate? estimate;
  final AppliedSummary? applied;
  final AnswerKeyState answerKey;

  factory KeyRequestResult.fromJson(Map<String, dynamic> json) =>
      KeyRequestResult(
        cached: json['cached'] == true,
        estimate: CostEstimate.maybe(json['estimate']),
        applied: AppliedSummary.maybe(json['applied']),
        answerKey: AnswerKeyState.fromJson(
          (json['answer_key'] as Map).cast<String, dynamic>(),
        ),
      );
}
