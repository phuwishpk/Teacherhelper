import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/features/appeals/appeals_screen.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/home/dashboard_page.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/review/review_detail_screen.dart';
import 'package:eduvision/features/review/review_queue_screen.dart';
import 'package:eduvision/features/review/review_repository.dart';
import 'package:eduvision/features/settings/ai_key.dart';
import 'package:eduvision/features/settings/settings_screen.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/pump_screen.dart';
import 'review_fixtures.dart';

class _OneAssignment extends Fake implements AssignmentsRepository {
  @override
  Future<Assignment> get(int id) async => Assignment(
    id: id,
    classroomId: 1,
    subjectId: 1,
    title: 'บวกลบเศษส่วนและจำนวนคละ',
  );
}

class _NoClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [];
}

class _NoAssignments extends Fake implements AssignmentsRepository {
  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [];
}

/// A small Android phone (360 dp wide): no RenderFlex overflow anywhere.
void main() {
  void small(WidgetTester tester) {
    tester.view.physicalSize = const Size(360, 3000);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
  }

  testWidgets('review detail fits a 360 dp phone', (tester) async {
    small(tester);
    final repo = FakeReviewRepository(
      rows: [queueRow(id: 11), queueRow(id: 12, position: 2)],
    );
    await pumpScreen(
      tester,
      const ReviewDetailScreen(assignmentId: 5, responseId: 11),
      overrides: [
        reviewRepositoryProvider.overrideWithValue(repo),
        cropLoaderProvider.overrideWithValue(NoCropLoader()),
      ],
    );
    await tester.tap(find.byTooltip('ลดคะแนน'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
    expect(find.text('เหตุผลที่แก้คะแนน (จำเป็น)'), findsOneWidget);
  });

  testWidgets('review queue and key settings fit a 360 dp phone', (
    tester,
  ) async {
    small(tester);
    await pumpScreen(
      tester,
      const ReviewQueueScreen(assignmentId: 5),
      overrides: [
        reviewRepositoryProvider.overrideWithValue(
          FakeReviewRepository(
            rows: [
              queueRow(
                id: 11,
                state: 'manual',
                manualReason: 'ai_key_missing',
                suspicious: true,
              ),
            ],
            meta: {'missing_ai_key_count': 1},
          ),
        ),
        assignmentsRepositoryProvider.overrideWithValue(_OneAssignment()),
        aiKeyRepositoryProvider.overrideWithValue(
          FakeAiKeyRepository(const AiKeyStatus(configured: false)),
        ),
      ],
    );
    expect(tester.takeException(), isNull);
    await unmountScreen(tester);

    await pumpScreen(
      tester,
      const SettingsScreen(),
      overrides: [
        aiKeyRepositoryProvider.overrideWithValue(
          FakeAiKeyRepository(
            AiKeyStatus(
              configured: true,
              keyLast4: '1234',
              lastVerifiedAt: DateTime.utc(2026, 10, 1),
            ),
          ),
        ),
      ],
    );
    expect(tester.takeException(), isNull);
  });

  testWidgets('home key card and the appeal dialog fit a 360 dp phone', (
    tester,
  ) async {
    small(tester);
    await pumpScreen(
      tester,
      Scaffold(
        body: DashboardPage(
          user: const User(id: 1, name: 'สมศรี ใจดีมากที่สุด', role: 'teacher'),
          onNavigate: (_) {},
        ),
      ),
      overrides: [
        aiKeyRepositoryProvider.overrideWithValue(
          FakeAiKeyRepository(
            const AiKeyStatus(
              configured: true,
              keyLast4: '1234',
              serverKeyAvailable: true,
            ),
          ),
        ),
        classroomsRepositoryProvider.overrideWithValue(_NoClassrooms()),
        assignmentsRepositoryProvider.overrideWithValue(_NoAssignments()),
      ],
    );
    expect(tester.takeException(), isNull);
    await unmountScreen(tester);

    final repo = FakeReviewRepository()
      ..appealRows = [
        {
          'id': 3,
          'response_id': 11,
          'status': 'open',
          'student': {'id': 1, 'name': 'ด.ญ. สมหญิง', 'student_number': 12},
          'assignment': {'id': 5, 'title': 'บวกลบเศษส่วนและจำนวนคละ'},
          'question': {'position': 2, 'max_points': 3},
          'final_score': 1.5,
        },
      ];
    await pumpScreen(
      tester,
      const AppealsScreen(),
      overrides: [reviewRepositoryProvider.overrideWithValue(repo)],
    );
    await tester.tap(find.text('ตอบคำขอ'));
    await tester.pumpAndSettle();
    expect(tester.takeException(), isNull);
  });
}
