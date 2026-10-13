import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/classrooms/co_teachers.dart';
import 'package:eduvision/features/classrooms/co_teachers_section.dart';
import 'package:eduvision/features/classrooms/invitations_card.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../google_signin/google_signin_fakes.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import 'classroom_fakes.dart';

class _FakeCoTeachers extends Fake implements CoTeachersRepository {
  List<CoTeacherRow> rows = const [
    CoTeacherRow(
      id: 1,
      email: 'science@example.com',
      status: 'accepted',
      teacherName: 'ครูวิทย์',
      courses: ['ว15101 วิทยาศาสตร์ 5'],
    ),
    CoTeacherRow(id: 2, email: 'art@example.com', status: 'pending'),
  ];
  List<ClassroomInvitation> pending = const [
    ClassroomInvitation(
      id: 9,
      classroomId: 20,
      classroomName: 'ป.6/1',
      academicYear: 2569,
      ownerName: 'ครูสมศรี',
    ),
  ];
  final calls = <String>[];
  Object? inviteError;
  Object? removeError;

  @override
  Future<List<CoTeacherRow>> list(int classroomId) async {
    calls.add('list:$classroomId');
    return rows;
  }

  @override
  Future<CoTeacherRow> invite(int classroomId, String email) async {
    calls.add('invite:$classroomId:$email');
    if (inviteError case final e?) throw e;
    return CoTeacherRow(id: 3, email: email.toLowerCase(), status: 'pending');
  }

  @override
  Future<void> remove(int classroomId, int rowId) async {
    calls.add('remove:$classroomId:$rowId');
    if (removeError case final e?) throw e;
    rows = [
      for (final r in rows)
        if (r.id != rowId) r,
    ];
  }

  @override
  Future<List<ClassroomInvitation>> invitations() async {
    calls.add('invitations');
    return pending;
  }

  @override
  Future<void> accept(int id) async {
    calls.add('accept:$id');
    pending = const [];
  }

  @override
  Future<void> decline(int id) async {
    calls.add('decline:$id');
    pending = const [];
  }
}

