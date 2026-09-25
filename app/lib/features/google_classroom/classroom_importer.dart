import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../../platform/attachment_rasterizer.dart';
import '../scan/scan_meta.dart';
import '../scan/scan_processor.dart';
import '../scan/scan_screen.dart' show studentLabel;
import 'attachment_downloader.dart';
import 'google_auth.dart';
import 'google_models.dart';

/// What happened to one picture: a photo, or one page of a PDF.
sealed class ImageOutcome {
  const ImageOutcome(this.label);

  /// File title, plus the page for a PDF ("งาน.pdf หน้า 2").
  final String label;
}

/// Cropped and queued for upload with `source = classroom` (§18.6).
final class ImageQueued extends ImageOutcome {
  const ImageQueued(
    super.label, {
    required this.clientScanId,
    required this.page,
    required this.student,
    this.identityNote,
  });

  final String clientScanId;
  final int page;

  /// Whose page the QR says it is.
  final String student;

  /// The QR names someone else than the matched account (§18.3): the
  /// server keeps it by the QR and flags `identity_mismatch`.
  final String? identityNote;
}

/// Kept as `needs_layout` until the layout can be fetched (offline).
final class ImageWaitingLayout extends ImageOutcome {
  const ImageWaitingLayout(super.label, {required this.page});

  final int page;
}

/// The picture cannot be used; [reasons] are Thai and meant for the student
/// too (they prefill "ตีกลับให้ถ่ายใหม่").
final class ImageRejected extends ImageOutcome {
  const ImageRejected(super.label, {required this.reasons, this.analysis});

  final List<String> reasons;

  /// Kept (with its file) only when the teacher may still use it: the photo
  /// failed nothing but the blur check.
  final ScanRejected? analysis;

  bool get canOverride => analysis?.canOverride ?? false;
}

/// The file itself failed: download, unsupported type, broken PDF.
final class AttachmentFailed extends ImageOutcome {
  const AttachmentFailed(super.label, {required this.reason});

  final String reason;
}

/// Everything done for one Classroom submission.
class SubmissionImport {
  const SubmissionImport({required this.submissionId, required this.outcomes});

  final int submissionId;
  final List<ImageOutcome> outcomes;

  bool get hasProblems =>
      outcomes.isEmpty ||
      outcomes.any((o) => o is ImageRejected || o is AttachmentFailed);

  int get queuedCount =>
      outcomes.where((o) => o is ImageQueued || o is ImageWaitingLayout).length;

  /// Distinct reasons, for the retake message to the student.
  List<String> get problems {
    if (outcomes.isEmpty) return const ['ไม่มีไฟล์แนบในงานที่ส่ง'];
    final seen = <String>{};
    return [
      for (final o in outcomes)
        ...switch (o) {
          ImageRejected(:final reasons) => reasons,
          AttachmentFailed(:final reason) => [reason],
          _ => const <String>[],
        },
    ].where(seen.add).toList();
  }

  SubmissionImport replace(ImageOutcome old, ImageOutcome next) =>
      SubmissionImport(
        submissionId: submissionId,
        outcomes: [for (final o in outcomes) identical(o, old) ? next : o],
      );
}

/// Downloads attachments for one batch ("ดาวน์โหลดและสแกนทั้งหมด").
abstract interface class DriveSession {
  Future<File> download(GoogleAttachment attachment, Directory directory);
}

/// One Drive token for a batch, renewed once when Google rejects it.
class _TokenDriveSession implements DriveSession {
  _TokenDriveSession(
    this._auth,
    this._downloader,
    this._expectedEmail,
    this._token,
  );

  final GoogleAuthGateway _auth;
  final DriveAttachmentDownloader _downloader;
  final String? _expectedEmail;
  DriveAccessToken _token;

  @override
  Future<File> download(
    GoogleAttachment attachment,
    Directory directory,
  ) async {
    try {
      return await _downloader.download(attachment, _token, directory);
    } on DriveTokenRejected {
      await _auth.invalidate(_token);
      _token = await _auth.driveAccessToken(expectedEmail: _expectedEmail);
      return _downloader.download(attachment, _token, directory);
    }
  }
}

