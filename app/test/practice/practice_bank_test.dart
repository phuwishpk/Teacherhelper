import 'dart:convert';

import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/practice/practice_bank_screen.dart';
import 'package:eduvision/features/practice/practice_models.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:eduvision/features/practice/skill_resources_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import 'practice_fixtures.dart';

void main() {
  testWidgets('a draft shows its answer and is approved from the list', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(400, 1400);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final bank = FakePracticeBank([
      bankItemJson(id: 31),
      bankItemJson(id: 32, type: 'mcq', status: 'approved'),
    ]);
    await pumpScreen(
      tester,
      const PracticeBankScreen(),
      overrides: [practiceBankRepositoryProvider.overrideWithValue(bank)],
    );
    expect(tester.takeException(), isNull);

    // Drafts tab first.
    expect(bank.listCalls.first, (null, PracticeItemStatus.draft));
    expect(find.text('3/4 + 1/4 = ?'), findsOneWidget);
    expect(find.text('คำตอบ: 1 / 4/4'), findsOneWidget);
    expect(find.textContaining('คำอธิบาย: ตัวส่วนเท่ากัน'), findsOneWidget);
    expect(find.text('ข้อใดเท่ากับ 1/2'), findsNothing);

    await tester.tap(find.widgetWithText(FilledButton, 'อนุมัติ'));
    await tester.pumpAndSettle();
    expect(bank.patches.single.$1, 31);
    expect(bank.patches.single.$2, {'status': 'approved'});
    expect(find.text('ไม่มีข้อที่รออนุมัติ'), findsOneWidget);

    await tester.tap(find.text('อนุมัติแล้ว'));
    await tester.pumpAndSettle();
    expect(find.text('3/4 + 1/4 = ?'), findsOneWidget);
    expect(find.text('ข้อใดเท่ากับ 1/2'), findsOneWidget);
    expect(find.text('คำตอบ: A'), findsOneWidget);
  });

  testWidgets('editing a draft sends the answer key and approves it', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(600, 1600);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    final bank = FakePracticeBank([bankItemJson(id: 31)]);
    await pumpScreen(
      tester,
      const PracticeBankScreen(),
      overrides: [practiceBankRepositoryProvider.overrideWithValue(bank)],
    );
    await tester.tap(find.text('แก้ไข'));
    await tester.pumpAndSettle();

    expect(find.text('แก้ไขข้อฝึก (ตัวเลข)'), findsOneWidget);
    await tester.enterText(
      find.byKey(const ValueKey('editor_prompt')),
      '1/4 + 2/4 = ?',
    );
    await tester.enterText(
      find.widgetWithText(TextFormField, 'ค่าที่ถูก'),
      '0.75',
    );
    await tester.enterText(
      find.byKey(const ValueKey('editor_accepted')),
      '3/4\n0.75',
    );
    await tester.tap(find.text('บันทึกและอนุมัติ'));
    await tester.pumpAndSettle();

    final (id, body) = bank.patches.single;
    expect(id, 31);
    expect(body['status'], 'approved');
    expect(body['prompt_text'], '1/4 + 2/4 = ?');
    expect(body['answer_key'], {
      'accepted': ['3/4', '0.75'],
      'numeric': {'value': 0.75, 'abs_tol': 0.0},
    });
    expect(body.containsKey('options'), isFalse);
    expect(find.text('บันทึกแล้ว'), findsOneWidget);
  });

  testWidgets('generate asks for a skill first, then a count', (tester) async {
    final bank = FakePracticeBank([]);
    await pumpScreen(
      tester,
      const PracticeBankScreen(
        initialSkill: Skill(id: 7, code: 'ค 1.1 ป.4/2', name: 'บวกลบเศษส่วน'),
      ),
      overrides: [practiceBankRepositoryProvider.overrideWithValue(bank)],
    );
    expect(bank.listCalls.first, (7, PracticeItemStatus.draft));
    expect(find.text('ลิงก์ทบทวน'), findsOneWidget);

    await tester.tap(find.text('สร้างข้อใหม่ด้วย AI'));
    await tester.pumpAndSettle();
    expect(find.text('สร้างข้อฝึกใหม่ของ ค 1.1 ป.4/2'), findsOneWidget);
    await tester.tap(find.text('สร้าง 5 ข้อ'));
    await tester.pumpAndSettle();
    expect(bank.generated, [(7, 5)]);
    expect(find.textContaining('ส่งคำขอแล้ว'), findsOneWidget);
  });

  test('isWebUrl accepts only http(s) with a host', () {
    expect(isWebUrl('https://www.youtube.com/watch?v=x'), isTrue);
    expect(isWebUrl(' http://example.org '), isTrue);
    expect(isWebUrl('javascript:alert(1)'), isFalse);
    expect(isWebUrl('example.org'), isFalse);
    expect(isWebUrl('https://'), isFalse);
  });

  test('repository: filters, generate, patch and resources', () async {
    final adapter = FakeHttpAdapter((o) async {
      switch ((o.method, o.uri.path)) {
        case ('GET', '/api/v1/practice-items'):
          return jsonResponse(200, {
            'data': [bankItemJson()],
            'meta': {'next_cursor': null},
          });
        case ('POST', '/api/v1/skills/7/practice-items/generate'):
          return jsonResponse(202, {
            'data': {'status': 'queued'},
          });
        case ('PATCH', '/api/v1/practice-items/31'):
          return jsonResponse(200, {'data': bankItemJson(status: 'retired')});
        case ('GET', '/api/v1/skills/7/resources'):
          return jsonResponse(200, {
            'data': [
              {'id': 1, 'skill_id': 7, 'title': 'คลิป', 'url': 'https://x.org'},
            ],
          });
        case ('POST', '/api/v1/skills/7/resources'):
          return jsonResponse(201, {
            'data': {
              'id': 2,
              'skill_id': 7,
              'title': 'ใหม่',
              'url': 'https://y.org',
            },
          });
      }
      return jsonResponse(404, {'message': 'nope'});
    });
    final repo = ApiPracticeBankRepository(fakeDio(adapter));
    Object? body(int i) {
      final d = adapter.requests[i].data;
      return d is String ? jsonDecode(d) : d;
    }

    final items = await repo.list(skillId: 7, status: PracticeItemStatus.draft);
    expect(items.single.acceptedAnswers, ['1', '4/4']);
    expect(adapter.requests[0].uri.queryParameters, {
      'skill': '7',
      'status': 'draft',
    });

    await repo.generate(7, count: 3);
    expect(body(1), {'count': 3});

    final retired = await repo.update(
      31,
      const PracticeItemPatch(status: PracticeItemStatus.retired),
    );
    expect(body(2), {'status': 'retired'});
    expect(retired.status, PracticeItemStatus.retired);

    expect((await repo.resources(7)).single.title, 'คลิป');
    final added = await repo.addResource(
      7,
      title: 'ใหม่',
      url: 'https://y.org',
    );
    expect(body(4), {'title': 'ใหม่', 'url': 'https://y.org'});
    expect(added.id, 2);
  });
}
