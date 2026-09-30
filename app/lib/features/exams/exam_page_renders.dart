import '../../core/api/api_client.dart';
import '../../platform/document_page_renderer.dart';
import '../assignments/answer_key_models.dart';
import 'exam_import_repository.dart';
import 'exam_models.dart';

/// What [renderPendingPages] did.
class PageRenderReport {
  const PageRenderReport({
    this.uploaded = 0,
    this.failures = const [],
    this.remaining = const [],
  });

  /// Pages rendered and uploaded.
  final int uploaded;

  /// Thai lines for the pages that could not be rendered or uploaded.
  final List<String> failures;

  /// `figures_pending` after the last upload.
  final List<FigurePending> remaining;
}

/// Renders every page of [pending] that `needs_render` on the phone and
/// uploads it (DESIGN §22.4: the server cannot render a PDF or decode
/// HEIC). A file the teacher picked in this session ([localFiles], by
/// source document id) is used as is; any other file is downloaded from
/// the exam first (`GET /exams/{id}/documents/{document_id}/file`).
/// [onProgress] gets (pages done, pages to do).
Future<PageRenderReport> renderPendingPages({
  required ExamImportRepository repo,
  required DocumentPageRenderer renderer,
  required int examId,
  required List<FigurePending> pending,
  Map<int, PickedDocument> localFiles = const {},
  void Function(int done, int total)? onProgress,
}) async {
  final todo = [
    for (final p in pending)
      if (p.needsRender) p,
  ];
  var remaining = pending;
  var uploaded = 0;
  final failures = <String>[];
  final paths = <int, String>{};
  final fileErrors = <int, String>{};

  Future<String> pathOf(FigurePending p) async {
    final known = paths[p.sourceDocumentId];
    if (known != null) return known;
    final local = localFiles[p.sourceDocumentId];
    final name = p.originalName ?? local?.name ?? 'exam-${p.sourceDocumentId}';
    final String path;
    if (local?.path case final localPath?) {
      path = localPath;
    } else if (local?.bytes case final bytes?) {
      path = await renderer.saveTemp(name, bytes);
    } else {
      final bytes = await repo.documentFile(examId, p.sourceDocumentId);
      path = await renderer.saveTemp(name, bytes);
    }
    return paths[p.sourceDocumentId] = path;
  }

  for (var i = 0; i < todo.length; i++) {
    onProgress?.call(i, todo.length);
    final p = todo[i];
    final label = '${p.originalName ?? 'ไฟล์'} หน้า ${p.pageNo}';
    final fileError = fileErrors[p.sourceDocumentId];
    if (fileError != null) {
      failures.add('$label: $fileError');
      continue;
    }
    final String path;
    try {
      path = await pathOf(p);
    } catch (e) {
      final message = e is PageRenderException ? e.message : apiErrorMessage(e);
      fileErrors[p.sourceDocumentId] = message;
      failures.add('$label: $message');
      continue;
    }
    try {
      final mime =
          p.mimeType ??
          localFiles[p.sourceDocumentId]?.mimeType ??
          documentMimeType(p.originalName ?? '');
      final jpeg = await renderer.renderPage(path, mime, p.pageNo);
      remaining = await repo.uploadPageImage(
        examId,
        sourceDocumentId: p.sourceDocumentId,
        pageNo: p.pageNo,
        jpegPath: jpeg,
      );
      uploaded++;
    } on PageRenderException catch (e) {
      failures.add('$label: ${e.message}');
    } catch (e) {
      failures.add('$label: ${apiErrorMessage(e)}');
    }
  }
  onProgress?.call(todo.length, todo.length);
  return PageRenderReport(
    uploaded: uploaded,
    failures: failures,
    remaining: remaining,
  );
}

/// How a figure of a question or option stands, for its label.
enum FigureStatus {
  /// No figure from the file (or the teacher's own picture).
  none,

  /// Cropped and shown.
  ready,

  /// The page image is there; the server is cropping it.
  cropping,

  /// Waiting for the page image ("ยังไม่มีภาพประกอบ").
  missing,
}

/// The status of a figure with [source] (`figure_source`), [pending]
/// (`figure_pending`) and [hasImage] in [detail].
FigureStatus figureStatus(
  ExamDetail detail, {
  required FigureSource? source,
  required bool pending,
  required bool hasImage,
}) {
  if (source == null) return hasImage ? FigureStatus.ready : FigureStatus.none;
  if (!pending) return hasImage ? FigureStatus.ready : FigureStatus.cropping;
  final hasPage = detail.pageImages.any(
    (p) =>
        p.sourceDocumentId == source.sourceDocumentId &&
        p.pageNo == source.pageNo,
  );
  return hasPage ? FigureStatus.cropping : FigureStatus.missing;
}