/// Classroom submissions -> the existing scan pipeline (DESIGN §18.2): the
/// teacher's phone downloads each attachment from Drive, converts PDF/HEIC
/// to pages, runs detectPage/cropPage and queues the upload with
/// `source = classroom` + `google_submission_id`. Only the cropped page and
/// crops reach our server, through the normal upload queue.
class ClassroomImporter {
  ClassroomImporter({
    required this._processor,
    required this._rasterizer,
    required this._downloader,
    required this._auth,
    required this._workRoot,
  });

  final ScanProcessor _processor;
  final AttachmentRasterizer _rasterizer;
  final DriveAttachmentDownloader _downloader;
  final GoogleAuthGateway _auth;
  final Future<Directory> Function() _workRoot;

  bool get isSupported => _processor.isSupported && _rasterizer.isSupported;

  /// Gets a Drive token for [expectedEmail] (may show the account picker or
  /// consent). Throws [GoogleAuthException].
  Future<DriveSession> openSession({String? expectedEmail}) async {
    final token = await _auth.driveAccessToken(expectedEmail: expectedEmail);
    return _TokenDriveSession(_auth, _downloader, expectedEmail, token);
  }

  /// Downloads and scans every attachment of [submission] (an assignment's
  /// Classroom submission). [onProgress] gets Thai status lines.
  Future<SubmissionImport> importSubmission(
    GoogleSubmission submission, {
    required int assignmentId,
    required DriveSession session,
    void Function(String status)? onProgress,
  }) async {
    final dir = Directory(
      p.join((await _workRoot()).path, 'submission_${submission.id}'),
    );
    final outcomes = <ImageOutcome>[];
    final attachments = submission.attachments;
    for (var i = 0; i < attachments.length; i++) {
      final a = attachments[i];
      final prefix = attachments.length > 1
          ? 'ไฟล์ ${i + 1}/${attachments.length}: '
          : '';
      if (!a.isSupported) {
        outcomes.add(
          AttachmentFailed(
            a.title,
            reason: AttachmentUnsupported(a.mimeType).message,
          ),
        );
        continue;
      }
      onProgress?.call('$prefixกำลังดาวน์โหลด ${a.title}…');
      final File file;
      try {
        file = await session.download(a, dir);
      } on DownloadFailure catch (e) {
        outcomes.add(AttachmentFailed(a.title, reason: e.message));
        continue;
      }

      final List<String> images;
      if (a.needsRasterize) {
        onProgress?.call('$prefixกำลังแปลง ${a.title} เป็นภาพ…');
        try {
          images = await _rasterizer.rasterize(file.path, a.mimeType);
        } on RasterizeException catch (e) {
          outcomes.add(AttachmentFailed(a.title, reason: e.message));
          continue;
        } finally {
          // The pages are new JPEG files; the original is not needed.
          await _delete(file);
        }
        if (images.isEmpty) {
          outcomes.add(
            AttachmentFailed(a.title, reason: 'ไม่พบหน้าใดในไฟล์นี้'),
          );
          continue;
        }
      } else {
        images = [file.path];
      }

      for (var page = 0; page < images.length; page++) {
        final label = images.length > 1
            ? '${a.title} หน้า ${page + 1}'
            : a.title;
        onProgress?.call('$prefixกำลังตรวจ $label…');
        outcomes.add(
          await _scan(images[page], label, submission, assignmentId),
        );
      }
    }
    await _cleanUp(dir, outcomes);
    return SubmissionImport(submissionId: submission.id, outcomes: outcomes);
  }

  /// The teacher keeps a picture that only failed the blur check.
  Future<ImageOutcome> acceptDespiteBlur(
    ImageRejected rejected, {
    required GoogleSubmission submission,
    required int assignmentId,
  }) async {
    final analysis = rejected.analysis;
    if (analysis == null || !analysis.canOverride) return rejected;
    try {
      final next = await _processor.acceptDespiteBlur(analysis);
      return _handle(next, rejected.label, submission, assignmentId);
    } catch (e) {
      debugPrint('classroom blur override failed: $e');
      await _processor.discard(analysis);
      return AttachmentFailed(
        rejected.label,
        reason: 'ประมวลผลภาพไม่สำเร็จ ลองอีกครั้ง',
      );
    }
  }

