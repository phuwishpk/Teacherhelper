import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/analysis/analysis_models.dart';
import 'package:eduvision/features/analysis/analysis_repository.dart';
import 'package:eduvision/features/analysis/classroom_analyses_screen.dart';
import 'package:eduvision/features/analysis/my_analysis_section.dart';
import 'package:eduvision/features/analysis/student_analysis_screen.dart';
import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:eduvision/features/practice/skill_practice_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/fake_api_server.dart';
import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import '../practice/practice_fixtures.dart';
import 'analysis_fakes.dart';

void _tallPhone(WidgetTester tester) {
  tester.view.physicalSize = const Size(400, 2400);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

void main() {
  group('models (DESIGN §20.5)', () {
    test('teacher payload: items, texts, next steps and flags', () {
      final a = StudentAnalysis.fromJson(teacherAnalysisJson());
      expect(a.id, 90);
      expect(a.status, AnalysisStatus.drafted);
      expect(a.strengths.single.skill.code, 'ค 1.1 ป.5/1');
      expect(a.strengths.single.value, 0.91);
      expect(a.areas.map((i) => i.tooLittle), [false, true]);
      expect(a.nextSteps.single.id, 2);
      expect(a.hasText, isTrue);
      expect(a.shared, isFalse);
      expect(a.awaitingApproval, isTrue);
      expect(a.showsOlderShared, isFalse);
      expect(a.generatedAt, DateTime.utc(2026, 9, 29, 18, 10));

      final older = StudentAnalysis.fromJson(
        teacherAnalysisJson(sharedText: 'ข้อความเก่า', approvedBy: 3),
      );
      expect(older.shared, isTrue);
      expect(older.showsOlderShared, isTrue);
      expect(older.approvedBy, 3);

      final empty = StudentAnalysis.fromJson(
        teacherAnalysisJson(
          status: 'computed',
          teacherText: null,
          studentText: null,
          nextSteps: const [],
        ),
      );
      expect(empty.hasText, isFalse);
      expect(empty.awaitingApproval, isFalse);
      expect(AnalysisStatus.fromApi('nope'), AnalysisStatus.computed);
    });

    test('classroom list and the student rows', () {
      final c = ClassroomAnalyses.fromJson(classroomAnalysesJson());
      expect(c.classroomId, 7);
      expect(c.autoShare, isFalse);
      expect(c.students.map((s) => s.studentNumber), [1, 2, 3, 4]);
      expect(c.students[2].analysis, isNull);
      expect(c.students.first.analysis!.stale, isTrue);
      expect(c.awaitingCount, 1);
      expect(c.withAutoShare(true).autoShare, isTrue);

      final mine = myAnalysesJson().map(MyAnalysis.fromJson).toList();
      expect(mine.first.classroomName, 'ป.5/1');
      expect(mine.first.nextSteps.single.code, 'ค 1.1 ป.4/2');
      expect(mine.last.sharedAt, isNull);
    });
  });

  group('ApiAnalysisRepository', () {
    test('paths and bodies of every call', () async {
      final adapter = FakeHttpAdapter((o) async {
        final path = FakeApiServer.apiPath(o.uri);
        return switch (path) {
          '/classrooms/7/analyses' => jsonResponse(200, {
            'data': classroomAnalysesJson(),
          }),
          '/students/57/analysis' => jsonResponse(200, {'data': null}),
          '/student/analysis' => jsonResponse(200, {'data': myAnalysesJson()}),
          '/classrooms/7' => jsonResponse(200, {'data': <String, Object>{}}),
          _ => jsonResponse(200, {'data': teacherAnalysisJson()}),
        };
      });
      final repo = ApiAnalysisRepository(fakeDio(adapter));
      RecordedRequest last() => RecordedRequest(adapter.requests.last, 200);

      expect((await repo.classroom(7)).students, hasLength(4));
      expect(last().path, '/classrooms/7/analyses');

      expect(await repo.student(57, 7), isNull);
      expect(last().options.uri.queryParameters, {'classroom_id': '7'});
      expect((await repo.student(55, 7))!.id, 90);

      await repo.runNow(55, 7);
      expect(last().method, 'POST');
      expect(last().path, '/students/55/analysis/run');
      expect(last().jsonBody, {'classroom_id': 7});

      await repo.edit(90, studentText: 'ใหม่');
      expect(last().method, 'PATCH');
      expect(last().path, '/analyses/90');
      expect(last().jsonBody, {'student_text': 'ใหม่'});

      await repo.approve(90);
      expect(last().method, 'POST');
      expect(last().path, '/analyses/90/approve');

      await repo.setAutoShare(7, true);
      expect(last().method, 'PATCH');
      expect(last().path, '/classrooms/7');
      expect(last().jsonBody, {'auto_share_analysis': true});

      expect(await repo.mine(), hasLength(2));
      expect(last().path, '/student/analysis');
    });
  });

  group('teacher: classroom list', () {
    testWidgets('statuses, awaiting count, auto-share and a student', (
      tester,
    ) async {
      _tallPhone(tester);
      final repo = FakeAnalysisRepository();
      await pumpScreen(
        tester,
        const ClassroomAnalysesScreen(classroomId: 7),
        overrides: [
          analysisRepositoryProvider.overrideWithValue(repo),
          classroomsRepositoryProvider.overrideWithValue(
            FakeAnalysisClassrooms(),
          ),
        ],
        extraRoutes: [
          GoRoute(
            path: '/classrooms/:id/students/:sid/analysis',
            builder: (_, s) => Text(
              'analysis-${s.pathParameters['id']}-${s.pathParameters['sid']}',
            ),
          ),
        ],
      );
      expect(find.text('วิเคราะห์รายคน · ป.5/1'), findsOneWidget);
      expect(find.byKey(const ValueKey('awaiting_count')), findsOneWidget);
      expect(find.text('รออนุมัติ'), findsOneWidget);
      expect(find.text('แชร์แล้ว'), findsOneWidget);
      expect(find.text('ยังไม่มีคะแนน'), findsOneWidget);
      expect(find.text('รอรอบกลางคืน'), findsOneWidget);
      expect(find.textContaining('ควรพัฒนา: ค 1.2 ป.5/1'), findsOneWidget);
      expect(find.textContaining('คะแนนเปลี่ยนหลังเขียนข้อความ'), findsOne);

      await tester.tap(find.byKey(const ValueKey('auto_share_switch')));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('autoShare:7:true'));
      final tile = tester.widget<SwitchListTile>(
        find.byKey(const ValueKey('auto_share_switch')),
      );
      expect(tile.value, isTrue);
      expect(find.textContaining('เปิดแชร์อัตโนมัติแล้ว'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('analysis_student_55')));
      await tester.pumpAndSettle();
      expect(find.text('analysis-7-55'), findsOneWidget);
    });
  });

  group('teacher: one student', () {
    Future<FakeAnalysisRepository> pump(
      WidgetTester tester, {
      FakeAnalysisRepository? repo,
    }) async {
      _tallPhone(tester);
      final r = repo ?? FakeAnalysisRepository();
      await pumpScreen(
        tester,
        const StudentAnalysisScreen(classroomId: 7, studentId: 55),
        overrides: [
          analysisRepositoryProvider.overrideWithValue(r),
          classroomsRepositoryProvider.overrideWithValue(
            FakeAnalysisClassrooms(),
          ),
        ],
        extraRoutes: [
          GoRoute(
            path: AppRoutes.settings,
            builder: (_, _) => const Text('settings-page'),
          ),
        ],
      );
      return r;
    }

    testWidgets('texts, strengths, areas and approve', (tester) async {
      final repo = await pump(tester);
      expect(find.text('1. ด.ญ. มะลิ'), findsOneWidget);
      expect(repo.calls, contains('student:55:7'));
      expect(find.text('เก่งเรื่องเศษส่วน ควรฝึกทศนิยมเพิ่ม'), findsOneWidget);
      expect(
        find.text('หนูทำเศษส่วนได้ดีมาก ลองฝึกทศนิยมอีกนิดนะ'),
        findsOneWidget,
      );
      expect(find.text('นักเรียนยังไม่เห็น'), findsOneWidget);
      expect(find.textContaining('(รอบกลางคืน)'), findsOneWidget);
      expect(find.text('จุดเด่น'), findsOneWidget);
      expect(find.text('จุดที่ควรพัฒนา'), findsOneWidget);
      expect(find.text('ข้อมูลยังน้อย'), findsOneWidget);
      expect(find.text('ขั้นต่อไป (มีแบบฝึก)'), findsOneWidget);
      expect(find.byKey(const ValueKey('analysis_stale')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('approve_analysis')));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('approve:90'));
      expect(find.text('นักเรียนเห็นแล้ว'), findsOneWidget);
      expect(find.byKey(const ValueKey('approve_analysis')), findsNothing);
      expect(find.text('แชร์แล้ว'), findsOneWidget);
    });

    testWidgets('analyze now writes new texts', (tester) async {
      final repo = await pump(
        tester,
        repo: FakeAnalysisRepository(
          analysis: teacherAnalysisJson(
            status: 'computed',
            teacherText: null,
            studentText: null,
            nextSteps: const [],
          ),
        ),
      );
      expect(find.textContaining('ยังไม่มีข้อความ รอบกลางคืน'), findsOneWidget);
      expect(find.text('เขียนเอง'), findsOneWidget);
      expect(find.byKey(const ValueKey('approve_analysis')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('run_analysis')));
      await tester.pumpAndSettle();
      expect(repo.calls, contains('run:55:7'));
      expect(find.text('ข้อความใหม่สำหรับครู'), findsOneWidget);
      expect(find.text('ข้อความใหม่ให้กำลังใจ'), findsOneWidget);
      expect(find.textContaining('(วิเคราะห์ตอนนี้)'), findsOneWidget);
      expect(find.textContaining('AI เขียนข้อความแล้ว'), findsOneWidget);
    });

    testWidgets('analyze now without a key points to settings', (tester) async {
      final repo = FakeAnalysisRepository()
        ..runError = apiError(422, 'ai_key_missing');
      await pump(tester, repo: repo);
      await tester.tap(find.byKey(const ValueKey('run_analysis')));
      await tester.pumpAndSettle();
      expect(find.textContaining('ยังไม่ได้ใส่ Gemini API key'), findsOne);
      await tester.tap(find.text('ไปตั้งค่า'));
      await tester.pumpAndSettle();
      expect(find.text('settings-page'), findsOneWidget);
    });

    testWidgets('analyze now: throttled and no data', (tester) async {
      final repo = FakeAnalysisRepository()
        ..runError = apiError(429, 'too_many_requests');
      await pump(tester, repo: repo);
      await tester.tap(find.byKey(const ValueKey('run_analysis')));
      await tester.pumpAndSettle();
      expect(find.textContaining('กดวิเคราะห์บ่อยเกินไป'), findsOneWidget);

      repo.runError = apiError(
        422,
        'analysis_no_data',
        'ยังไม่มีตัวชี้วัดที่ประเมินแล้วในห้องนี้',
      );
      await tester.tap(find.byKey(const ValueKey('run_analysis')));
      await tester.pumpAndSettle();
      expect(
        find.text('ยังไม่มีตัวชี้วัดที่ประเมินแล้วในห้องนี้'),
        findsOneWidget,
      );
    });

    testWidgets('edit sends only the changed text; save and share', (
      tester,
    ) async {
      final repo = await pump(tester);
      await tester.tap(find.byKey(const ValueKey('edit_analysis')));
      await tester.pumpAndSettle();
      expect(find.text('แก้ข้อความวิเคราะห์'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('student_text_field')),
        'เธออ่อนเรื่องทศนิยม',
      );
      await tester.pump();
      expect(find.textContaining('ลองเลี่ยงคำว่า "อ่อน"'), findsOneWidget);
      await tester.enterText(
        find.byKey(const ValueKey('student_text_field')),
        'ลองฝึกทศนิยมอีกนิด เธอทำได้แน่นอน',
      );
      await tester.tap(find.byKey(const ValueKey('save_analysis')));
      await tester.pumpAndSettle();
      expect(repo.edits.single, (
        teacherText: null,
        studentText: 'ลองฝึกทศนิยมอีกนิด เธอทำได้แน่นอน',
      ));
      expect(repo.calls.where((c) => c.startsWith('approve')), isEmpty);
      expect(find.text('บันทึกแล้ว'), findsOneWidget);
      expect(find.text('ลองฝึกทศนิยมอีกนิด เธอทำได้แน่นอน'), findsOneWidget);

      // Save and share: the teacher text changes, then approve.
      await tester.tap(find.byKey(const ValueKey('edit_analysis')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('teacher_text_field')),
        'ทศนิยมยังต้องฝึก',
      );
      await tester.tap(find.byKey(const ValueKey('save_and_share_analysis')));
      await tester.pumpAndSettle();
      expect(repo.edits.last, (
        teacherText: 'ทศนิยมยังต้องฝึก',
        studentText: null,
      ));
      expect(repo.calls.last, 'approve:90');
      expect(find.text('บันทึกและแชร์ให้นักเรียนแล้ว'), findsOneWidget);
      expect(find.text('นักเรียนเห็นแล้ว'), findsOneWidget);
    });

    testWidgets('an emptied text is refused; cancel sends nothing', (
      tester,
    ) async {
      final repo = await pump(tester);
      await tester.tap(find.byKey(const ValueKey('edit_analysis')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('teacher_text_field')),
        '  ',
      );
      await tester.tap(find.byKey(const ValueKey('save_analysis')));
      await tester.pumpAndSettle();
      expect(find.text('กรอกข้อความสำหรับครู'), findsOneWidget);
      await tester.tap(find.byTooltip('ยกเลิก'));
      await tester.pumpAndSettle();
      expect(repo.edits, isEmpty);
      expect(find.text('แก้ข้อความวิเคราะห์'), findsNothing);
    });

    testWidgets('stale texts and an older shared text', (tester) async {
      await pump(
        tester,
        repo: FakeAnalysisRepository(
          analysis: teacherAnalysisJson(
            stale: true,
            sharedText: 'ข้อความเก่าที่แชร์ไว้',
            approvedBy: 3,
          ),
        ),
      );
      expect(find.byKey(const ValueKey('analysis_stale')), findsOneWidget);
      expect(
        find.text('นักเรียนยังเห็นข้อความที่แชร์ไว้ก่อนหน้า'),
        findsOneWidget,
      );
      await tester.tap(find.text('นักเรียนยังเห็นข้อความที่แชร์ไว้ก่อนหน้า'));
      await tester.pumpAndSettle();
      expect(find.text('ข้อความเก่าที่แชร์ไว้'), findsOneWidget);
    });

    testWidgets('nothing assessed yet', (tester) async {
      await pump(tester, repo: FakeAnalysisRepository(noData: true));
      expect(find.text('ยังไม่มีข้อมูลให้วิเคราะห์'), findsOneWidget);
      expect(find.byKey(const ValueKey('run_analysis')), findsNothing);
    });
  });

  group('student', () {
    testWidgets('shared texts only, and a next step opens its practice', (
      tester,
    ) async {
      _tallPhone(tester);
      final repo = FakeAnalysisRepository();
      await pumpScreen(
        tester,
        const Scaffold(body: SingleChildScrollView(child: MyAnalysisSection())),
        overrides: [
          analysisRepositoryProvider.overrideWithValue(repo),
          studentPracticeRepositoryProvider.overrideWithValue(
            FakeStudentPractice(),
          ),
        ],
        extraRoutes: [
          GoRoute(
            path: '/student/practice/skills/:skillId',
            builder: (_, s) => SkillPracticeScreen(
              skillId: int.parse(s.pathParameters['skillId']!),
              skill: s.extra is Skill ? s.extra as Skill : null,
            ),
          ),
          GoRoute(
            path: '/student/practice/:itemId',
            builder: (_, s) => Text('item-${s.pathParameters['itemId']}'),
          ),
        ],
      );
      expect(repo.calls, ['mine']);
      expect(find.text('ข้อความจากครู · ป.5/1'), findsOneWidget);
      expect(find.text('ข้อความจากครู · ชุมนุมคณิต'), findsOneWidget);
      expect(
        find.text('หนูทำเศษส่วนได้ดีมาก ลองฝึกทศนิยมอีกนิดนะ'),
        findsOneWidget,
      );
      // Never the teacher's version, strengths or a class figure.
      expect(find.textContaining('ฉบับครู'), findsNothing);
      expect(find.textContaining('จุดเด่น'), findsNothing);
      expect(find.textContaining('เฉลี่ย'), findsNothing);
      expect(find.text('ลองฝึกต่อ'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('next_step_7_7')));
      await tester.pumpAndSettle();
      expect(find.text('ฝึก ค 1.1 ป.4/2'), findsOneWidget);
      expect(find.text('3/4 + 1/4 = ?'), findsOneWidget);
      await tester.tap(find.text('3/4 + 1/4 = ?'));
      await tester.pumpAndSettle();
      expect(find.text('item-31'), findsOneWidget);
    });

    testWidgets('practice of an indicator with nothing to do now', (
      tester,
    ) async {
      await pumpScreen(
        tester,
        const SkillPracticeScreen(
          skillId: 99,
          skill: Skill(id: 99, code: 'ค 9.9', name: 'อื่น ๆ'),
        ),
        overrides: [
          studentPracticeRepositoryProvider.overrideWithValue(
            FakeStudentPractice(),
          ),
        ],
      );
      expect(find.text('ฝึก ค 9.9'), findsOneWidget);
      expect(find.text('ตอนนี้ยังไม่มีข้อฝึกของตัวชี้วัดนี้'), findsOneWidget);
    });

    testWidgets('no shared text: nothing shown', (tester) async {
      await pumpScreen(
        tester,
        const Scaffold(body: MyAnalysisSection()),
        overrides: [
          analysisRepositoryProvider.overrideWithValue(_NoAnalyses()),
        ],
      );
      expect(find.textContaining('ข้อความจากครู'), findsNothing);
    });
  });

  test('routes', () {
    expect(AppRoutes.classroomAnalyses(7), '/classrooms/7/analyses');
    expect(
      AppRoutes.studentAnalysis(7, 55),
      '/classrooms/7/students/55/analysis',
    );
    expect(AppRoutes.studentSkillPractice(3), '/student/practice/skills/3');
    expect(AppRoutes.isStudentArea(AppRoutes.studentSkillPractice(3)), isTrue);
    expect(AppRoutes.isStudentArea(AppRoutes.studentAnalysis(7, 55)), isFalse);
  });
}

class _NoAnalyses extends FakeAnalysisRepository {
  @override
  Future<List<MyAnalysis>> mine() async => const [];
}
