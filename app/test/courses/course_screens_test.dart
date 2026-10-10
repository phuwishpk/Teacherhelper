import 'package:dio/dio.dart';
import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/courses/course_detail_screen.dart';
import 'package:eduvision/features/courses/course_form_screen.dart';
import 'package:eduvision/features/courses/course_item_forms.dart';
import 'package:eduvision/features/courses/course_models.dart';
import 'package:eduvision/features/courses/courses_screen.dart';
import 'package:eduvision/features/courses/indicator_widgets.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';
import 'course_fakes.dart';

void main() {
  group('CoursesScreen', () {
    testWidgets('an empty list explains courses', (tester) async {
      await pumpScreen(
        tester,
        const CoursesScreen(),
        overrides: overrides(FakeCoursesRepository()),
      );
      expect(find.text('ยังไม่มีรายวิชา'), findsOneWidget);
    });

    testWidgets('lists courses and opens one; "กรอกในฟอร์ม" opens the form', (
      tester,
    ) async {
      await pumpScreen(
        tester,
        const CoursesScreen(),
        overrides: overrides(
          FakeCoursesRepository([
            course(id: 4, indicators: const [fraction]),
          ]),
        ),
        extraRoutes: [
          stubRoute('/courses/new', 'form'),
          stubRoute('/courses/:id', 'detail'),
        ],
      );
      expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
      expect(find.textContaining('ป.5/1'), findsOneWidget);
      expect(
        find.textContaining('ตัวชี้วัด 1 · หน่วย 0 · แผน 0'),
        findsOneWidget,
      );

      await tester.tap(find.byKey(const ValueKey('course_4')));
      await tester.pumpAndSettle();
      expect(find.text('detail /courses/4'), findsOneWidget);
      await tester.pageBack();
      await tester.pumpAndSettle();

      await tester.tap(find.text('สร้างรายวิชา'));
      await tester.pumpAndSettle();
      expect(find.text('ให้ AI อ่านจากเอกสาร'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('new_course_form')));
      await tester.pumpAndSettle();
      expect(find.text('form /courses/new'), findsOneWidget);
    });
  });

  group('CourseFormScreen', () {
    testWidgets('adds an own subject group and selects it', (tester) async {
      tall(tester);
      final skills = FakeSkills()..subjectList = const [];
      await pumpScreen(
        tester,
        const CourseFormScreen(),
        overrides: overrides(FakeCoursesRepository(), skills: skills),
      );
      expect(find.byKey(const ValueKey('subject_empty_hint')), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('subject_add')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('subject_save')));
      await tester.pumpAndSettle();
      expect(find.text('กรอกชื่อกลุ่มสาระ'), findsOneWidget);
      await tester.enterText(
        find.byKey(const ValueKey('subject_name')),
        'หน้าที่พลเมือง',
      );
      await tester.tap(find.byKey(const ValueKey('subject_save')));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('subject_empty_hint')), findsNothing);
      expect(find.text('หน้าที่พลเมือง (ของฉัน)'), findsOneWidget);
      expect(
        tester
            .state<FormFieldState<int>>(
              find.byKey(const ValueKey('course_subject')),
            )
            .value,
        90,
      );
    });

    testWidgets('creates a course bound to classrooms with indicators', (
      tester,
    ) async {
      tall(tester);
      final courses = FakeCoursesRepository();
      final skills = FakeSkills();
      await pumpScreen(
        tester,
        const CourseFormScreen(initialClassroomId: 7),
        overrides: overrides(courses, skills: skills),
        extraRoutes: [stubRoute('/courses/:id', 'detail')],
      );

      await tester.tap(find.byKey(const ValueKey('course_save')));
      await tester.pumpAndSettle();
      expect(find.text('กรอกรหัส'), findsOneWidget);
      expect(find.text('เลือกกลุ่มสาระ'), findsOneWidget);
      expect(courses.createdDrafts, isEmpty);

      await tester.enterText(
        find.byKey(const ValueKey('course_code')),
        'ค15101',
      );
      await tester.enterText(
        find.byKey(const ValueKey('course_name')),
        'คณิตศาสตร์ 5',
      );
      await tester.tap(find.byKey(const ValueKey('course_subject')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('คณิตศาสตร์').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('course_grade')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ป.5').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('ทั้งปี'));
      await tester.enterText(find.byKey(const ValueKey('course_hours')), '160');
      expect(
        tester
            .widget<FilterChip>(
              find.byKey(const ValueKey('course_classroom_7')),
            )
            .selected,
        isTrue,
      );
      await tester.tap(find.byKey(const ValueKey('course_classroom_8')));
      await tester.pumpAndSettle();

      await tester.tap(find.text('เลือกตัวชี้วัด'));
      await tester.pumpAndSettle();
      expect(skills.searches.last['level'], 'indicator,sub_indicator');
      expect(skills.searches.last['subject'], 1);
      expect(skills.searches.last['grade'], 5);
      expect(find.text('ครูเพิ่มเอง'), findsOneWidget);
      await tester.tap(find.text('ค 1.1 ป.5/1'));
      await tester.pump();
      await tester.tap(find.text('ใช้ 1 รายการ'));
      await tester.pumpAndSettle();
      expect(find.text('ตัวชี้วัดของรายวิชา (1)'), findsOneWidget);

      await tester.ensureVisible(find.byKey(const ValueKey('course_save')));
      await tester.tap(find.byKey(const ValueKey('course_save')));
      await tester.pumpAndSettle();

      final draft = courses.createdDrafts.single;
      expect(draft.toJson(), {
        'code': 'ค15101',
        'name': 'คณิตศาสตร์ 5',
        'subject_id': 1,
        'grade_level': 5,
        'semester': 0,
        'academic_year': draft.academicYear,
        'hours': 160,
        'description': null,
      });
      expect(courses.createdClassrooms.single..sort(), [7, 8]);
      expect(courses.createdSkills.single, [1]);
      expect(find.text('detail /courses/500'), findsOneWidget);
    });

    testWidgets('pick mode pops the new course back to its caller', (
      tester,
    ) async {
      tall(tester);
      final courses = FakeCoursesRepository();
      Object? popped;
      await pumpScreen(
        tester,
        Builder(
          builder: (context) => FilledButton(
            onPressed: () async => popped = await context.push(
              AppRoutes.courseNewFor(7, pick: true),
            ),
            child: const Text('open'),
          ),
        ),
        overrides: overrides(courses),
        extraRoutes: [
          GoRoute(
            path: AppRoutes.courseNew,
            builder: (_, state) => CourseFormScreen(
              initialClassroomId: int.tryParse(
                state.uri.queryParameters['classroom'] ?? '',
              ),
              popOnCreate: state.uri.queryParameters['pick'] == '1',
            ),
          ),
        ],
      );
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      await tester.enterText(find.byKey(const ValueKey('course_code')), 'ค1');
      await tester.enterText(find.byKey(const ValueKey('course_name')), 'คณิต');
      await tester.tap(find.byKey(const ValueKey('course_subject')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('คณิตศาสตร์').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('course_grade')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ป.5').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('course_save')));
      await tester.pumpAndSettle();

      expect(popped, isA<Course>());
      expect((popped! as Course).code, 'ค1');
      expect(courses.createdClassrooms.single, [7]);
      expect(find.text('open'), findsOneWidget);
    });

    testWidgets('edit sends only the sets that changed', (tester) async {
      tall(tester);
      final existing = course(
        id: 4,
        indicators: const [fraction],
        assignmentCount: 2,
      );
      final courses = FakeCoursesRepository([existing]);
      await pumpScreen(
        tester,
        CourseEditScreen(courseId: 4, initial: existing),
        overrides: overrides(courses),
      );
      expect(
        find.text('เปลี่ยนกลุ่มสาระไม่ได้ เพราะมีการบ้านใช้รายวิชานี้แล้ว'),
        findsOneWidget,
      );
      await tester.tap(find.byKey(const ValueKey('course_classroom_8')));
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const ValueKey('course_save')));
      await tester.tap(find.byKey(const ValueKey('course_save')));
      await tester.pumpAndSettle();

      expect(courses.calls, contains('update:4'));
      expect(courses.classroomSets.single.$1, 4);
      expect(courses.classroomSets.single.$2, [7, 8]);
      expect(courses.indicatorSets, isEmpty, reason: 'indicators unchanged');
      expect(find.text('stub-home'), findsOneWidget);
    });
  });

  group('CourseDetailScreen', () {
    FakeCoursesRepository detail() => FakeCoursesRepository([
      course(
        id: 4,
        indicators: const [fraction, schoolSkill],
        units: [
          unitJson(21, title: 'เศษส่วน', indicators: const [fraction]),
        ],
        plans: [
          planJson(31, unitId: 21, title: 'การบวกเศษส่วน'),
          planJson(32, position: 2, title: 'ทบทวน', taughtOn: '2026-09-01'),
        ],
      ),
    ]);

    testWidgets('shows units, plans and indicators; marks a plan taught', (
      tester,
    ) async {
      tall(tester);
      final courses = detail();
      await pumpScreen(
        tester,
        const CourseDetailScreen(courseId: 4),
        overrides: overrides(courses),
      );
      expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
      expect(find.text('ตัวชี้วัดของรายวิชา (2)'), findsOneWidget);
      expect(find.text('ครูเพิ่มเอง'), findsOneWidget);
      expect(find.text('หน่วยที่ 1 เศษส่วน'), findsOneWidget);
      expect(find.text('แผนที่ 1 การบวกเศษส่วน'), findsOneWidget);
      expect(find.text('แผนที่ไม่อยู่ในหน่วย'), findsOneWidget);
      expect(find.textContaining('สอนแล้ว 1 ก.ย. 2569'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('plan_taught_31')));
      await tester.pumpAndSettle();
      final (id, draft) = courses.planSaves.single;
      final today = DateTime.now();
      expect(id, 31);
      expect(draft.taughtOn, DateTime(today.year, today.month, today.day));
      expect(draft.toJson()['unit_id'], 21, reason: 'the rest is kept');

      await tester.tap(find.byKey(const ValueKey('plan_taught_32')));
      await tester.pumpAndSettle();
      expect(courses.planSaves.last.$2.taughtOn, isNull);
    });

    testWidgets('"สมุดคะแนน" opens the course gradebook (§23.9)', (
      tester,
    ) async {
      tall(tester);
      await pumpScreen(
        tester,
        const CourseDetailScreen(courseId: 4),
        overrides: overrides(detail()),
        extraRoutes: [stubRoute('/courses/:id/gradebook', 'gradebook')],
      );
      await tester.tap(find.byKey(const ValueKey('course_gradebook')));
      await tester.pumpAndSettle();
      expect(find.text('gradebook /courses/4/gradebook'), findsOneWidget);
    });

    testWidgets('adds a unit and edits a plan through the forms', (
      tester,
    ) async {
      tall(tester);
      final courses = detail();
      await pumpScreen(
        tester,
        const CourseDetailScreen(courseId: 4),
        overrides: overrides(courses),
      );

      await tester.tap(find.byKey(const ValueKey('course_add_unit')));
      await tester.pumpAndSettle();
      expect(find.byType(UnitFormScreen), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('unit_save')));
      await tester.pumpAndSettle();
      expect(find.text('กรอกชื่อหน่วย'), findsOneWidget);
      await tester.enterText(
        find.widgetWithText(TextFormField, 'ชื่อหน่วย'),
        'ทศนิยม',
      );
      await tester.enterText(
        find.widgetWithText(TextFormField, 'จำนวนชั่วโมง'),
        '9999',
      );
      await tester.tap(find.byKey(const ValueKey('unit_save')));
      await tester.pumpAndSettle();
      expect(find.text('กรอกจำนวนชั่วโมง 0–2000'), findsOneWidget);
      await tester.enterText(
        find.widgetWithText(TextFormField, 'จำนวนชั่วโมง'),
        '8',
      );
      await tester.tap(find.byKey(const ValueKey('unit_save')));
      await tester.pumpAndSettle();
      expect(find.byType(UnitFormScreen), findsNothing);
      expect(courses.unitSaves.single.$1, isNull);
      expect(courses.unitSaves.single.$2.toJson(), {
        'title': 'ทศนิยม',
        'hours': 8,
        'description': null,
        'skill_ids': <int>[],
      });

      await tester.tap(find.text('แผนที่ 1 การบวกเศษส่วน'));
      await tester.pumpAndSettle();
      expect(find.byType(LessonPlanFormScreen), findsOneWidget);
      expect(find.text('บวกเศษส่วนได้'), findsOneWidget);
      await tester.enterText(
        find.byKey(const ValueKey('plan_title')),
        'การบวกและลบเศษส่วน',
      );
      await tester.enterText(
        find.byKey(const ValueKey('plan_activities')),
        'เล่นเกมเศษส่วน',
      );
      await tester.tap(find.byKey(const ValueKey('plan_unit')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ไม่อยู่ในหน่วย').last);
      await tester.pumpAndSettle();
      await tester.ensureVisible(find.byKey(const ValueKey('plan_save')));
      await tester.tap(find.byKey(const ValueKey('plan_save')));
      await tester.pumpAndSettle();
      final (planId, plan) = courses.planSaves.single;
      expect(planId, 31);
      expect(plan.toJson(), {
        'title': 'การบวกและลบเศษส่วน',
        'unit_id': null,
        'hours': 2,
        'objectives': 'บวกเศษส่วนได้',
        'content': null,
        'activities': 'เล่นเกมเศษส่วน',
        'assessment': null,
        'taught_on': null,
        'skill_ids': <int>[],
      });
    });

    testWidgets('deletes a plan after asking', (tester) async {
      tall(tester);
      final courses = detail();
      await pumpScreen(
        tester,
        const CourseDetailScreen(courseId: 4),
        overrides: overrides(courses),
      );
      await tester.tap(find.text('แผนที่ 2 ทบทวน'));
      await tester.pumpAndSettle();
      await tester.tap(find.byTooltip('ลบแผน'));
      await tester.pumpAndSettle();
      expect(find.text('ลบแผนนี้?'), findsOneWidget);
      await tester.tap(find.text('ลบ'));
      await tester.pumpAndSettle();
      expect(courses.calls, contains('deletePlan:32'));
      expect(find.byType(LessonPlanFormScreen), findsNothing);
    });

    testWidgets('picks the course indicators', (tester) async {
      tall(tester);
      final courses = detail();
      await pumpScreen(
        tester,
        const CourseDetailScreen(courseId: 4),
        overrides: overrides(courses),
      );
      await tester.tap(find.byKey(const ValueKey('course_edit_indicators')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ค 1.1 ป.5/2'));
      await tester.pump();
      await tester.tap(find.text('ใช้ 3 รายการ'));
      await tester.pumpAndSettle();
      expect(courses.indicatorSets.single.$1, 4);
      expect(courses.indicatorSets.single.$2, [1, 90, 2]);
    });

    testWidgets('a course in use cannot be deleted', (tester) async {
      tall(tester);
      final courses = FakeCoursesRepository([course(id: 4)])
        ..deleteError = DioException(
          requestOptions: RequestOptions(path: '/courses/4'),
          response: Response(
            requestOptions: RequestOptions(path: '/courses/4'),
            statusCode: 409,
            data: {'message': 'x', 'errors': {}, 'code': 'course_in_use'},
          ),
        );
      await pumpScreen(
        tester,
        const CourseDetailScreen(courseId: 4),
        overrides: overrides(courses),
      );
      await tester.tap(find.byTooltip('ตัวเลือก'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ลบรายวิชา'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(FilledButton, 'ลบ'));
      await tester.pumpAndSettle();
      expect(
        find.text('ลบไม่ได้ เพราะมีการบ้านใช้รายวิชานี้อยู่'),
        findsOneWidget,
      );
      expect(find.byType(CourseDetailScreen), findsOneWidget);
    });
  });

  group('AddIndicatorDialog', () {
    testWidgets('adds a school indicator under a picked standard', (
      tester,
    ) async {
      final courses = FakeCoursesRepository();
      final skills = FakeSkills();
      Skill? added;
      await pumpScreen(
        tester,
        Builder(
          builder: (context) => FilledButton(
            onPressed: () async => added = await showAddIndicatorDialog(
              context,
              subjectId: 1,
              code: 'ค 1.1 ป.5/9',
              name: 'ใช้เศษส่วนแก้ปัญหา',
            ),
            child: const Text('open'),
          ),
        ),
        overrides: overrides(courses, skills: skills),
      );
      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();
      expect(skills.searches.last, {
        'subject': 1,
        'grade': null,
        'q': 'ค 1.1',
        'level': 'standard,indicator',
      });

      await tester.tap(find.byKey(const ValueKey('add_indicator_save')));
      await tester.pumpAndSettle();
      expect(
        find.text('เลือกมาตรฐานหรือตัวชี้วัดที่จะเพิ่มไว้ใต้ และกรอกชื่อ'),
        findsOneWidget,
      );
      await tester.tap(find.widgetWithText(RadioListTile<int>, 'ค 1.1'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('add_indicator_save')));
      await tester.pumpAndSettle();

      expect(courses.addedIndicators.single, {
        'parent_id': 11,
        'name': 'ใช้เศษส่วนแก้ปัญหา',
        'code': 'ค 1.1 ป.5/9',
      });
      expect(added?.sourceLabel, 'ครูเพิ่มเอง');
    });
  });
}
