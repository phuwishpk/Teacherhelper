import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/assignments/skills_picker.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart' show FakeCoursesRepository;
import '../review/review_fixtures.dart';

const _fraction = Skill(id: 1, code: 'ค 1.1 ป.5/1', name: 'เศษส่วน');
const _decimal = Skill(id: 2, code: 'ค 1.1 ป.5/2', name: 'ทศนิยม');
const _ratio = Skill(id: 3, code: 'ค 1.2 ป.5/1', name: 'อัตราส่วน');

class _Repo extends Fake implements AssignmentsRepository {
  _Repo({this.error});

  final Object? error;
  final searches = <(int?, int?, String?)>[];
  final levels = <String?>[];

  @override
  Future<List<Skill>> searchSkills({
    int? subjectId,
    int? grade,
    String? q,
    String? level,
  }) async {
    searches.add((subjectId, grade, q));
    levels.add(level);
    if (error != null) throw error!;
    const all = [_fraction, _decimal, _ratio];
    if (q == null || q.isEmpty) return all;
    return all.where((s) => s.code.contains(q) || s.name.contains(q)).toList();
  }
}

Widget _host(
  _Repo repo,
  void Function(List<Skill>?) onResult, {
  List<Skill> selected = const [],
  FakeCoursesRepository? courses,
}) {
  return ProviderScope(
    overrides: [
      assignmentsRepositoryProvider.overrideWithValue(repo),
      coursesRepositoryProvider.overrideWithValue(
        courses ?? FakeCoursesRepository(),
      ),
    ],
    child: MaterialApp(
      home: Scaffold(
        body: Builder(
          builder: (context) => FilledButton(
            onPressed: () async => onResult(
              await showSkillsPicker(
                context,
                subjectId: 1,
                grade: 5,
                selected: selected,
              ),
            ),
            child: const Text('เลือกทักษะ'),
          ),
        ),
      ),
    ),
  );
}

void main() {
  testWidgets('searches with subject and grade, filters as you type and '
      'returns the ticked skills', (tester) async {
    final repo = _Repo();
    List<Skill>? result;
    await tester.pumpWidget(
      _host(repo, (r) => result = r, selected: const [_fraction]),
    );

    await tester.tap(find.text('เลือกทักษะ'));
    await tester.pumpAndSettle();

    expect(find.text('เลือกตัวชี้วัด / ทักษะ'), findsOneWidget);
    expect(repo.searches.single, (1, 5, ''));
    expect(
      repo.levels.single,
      'indicator,sub_indicator',
      reason: 'questions take only indicators and sub-indicators (§20.2)',
    );
    expect(find.byType(CheckboxListTile), findsNWidgets(3));
    expect(find.byType(InputChip), findsOneWidget);
    expect(find.text('ใช้ 1 รายการ'), findsOneWidget);

    await tester.enterText(find.byType(TextField), 'ทศนิยม');
    await tester.pump(const Duration(milliseconds: 400));
    await tester.pumpAndSettle();

    expect(repo.searches.last, (1, 5, 'ทศนิยม'));
    expect(find.byType(CheckboxListTile), findsOneWidget);
    expect(find.widgetWithText(CheckboxListTile, 'ทศนิยม'), findsOneWidget);

    await tester.tap(find.byType(CheckboxListTile));
    await tester.pumpAndSettle();
    expect(find.text('ใช้ 2 รายการ'), findsOneWidget);

    await tester.tap(find.text('ใช้ 2 รายการ'));
    await tester.pumpAndSettle();

    expect(result?.map((s) => s.id), [1, 2]);
    expect(find.byType(AlertDialog), findsNothing);
  });

  testWidgets('unticking a preselected skill removes it; cancel returns null', (
    tester,
  ) async {
    final repo = _Repo();
    List<Skill>? result = const [];
    await tester.pumpWidget(
      _host(repo, (r) => result = r, selected: const [_fraction, _ratio]),
    );

    await tester.tap(find.text('เลือกทักษะ'));
    await tester.pumpAndSettle();
    expect(find.text('ใช้ 2 รายการ'), findsOneWidget);

    // The chip's delete icon and the list checkbox both unselect.
    await tester.tap(
      find.descendant(
        of: find.byType(InputChip).first,
        matching: find.byType(Icon),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('ใช้ 1 รายการ'), findsOneWidget);

    await tester.tap(find.widgetWithText(CheckboxListTile, 'ค 1.2 ป.5/1'));
    await tester.pumpAndSettle();
    expect(find.text('ใช้ 0 รายการ'), findsOneWidget);

    await tester.tap(find.text('ยกเลิก'));
    await tester.pumpAndSettle();
    expect(result, isNull);
  });

  testWidgets('a failing search shows the API message', (tester) async {
    final repo = _Repo(
      error: apiError(503, {
        'message': 'ค้นหาไม่ได้ชั่วคราว',
        'errors': <String, Object>{},
        'code': 'search_unavailable',
      }),
    );
    await tester.pumpWidget(_host(repo, (_) {}));

    await tester.tap(find.text('เลือกทักษะ'));
    await tester.pumpAndSettle();

    expect(find.text('ค้นหาไม่ได้ชั่วคราว'), findsOneWidget);
    expect(find.byType(CheckboxListTile), findsNothing);
  });

  testWidgets('"เพิ่มตัวชี้วัดของโรงเรียน" adds a missing one and ticks it', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final repo = _Repo();
    final courses = FakeCoursesRepository();
    List<Skill>? result;
    await tester.pumpWidget(_host(repo, (r) => result = r, courses: courses));
    await tester.tap(find.text('เลือกทักษะ'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('skills_picker_add_indicator')));
    await tester.pumpAndSettle();
    expect(find.text('เพิ่มตัวชี้วัดของโรงเรียน'), findsWidgets);
    expect(repo.levels.last, 'standard,indicator');
    await tester.tap(find.widgetWithText(RadioListTile<int>, 'ค 1.1 ป.5/1'));
    await tester.enterText(
      find.byKey(const ValueKey('add_indicator_name')),
      'เศษส่วนในชีวิตประจำวัน',
    );
    await tester.tap(find.byKey(const ValueKey('add_indicator_save')));
    await tester.pumpAndSettle();

    expect(courses.addedIndicators.single, {
      'parent_id': 1,
      'name': 'เศษส่วนในชีวิตประจำวัน',
      'code': null,
    });
    expect(find.text('ครูเพิ่มเอง'), findsOneWidget);
    await tester.tap(find.text('ใช้ 1 รายการ'));
    await tester.pumpAndSettle();
    expect(result?.single.sourceLabel, 'ครูเพิ่มเอง');
  });
}
