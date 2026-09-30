import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart' show KeepAliveLink;

import '../../core/api/api_client.dart';
import '../worksheets/pdf_files.dart';
import '../worksheets/print_job.dart';
import '../worksheets/print_job_poller.dart';
import 'exam_models.dart';
import 'exam_providers.dart';
import 'exams_repository.dart';

/// What `POST /exams/{id}/prints` renders (DESIGN §22.6).
enum ExamPrintKind {
  /// One booklet per version, no names, no QR; copies are printed by hand.
  booklet('exam_booklet'),

  /// One answer sheet (1–2 pages) per student, with name, number and QR.
  answerSheet('answer_sheet'),

  /// The teacher's key sheet: the answer-sheet layout for student 0.
  keySheet('key_sheet');

  const ExamPrintKind(this.apiValue);

  final String apiValue;
}

/// One row of the print screen: a booklet of a version, or a sheet kind.
typedef ExamPrintTarget = ({ExamPrintKind kind, int? versionNo});

/// The body of `POST /exams/{id}/prints`.
class ExamPrintRequest {
  const ExamPrintRequest.booklet(int this.versionNo)
    : kind = ExamPrintKind.booklet,
      studentIds = null;

  /// [studentIds] null = the whole roster (ordered by เลขที่ on the server).
  const ExamPrintRequest.answerSheets({this.studentIds})
    : kind = ExamPrintKind.answerSheet,
      versionNo = null;

  const ExamPrintRequest.keySheet()
    : kind = ExamPrintKind.keySheet,
      versionNo = null,
      studentIds = null;

  final ExamPrintKind kind;
  final int? versionNo;
  final List<int>? studentIds;

  ExamPrintTarget get target => (kind: kind, versionNo: versionNo);

  Map<String, Object?> toJson() => {
    'kind': kind.apiValue,
    'version_no': ?versionNo,
    'student_ids': ?studentIds,
  };

  /// The server's download name (WorksheetPrintController::fileName).
  String fileName(int examId, PrintJob job) {
    final layout = job.layoutVersion;
    final suffix = layout == null ? '' : '-v$layout';
    return switch (kind) {
      ExamPrintKind.booklet =>
        'exam-$examId-booklet-${job.versionNo ?? versionNo ?? 1}.pdf',
      ExamPrintKind.answerSheet => 'exam-$examId-answer-sheets$suffix.pdf',
      ExamPrintKind.keySheet => 'exam-$examId-key-sheet$suffix.pdf',
    };
  }
}

/// Booklet copies per version: the class split as evenly as possible, the
/// first versions taking the remainder (35 students, 2 versions → 18, 17).
List<int> examBookletCopies(int students, int versions) {
  if (versions < 1) return const [];
  final n = students < 0 ? 0 : students;
  return [
    for (var v = 0; v < versions; v++)
      n ~/ versions + (v < n % versions ? 1 : 0),
  ];
}

/// Why a kind cannot be printed yet, mirroring the checks of
/// ExamPrintService so the teacher sees the reason before asking the
/// server; null when it can. The server still decides (§22.6).
abstract final class ExamPrintReadiness {
  static String? booklet(ExamDetail d) {
    if (d.questionCount == 0) return 'ยังไม่มีข้อ เพิ่มข้อก่อนพิมพ์เล่ม';
    if (!d.isManual && !d.keyApproved) {
      return 'อนุมัติเฉลยก่อนพิมพ์เล่มให้นักเรียน';
    }
    final blocked = d.bookletIncomplete;
    if (blocked.isNotEmpty) {
      return 'ข้อ ${blocked.map((b) => b.position).join(', ')} '
          'ยังพิมพ์ในเล่มไม่ได้ (ต้องอนุมัติข้อและมีโจทย์)';
    }
    return null;
  }

  static String? answerSheets(ExamDetail d, {int? students}) {
    if (d.isManual) return 'ข้อสอบที่ครูตรวจเองไม่มีกระดาษคำตอบ';
    if (d.questionCount == 0) return 'ยังไม่มีข้อ';
    if (!d.keyApproved) return 'อนุมัติเฉลยก่อนพิมพ์กระดาษคำตอบ';
    if (d.sheetOverflow) return _overflow;
    if (students == 0) return 'ห้องนี้ยังไม่มีนักเรียน';
    return null;
  }

  static String? keySheet(ExamDetail d) {
    if (d.isManual) return 'ข้อสอบที่ครูตรวจเองไม่มีกระดาษเฉลย';
    if (d.questionCount == 0) return 'ยังไม่มีข้อ เพิ่มตอนและข้อก่อน';
    if (d.sheetOverflow) return _overflow;
    return null;
  }

