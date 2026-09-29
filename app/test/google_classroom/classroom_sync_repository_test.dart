import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/push/push_routes.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/google_classroom/google_models.dart';
import 'package:eduvision/features/google_classroom/google_repository.dart';
import 'package:eduvision/features/google_classroom/submissions_screen.dart';
import 'package:eduvision/features/home/teacher_attention.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:flutter_test/flutter_test.dart';

import '../assignments/answer_key_fixtures.dart';
import '../helpers/fake_api_server.dart';
import '../helpers/fake_http_adapter.dart';
import '../review/review_fixtures.dart';

/// Phase 8 build step 4 (DESIGN §19.3, §19.9): the sync, grade conflict,
/// late-policy and attention endpoints with a fake Dio, and the models they
/// fill. Nothing here reaches Google.
void main() {
  ApiGoogleClassroomRepository repoWith(FakeHttpAdapter adapter) =>
      ApiGoogleClassroomRepository(fakeDio(adapter));

  Map<String, dynamic> conflictJson({
    int id = 5,
    String status = 'open',
    bool canPushApp = true,
    Object? appScore = '8.00',
    Object? classroomScore = 6.5,
  }) => {
    'id': id,
    'submission_id': 40,
    'import_id': 31,
    'student': {'id': 4567, 'name': 'ด.ช. สมชาย', 'student_number': 12},
    'app_score': appScore,
    'classroom_score': classroomScore,
    'status': status,
    'reason': status == 'accepted_classroom' ? 'รับคะแนนจาก Classroom' : null,
    'detected_at': '2026-09-30T02:00:00+00:00',
    'resolved_by': null,
    'resolved_at': null,
    'can_push_app': canPushApp,
    'alternate_link': 'https://classroom.google.com/c/a/s/31',
  };

  test('syncNow posts to /classrooms/{id}/google-sync', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(202, {
        'data': {'queued': true},
      }),
    );
    await repoWith(adapter).syncNow(7);
    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/classrooms/7/google-sync');
  });

  test('syncNow surfaces 409 google_reconnect_required', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(409, {
        'message': 'ต้องเชื่อมบัญชี Google ใหม่',
        'errors': {},
        'code': 'google_reconnect_required',
      }),
    );
    Object? error;
    try {
      await repoWith(adapter).syncNow(7);
    } catch (e) {
      error = e;
    }
    expect(isGoogleReconnectError(error!), isTrue);
    expect(googleErrorMessage(error), contains('เชื่อมใหม่'));
  });

  test('gradeConflicts reads rows; decimal strings become numbers', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          conflictJson(),
          conflictJson(
            id: 4,
            status: 'accepted_classroom',
            canPushApp: false,
            appScore: null,
          ),
        ],
      }),
    );
    final rows = await repoWith(adapter).gradeConflicts(12);
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/assignments/12/grade-conflicts',
    );
    expect(rows, hasLength(2));
    final open = rows.first;
    expect(open.isOpen, isTrue);
    expect(open.appScore, 8.0);
    expect(open.classroomScore, 6.5);
    expect(open.importId, 31);
    expect(open.studentLabel, 'ด.ช. สมชาย (เลขที่ 12)');
    expect(open.canPushApp, isTrue);
    expect(open.detectedAt, DateTime.utc(2026, 9, 30, 2));
    final done = rows.last;
    expect(done.status, GradeConflictStatus.acceptedClassroom);
    expect(done.reason, 'รับคะแนนจาก Classroom');
    expect(done.canPushApp, isFalse);
    expect(done.appScore, isNull);
  });

  test('resolveConflict sends {action} and reads the settled row', () async {
    for (final action in GradeConflictAction.values) {
      final adapter = FakeHttpAdapter(
        (_) async =>
            jsonResponse(200, {'data': conflictJson(status: 'dismissed')}),
      );
      final row = await repoWith(adapter).resolveConflict(5, action);
      final req = adapter.requests.single;
      expect(req.method, 'POST');
      expect(req.uri.path, '/api/v1/grade-conflicts/5/resolve');
      expect(FakeApiServer.bodyOf(req), {'action': action.apiValue});
      expect(row.status, GradeConflictStatus.dismissed);
    }
    expect(GradeConflictAction.values.map((a) => a.apiValue), [
      'push_app',
      'accept_classroom',
      'dismiss',
    ]);
  });

  test('acceptLate posts and reads the row back as new + late', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(202, {
        'data': {
          'id': 31,
          'google_submission_id': 'Cg31',
          'state': 'new',
          'late': true,
          'pushed_grade': null,
          'classroom_grade': '7.50',
        },
      }),
    );
    final row = await repoWith(adapter).acceptLate(31);
    expect(
      adapter.requests.single.uri.path,
      '/api/v1/google-submissions/31/accept-late',
    );
    expect(row!.state, SubmissionImportState.newSubmission);
    expect(row.late, isTrue);
    expect(row.classroomGrade, 7.5);
    expect(row.pushedGrade, isNull);
  });

  test('the new error codes read in Thai', () {
    Object err(String code) => apiError(409, {'message': 'x', 'code': code});
    expect(googleErrorMessage(err('coursework_not_owned')), contains('เว็บ'));
    expect(
      googleErrorMessage(err('conflict_resolved')),
      contains('ตัดสินไปแล้ว'),
    );
    expect(googleErrorMessage(err('import_not_rejected')), contains('ส่งช้า'));
  });

  test('teacher attention reads the counts; missing ones are 0', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': {
          'keys_pending': 2,
          'grade_conflicts': 3,
          'grade_failed': 1,
          'regrade_pending': 4,
          'needs_reconnect': true,
        },
      }),
    );
    final a = await ApiTeacherAttentionRepository(fakeDio(adapter)).attention();
    expect(adapter.requests.single.uri.path, '/api/v1/teacher/attention');
    expect(a.keysPending, 2);
    expect(a.gradeConflicts, 3);
    expect(a.gradeFailed, 1);
    expect(a.feedbackFailed, 0);
    expect(a.regradePending, 4);
    expect(a.needsReconnect, isTrue);
    expect(a.isEmpty, isFalse);
    expect(const TeacherAttention().isEmpty, isTrue);
  });

  test('approve sends subject_id only when given', () async {
    final bodies = <Map<String, dynamic>>[];
    final adapter = FakeHttpAdapter((o) async {
      bodies.add(FakeApiServer.bodyOf(o));
      return jsonResponse(200, {'data': answerKeyJson()});
    });
    final repo = ApiAnswerKeyRepository(fakeDio(adapter));
    await repo.approve(12);
    await repo.approve(12, subjectId: 3);
    expect(bodies, [
      <String, dynamic>{},
      {'subject_id': 3},
    ]);
    expect(adapter.requests.map((r) => r.uri.path).toSet(), {
      '/api/v1/assignments/12/answer-key/approve',
    });
  });

  group('models', () {
    test('a Classroom website mirror: no subject, no grade push', () {
      final a = Assignment.fromJson({
        'id': 9,
        'classroom_id': 7,
        'subject_id': null,
        'subject': null,
        'title': 'งานจาก Google Classroom',
        'status': 'draft',
        'mode': 'freeform',
        'source': 'classroom_web',
        'google_link': {
          'course_work_id': 'cw-1',
          'alternate_link': 'https://classroom.google.com/c/a/a/cw-1',
          'drive_file_id': null,
          'has_blank_worksheet': false,
          'posted_at': '2026-09-29T01:00:00+00:00',
          'origin': 'classroom_web',
          'can_push_grades': false,
          'materials': [
            {
              'drive_file_id': 'd1',
              'title': 'ใบงาน.pdf',
              'mime_type': 'application/pdf',
              'supported': true,
            },
            {
              'drive_file_id': 'd2',
              'title': 'โจทย์',
              'mime_type': 'application/vnd.google-apps.document',
              'supported': false,
            },
          ],
          'last_synced_at': '2026-09-30T02:05:00+00:00',
        },
      });
      expect(a.subjectId, isNull);
      expect(a.needsSubject, isTrue);
      expect(a.fromClassroomWeb, isTrue);
      final link = a.googleLink!;
      expect(link.fromClassroomWeb, isTrue);
      expect(link.canPushGrades, isFalse);
      expect(link.materials, hasLength(2));
      expect(link.materials.last.supported, isFalse);
      expect(link.materials.last.isGoogleDoc, isTrue);
      expect(link.lastSyncedAt, DateTime.utc(2026, 9, 30, 2, 5));
    });

    test('an older link without origin is app courseWork', () {
      final link = AssignmentGoogleLink.fromJson({
        'course_work_id': 'cw',
        'alternate_link': 'https://classroom.google.com/x',
      });
      expect(link.origin, AssignmentGoogleLink.originApp);
      expect(link.canPushGrades, isTrue);
      expect(link.materials, isEmpty);
      expect(
        const AssignmentGoogleLink(
          courseWorkId: 'x',
          alternateLink: '',
          origin: AssignmentGoogleLink.originClassroomWeb,
        ).canPushGrades,
        isFalse,
        reason: 'defaults follow the origin',
      );
      final withoutFlag = CourseWorkMaterial.fromJson({
        'title': 'scan.jpg',
        'mime_type': 'image/jpeg',
      });
      expect(withoutFlag.supported, isTrue);
    });

    test('a classroom link carries the last sync times', () {
      final link = ClassroomGoogleLink.fromJson({
        'course_id': 'c1',
        'course_name': 'คณิต',
        'linked_at': '2026-09-01T00:00:00+00:00',
        'roster_synced_at': null,
        'work_synced_at': '2026-09-30T02:10:00+00:00',
      });
      expect(link.rosterSyncedAt, isNull);
      expect(link.workSyncedAt, DateTime.utc(2026, 9, 30, 2, 10));
    });

    test('formatScore drops needless decimals', () {
      expect(formatScore(8), '8');
      expect(formatScore(7.5), '7.5');
      expect(formatScore(7.25), '7.25');
    });

    test('scoresForClipboard lists published totals by number', () {
      const s = SubmissionStudent.new;
      final rows = [
        GoogleSubmission(
          id: 1,
          googleSubmissionId: 'a',
          state: SubmissionImportState.imported,
          student: s(id: 2, name: 'บี', studentNumber: 2),
        ),
        GoogleSubmission(
          id: 2,
          googleSubmissionId: 'b',
          state: SubmissionImportState.imported,
          student: s(id: 1, name: 'เอ', studentNumber: 1),
        ),
        GoogleSubmission(
          id: 3,
          googleSubmissionId: 'c',
          state: SubmissionImportState.imported,
          student: s(id: 3, name: 'ซี', studentNumber: 3),
        ),
        const GoogleSubmission(
          id: 4,
          googleSubmissionId: 'd',
          state: SubmissionImportState.newSubmission,
        ),
      ];
      final text = scoresForClipboard(rows, {
        1: const SubmissionSummary(
          id: 11,
          status: 'published',
          responseCount: 2,
          reviewedCount: 2,
          totalScore: 9.5,
        ),
        2: const SubmissionSummary(
          id: 12,
          status: 'published',
          responseCount: 2,
          reviewedCount: 2,
          totalScore: 6,
        ),
        3: const SubmissionSummary(
          id: 13,
          status: 'needs_review',
          responseCount: 2,
          reviewedCount: 0,
          totalScore: 4,
        ),
      });
      expect(text, '1\tเอ\t9.5\n2\tบี\t6');
    });

    test('review queue summaries know an accepted Classroom total', () {
      final s = SubmissionSummary.fromJson({
        'id': 1,
        'status': 'published',
        'total_score': 6.5,
        'total_override': '6.50',
      });
      expect(s.totalOverridden, isTrue);
      expect(SubmissionSummary.fromJson({'id': 2}).totalOverridden, isFalse);
    });
  });

  group('push routes (DESIGN §19.3)', () {
    const teacher = User(id: 1, name: 'ครู', role: 'teacher');
    const student = User(id: 2, name: 'นักเรียน', role: 'student');

    test('new Classroom work opens its answer key', () {
      expect(
        routeForPush({
          'type': 'classroom_work_imported',
          'assignment_id': '9',
        }, teacher),
        AppRoutes.answerKey(9),
      );
      expect(
        routeForPush({'type': 'classroom_work_imported'}, teacher),
        isNull,
      );
    });

    test('a dropped Google grant opens settings', () {
      expect(
        routeForPush({'type': 'google_reconnect'}, teacher),
        AppRoutes.settings,
      );
      expect(routeForPush({'type': 'google_reconnect'}, student), isNull);
    });
  });
}
