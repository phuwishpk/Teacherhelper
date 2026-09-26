import 'package:eduvision/features/assignments/question.dart';
import 'package:eduvision/features/practice/practice_models.dart';
import 'package:eduvision/features/practice/practice_repository.dart';
import 'package:eduvision/features/practice/skill_resources_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';

class _Resources extends Fake implements PracticeBankRepository {
  final rows = <LearningResource>[
    const LearningResource(id: 1, title: 'คลิปเศษส่วน', url: 'https://x.org/a'),
  ];
  final added = <(int, String, String)>[];

  @override
  Future<List<LearningResource>> resources(int skillId) async => [...rows];

  @override
  Future<LearningResource> addResource(
    int skillId, {
    required String title,
    required String url,
  }) async {
    added.add((skillId, title, url));
    final r = LearningResource(id: 2, title: title, url: url);
    rows.add(r);
    return r;
  }
}

void main() {
  testWidgets('lists links and adds one with a checked URL', (tester) async {
    final repo = _Resources();
    await pumpScreen(
      tester,
      const SkillResourcesScreen(
        skillId: 7,
        skill: Skill(id: 7, code: 'ค 1.1', name: 'เศษส่วน'),
      ),
      overrides: [practiceBankRepositoryProvider.overrideWithValue(repo)],
    );
    expect(find.text('ลิงก์ทบทวน ค 1.1'), findsOneWidget);
    expect(find.text('คลิปเศษส่วน'), findsOneWidget);

    await tester.tap(find.text('เพิ่มลิงก์'));
    await tester.pumpAndSettle();
    await tester.enterText(
      find.byKey(const ValueKey('resource_title')),
      'แบบฝึกออนไลน์',
    );
    await tester.enterText(
      find.byKey(const ValueKey('resource_url')),
      'javascript:alert(1)',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'เพิ่ม'));
    await tester.pumpAndSettle();
    expect(find.text('ต้องเป็นลิงก์ http:// หรือ https://'), findsOneWidget);
    expect(repo.added, isEmpty);

    await tester.enterText(
      find.byKey(const ValueKey('resource_url')),
      'https://y.org/b',
    );
    await tester.tap(find.widgetWithText(FilledButton, 'เพิ่ม'));
    await tester.pumpAndSettle();
    expect(repo.added, [(7, 'แบบฝึกออนไลน์', 'https://y.org/b')]);
    expect(find.text('แบบฝึกออนไลน์'), findsOneWidget);
  });
}