/// DESIGN §29.13.4: co-teaching by invitation.
void main() {
  group('repository', () {
    test('the API calls', () async {
      final adapter = FakeHttpAdapter((o) async {
        if (o.path == '/classroom-invitations') {
          return jsonResponse(200, {
            'data': [
              {
                'id': 9,
                'status': 'pending',
                'classroom': {
                  'id': 20,
                  'name': 'ป.6/1',
                  'grade_level': 6,
                  'academic_year': 2569,
                },
                'owner': {'id': 2, 'name': 'ครูสมศรี'},
                'invited_at': '2026-10-13T03:00:00+00:00',
              },
            ],
          });
        }
        if (o.method == 'GET') {
          return jsonResponse(200, {
            'data': [
              {
                'id': 1,
                'classroom_id': 7,
                'email': 'science@example.com',
                'status': 'accepted',
                'teacher': {'id': 5, 'name': 'ครูวิทย์'},
                'courses': [
                  {'id': 40, 'code': 'ว15101', 'name': 'วิทยาศาสตร์ 5'},
                ],
                'invited_at': '2026-10-13T03:00:00+00:00',
                'decided_at': '2026-10-13T04:00:00+00:00',
              },
            ],
          });
        }
        if (o.method == 'POST' && o.path.endsWith('/teachers')) {
          return jsonResponse(201, {
            'data': {
              'id': 3,
              'classroom_id': 7,
              'email': 'art@example.com',
              'status': 'pending',
              'teacher': null,
              'courses': [],
            },
          });
        }
        return jsonResponse(200, {'data': <String, Object?>{}});
      });
      final repo = ApiCoTeachersRepository(fakeDio(adapter));

      final rows = await repo.list(7);
      expect(adapter.requests.last.path, '/classrooms/7/teachers');
      expect(rows.single.teacherName, 'ครูวิทย์');
      expect(rows.single.accepted, isTrue);
      expect(rows.single.courses, ['ว15101 วิทยาศาสตร์ 5']);
      expect(rows.single.statusLabel, 'ร่วมสอนอยู่');

      final row = await repo.invite(7, ' Art@example.com ');
      expect(adapter.requests.last.method, 'POST');
      expect(adapter.requests.last.data, {'email': 'Art@example.com'});
      expect(row.status, 'pending');
      expect(row.statusLabel, 'รอตอบรับ');

      await repo.remove(7, 3);
      expect(adapter.requests.last.method, 'DELETE');
      expect(adapter.requests.last.path, '/classrooms/7/teachers/3');

      final invitations = await repo.invitations();
      expect(adapter.requests.last.path, '/classroom-invitations');
      expect(invitations.single.classroomName, 'ป.6/1');
      expect(invitations.single.ownerName, 'ครูสมศรี');

      await repo.accept(9);
      expect(adapter.requests.last.path, '/classroom-invitations/9/accept');
      await repo.decline(9);
      expect(adapter.requests.last.path, '/classroom-invitations/9/decline');
    });
  });

  group('the owner\'s section', () {
    Future<_FakeCoTeachers> pump(
      WidgetTester tester, {
      _FakeCoTeachers? fake,
    }) async {
      tester.view.physicalSize = const Size(800, 1600);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      final repo = fake ?? _FakeCoTeachers();
      await pumpScreen(
        tester,
        Scaffold(body: CoTeachersSection(classroom: room())),
        overrides: [coTeachersRepositoryProvider.overrideWithValue(repo)],
      );
      return repo;
    }

    testWidgets('lists the co-teachers with their state and courses', (
      tester,
    ) async {
      await pump(tester);
      expect(find.text('ครูวิทย์'), findsOneWidget);
      expect(
        find.textContaining('ร่วมสอนอยู่ · ว15101 วิทยาศาสตร์ 5'),
        findsOneWidget,
      );
      expect(find.text('art@example.com'), findsOneWidget);
      expect(find.textContaining('รอตอบรับ'), findsOneWidget);
    });

    testWidgets('invites by e-mail and shows the server\'s refusal', (
      tester,
    ) async {
      final fake = await pump(tester);
      await tester.tap(find.byKey(const ValueKey('co_teacher_invite')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('invite_send')));
      await tester.pumpAndSettle();
      expect(find.text('กรอกอีเมลให้ถูกต้อง'), findsOneWidget);

      fake.inviteError = apiError(
        409,
        'invitation_exists',
        message: 'เชิญอีเมลนี้ไว้แล้ว',
      );
      await tester.enterText(
        find.byKey(const ValueKey('invite_email')),
        'new@example.com',
      );
      await tester.tap(find.byKey(const ValueKey('invite_send')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('invite_error')), findsOneWidget);
      expect(find.text('เชิญอีเมลนี้ไว้แล้ว'), findsOneWidget);

      fake.inviteError = null;
      await tester.tap(find.byKey(const ValueKey('invite_send')));
      await tester.pumpAndSettle();
      expect(
        fake.calls.where((c) => c.startsWith('invite:7:new@example.com')),
        hasLength(2),
      );
      expect(find.textContaining('เชิญ new@example.com แล้ว'), findsOneWidget);
    });

    testWidgets('removes a co-teacher after confirming', (tester) async {
      final fake = await pump(tester);
      await tester.tap(find.byKey(const ValueKey('co_teacher_remove_1')));
      await tester.pumpAndSettle();
      expect(find.text('เอา ครูวิทย์ ออกจากห้อง?'), findsOneWidget);
      await tester.tap(find.text('เอาออก'));
      await tester.pumpAndSettle();
      expect(fake.calls, contains('remove:7:1'));
      expect(find.text('ครูวิทย์'), findsNothing);
    });
  });

  group('the invitations card', () {
    testWidgets('accepts an invitation and refreshes the rooms', (
      tester,
    ) async {
      final fake = _FakeCoTeachers();
      final rooms = FakeSchoolClassrooms();
      await pumpScreen(
        tester,
        const Scaffold(body: InvitationsCard()),
        overrides: [
          coTeachersRepositoryProvider.overrideWithValue(fake),
          classroomsRepositoryProvider.overrideWithValue(rooms),
        ],
      );
      expect(find.byKey(const ValueKey('invitations_card')), findsOneWidget);
      expect(find.text('ห้อง ป.6/1'), findsOneWidget);
      expect(
        find.textContaining('ครู ครูสมศรี เชิญคุณร่วมสอน'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('invitation_accept_9')));
      await tester.pumpAndSettle();
      expect(fake.calls, containsAllInOrder(['invitations', 'accept:9']));
      expect(find.textContaining('ตอบรับแล้ว ห้อง ป.6/1'), findsOneWidget);
      expect(find.byKey(const ValueKey('invitations_card')), findsNothing);
    });

    testWidgets('is nothing without invitations', (tester) async {
      final fake = _FakeCoTeachers()..pending = const [];
      await pumpScreen(
        tester,
        const Scaffold(body: InvitationsCard()),
        overrides: [coTeachersRepositoryProvider.overrideWithValue(fake)],
      );
      expect(find.byKey(const ValueKey('invitations_card')), findsNothing);
    });
  });
}
