import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/mastery/classroom_mastery_screen.dart';
import 'package:eduvision/features/mastery/mastery_models.dart';
import 'package:eduvision/features/mastery/mastery_page.dart';
import 'package:eduvision/features/mastery/mastery_repository.dart';
import 'package:eduvision/features/mastery/student_mastery_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/fake_http_adapter.dart';

Map<String, dynamic> classroomMasteryJson() => {
  'skills': [
    {'id': 7, 'code': 'ค 1.1', 'name': 'เศษส่วน'},
    {'id': 8, 'code': 'ค 1.2', 'name': 'ทศนิยม'},
  ],
  'students': [
    {'id': 4012, 'student_number': 12, 'name': 'ด.ญ. สมหญิง'},
    {'id': 4003, 'student_number': 3, 'name': 'ด.ช. สมชาย'},
  ],
  'cells': [
    {'student_id': 4003, 'skill_id': 7, 'value': 0.9, 'n_obs': 3},
    {'student_id': 4003, 'skill_id': 8, 'value': 0.3, 'n_obs': 2},
    {'student_id': 4012, 'skill_id': 7, 'value': 0.55, 'n_obs': 4},
    // One observation only: shown, but as "ข้อมูลยังน้อย".
    {'student_id': 4012, 'skill_id': 8, 'value': 0.2, 'n_obs': 1},
  ],
};

List<Map<String, dynamic>> studentRows() => [
  {
    'skill': {'id': 7, 'code': 'ค 1.1', 'name': 'เศษส่วน'},
    'value': 0.9,
    'n_obs': 3,
  },
  {
    'skill': {'id': 8, 'code': 'ค 1.2', 'name': 'ทศนิยม'},
    'value': 0.3,
    'n_obs': 2,
  },
  {
    'skill': {'id': 9, 'code': 'ค 1.3', 'name': 'ร้อยละ'},
    'value': 0.1,
    'n_obs': 1,
  },
  {
    'skill': {'id': 10, 'code': 'ค 2.1', 'name': 'การวัด'},
    'value': 0.5,
    'n_obs': 5,
  },
];

class _FakeMastery implements MasteryRepository {
  _FakeMastery({this.available = true});

  final bool available;

  @override
  Future<ClassroomMastery> classroom(int classroomId) async =>
      ClassroomMastery.fromJson(classroomMasteryJson());

  @override
  Future<MasteryList> mine() async => MasteryList(
    rows: available ? studentRows().map(SkillMastery.fromJson).toList() : [],
    available: available,
  );

  @override
  Future<List<SkillMastery>> student(int studentId) async =>
      studentRows().map(SkillMastery.fromJson).toList();
}

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 1,
      name: 'ป.4/1',
      gradeLevel: 4,
      academicYear: 2569,
      classCode: 'K7Q3M2',
    ),
  ];

  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 4003, studentNumber: 3, name: 'ด.ช. สมชาย'),
  ];
}

