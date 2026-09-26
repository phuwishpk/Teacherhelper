import 'dart:convert';

import 'package:eduvision/core/router/app_router.dart';
import 'package:eduvision/features/practice/practice_attempt_screen.dart';
import 'package:eduvision/features/practice/practice_models.dart';
import 'package:eduvision/features/practice/practice_page.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/fake_http_adapter.dart';
import 'practice_fixtures.dart';

Future<GoRouter> _pumpPractice(
  WidgetTester tester,
  FakeStudentPractice repo,
) async {
  tester.view.physicalSize = const Size(400, 900);
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
  final router = GoRouter(
    initialLocation: '/student',
    routes: [
      GoRoute(
        path: '/student',
        builder: (_, _) => const Scaffold(body: PracticePage()),
      ),
      GoRoute(
        path: '/student/practice/:itemId',
        builder: (context, state) => PracticeAttemptScreen(
          itemId: int.parse(state.pathParameters['itemId']!),
          args: state.extra as PracticeAttemptArgs?,
        ),
      ),
    ],
  );
  await tester.pumpWidget(
    ProviderScope(
      overrides: [studentPracticeRepositoryProvider.overrideWithValue(repo)],
      child: MaterialApp.router(routerConfig: router),
    ),
  );
  await tester.pumpAndSettle();
  return router;
}

