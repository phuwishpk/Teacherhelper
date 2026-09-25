import '../../platform/scan_pipeline.dart';
import '../upload_queue/queued_scan.dart';

/// Photos whose Laplacian variance (on the frame warped to 200 DPI) is below
/// this are sent back for a retake (DESIGN §6.2 step 4). A starting value:
/// tune it from the Phase 1 classroom photos (KICKOFF 2b) and keep it low
/// enough that sharp pages are never refused; the teacher can still keep a
/// page that only fails this check.
const defaultMinBlurScore = 60.0;

/// Share of dark pixels inside a bubble from which the confirm screen shows
/// it as filled. Display only: the server decides the answer from `mcq_fill`.
const bubbleFilledShare = 0.5;

/// Ink share below which a box is shown as empty on the confirm screen.
const emptyInkRatio = 0.003;

/// Corner of an ArUco marker (ids of DESIGN §5.2; manifest.json of
/// backend/resources/worksheet/aruco).
enum MarkerCorner {
  topLeft(0, 'มุมบนซ้าย'),
  topRight(1, 'มุมบนขวา'),
  bottomRight(2, 'มุมล่างขวา'),
  bottomLeft(3, 'มุมล่างซ้าย');

  const MarkerCorner(this.id, this.label);

  final int id;
  final String label;

  static MarkerCorner? byId(int id) {
    for (final c in values) {
      if (c.id == id) return c;
    }
    return null;
  }
}

/// Why a photo cannot be used as it is. Every message is Thai and tells the
/// teacher what to do next.
sealed class ScanIssue {
  const ScanIssue();

  String get message;
}

final class MarkersMissing extends ScanIssue {
  const MarkersMissing(this.ids);

  final List<int> ids;

  @override
  String get message {
    if (ids.length >= 4) {
      return 'ไม่พบสัญลักษณ์สี่เหลี่ยมที่มุมกระดาษเลย '
          'ถ่ายให้เห็นใบงานทั้งแผ่นและทั้ง 4 มุม';
    }
    final corners = [
      for (final id in ids) MarkerCorner.byId(id)?.label ?? 'มุม $id',
    ];
    final list = corners.length == 1
        ? corners.first
        : '${corners.sublist(0, corners.length - 1).join(' ')} และ${corners.last}';
    return 'มองไม่เห็นสัญลักษณ์ที่$list '
        'ถอยกล้องออกหรือจัดกระดาษให้เห็นครบทั้ง 4 มุม';
  }
}

final class QrUnreadable extends ScanIssue {
  const QrUnreadable();

  @override
  String get message =>
      'อ่าน QR ที่หัวกระดาษไม่ได้ ระวังเงาหรือแสงสะท้อนบน QR แล้วถ่ายใหม่';
}

final class NotAWorksheet extends ScanIssue {
  const NotAWorksheet(this.payload);

  final String payload;

  bool get isLoginCard => payload.startsWith('EVL1.');

  @override
  String get message => isLoginCard
      ? 'นี่คือบัตรเข้าสู่ระบบของนักเรียน ไม่ใช่ใบงาน'
      : 'QR นี้ไม่ใช่ใบงานของ EduVision';
}

/// `student_id = 0`: the anonymous spare worksheet, accepted only through
/// Google Classroom (DESIGN §18.3).
final class SpareWorksheet extends ScanIssue {
  const SpareWorksheet();

  @override
  String get message =>
      'นี่คือใบงานสำรองที่ไม่ระบุชื่อ ส่งได้เฉพาะผ่าน Google Classroom';
}

final class TooBlurry extends ScanIssue {
  const TooBlurry(this.score, this.minimum);

  final double score;
  final double minimum;

  @override
  String get message =>
      'ภาพไม่คมชัด ถือโทรศัพท์ให้นิ่งและรอให้กล้องโฟกัสก่อนถ่าย';
}

final class AssignmentUnknown extends ScanIssue {
  const AssignmentUnknown(this.assignmentId, [this.serverMessage]);

  final int assignmentId;
  final String? serverMessage;

  @override
  String get message =>
      'ไม่พบการบ้าน #$assignmentId ในบัญชีนี้ (อาจเป็นใบงานของครูคนอื่น)';
}

final class LayoutPageUnknown extends ScanIssue {
  const LayoutPageUnknown({required this.page, required this.version});

  final int page;
  final int version;

  @override
  String get message =>
      'ไม่พบหน้า $page ใน layout เวอร์ชัน $version ของการบ้านนี้ '
      'ใบงานนี้อาจพิมพ์จาก layout ที่ถูกลบไปแล้ว';
}

final class ProcessingFailed extends ScanIssue {
  const ProcessingFailed(this.message);

  @override
  final String message;
}

/// Checks a detection result before any layout lookup. Returns an empty
/// list when the photo is usable.
List<ScanIssue> checkDetection(
  PageDetection detection, {
  double minBlurScore = defaultMinBlurScore,
}) {
  final issues = <ScanIssue>[];
  final missing = detection.missingMarkerIds.toSet().toList()..sort();
  if (missing.isNotEmpty) issues.add(MarkersMissing(missing));

  final payload = detection.qrPayload;
  if (payload == null || payload.isEmpty) {
    issues.add(const QrUnreadable());
  } else {
    final qr = WorksheetQr.tryParse(payload);
    if (qr == null) {
      issues.add(NotAWorksheet(payload));
    } else if (qr.studentId == 0) {
      issues.add(const SpareWorksheet());
    }
  }

  // The score is only comparable on the warped frame, i.e. when every
  // marker was found; otherwise the marker issue asks for a retake anyway.
  if (missing.isEmpty && detection.blurScore < minBlurScore) {
    issues.add(TooBlurry(detection.blurScore, minBlurScore));
  }
  return issues;
}

/// True when the only problem is blur, which the teacher may override
/// while the threshold is still being tuned.
bool onlyBlur(List<ScanIssue> issues) =>
    issues.isNotEmpty && issues.every((i) => i is TooBlurry);

/// Options shown as filled on the confirm screen.
List<String> filledOptions(Map<String, double>? fill) => [
  for (final e in (fill ?? const <String, double>{}).entries)
    if (e.value >= bubbleFilledShare) e.key,
];