void main() {
  group('levels (DESIGN §11.7, §14.2)', () {
    test('cut points and too little data', () {
      expect(MasteryLevel.of(0.75, 2), MasteryLevel.good);
      expect(MasteryLevel.of(0.749, 2), MasteryLevel.partial);
      expect(MasteryLevel.of(0.4, 2), MasteryLevel.partial);
      expect(MasteryLevel.of(0.39, 2), MasteryLevel.notYet);
      expect(MasteryLevel.of(0.95, 1), MasteryLevel.tooLittle);
    });

    test('weakest first puts too-little-data skills last', () {
      final sorted = SkillMastery.weakestFirst(
        studentRows().map(SkillMastery.fromJson),
      );
      expect(sorted.map((m) => m.skill.code), [
        'ค 1.2',
        'ค 2.1',
        'ค 1.1',
        'ค 1.3',
      ]);
    });

    test('classroom payload pivots into cells, students by number', () {
      final m = ClassroomMastery.fromJson(classroomMasteryJson());
      expect(m.students.map((s) => s.studentNumber), [3, 12]);
      expect(m.cell(4012, 8)!.level, MasteryLevel.tooLittle);
      expect(m.cell(4003, 8)!.level, MasteryLevel.notYet);
      expect(m.skillMean(7), closeTo((0.9 + 0.55) / 2, 1e-9));
      expect(m.skillMean(8), closeTo(0.3, 1e-9), reason: 'n_obs < 2 ignored');
    });
  });

  testWidgets('classroom heatmap: cells, weak skills and tap to a student', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(390, 844); // a phone
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final router = GoRouter(
      initialLocation: '/classrooms/1/mastery',
      routes: [
        GoRoute(
          path: '/classrooms/:id/mastery',
          builder: (_, _) => const ClassroomMasteryScreen(classroomId: 1),
        ),
        GoRoute(
          path: '/classrooms/:id/students/:sid/mastery',
          builder: (_, state) => Text('student-${state.pathParameters['sid']}'),
        ),
      ],
    );
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          masteryRepositoryProvider.overrideWithValue(_FakeMastery()),
          classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        ],
        child: MaterialApp.router(routerConfig: router),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('ทักษะของห้อง ป.4/1'), findsOneWidget);
    final heatmap = find.byKey(const ValueKey('mastery_heatmap'));
    expect(heatmap, findsOneWidget);
    expect(
      find.descendant(of: heatmap, matching: find.text('3. ด.ช. สมชาย')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: heatmap, matching: find.text('90%')),
      findsOneWidget,
    );
    expect(
      find.descendant(of: heatmap, matching: find.text('20%')),
      findsOneWidget,
    );
    // Weak skills of the class, lowest mean first.
    expect(find.textContaining('ค 1.2 ทศนิยม'), findsOneWidget);
    expect(find.text('เฉลี่ย 30%'), findsOneWidget);
    expect(
      find.textContaining('ข้อมูลยังน้อย'),
      findsWidgets,
      reason: 'legend',
    );
    expect(tester.takeException(), isNull);

    await tester.tap(find.text('3. ด.ช. สมชาย'));
    await tester.pumpAndSettle();
    expect(find.text('student-4003'), findsOneWidget);
  });

  testWidgets('teacher sees a student\'s three weakest skills', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          masteryRepositoryProvider.overrideWithValue(_FakeMastery()),
          classroomsRepositoryProvider.overrideWithValue(_FakeClassrooms()),
        ],
        child: const MaterialApp(
          home: StudentMasteryScreen(classroomId: 1, studentId: 4003),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('3. ด.ช. สมชาย'), findsOneWidget);
    expect(find.text('จุดอ่อน 3 อันดับ'), findsOneWidget);
    // Only partial / not-yet skills with enough data are weaknesses.
    expect(find.text('ค 1.2 ทศนิยม'), findsOneWidget);
    expect(find.text('ค 2.1 การวัด'), findsOneWidget);
    expect(find.text('ค 1.3 ร้อยละ'), findsNothing);
  });

  testWidgets('student mastery tab: levels and "ข้อมูลยังน้อย"', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(400, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    var practiced = false;
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          masteryRepositoryProvider.overrideWithValue(_FakeMastery()),
        ],
        child: MaterialApp(
          home: Scaffold(body: MasteryPage(onPractice: () => practiced = true)),
        ),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('เข้าใจดี 1 ทักษะ'), findsOneWidget);
    expect(find.text('เข้าใจบางส่วน 1 ทักษะ'), findsOneWidget);
    expect(find.text('ยังไม่เข้าใจ 1 ทักษะ'), findsOneWidget);
    expect(find.text('ข้อมูลยังน้อย 1 ทักษะ'), findsOneWidget);
    expect(find.textContaining('ข้อมูลยังน้อย (1 ครั้ง)'), findsOneWidget);
    await tester.tap(find.text('ฝึกทักษะที่ควรทบทวน'));
    expect(practiced, isTrue);
  });

  testWidgets('placeholder server answer shows the empty state', (
    tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          masteryRepositoryProvider.overrideWithValue(
            _FakeMastery(available: false),
          ),
        ],
        child: const MaterialApp(home: Scaffold(body: MasteryPage())),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่มีข้อมูลทักษะ'), findsOneWidget);
  });

  test('repository paths and the placeholder meta', () async {
    final adapter = FakeHttpAdapter((o) async {
      return switch (o.uri.path) {
        '/api/v1/student/mastery' => jsonResponse(200, {
          'data': <Object>[],
          'meta': {'available': false},
        }),
        '/api/v1/classrooms/1/mastery' => jsonResponse(200, {
          'data': classroomMasteryJson(),
        }),
        _ => jsonResponse(200, {'data': studentRows()}),
      };
    });
    final repo = ApiMasteryRepository(fakeDio(adapter));
    expect((await repo.mine()).available, isFalse);
    expect((await repo.classroom(1)).skills, hasLength(2));
    expect(await repo.student(4003), hasLength(4));
    expect(adapter.requests.last.uri.path, '/api/v1/students/4003/mastery');
  });
}
