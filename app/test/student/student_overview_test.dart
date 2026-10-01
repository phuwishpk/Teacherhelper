import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/mastery/course_mastery.dart';
import 'package:eduvision/features/student/student_labels.dart';
import 'package:eduvision/features/student/student_overview.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import 'student_fixtures.dart';

/// The student's combined view across classrooms (DESIGN §24.11, §24.26):
/// labels, grouping by subject and `GET /student/overview`.
void main() {
  group('ClassroomLabel', () {
    test('reads the label and says "ป.5/1 · 2569"', () {
      final l = ClassroomLabel.fromJson(room51)!;
      expect(l.id, 7);
      expect(l.text, 'ป.5/1 · 2569');
      expect(l.closed, isFalse);
      expect(ClassroomLabel.fromJson(room41)!.closed, isTrue);
    });

    test('no year shows the name only; junk is null', () {
      expect(
        ClassroomLabel.fromJson({'id': 1, 'name': 'ป.1/1'})!.text,
        'ป.1/1',
      );
      expect(ClassroomLabel.fromJson({'name': 'ไม่มี id'}), isNull);
      expect(ClassroomLabel.fromJson('ป.1/1'), isNull);
      expect(ClassroomLabel.listFromJson([room51, 3, room41]), hasLength(2));
      expect(ClassroomLabel.listFromJson(null), isEmpty);
    });
  });

  group('SubjectTag', () {
    const c = CourseRef(id: 4, code: 'ค15101', name: 'คณิตศาสตร์ 5');
    final open = ClassroomLabel.fromJson(room51);
    final closed = ClassroomLabel.fromJson(room41);

    test('a course in a classroom, or "อื่นๆ (วิชา)" without a course', () {
      final tag = SubjectTag(course: c, subjectName: 'คณิต', classroom: open);
      expect(tag.key, 'c4:7');
      expect(tag.title, 'ค15101 คณิตศาสตร์ 5');
      expect(tag.classroomText, 'ป.5/1 · 2569');

      final other = SubjectTag(subjectName: 'ภาษาไทย', classroom: open);
      expect(other.key, 'sภาษาไทย:7');
      expect(other.title, 'อื่นๆ (ภาษาไทย)');
      expect(const SubjectTag().title, 'อื่นๆ');
      expect(const SubjectTag().classroomText, isNull);
      expect(
        const SubjectTag(course: CourseRef(id: 9)).title,
        'รายวิชา',
        reason: 'a course without code or name still has a title',
      );
    });

    test('an old answer without a label falls back to the classroom name', () {
      const tag = SubjectTag(subjectName: 'คณิต', classroomName: 'ป.5/2');
      expect(tag.key, 'sคณิต:ป.5/2');
      expect(tag.classroomText, 'ป.5/2');
      expect(tag.closed, isFalse);
    });

    test('groups open classrooms first, courses before "อื่นๆ"', () {
      final items = [
        ('old', SubjectTag(course: c, classroom: closed)),
        ('thai', SubjectTag(subjectName: 'ภาษาไทย', classroom: open)),
        ('math 1', SubjectTag(course: c, classroom: open)),
        (
          'science',
          SubjectTag(
            course: const CourseRef(id: 5, code: 'ว15101'),
            classroom: open,
          ),
        ),
        ('math 2', SubjectTag(course: c, classroom: open)),
      ];
      final sections = groupBySubject(items, (i) => i.$2);
      expect(
        [
          for (final s in sections) [for (final i in s.items) i.$1],
        ],
        [
          ['math 1', 'math 2'],
          ['science'],
          ['thai'],
          ['old'],
        ],
      );
    });

    test('the chip names the classroom only when a title repeats', () {
      final a = SubjectTag(course: c, classroom: open);
      final b = SubjectTag(course: c, classroom: closed);
      const t = SubjectTag(subjectName: 'ภาษาไทย');
      expect(
        subjectChipLabel(a, [a, b, t]),
        'ค15101 คณิตศาสตร์ 5 · ป.5/1 · 2569',
      );
      expect(subjectChipLabel(t, [a, b, t]), 'อื่นๆ (ภาษาไทย)');
      expect(subjectChipLabel(a, [a, t]), 'ค15101 คณิตศาสตร์ 5');
    });
  });

  group('StudentOverview', () {
    test('parses the groups and splits open from old classrooms', () {
      final o = StudentOverview.fromJson(studentOverviewJson());
      expect(o.classrooms.map((c) => c.text), ['ป.5/1 · 2569', 'ป.4/1 · 2568']);
      expect(o.groups.map((g) => g.tag.key), [
        keyMath5,
        keyScience5,
        keyThai,
        keyMath4,
      ]);
      expect(o.openGroups, hasLength(3));
      expect(o.closedGroups.single.tag.key, keyMath4);
      expect(o.todoCount, 3);

      final math = o.groupOf(keyMath5)!;
      expect(math.teacherName, 'ครูสมศรี');
      expect(math.todoCount, 2);
      expect(math.resultsCount, 1);
      expect(math.latestPublishedAt, DateTime.utc(2026, 9, 30, 3));
      expect(math.hasGrade, isTrue);
      expect(math.gradeText, '3');
      expect(math.subject?.name, 'คณิตศาสตร์');

      expect(o.groupOf(keyScience5)!.hasGrade, isFalse);
      expect(o.groupOf(keyMath4)!.gradeText, 'ร');
      expect(o.groupOf(keyThai)!.tag.title, 'อื่นๆ (ภาษาไทย)');
      expect(o.groupOf('c99:1'), isNull);
    });

    test('a group without a classroom label is skipped', () {
      final o = StudentOverview.fromJson({
        'classrooms': [],
        'groups': [
          {'course': math5, 'classroom': null},
          'junk',
        ],
      });
      expect(o.groups, isEmpty);
      expect(StudentOverview.fromJson(const {}).classrooms, isEmpty);
    });

    test('the repository reads GET /student/overview', () async {
      final adapter = FakeHttpAdapter(
        (_) async => jsonResponse(200, {'data': studentOverviewJson()}),
      );
      final o = await ApiStudentOverviewRepository(fakeDio(adapter)).overview();
      expect(adapter.requests.single.uri.path, '/api/v1/student/overview');
      expect(o.groups, hasLength(4));
    });
  });

  group('labels on the student endpoints', () {
    test('assignments and results carry their course and classroom', () {
      final work = assignmentsFixture();
      expect(work.first.course?.title, 'ค15101 คณิตศาสตร์ 5');
      expect(work.first.classroom?.text, 'ป.5/1 · 2569');
      expect(work.first.classroomName, 'ป.5/1');
      expect(work.first.tag.key, keyMath5);
      expect(work[1].tag.key, keyThai);
      expect(work.last.tag.closed, isTrue);

      final results = resultsFixture();
      expect(results.first.tag.key, keyMath5);
      expect(results.last.tag.key, keyMath4);
    });

    test('a student course lists its classrooms', () {
      final c = StudentCourse.fromJson({
        'id': 4,
        'code': 'ค15101',
        'name': 'คณิตศาสตร์ 5',
        'classrooms': [room51, room41],
      });
      expect(c.classrooms.map((r) => r.text), ['ป.5/1 · 2569', 'ป.4/1 · 2568']);
    });

    test('the subject route keeps its key; the student may open it', () {
      final location = AppRoutes.studentSubject(keyThai);
      expect(Uri.parse(location).queryParameters['key'], keyThai);
      expect(AppRoutes.isStudentArea(AppRoutes.studentSubjectPath), isTrue);
      expect(AppRoutes.isStudentArea(AppRoutes.myGrades), isTrue);
      expect(
        AppRoutes.myCourseGrade(4, classroomId: 7),
        '/student/courses/4/grade?classroom=7',
      );
      expect(AppRoutes.myCourseGrade(4), '/student/courses/4/grade');
    });
  });
}