  /// Deletes the files still kept for [outcomes] (pictures waiting for the
  /// teacher's blur decision).
  Future<void> discardPending(Iterable<ImageOutcome> outcomes) async {
    for (final o in outcomes) {
      if (o case ImageRejected(:final analysis?)) {
        await _processor.discard(analysis);
      }
    }
  }

  Future<ImageOutcome> _scan(
    String imagePath,
    String label,
    GoogleSubmission submission,
    int assignmentId,
  ) async {
    try {
      final analysis = await _processor.analyze(
        imagePath,
        source: ScanSource.classroom(
          googleSubmissionId: submission.googleSubmissionId,
        ),
      );
      return await _handle(analysis, label, submission, assignmentId);
    } catch (e) {
      debugPrint('classroom scan failed: $e');
      await _delete(File(imagePath));
      return AttachmentFailed(label, reason: 'ประมวลผลภาพไม่สำเร็จ');
    }
  }

  Future<ImageOutcome> _handle(
    ScanAnalysis analysis,
    String label,
    GoogleSubmission submission,
    int assignmentId,
  ) async {
    switch (analysis) {
      case ScanRejected(:final issues):
        final keep = analysis.canOverride;
        if (!keep) await _processor.discard(analysis);
        return ImageRejected(
          label,
          reasons: [for (final i in issues) i.message],
          analysis: keep ? analysis : null,
        );
      case ScanReady(:final qr) || ScanNeedsLayout(:final qr)
          when qr.assignmentId != assignmentId:
        await _processor.discard(analysis);
        return ImageRejected(
          label,
          reasons: [
            'ใบงานในรูปเป็นของการบ้านอื่น (#${qr.assignmentId}) '
                'ไม่ใช่งานนี้ ให้ส่งรูปใบงานของงานนี้',
          ],
        );
      case ScanReady(:final qr, :final student):
        final id = await _processor.confirm(analysis);
        return ImageQueued(
          label,
          clientScanId: id,
          page: qr.page,
          student: qr.studentId == 0
              ? '${submission.studentLabel} (ใบงานสำรอง)'
              : studentLabel(student, qr.studentId),
          identityNote: _identityNote(qr.studentId, student?.name, submission),
        );
      case ScanNeedsLayout(:final qr):
        await _processor.keepForLater(analysis);
        return ImageWaitingLayout(label, page: qr.page);
    }
  }

  static String? _identityNote(
    int qrStudentId,
    String? qrName,
    GoogleSubmission submission,
  ) {
    final matched = submission.student;
    if (qrStudentId == 0 || matched == null || matched.id == qrStudentId) {
      return null;
    }
    return 'QR ในใบงานเป็นของ ${qrName ?? 'นักเรียนรหัส $qrStudentId'} '
        'แต่ส่งจากบัญชีของ ${matched.name} ระบบจะบันทึกตาม QR '
        'และติดป้ายให้ครูตรวจในคิวตรวจทาน';
  }

  Future<void> _cleanUp(Directory dir, List<ImageOutcome> outcomes) async {
    final keeps = outcomes.any(
      (o) =>
          o is ImageRejected &&
          o.analysis != null &&
          p.isWithin(dir.path, o.analysis!.imagePath),
    );
    if (keeps) return;
    try {
      if (await dir.exists()) await dir.delete(recursive: true);
    } on FileSystemException {
      // Temp files; the OS clears the cache eventually.
    }
  }

  static Future<void> _delete(File file) async {
    try {
      if (await file.exists()) await file.delete();
    } on FileSystemException {
      // Same as above.
    }
  }
}

final classroomImporterProvider = Provider<ClassroomImporter>(
  (ref) => ClassroomImporter(
    processor: ref.watch(scanProcessorProvider),
    rasterizer: ref.watch(attachmentRasterizerProvider),
    downloader: ref.watch(driveAttachmentDownloaderProvider),
    auth: ref.watch(googleAuthProvider),
    workRoot: () async => Directory(
      p.join((await getTemporaryDirectory()).path, 'classroom_import'),
    ),
  ),
);
