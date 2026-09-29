import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/hand_in/hand_in_models.dart';
import 'package:eduvision/features/hand_in/hand_in_repository.dart';
import 'package:eduvision/features/hand_in/teacher_upload_screen.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';

PickedDocument _file(String name, [int size = 3]) =>
    PickedDocument(name: name, bytes: Uint8List(size));

/// Request/response shapes of the hand-in endpoints (DESIGN §19.6, §19.9).
void main() {
  test('studentAssignments reads the list of work to hand in', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {
            'id': 7,
            'title': 'เศษส่วน ชุดที่ 3',
            'classroom': {'id': 2, 'name': 'ป.5/2'},
            'subject_name': 'คณิตศาสตร์',
            'due_at': '2026-10-01T02:00:00+00:00',
            'accept_late': false,
            'can_submit': false,
            'submission_id': 55,
            'submitted_at': '2026-09-30T01:00:00+00:00',
            'late': true,
            'status': 'submitted',
          },
          {'id': 8, 'title': 'งานไม่มีกำหนด', 'status': 'not_submitted'},
        ],
      }),
    );
    final list = await ApiHandInRepository(
      fakeDio(adapter),
    ).studentAssignments();

    expect(adapter.requests.single.uri.path, '/api/v1/student/assignments');
    final a = list.first;
    expect(a.classroomName, 'ป.5/2');
    expect(a.subjectName, 'คณิตศาสตร์');
    expect(a.dueAt, DateTime.utc(2026, 10, 1, 2));
    expect(a.acceptLate, isFalse);
    expect(a.canSubmit, isFalse);
    expect(a.submissionId, 55);
    expect(a.late, isTrue);
    expect(a.isSubmitted, isTrue);
    expect(a.isPublished, isFalse);
    expect(a.isOverdue(DateTime.utc(2026, 10, 2)), isTrue);
    final b = list.last;
    expect(b.canSubmit, isTrue);
    expect(b.isSubmitted, isFalse);
    expect(b.isOverdue(DateTime.utc(2030)), isFalse);
  });

  test('submit posts files[] and reports progress', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'data': {
          'assignment_id': 7,
          'submission_id': 55,
          'submitted_at': '2026-09-30T01:00:00+00:00',
          'late': true,
          'status': 'submitted',
          'files': 2,
          'pages': 3,
        },
      }),
    );
    final receipt = await ApiHandInRepository(
      fakeDio(adapter),
    ).submit(7, [_file('p1.jpg'), _file('p2.pdf')], onProgress: (_, _) {});

    final req = adapter.requests.single;
    expect(req.method, 'POST');
    expect(req.uri.path, '/api/v1/student/assignments/7/submission');
    final form = req.data as FormData;
    expect(form.files.map((e) => e.key), ['files[]', 'files[]']);
    expect(form.files.map((e) => e.value.contentType?.mimeType), [
      'image/jpeg',
      'application/pdf',
    ]);
    expect(receipt.submissionId, 55);
    expect(receipt.late, isTrue);
    expect(receipt.pages, 3);
    expect(receipt.submittedAt, DateTime.utc(2026, 9, 30, 1));
  });

  test('uploadForStudent posts to the student pages endpoint', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(201, {
        'data': {
          'submission_id': 90,
          'student_id': 41,
          'pages': [
            {'id': 1, 'position': 1},
            {'id': 2, 'position': 2},
          ],
          'grading': false,
          'waiting_key': false,
          'regrade_pending': true,
        },
      }),
    );
    final result = await ApiHandInRepository(
      fakeDio(adapter),
    ).uploadForStudent(12, 41, [_file('a.png'), _file('b.webp')]);

    expect(
      adapter.requests.single.uri.path,
      '/api/v1/assignments/12/students/41/pages',
    );
    expect(result.submissionId, 90);
    expect(result.studentId, 41);
    expect(result.pages, 2);
    expect(result.regradePending, isTrue);
    expect(result.grading, isFalse);
  });

  test('handedIn reads the submissions of the review queue meta', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [],
        'meta': {
          'submissions': [
            {
              'id': 90,
              'status': 'needs_review',
              'late': true,
              'student': {'id': 41, 'name': 'ก', 'student_number': 1},
            },
            {'id': 91, 'status': 'grading', 'student': null},
          ],
        },
      }),
    );
    final map = await ApiHandInRepository(fakeDio(adapter)).handedIn(12);

    final req = adapter.requests.single;
    expect(req.uri.path, '/api/v1/assignments/12/review-queue');
    expect(req.uri.queryParameters['per_page'], '1');
    expect(map.keys, [41]);
    expect(map[41]!.submissionId, 90);
    expect(map[41]!.late, isTrue);
    expect(map[41]!.status, 'needs_review');
  });

  group('handInFilesProblem', () {
    test('needs 1 to 5 files of an accepted type and size', () {
      expect(handInFilesProblem([]), contains('อย่างน้อย 1 ไฟล์'));
      expect(
        handInFilesProblem([for (var i = 0; i < 6; i++) _file('p$i.jpg')]),
        contains('ไม่เกิน 5 ไฟล์'),
      );
      expect(handInFilesProblem([_file('work.docx')]), contains('PDF'));
      expect(
        handInFilesProblem([_file('big.jpg', kMaxHandInFileBytes + 1)]),
        contains('10 MB'),
      );
      expect(handInFilesProblem([_file('a.HEIC'), _file('b.pdf')]), isNull);
      expect(
        handInFilesProblem([PickedDocument(name: 'cam.jpg', path: '/c.jpg')]),
        isNull,
      );
    });
  });

  test('teacher upload helpers pick assignments and filter students', () {
    const worksheetDraft = Assignment(
      id: 1,
      classroomId: 2,
      subjectId: 3,
      title: 'ร่าง',
    );
    const freeformDraft = Assignment(
      id: 2,
      classroomId: 2,
      subjectId: null,
      title: 'งานเว็บ',
      mode: AssignmentMode.freeform,
    );
    const ready = Assignment(
      id: 3,
      classroomId: 2,
      subjectId: 3,
      title: 'พร้อม',
      status: 'ready',
    );
    expect(canUploadFor(worksheetDraft), isFalse);
    expect(canUploadFor(freeformDraft), isTrue);
    expect(canUploadFor(ready), isTrue);
    expect(subjectKeyOf(freeformDraft), -1);
    expect(subjectKeyOf(ready), 3);

    const roster = [
      RosterStudent(studentId: 1, studentNumber: 1, name: 'ด.ช. กล้า'),
      RosterStudent(studentId: 2, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
    ];
    expect(filterStudents(roster, ''), roster);
    expect(filterStudents(roster, 'สมหญิง').single.studentId, 2);
    expect(filterStudents(roster, '12').single.studentId, 2);
    expect(filterStudents(roster, '1').single.studentId, 1);
    expect(filterStudents(roster, 'ไม่มี'), isEmpty);
  });

  test('Assignment reads submissions_count from the list', () {
    final a = Assignment.fromJson({
      'id': 3,
      'classroom_id': 2,
      'subject_id': 1,
      'title': 'x',
      'submissions_count': 4,
    });
    expect(a.submissionsCount, 4);
    expect(a.withGoogleLink(null).submissionsCount, 4);
  });
}
