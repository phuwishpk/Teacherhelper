import 'dart:convert';

import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/features/results/result_detail_screen.dart';
import 'package:eduvision/features/results/results_repository.dart';
import 'package:eduvision/features/results/student_result.dart';
import 'package:eduvision/features/review/review_models.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';

Map<String, dynamic> _detail({
  Map<String, dynamic>? appealOnQ2,
  bool overridden = false,
}) => {
  'submission_id': 70,
  'total_overridden': overridden,
  'assignment': {
    'id': 5,
    'title': 'บวกเลข',
    'subject': {'name': 'คณิตศาสตร์'},
  },
  'total_score': 3.5,
  'max_score': 5,
  'published_at': '2026-10-02T03:00:00Z',
  'responses': [
    {
      'id': 12,
      'question': {
        'position': 2,
        'type': 'show_work',
        'prompt_text': '3x + 5 = 20',
        'max_points': 3,
      },
      'final_score': 1.5,
      'final_understanding': 'partial',
      'final_error_types': ['calculation'],
      'explanation': 'ตั้งสมการถูกแล้ว แต่บรรทัดที่ 3 หาร 15 ด้วย 3 ผิด',
      'next_step': 'ฝึกหารจำนวนเต็มสองหลัก',
      'has_crop': true,
      'appeal': appealOnQ2,
      'can_appeal': appealOnQ2 == null,
    },
    {
      'id': 11,
      'question': {
        'position': 1,
        'type': 'short',
        'prompt_text': '100 + 25 = ?',
        'max_points': 2,
      },
      'final_score': 2,
      'final_understanding': 'good',
      'final_error_types': <String>[],
      'explanation': 'ถูกต้อง เยี่ยมมาก',
      'has_crop': true,
      'appeal': {
        'id': 2,
        'status': 'rejected',
        'teacher_note': 'คำตอบถูกและได้เต็มอยู่แล้ว',
      },
      'can_appeal': false,
    },
  ],
};

class _FakeResults extends Fake implements ResultsRepository {
  _FakeResults({this.overridden = false});

  final bool overridden;
  Map<String, dynamic>? appealOnQ2;
  final appeals = <(int, String?)>[];

  @override
  Future<StudentResultDetail> detail(int submissionId) async =>
      StudentResultDetail.fromJson(
        _detail(appealOnQ2: appealOnQ2, overridden: overridden),
      );

  @override
  Future<Appeal> appeal(int responseId, {String? reason}) async {
    appeals.add((responseId, reason));
    appealOnQ2 = {'id': 9, 'status': 'open', 'reason': reason};
    return Appeal.fromJson(appealOnQ2!);
  }
}

void main() {
  testWidgets('student sees each question and can appeal once', (tester) async {
    tester.view.physicalSize = const Size(800, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final results = _FakeResults();
    await pumpScreen(
      tester,
      const ResultDetailScreen(submissionId: 70),
      overrides: [
        resultsRepositoryProvider.overrideWithValue(results),
        cropLoaderProvider.overrideWithValue(NoCropLoader()),
      ],
    );

    expect(find.text('บวกเลข'), findsWidgets);
    expect(find.text('3.5/5'), findsOneWidget);
    expect(find.text(totalOverriddenNote), findsNothing);
    // Sorted by question position.
    final q1 = tester.getTopLeft(find.text('ข้อ 1')).dy;
    final q2 = tester.getTopLeft(find.text('ข้อ 2')).dy;
    expect(q1, lessThan(q2));
    expect(find.text('2/2 คะแนน'), findsOneWidget);
    expect(find.text('1.5/3 คะแนน'), findsOneWidget);
    expect(find.text('เข้าใจดี'), findsOneWidget);
    expect(find.text('เข้าใจบางส่วน'), findsOneWidget);
    expect(find.text('จุดที่ควรระวัง: คำนวณพลาด'), findsOneWidget);
    expect(
      find.text('ตั้งสมการถูกแล้ว แต่บรรทัดที่ 3 หาร 15 ด้วย 3 ผิด'),
      findsOneWidget,
    );
    expect(find.text('ขั้นต่อไป: ฝึกหารจำนวนเต็มสองหลัก'), findsOneWidget);
    expect(find.text('ไม่มีภาพของข้อนี้'), findsNWidgets(2));
    // Q1 already had its one appeal answered.
    expect(find.text('คำขอให้ครูตรวจใหม่: ครูยืนยันผลเดิม'), findsOneWidget);
    expect(find.text('ครู: คำตอบถูกและได้เต็มอยู่แล้ว'), findsOneWidget);
    expect(find.text('ขอให้ครูตรวจใหม่'), findsOneWidget, reason: 'Q2 only');

    await tester.tap(find.text('ขอให้ครูตรวจใหม่'));
    await tester.pumpAndSettle();
    expect(find.text('ขอให้ครูตรวจข้อ 2 ใหม่?'), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('appeal_reason')),
      'หนูหารได้ 5 ค่ะ',
    );
    await tester.tap(find.text('ส่งคำขอ'));
    await tester.pumpAndSettle();

    expect(results.appeals, [(12, 'หนูหารได้ 5 ค่ะ')]);
    expect(find.text('คำขอให้ครูตรวจใหม่: รอครูตรวจ'), findsOneWidget);
    expect(find.text('ขอให้ครูตรวจใหม่'), findsNothing);
  });

  testWidgets('a total taken from Classroom carries a note (§19.3)', (
    tester,
  ) async {
    await pumpScreen(
      tester,
      const ResultDetailScreen(submissionId: 70),
      overrides: [
        resultsRepositoryProvider.overrideWithValue(
          _FakeResults(overridden: true),
        ),
        cropLoaderProvider.overrideWithValue(NoCropLoader()),
      ],
    );

    expect(find.text('3.5/5'), findsOneWidget);
    expect(find.text(totalOverriddenNote), findsOneWidget);
  });

  test('summary parses total_overridden', () {
    expect(StudentResult.fromJson(_detail()).totalOverridden, isFalse);
    expect(
      StudentResultDetail.fromJson(
        _detail(overridden: true),
      ).summary.totalOverridden,
      isTrue,
    );
    final noMax = _detail(overridden: true)..remove('max_score');
    final d = StudentResultDetail.fromJson(noMax);
    expect(d.summary.maxScore, 5, reason: 'summed from the questions');
    expect(d.summary.totalOverridden, isTrue);
  });

  test('repository: detail path and appeal body', () async {
    final adapter = FakeHttpAdapter((options) async {
      if (options.method == 'POST') {
        return jsonResponse(201, {
          'data': {'id': 9, 'response_id': 12, 'status': 'open'},
        });
      }
      return jsonResponse(200, {'data': _detail()});
    });
    final repo = ApiResultsRepository(fakeDio(adapter));

    final d = await repo.detail(70);
    expect(adapter.requests.first.uri.path, '/api/v1/student/results/70');
    expect(d.summary.title, 'บวกเลข');
    expect(d.answers.map((a) => a.position), [1, 2]);
    expect(d.answers.first.canAppeal, isFalse);
    expect(d.answers.last.canAppeal, isTrue);

    await repo.appeal(12, reason: 'ช่วยดูอีกที');
    await repo.appeal(12);
    final posts = adapter.requests.skip(1).toList();
    expect(posts.first.uri.path, '/api/v1/student/responses/12/appeal');
    Object? body(int i) => posts[i].data is String
        ? jsonDecode(posts[i].data as String)
        : posts[i].data;
    expect(body(0), {'reason': 'ช่วยดูอีกที'});
    expect(body(1), <String, dynamic>{}, reason: 'reason is optional');
  });
}
