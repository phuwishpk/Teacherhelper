import '../assignments/answer_key_models.dart';
import '../assignments/assignment.dart';
import '../classrooms/classroom.dart';
import '../exams/exam_scan_models.dart';
import '../hand_in/hand_in_models.dart';
import '../hand_in/teacher_upload_screen.dart' show canUploadFor;
import '../upload_queue/queued_scan.dart';

/// Whose page a photo of the web camera scan is (DESIGN §25.6), from the
/// worksheet QR and the teacher's own assignments and rosters.
class WebScanTarget {
  const WebScanTarget({
    required this.assignmentId,
    required this.assignmentTitle,
    required this.studentId,
    required this.studentNumber,
    required this.studentName,
    required this.page,
  });

  final int assignmentId;
  final String assignmentTitle;
  final int studentId;
  final int studentNumber;
  final String studentName;
  final int page;

  (int, int) get key => (assignmentId, studentId);
}

/// One photo taken on the web scan screen. [target] is null when the QR
/// could not be used; [problem] then says why in Thai.
class WebScanShot {
  const WebScanShot({
    required this.id,
    required this.file,
    this.target,
    this.problem,
  });

  /// Increasing per screen: a later shot of the same page wins.
  final int id;
  final PickedDocument file;
  final WebScanTarget? target;
  final String? problem;
}

/// The pages of one student for one assignment: one whole-page hand-in.
class WebScanGroup {
  const WebScanGroup(this.target, this.pages);

  /// The first page's target (assignment and student are the same for all).
  final WebScanTarget target;

  /// Sorted by page, one photo per page.
  final List<WebScanShot> pages;

  /// Why this hand-in cannot be sent as it is, or null.
  String? get problem => pages.length > kMaxHandInFiles
      ? 'ส่งได้ไม่เกิน $kMaxHandInFiles หน้าต่อคน ลบหน้าที่ถ่ายเกินออก'
      : null;
}

/// Photos with a usable QR grouped per (assignment, student). A page taken
/// twice keeps its latest photo.
List<WebScanGroup> groupWebScanShots(List<WebScanShot> shots) {
  final byKey = <(int, int), Map<int, WebScanShot>>{};
  for (final shot in shots) {
    final t = shot.target;
    if (t == null) continue;
    final pages = byKey.putIfAbsent(t.key, () => {});
    final previous = pages[t.page];
    if (previous == null || previous.id < shot.id) pages[t.page] = shot;
  }
  return [
    for (final pages in byKey.values)
      WebScanGroup(
        pages.values.first.target!,
        pages.values.toList()
          ..sort((a, b) => a.target!.page.compareTo(b.target!.page)),
      ),
  ]..sort((a, b) {
    final byTitle = a.target.assignmentTitle.compareTo(
      b.target.assignmentTitle,
    );
    return byTitle != 0
        ? byTitle
        : a.target.studentNumber.compareTo(b.target.studentNumber);
  });
}

/// Photos taken again for a page that a later photo replaced.
Set<int> replacedWebScanShots(List<WebScanShot> shots) {
  final kept = {
    for (final g in groupWebScanShots(shots))
      for (final p in g.pages) p.id,
  };
  return {
    for (final s in shots)
      if (s.target != null && !kept.contains(s.id)) s.id,
  };
}

/// What [payload] (the QR text, null when none was read) means for this
/// teacher: a target, or a Thai problem.
Future<({WebScanTarget? target, String? problem})> resolveWebScanQr(
  String? payload, {
  required List<Assignment> assignments,
  required Future<List<RosterStudent>> Function(int classroomId) roster,
}) async {
  ({WebScanTarget? target, String? problem}) fail(String problem) =>
      (target: null, problem: problem);

  if (payload == null) {
    return fail(
      'อ่าน QR ไม่ได้ ถ่ายใหม่ให้เห็น QR ชัดทั้งดวง หรือส่งแบบเลือกนักเรียนเอง',
    );
  }
  if (payload.startsWith('${ExamQr.prefix}.') ||
      payload.startsWith('${ExamQr.sharedPrefix}.')) {
    return fail('นี่คือกระดาษคำตอบข้อสอบ สแกนข้อสอบได้ในแอป Android และ iOS');
  }
  final qr = WorksheetQr.tryParse(payload);
  if (qr == null) return fail('QR นี้ไม่ใช่ QR ของใบงาน Krucheck');

  final assignment = assignments
      .where((a) => a.id == qr.assignmentId)
      .firstOrNull;
  if (assignment == null) {
    return fail('ใบงานนี้ไม่ใช่การบ้านของคุณ หรือการบ้านถูกลบไปแล้ว');
  }
  if (!canUploadFor(assignment)) {
    return fail('การบ้าน "${assignment.title}" ยังเป็นฉบับร่าง ส่งงานไม่ได้');
  }
  final students = await roster(assignment.classroomId);
  final student = students
      .where((s) => s.studentId == qr.studentId)
      .firstOrNull;
  final assigned = assignment.studentIds?.contains(qr.studentId) ?? true;
  if (student == null || !assigned) {
    return fail(
      'นักเรียนในใบงานนี้ไม่อยู่ในรายชื่อของการบ้าน "${assignment.title}"',
    );
  }
  return (
    target: WebScanTarget(
      assignmentId: assignment.id,
      assignmentTitle: assignment.title,
      studentId: student.studentId,
      studentNumber: student.studentNumber,
      studentName: student.name,
      page: qr.page,
    ),
    problem: null,
  );
}