  static const _overflow =
      'ข้อมากเกินกระดาษคำตอบ 2 หน้า ลดจำนวนข้อหรือจำนวนหลักของตอนเติมตัวเลข';
}

enum ExamPrintPhase { requesting, rendering, downloading, ready, failed }

/// Where one print of the screen stands.
class ExamPrintState {
  const ExamPrintState({
    required this.phase,
    required this.request,
    this.job,
    this.file,
    this.error,
  });

  final ExamPrintPhase phase;
  final ExamPrintRequest request;
  final PrintJob? job;
  final PdfFile? file;
  final String? error;

  bool get busy =>
      phase != ExamPrintPhase.ready && phase != ExamPrintPhase.failed;

  String get label => switch (phase) {
    ExamPrintPhase.requesting => 'กำลังส่งคำขอ…',
    ExamPrintPhase.rendering => printStatusLabel(job?.status ?? 'queued'),
    ExamPrintPhase.downloading => 'กำลังดาวน์โหลด…',
    ExamPrintPhase.ready => 'พร้อมแล้ว',
    ExamPrintPhase.failed => error ?? 'สร้าง PDF ไม่สำเร็จ',
  };

  ExamPrintState copyWith({
    ExamPrintPhase? phase,
    PrintJob? job,
    PdfFile? file,
    String? error,
  }) => ExamPrintState(
    phase: phase ?? this.phase,
    request: request,
    job: job ?? this.job,
    file: file ?? this.file,
    error: error ?? this.error,
  );
}

/// The wait between two status polls; tests make it instant.
final examPrintDelayProvider = Provider<Future<void> Function(Duration)>(
  (ref) => Future.delayed,
);

/// The prints of one exam on the print screen: each target runs on its
/// own (request → poll `worksheet-prints` → download), so every booklet
/// can render at once. Kept alive while any print runs, so leaving the
/// screen does not drop a job half way.
class ExamPrintsNotifier
    extends Notifier<Map<ExamPrintTarget, ExamPrintState>> {
  ExamPrintsNotifier(this.examId);

  final int examId;

  var _running = 0;
  KeepAliveLink? _keepAlive;

  @override
  Map<ExamPrintTarget, ExamPrintState> build() => const {};

  void _put(ExamPrintState s) {
    if (!ref.mounted) return;
    state = {...state, s.request.target: s};
  }

  /// Queues [request] and follows it to a downloaded PDF. A target that is
  /// still running is left alone.
  Future<void> run(ExamPrintRequest request) async {
    if (state[request.target]?.busy ?? false) return;
    var s = ExamPrintState(phase: ExamPrintPhase.requesting, request: request);
    _put(s);
    _running++;
    _keepAlive ??= ref.keepAlive();
    try {
      final repo = ref.read(examsRepositoryProvider);
      final queued = await repo.requestPrint(examId, request);
      if (!ref.mounted) return;
      // The first print locks the structure (§22.6): show it on the exam.
      final detail = ref.read(examDetailProvider(examId)).value;
      if (detail != null && !detail.structureLocked) {
        ref.invalidate(examDetailProvider(examId));
      }
      s = s.copyWith(phase: ExamPrintPhase.rendering, job: queued);
      _put(s);
      var job = queued;
      if (!job.isReady) {
        job = await waitForPrintJob(
          () => repo.printStatus(queued),
          delay: ref.read(examPrintDelayProvider),
          onUpdate: (j) => _put(s = s.copyWith(job: j)),
        );
      }
      if (!ref.mounted) return;
      _put(s = s.copyWith(phase: ExamPrintPhase.downloading, job: job));
      final file = await ref
          .read(pdfFilesProvider)
          .fetch(job.downloadUrl!, fileName: request.fileName(examId, job));
      _put(s = s.copyWith(phase: ExamPrintPhase.ready, file: file));
    } catch (e) {
      final message = switch (e) {
        PrintJobFailed() || PrintJobTimeout() => e.toString(),
        _ => apiErrorMessage(e),
      };
      _put(s.copyWith(phase: ExamPrintPhase.failed, error: message));
    } finally {
      _running--;
      if (_running == 0) {
        _keepAlive?.close();
        _keepAlive = null;
      }
    }
  }
}

final examPrintsProvider = NotifierProvider.autoDispose
    .family<ExamPrintsNotifier, Map<ExamPrintTarget, ExamPrintState>, int>(
      ExamPrintsNotifier.new,
    );
