import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/features/analysis/analysis_repository.dart';
import 'package:eduvision/features/attendance/attendance_models.dart';
import 'package:eduvision/features/class_posts/class_post_models.dart';
import 'package:eduvision/features/mastery/course_mastery.dart';
import 'package:eduvision/features/mastery/mastery_models.dart';
import 'package:eduvision/features/mastery/mastery_repository.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:eduvision/features/student/student_shell.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../attendance/attendance_fakes.dart';
import '../class_posts/class_post_fakes.dart';
import '../helpers/pump_screen.dart';
import '../live/live_test_support.dart';
import '../student/student_fixtures.dart';

const _student = User(id: 11, name: 'นักเรียน ก', role: 'student');
const _classroomId = 7;
const _courseId = 3;

FeedItem _postItem(int id, String body) => FeedItem(
  type: FeedType.post,
  post: ClassPost(
    id: id,
    kind: PostKind.announcement,
    body: body,
    authorName: 'ครูสมศรี',
    classroom: const PostRef(id: _classroomId, name: 'ป.5/1'),
    publishedAt: DateTime.utc(2026, 11, 1),
    isMine: false,
    attachments: const [],
  ),
);

String _todayIso() {
  final now = DateTime.now();
  final y = now.year.toString().padLeft(4, '0');
  final m = now.month.toString().padLeft(2, '0');
  final d = now.day.toString().padLeft(2, '0');
  return '$y-$m-$d';
}

MyAttendanceCourse _attendanceCourse(String status) =>
    MyAttendanceCourse.fromJson({
      'course': {'id': _courseId, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
      'classroom': {
        'id': _classroomId,
        'name': 'ป.5/1',
        'academic_year': 2569,
      },
      'counts': {
        'present': status == 'present' ? 1 : 0,
        'late': status == 'late' ? 1 : 0,
        'absent': 0,
        'personal_leave': 0,
        'sick_leave': 0,
      },
      'counted': 1,
      'rate': status == 'present' ? 1.0 : 0.5,
      'sessions': [
        {
          'id': 55,
          'held_on': _todayIso(),
          'period_no': 1,
          'status': status,
          'note': null,
        },
      ],
    });

/// Integration test: the student home follows posts and attendance changes
/// triggered via live Firestore signals (DESIGN §38.11 / C16).
void main() {
  testWidgets(
    'student home: posts appear/disappear and attendance updates via signals',
    (tester) async {
      tester.view.physicalSize = const Size(480, 1280);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);

      final db = FakeFirebaseFirestore();
      final posts = FakeClassPosts(feed: []);
      final attendance = FakeAttendance(mine: []);

      await pumpScreen(
        tester,
        const StudentShell(),
        overrides: [
          ...studentViewOverrides(classPosts: posts, attendance: attendance),
          practiceRecommendationsProvider.overrideWith((ref) async => const []),
          myMasteryProvider.overrideWith(
            (ref) async => const MasteryList(rows: []),
          ),
          myAnalysesProvider.overrideWith((ref) async => const []),
          myCoursesProvider.overrideWith((ref) async => const []),
          liveSessionProvider.overrideWithValue(
            FakeLiveSession(db: db, uid: 'u11'),
          ),
          currentUserProvider.overrideWithValue(_student),
        ],
        extraRoutes: [
          GoRoute(
            path: '/student/posts/:id',
            builder: (_, s) => Text('post ${s.pathParameters['id']}'),
          ),
          GoRoute(
            path: '/student/attendance',
            builder: (_, _) => const Text('attendance'),
          ),
        ],
      );

      // 1. เปิดหน้าแรก: ยังไม่มีโพสต์และไม่มีการ์ดเช็คชื่อ.
      expect(find.byKey(const ValueKey('latest_posts')), findsNothing);
      expect(find.byKey(const ValueKey('today_attendance')), findsNothing);

      // 2. เพิ่มโพสต์ใน fake แล้วเขียน class_feed signal.
      posts.feedItems = [_postItem(101, 'ประกาศสอบ')];
      await db.doc('users/u11/class_feed/$_classroomId').set({
        'classroom_id': _classroomId,
        'updated_at': 1,
      });
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('latest_posts')), findsOneWidget);
      expect(find.text('ประกาศสอบ'), findsOneWidget);

      // 3. เอาโพสต์ออก แล้วเขียน signal ซ้ำ.
      posts.feedItems = [];
      await db.doc('users/u11/class_feed/$_classroomId').set({
        'classroom_id': _classroomId,
        'updated_at': 2,
      });
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('latest_posts')), findsNothing);

      // 4. เพิ่มคาบวันนี้สถานะ "late" แล้วเขียน attendance signal.
      attendance.mineValue = [_attendanceCourse('late')];
      await db.doc('users/u11/attendance/${_courseId}_$_classroomId').set({
        'course_id': _courseId,
        'classroom_id': _classroomId,
        'updated_at': 1,
      });
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('today_attendance')), findsOneWidget);
      expect(find.text('มาสาย'), findsOneWidget);

      // 5. เปลี่ยนสถานะเป็น "present" แล้วเขียน signal ซ้ำ.
      attendance.mineValue = [_attendanceCourse('present')];
      await db.doc('users/u11/attendance/${_courseId}_$_classroomId').set({
        'course_id': _courseId,
        'classroom_id': _classroomId,
        'updated_at': 2,
      });
      await tester.pumpAndSettle();

      expect(find.text('มาตรงเวลา'), findsOneWidget);
    },
  );
}
