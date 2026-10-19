import 'package:eduvision/core/api/api_client.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/token_storage.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/my_indicators/my_indicators.dart';
import 'package:eduvision/features/my_indicators/my_indicators_card.dart';
import 'package:eduvision/features/my_indicators/my_indicators_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'fake_my_indicators.dart';

const _teacher = User(id: 7, name: 'สมศรี', role: 'teacher');

void main() {
  testWidgets(
    'a subject and grade adds all its indicators, which are then unticked or removed',
    (tester) async {
      tall(tester);
      final mine = FakeMyIndicators(const [fraction, decimal, schoolSkill]);
      final skills = FakeSkills();
      await pumpScreen(
        tester,
        const MyIndicatorsScreen(),
        overrides: [
          ...overrides(FakeCoursesRepository(), skills: skills),
          myIndicatorsRepositoryProvider.overrideWithValue(mine),
        ],
      );
      expect(find.textContaining('ยังไม่ได้เลือก'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('my_indicators_add_group')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('my_indicators_subject')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('คณิตศาสตร์').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('my_indicators_grade')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ป.5').last);
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('my_indicators_add_confirm')));
      await tester.pumpAndSettle();

      expect(skills.searches.last, {
        'subject': 1,
        'grade': 5,
        'q': null,
        'level': 'indicator,sub_indicator',
      });
      expect(
        mine.saves.single,
        [fraction.id, decimal.id, schoolSkill.id]..sort(),
      );
      expect(find.text('คณิตศาสตร์ ป.5'), findsOneWidget);
      expect(find.text('เลือกไว้ 3 จาก 3 ตัว'), findsOneWidget);

      // Open the group and untick the decimal indicator.
      await tester.tap(find.text('คณิตศาสตร์ ป.5'));
      await tester.pumpAndSettle();
      await tester.tap(find.widgetWithText(CheckboxListTile, decimal.code));
      await tester.pumpAndSettle();
      expect(mine.saves.last, [fraction.id, schoolSkill.id]..sort());
      expect(find.text('เลือกไว้ 2 จาก 3 ตัว'), findsOneWidget);

      // Remove the whole subject and grade.
      await tester.tap(find.text('เอาวิชาและชั้นนี้ออก'));
      await tester.pumpAndSettle();
      expect(mine.saves.last, isEmpty);
      expect(find.textContaining('ยังไม่ได้เลือก'), findsOneWidget);
    },
  );

  group('home card', () {
    Future<InMemoryTokenStorage> pumpCard(
      WidgetTester tester,
      FakeMyIndicators mine, {
      InMemoryTokenStorage? storage,
    }) async {
      final store = storage ?? InMemoryTokenStorage();
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            myIndicatorsRepositoryProvider.overrideWithValue(mine),
            tokenStorageProvider.overrideWithValue(store),
            currentUserProvider.overrideWith((ref) => _teacher),
          ],
          child: MaterialApp(
            home: Scaffold(body: MyIndicatorsCard(onOpen: () {})),
          ),
        ),
      );
      await tester.pumpAndSettle();
      return store;
    }

    testWidgets('asks a teacher without a set until "ไม่ใช่ตอนนี้"', (
      tester,
    ) async {
      final store = await pumpCard(tester, FakeMyIndicators(const [fraction]));
      expect(find.text('เลือกตัวชี้วัดที่คุณสอน'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('my_indicators_card_later')));
      await tester.pumpAndSettle();
      expect(find.text('เลือกตัวชี้วัดที่คุณสอน'), findsNothing);
      expect(store.myIndicatorsPromptClosed, {_teacher.id});

      // Remembered on this device for this teacher.
      await tester.pumpWidget(const SizedBox());
      await pumpCard(
        tester,
        FakeMyIndicators(const [fraction]),
        storage: store,
      );
      expect(find.text('เลือกตัวชี้วัดที่คุณสอน'), findsNothing);
    });

    testWidgets('stays away once the teacher keeps a set', (tester) async {
      await pumpCard(
        tester,
        FakeMyIndicators(const [fraction], initial: [fraction.id]),
      );
      expect(find.text('เลือกตัวชี้วัดที่คุณสอน'), findsNothing);
    });
  });

  for (final width in [390.0, 820.0, 1440.0]) {
    testWidgets('fits a ${width.toInt()} px window', (tester) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await pumpScreen(
        tester,
        const MyIndicatorsScreen(),
        overrides: [
          ...overrides(FakeCoursesRepository()),
          myIndicatorsRepositoryProvider.overrideWithValue(
            FakeMyIndicators(
              const [fraction, decimal, schoolSkill],
              initial: [fraction.id, schoolSkill.id],
            ),
          ),
        ],
      );
      await tester.tap(find.text('คณิตศาสตร์ ป.5'));
      await tester.pumpAndSettle();
      expect(tester.takeException(), isNull);
      expect(find.text('เลือกไว้ 2 จาก 3 ตัว'), findsOneWidget);
    });
  }
}
