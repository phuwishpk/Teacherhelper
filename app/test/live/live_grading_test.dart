import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/features/assignments/live_grading_progress.dart';
import 'package:eduvision/features/student/live_refresh.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import 'live_test_support.dart';

const _teacher = User(id: 12, name: 'ครูเอ', role: 'teacher');

Map<String, Object> _counts({
  int awaitingScan = 0,
  int grading = 0,
  int needsReview = 0,
  int reviewed = 0,
  int published = 0,
  int awaitingAiKey = 0,
}) => {
  'title': 'เศษส่วน',
  'total': awaitingScan + grading + needsReview + reviewed + published,
  'awaiting_scan': awaitingScan,
  'grading': grading,
  'needs_review': needsReview,
  'reviewed': reviewed,
  'published': published,
  'awaiting_ai_key': awaitingAiKey,
};

Future<ProviderContainer> _pumpCard(
  WidgetTester tester,
  LiveSession live, {
  User user = _teacher,
}) async {
  final container = ProviderContainer(
    overrides: [
      liveSessionProvider.overrideWithValue(live),
      currentUserProvider.overrideWithValue(user),
    ],
  );
  addTearDown(container.dispose);
  await tester.pumpWidget(
    UncontrolledProviderScope(
      container: container,
      child: const MaterialApp(
        home: Scaffold(body: LiveGradingProgressCard(assignmentId: 7)),
      ),
    ),
  );
  await tester.pumpAndSettle();
  return container;
}

void main() {
  late FakeFirebaseFirestore db;

  setUp(() => db = FakeFirebaseFirestore());

  testWidgets('the card moves on its own while the AI grades', (tester) async {
    final doc = db.doc('users/u12/assignments/7');
    await doc.set(_counts(awaitingScan: 1, grading: 3, needsReview: 1));
    await _pumpCard(tester, FakeLiveSession(db: db, uid: 'u12'));

    expect(find.byKey(const ValueKey('live_grading_progress')), findsOneWidget);
    expect(find.text('AI ตรวจแล้ว 1 จาก 4 คนที่ส่ง'), findsOneWidget);
    expect(
      tester
          .widget<LinearProgressIndicator>(
            find.byKey(const ValueKey('live_grading_bar')),
          )
          .value,
      0.25,
    );
    expect(find.text('AI กำลังตรวจ 3 · รอครูตรวจทาน 1'), findsOneWidget);
    expect(find.byKey(const ValueKey('live_grading_ai_key')), findsNothing);

    await doc.set(
      _counts(awaitingScan: 1, needsReview: 2, published: 2, awaitingAiKey: 3),
    );
    await tester.pumpAndSettle();

    expect(find.text('AI ตรวจแล้ว 4 จาก 4 คนที่ส่ง'), findsOneWidget);
    expect(find.text('รอครูตรวจทาน 2 · เผยแพร่ผลแล้ว 2'), findsOneWidget);
    expect(
      find.text('รอคีย์ Gemini 3 ข้อ ใส่คีย์ในหน้าตั้งค่าแล้ว AI จะตรวจต่อ'),
      findsOneWidget,
    );
  });

  testWidgets('no card without a document or before anything came in', (
    tester,
  ) async {
    await _pumpCard(tester, FakeLiveSession(db: db, uid: 'u12'));
    expect(find.byKey(const ValueKey('live_grading_progress')), findsNothing);

    await db.doc('users/u12/assignments/7').set(_counts(awaitingScan: 5));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('live_grading_progress')), findsNothing);
  });

  testWidgets('no card when the live layer is off', (tester) async {
    await db.doc('users/u12/assignments/7').set(_counts(grading: 2));
    await _pumpCard(tester, const DisabledLiveSession());
    expect(find.byKey(const ValueKey('live_grading_progress')), findsNothing);
  });

  testWidgets('no card while Firebase holds another account', (tester) async {
    await db.doc('users/u12/assignments/7').set(_counts(grading: 2));
    await _pumpCard(tester, FakeLiveSession(db: db, uid: 'u99'));
    expect(find.byKey(const ValueKey('live_grading_progress')), findsNothing);
  });

  for (final width in [390.0, 820.0, 1440.0]) {
    testWidgets('the card fits at ${width.toInt()} px with every line shown', (
      tester,
    ) async {
      tester.view.physicalSize = Size(width, 900);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await db
          .doc('users/u12/assignments/7')
          .set(
            _counts(
              grading: 12,
              needsReview: 10,
              reviewed: 9,
              published: 8,
              awaitingAiKey: 120,
            ),
          );
      await _pumpCard(tester, FakeLiveSession(db: db, uid: 'u12'));
      expect(tester.takeException(), isNull);
      expect(find.byKey(const ValueKey('live_grading_ai_key')), findsOneWidget);
    });
  }

  test('GradingProgress handles missing fields and an empty hand-in', () {
    final p = GradingProgress.fromMap({'total': 2, 'awaiting_scan': 2});
    expect(p.handedIn, 0);
    expect(p.fraction, 0);
    expect(p.aiDone, 0);
  });

  test('change ticks start after the first snapshot', () async {
    final container = ProviderContainer(
      overrides: [
        liveSessionProvider.overrideWithValue(
          FakeLiveSession(db: db, uid: 'u12'),
        ),
        currentUserProvider.overrideWithValue(_teacher),
      ],
    );
    addTearDown(container.dispose);
    await db.doc('users/u12/submissions/1').set({'status': 'grading'});
    final ticks = <int>[];
    container.listen(
      liveSubmissionTicksProvider,
      (_, next) => next.whenData(ticks.add),
      fireImmediately: true,
    );
    await pumpEventQueue();
    expect(ticks, isEmpty, reason: 'the existing documents are not a change');

    await db.doc('users/u12/submissions/1').set({'status': 'published'});
    await pumpEventQueue();
    await db.doc('users/u12/submissions/2').set({'status': 'grading'});
    await pumpEventQueue();
    expect(ticks, [1, 2]);
    // Another user's documents never tick.
    await db.doc('users/u99/submissions/3').set({'status': 'grading'});
    await pumpEventQueue();
    expect(ticks, [1, 2]);
  });

  test('no ticks while the live layer is off', () async {
    final container = ProviderContainer(
      overrides: [currentUserProvider.overrideWithValue(_teacher)],
    );
    addTearDown(container.dispose);
    final ticks = <int>[];
    container.listen(
      liveAssignmentTicksProvider,
      (_, next) => next.whenData(ticks.add),
    );
    await pumpEventQueue();
    expect(ticks, isEmpty);
  });
}