void main() {
  testWidgets('recommendations by weak skill, then a numeric attempt with '
      'the explanation', (tester) async {
    final repo = FakeStudentPractice(
      results: {
        31: {
          'score_ratio': 0,
          'explanation': 'ตัวส่วนเท่ากัน บวกเฉพาะตัวเศษ ได้ 4/4 = 1',
          'mastery': {
            'skill': {'id': 7, 'code': 'ค 1.1 ป.4/2', 'name': 'บวกลบเศษส่วน'},
            'value': 0.27,
            'n_obs': 4,
          },
        },
      },
    );
    await _pumpPractice(tester, repo);

    // Grouped by skill, weakest first, with the level and the review link.
    expect(find.text('ค 1.1 ป.4/2'), findsOneWidget);
    expect(find.text('บวกลบเศษส่วน'), findsOneWidget);
    expect(find.text('ยังไม่เข้าใจ'), findsOneWidget);
    expect(find.text('ข้อมูลยังน้อย'), findsOneWidget, reason: 'n_obs 1');
    expect(find.text('คลิปเศษส่วน'), findsOneWidget);
    expect(find.text('3/4 + 1/4 = ?'), findsOneWidget);

    await tester.tap(find.text('3/4 + 1/4 = ?'));
    await tester.pumpAndSettle();
    expect(find.byType(PracticeAttemptScreen), findsOneWidget);
    expect(find.text('3/4 + 1/4 = ?'), findsOneWidget);

    // Empty answer is not sent.
    await tester.tap(find.byKey(const ValueKey('practice_submit')));
    await tester.pumpAndSettle();
    expect(find.text('พิมพ์คำตอบก่อน'), findsOneWidget);
    expect(repo.attempts, isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('practice_answer')),
      '4/8',
    );
    await tester.tap(find.byKey(const ValueKey('practice_submit')));
    await tester.pumpAndSettle();

    expect(repo.attempts, [(31, '4/8')]);
    expect(find.byKey(const ValueKey('practice_result')), findsOneWidget);
    expect(find.text('ยังไม่ถูก'), findsOneWidget);
    expect(
      find.text('ตัวส่วนเท่ากัน บวกเฉพาะตัวเศษ ได้ 4/4 = 1'),
      findsOneWidget,
    );
    expect(find.text('ทักษะนี้ตอนนี้ 27%'), findsOneWidget);
    // No second try of the same item: the next one of the skill instead.
    expect(find.byKey(const ValueKey('practice_submit')), findsNothing);

    await tester.tap(find.text('ข้อต่อไป'));
    await tester.pumpAndSettle();
    // The next item replaced this one (back still goes to the list).
    expect(find.text('ข้อใดเท่ากับ 1/2'), findsOneWidget);
    expect(find.text('3/4 + 1/4 = ?'), findsNothing);
    expect(find.byKey(const ValueKey('practice_option_A')), findsOneWidget);
  });

  testWidgets('mcq: pick an option, a right answer says so', (tester) async {
    final repo = FakeStudentPractice(
      results: {
        32: {'score_ratio': 1, 'explanation': '2/4 ตัดทอนได้ 1/2'},
      },
    );
    await _pumpPractice(tester, repo);
    await tester.tap(find.text('ข้อใดเท่ากับ 1/2'));
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('practice_submit')));
    await tester.pumpAndSettle();
    expect(find.text('เลือกคำตอบก่อน'), findsOneWidget);

    await tester.tap(find.byKey(const ValueKey('practice_option_A')));
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('practice_submit')));
    await tester.pumpAndSettle();

    expect(repo.attempts, [(32, 'A')]);
    expect(find.text('ถูกต้อง เก่งมาก'), findsOneWidget);
    expect(find.text('คำอธิบายเพิ่มเติม'), findsOneWidget);
    // Last item of the skill: back to the list.
    await tester.tap(find.text('กลับไปหน้าแบบฝึก'));
    await tester.pumpAndSettle();
    expect(find.byType(PracticePage), findsOneWidget);
  });

  testWidgets('a restored route finds the item in the recommendations', (
    tester,
  ) async {
    final repo = FakeStudentPractice();
    final router = await _pumpPractice(tester, repo);
    router.go(AppRoutes.studentPractice(41));
    await tester.pumpAndSettle();
    expect(find.text('สะกดคำว่า "กะ-เพรา"'), findsOneWidget);
    expect(find.widgetWithText(TextField, 'คำตอบ'), findsOneWidget);
  });

  testWidgets('no recommendations: an empty state', (tester) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          studentPracticeRepositoryProvider.overrideWithValue(_Empty()),
        ],
        child: const MaterialApp(home: Scaffold(body: PracticePage())),
      ),
    );
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่มีแบบฝึกแนะนำ'), findsOneWidget);
  });

  group('repository', () {
    test('GET /student/practice accepts a flat item list too', () async {
      final adapter = FakeHttpAdapter((o) async {
        if (o.method == 'POST') {
          return jsonResponse(201, {
            'data': {'score_ratio': 0.5, 'explanation': 'เกือบถูก'},
          });
        }
        return jsonResponse(200, {
          'data': [
            {
              'id': 31,
              'skill': {'id': 7, 'code': 'ค 1.1', 'name': 'เศษส่วน'},
              'answer_type': 'numeric',
              'prompt_text': '1/2 + 1/2',
            },
            {
              'id': 32,
              'skill': {'id': 7, 'code': 'ค 1.1', 'name': 'เศษส่วน'},
              'answer_type': 'mcq',
              'prompt_text': 'เลือก',
              'options': ['หนึ่ง', 'สอง'],
            },
          ],
        });
      });
      final repo = ApiStudentPracticeRepository(fakeDio(adapter));
      final recs = await repo.recommendations();
      expect(adapter.requests.single.uri.path, '/api/v1/student/practice');
      expect(recs, hasLength(1));
      expect(recs.single.items.map((i) => i.id), [31, 32]);
      expect(recs.single.items.last.options.map((o) => o.key), ['A', 'B']);

      final result = await repo.attempt(31, ' 1 ');
      final post = adapter.requests.last;
      expect(post.uri.path, '/api/v1/student/practice/31/attempts');
      final body = post.data is String
          ? jsonDecode(post.data as String)
          : post.data;
      expect(body, {'answer': ' 1 '});
      expect(result.partlyCorrect, isTrue);
      expect(result.explanation, 'เกือบถูก');
    });

    test('options come as maps, strings or a keyed object', () {
      expect(
        PracticeOption.listFromJson({'A': 'x', 'B': 'y'}).map((o) => o.key),
        ['A', 'B'],
      );
      expect(
        PracticeOption.listFromJson([
          {'label': 'ก', 'value': 'หนึ่ง'},
        ]).single.key,
        'ก',
      );
      expect(PracticeOption.listFromJson(null), isEmpty);
    });
  });
}

class _Empty implements StudentPracticeRepository {
  @override
  Future<List<PracticeRecommendation>> recommendations() async => const [];

  @override
  Future<PracticeAttemptResult> attempt(int itemId, String answer) =>
      throw UnimplementedError();
}
