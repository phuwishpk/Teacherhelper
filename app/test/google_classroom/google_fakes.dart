import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/google_classroom/google_auth.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';

/// In-memory [GoogleClassroomRepository] that records every call.
class FakeGoogleRepository implements GoogleClassroomRepository {
  FakeGoogleRepository({
    this.statusValue = const GoogleStatus(
      connected: true,
      email: 'kru@school.ac.th',
    ),
    this.rosterRows = const [],
    this.submissionRows = const [],
    this.courseRows = const [],
  });

  GoogleStatus statusValue;
  List<GoogleRosterEntry> rosterRows;
  List<GoogleSubmission> submissionRows;
  List<GoogleCourse> courseRows;
  Object? error;

  final connected = <String>[];
  int disconnects = 0;
  final linked = <(int, String)>[];
  final savedMatches = <Map<String, int?>>[];
  final posts = <(int, bool, String?)>[];
  final returns = <(int, String)>[];
  int retries = 0;
  int submissionLoads = 0;

  Future<void> _maybeFail() async {
    if (error case final e?) throw e;
  }

  @override
  Future<GoogleStatus> status() async {
    await _maybeFail();
    return statusValue;
  }

  @override
  Future<GoogleStatus> connect(String serverAuthCode) async {
    await _maybeFail();
    connected.add(serverAuthCode);
    return statusValue = const GoogleStatus(
      connected: true,
      email: 'kru@school.ac.th',
    );
  }

  @override
  Future<void> disconnect() async {
    await _maybeFail();
    disconnects++;
    statusValue = GoogleStatus.disconnected;
  }

  @override
  Future<List<GoogleCourse>> courses() async {
    await _maybeFail();
    return courseRows;
  }

  @override
  Future<ClassroomGoogleLink> link(int classroomId, GoogleCourse course) async {
    await _maybeFail();
    linked.add((classroomId, course.courseId));
    return ClassroomGoogleLink(
      courseId: course.courseId,
      courseName: course.name,
    );
  }

  @override
  Future<void> unlink(int classroomId) async => _maybeFail();

  @override
  Future<List<GoogleRosterEntry>> roster(int classroomId) async {
    await _maybeFail();
    return rosterRows;
  }

  @override
  Future<void> saveRoster(int classroomId, Map<String, int?> matches) async {
    await _maybeFail();
    savedMatches.add(matches);
  }

  @override
  Future<AssignmentGoogleLink> post(
    int assignmentId, {
    required bool attachBlankWorksheet,
    String? instructions,
    DateTime? dueAt,
  }) async {
    await _maybeFail();
    posts.add((assignmentId, attachBlankWorksheet, instructions));
    return AssignmentGoogleLink(
      courseWorkId: '9001',
      alternateLink: 'https://classroom.google.com/c/abc/a/9001/details',
      hasBlankWorksheet: attachBlankWorksheet,
    );
  }

  @override
  Future<List<GoogleSubmission>> submissions(int assignmentId) async {
    await _maybeFail();
    submissionLoads++;
    return submissionRows;
  }

  @override
  Future<GoogleSubmission?> returnForRetake(int importId, String reason) async {
    await _maybeFail();
    returns.add((importId, reason));
    final row = submissionRows.firstWhere((r) => r.id == importId);
    return GoogleSubmission(
      id: row.id,
      googleSubmissionId: row.googleSubmissionId,
      state: SubmissionImportState.returnedForRetake,
      student: row.student,
      attachments: row.attachments,
      retakeReason: reason,
    );
  }

  @override
  Future<int?> retryGrades(int assignmentId) async {
    await _maybeFail();
    retries++;
    return 1;
  }
}

/// Gateway that "signs in" without the plugin.
class FakeGoogleAuth implements GoogleAuthGateway {
  FakeGoogleAuth({this.error});

  GoogleAuthException? error;
  int serverCodes = 0;
  int signOuts = 0;

  @override
  Future<GoogleServerAuth> requestServerAuthCode() async {
    if (error case final e?) throw e;
    serverCodes++;
    return const GoogleServerAuth(
      serverAuthCode: '4/0server-code',
      email: 'kru@school.ac.th',
    );
  }

  @override
  Future<DriveAccessToken> driveAccessToken({String? expectedEmail}) async {
    if (error case final e?) throw e;
    return const DriveAccessToken(value: 'ya29.x', email: 'kru@school.ac.th');
  }

  @override
  Future<void> invalidate(DriveAccessToken token) async {}

  @override
  Future<void> signOut() async => signOuts++;
}
