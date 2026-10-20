import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:eduvision/features/assistant/assistant_panel.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// Students' results in "ผู้ช่วย Krucheck" (DESIGN §37.8).
class _Fake implements AssistantRepository {
  _Fake(this.statusValue);

  AssistantStatus statusValue;
  final scopes = <DataScope?>[];
  int acks = 0;

  @override
  Future<AssistantStatus> status() async => statusValue;
  @override
  Future<AssistantStatus> acknowledgeStudentData() async {
    acks++;
    return statusValue = const AssistantStatus(
      available: true,
      studentDataAcknowledged: true,
    );
  }

  @override
  Future<List<AssistantConversation>> conversations() async => const [];
  @override
  Future<AssistantReply> conversation(int id) => throw UnimplementedError();
  @override
  Future<void> delete(int id) async {}
  @override
  Future<AssistantReply> retry(int id) => throw UnimplementedError();
  @override
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  }) => throw UnimplementedError();

  @override
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
    List<int> documentIds = const [],
  }) async {
    scopes.add(scope);
    return AssistantReply(
      const AssistantConversation(id: 7, title: 'ถาม'),
      [
        AssistantMessage(id: 1, role: 'user', content: text),
        const AssistantMessage(
          id: 2,
          role: 'assistant',
          content: 'S1 ยังไม่ส่ง 2 งาน ส่วน S2 ได้ 45%',
        ),
      ],
      labels: const [
        StudentLabel(
          label: 'S1',
          studentId: 11,
          name: 'เด็กชายธนาธิป ฉัตรชัย',
          classroomId: 3,
        ),
        StudentLabel(
          label: 'S2',
          studentId: 12,
          name: 'เด็กหญิงปุณยวีร์ ทองคำ',
        ),
      ],
    );
  }
}

class _Classrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<Classroom>> list() async => const [
    Classroom(
      id: 3,
      name: 'ป.5/1',
      gradeLevel: 5,
      academicYear: 2569,
      classCode: 'ABC123',
    ),
  ];

  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(
      studentId: 11,
      studentNumber: 1,
      name: 'เด็กชายธนาธิป ฉัตรชัย',
    ),
  ];
}

class _Assignments extends Fake implements AssignmentsRepository {
  @override
  Future<List<Assignment>> list({int? classroomId}) async => const [];
}

void main() {
  late List<String> routes;

  Future<_Fake> pump(
    WidgetTester tester, {
    AssistantStatus status = const AssistantStatus(available: true),
  }) async {
    routes = [];
    final repo = _Fake(status);
    tester.view.physicalSize = const Size(820, 1200);
    tester.view.devicePixelRatio = 1;
    addTearDown(tester.view.reset);
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          assistantRepositoryProvider.overrideWithValue(repo),
          classroomsRepositoryProvider.overrideWithValue(_Classrooms()),
          assignmentsRepositoryProvider.overrideWithValue(_Assignments()),
        ],
        child: MaterialApp(
          theme: AppTheme.light(),
          home: Scaffold(
            body: AssistantPanel(onClose: () {}, onNavigate: routes.add),
          ),
        ),
      ),
    );
    await tester.pumpAndSettle();
    return repo;
  }

  Future<void> ask(WidgetTester tester, String text) async {
    await tester.enterText(find.byKey(const ValueKey('assistant_input')), text);
    await tester.tap(find.byKey(const ValueKey('assistant_send')));
    await tester.pumpAndSettle();
  }

  testWidgets('answers show names for labels and link to the student', (
    tester,
  ) async {
    await pump(tester);
    await ask(tester, 'ธนาธิปเป็นยังไง');

    expect(
      find.text(
        'เด็กชายธนาธิป ฉัตรชัย ยังไม่ส่ง 2 งาน ส่วน เด็กหญิงปุณยวีร์ ทองคำ ได้ 45%',
      ),
      findsOneWidget,
    );
    expect(find.text('ธนาธิปเป็นยังไง'), findsOneWidget);
    // Only a student with a classroom gets a link.
    expect(find.byKey(const ValueKey('assistant_student_S2')), findsNothing);
    await tester.tap(find.byKey(const ValueKey('assistant_student_S1')));
    expect(routes, ['/classrooms/3/students/11/mastery']);
  });

  testWidgets('the notice first, then a classroom goes with the question', (
    tester,
  ) async {
    final repo = await pump(tester);

    await tester.tap(find.byKey(const ValueKey('assistant_data')));
    await tester.pumpAndSettle();
    expect(find.text('ถามผู้ช่วยเรื่องข้อมูลนักเรียน'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('assistant_data_ack')));
    await tester.pumpAndSettle();
    expect(repo.acks, 1);

    await tester.tap(find.byKey(const ValueKey('assistant_data_classroom_3')));
    await tester.pumpAndSettle();
    expect(find.text('ข้อมูล: ห้อง ป.5/1'), findsOneWidget);

    await ask(tester, 'ห้องนี้เป็นยังไง');
    expect(repo.scopes.single?.toJson(), {
      'set': 'classroom_summary',
      'classroom_id': 3,
    });

    // A student from the roster; then removed.
    await tester.tap(find.byKey(const ValueKey('assistant_scope')));
    await tester.pumpAndSettle();
    await tester.tap(find.text('นักเรียน'));
    await tester.pumpAndSettle();
    await tester.tap(
      find.byKey(const ValueKey('assistant_data_student_classroom')),
    );
    await tester.pumpAndSettle();
    await tester.tap(find.text('ป.5/1').last);
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const ValueKey('assistant_data_student_11')));
    await tester.pumpAndSettle();
    expect(find.text('ข้อมูล: เด็กชายธนาธิป ฉัตรชัย'), findsOneWidget);
    await ask(tester, 'คนนี้อ่อนตรงไหน');
    expect(repo.scopes.last?.toJson(), {
      'set': 'student_history',
      'classroom_id': 3,
      'student_id': 11,
    });

    await tester.tap(find.byTooltip('เลิกใช้ข้อมูลนี้'));
    await tester.pumpAndSettle();
    await ask(tester, 'ทั่วไป');
    expect(repo.scopes.last, isNull);
  });

  testWidgets('no chip when the admin switched student data off', (
    tester,
  ) async {
    await pump(
      tester,
      status: const AssistantStatus(available: true, studentDataAllowed: false),
    );
    expect(find.byKey(const ValueKey('assistant_data')), findsNothing);
  });
}
