import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:eduvision/features/mastery/classroom_mastery_screen.dart';
import 'package:eduvision/features/mastery/mastery_models.dart';
import 'package:eduvision/features/mastery/mastery_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/fake_charts.dart';
import '../helpers/pump_screen.dart';

/// `GET /classrooms/7/mastery?course_id=&unit_id=` with standard groups.
Map<String, dynamic> _heatmapJson({int? courseId, int? unitId}) {
  final unit = unitId != null;
  return {
    'classroom_id': 7,
    'course_id': courseId,
    'unit_id': unitId,
    'skills': [
      chartSkill(1, 'ค 1.1 ป.5/1'),
      chartSkill(2, 'ค 1.1 ป.5/2'),
      if (!unit) chartSkill(3, 'ค 1.2 ป.5/1'),
      if (!unit) chartSkill(5, 'ค 9 ครู'),
    ],
    'groups': [
      {
        'standard': {'id': 11, 'code': 'ค 1.1', 'name': 'เศษส่วน'},
        'skill_ids': [1, 2],
      },
      if (!unit) ...[
        {
          'standard': {'id': 12, 'code': 'ค 1.2', 'name': 'ทศนิยม'},
          'skill_ids': [3],
        },
        {
          'standard': null,
          'skill_ids': [5],
        },
      ],
    ],
    'students': [
      {'id': 55, 'name': 'เด็กชายเอ', 'student_number': 1},
    ],
    'cells': [
      {'student_id': 55, 'skill_id': 1, 'value': 0.8, 'n_obs': 2},
    ],
  };
}

class _FakeMastery extends MasteryRepository {
  final calls = <(int, int?, int?)>[];

  @override
  Future<ClassroomMastery> classroom(
    int classroomId, {
    int? courseId,
    int? unitId,
  }) async {
    calls.add((classroomId, courseId, unitId));
    return ClassroomMastery.fromJson(
      _heatmapJson(courseId: courseId, unitId: unitId),
    );
  }

  @override
  Future<MasteryList> mine() async => const MasteryList(rows: []);

  @override
  Future<List<SkillMastery>> student(int studentId, {int? courseId}) async =>
      const [];
}

void main() {
  test('groups parse with their standard', () {
    final m = ClassroomMastery.fromJson(_heatmapJson(courseId: 4));
    expect(m.courseId, 4);
    expect(m.unitId, isNull);
    expect(m.groups.map((g) => g.label), ['ค 1.1', 'ค 1.2', 'ไม่มีมาตรฐาน']);
    expect(m.groups.first.skillIds, [1, 2]);
    expect(m.groups.first.standardName, 'เศษส่วน');
  });

  testWidgets('course and unit filters, columns grouped by standard', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final mastery = _FakeMastery();
    await pumpScreen(
      tester,
      const ClassroomMasteryScreen(classroomId: 7, initialCourseId: 4),
      overrides: [
        masteryRepositoryProvider.overrideWithValue(mastery),
        classroomsRepositoryProvider.overrideWithValue(FakeClassrooms()),
        coursesRepositoryProvider.overrideWithValue(
          FakeCoursesRepository([
            course(
              units: [
                unitJson(20),
                unitJson(21, position: 2, title: 'ทศนิยม'),
              ],
            ),
          ]),
        ),
      ],
    );
    expect(tester.takeException(), isNull);
    expect(mastery.calls.first, (7, 4, null));
    expect(find.text('ทักษะของห้อง ป.5/1'), findsOneWidget);
    final heatmap = find.byKey(const ValueKey('mastery_heatmap'));
    for (final band in ['ค 1.1', 'ค 1.2', 'ไม่มีมาตรฐาน']) {
      expect(
        find.descendant(of: heatmap, matching: find.text(band)),
        findsOneWidget,
      );
    }

    // One unit of the course.
    await tester.tap(find.byKey(const ValueKey('heatmap_unit_4')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('หน่วย 1 เศษส่วน').last);
    await tester.pumpAndSettle();
    expect(mastery.calls.last, (7, 4, 20));
    expect(
      find.descendant(of: heatmap, matching: find.text('ค 1.2')),
      findsNothing,
    );

    // Back to every skill: no course, no unit.
    await tester.tap(find.byKey(const ValueKey('heatmap_course')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('ทุกทักษะ').last);
    await tester.pumpAndSettle();
    expect(mastery.calls.last, (7, null, null));
    expect(find.byKey(const ValueKey('heatmap_unit_4')), findsNothing);
  });
}
