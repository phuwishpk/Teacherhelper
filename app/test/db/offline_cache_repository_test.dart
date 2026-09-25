import 'package:drift/native.dart';
import 'package:eduvision/core/db/app_database.dart';
import 'package:eduvision/features/assignments/assignment.dart';
import 'package:eduvision/features/assignments/assignments_repository.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/scan/offline_cache_repository.dart';
import 'package:flutter_test/flutter_test.dart';

class _FakeClassrooms extends Fake implements ClassroomsRepository {
  @override
  Future<List<RosterStudent>> roster(int id) async => const [
    RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
    RosterStudent(studentId: 4568, studentNumber: 3, name: 'ด.ช. สมชาย'),
  ];
}

class _FakeAssignments extends Fake implements AssignmentsRepository {
  final layoutCalls = <int>[];

  @override
  Future<List<Assignment>> list({int? classroomId}) async => [
    const Assignment(
      id: 123,
      classroomId: 1,
      subjectId: 1,
      title: 'เศษส่วน',
      currentLayoutVersion: 2,
    ),
    const Assignment(
      id: 124,
      classroomId: 1,
      subjectId: 1,
      title: 'ยังไม่มี layout',
    ),
  ];

  @override
  Future<List<LayoutVersion>> layouts(int assignmentId, {int? version}) async {
    layoutCalls.add(assignmentId);
    return [
      LayoutVersion(
        version: 1,
        pages: [
          {'assignment_id': assignmentId, 'version': 1, 'page': 1},
        ],
      ),
      LayoutVersion(
        version: 2,
        pages: [
          {'assignment_id': assignmentId, 'version': 2, 'page': 1},
          {'assignment_id': assignmentId, 'version': 2, 'page': 2},
        ],
      ),
    ];
  }
}

void main() {
  late AppDatabase db;
  late OfflineCacheRepository repo;
  late _FakeAssignments assignments;

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    assignments = _FakeAssignments();
    repo = OfflineCacheRepository(
      db,
      classrooms: _FakeClassrooms(),
      assignments: assignments,
    );
  });

  tearDown(() => db.close());

  test('prepareClassroom caches roster and every layout version', () async {
    final summary = await repo.prepareClassroom(1);

    expect(summary.students, 2);
    expect(summary.assignments, 2);
    expect(summary.layoutPages, 3);
    expect(assignments.layoutCalls, [
      123,
    ], reason: 'skip assignments without layout');

    final roster = await repo.cachedRoster(1);
    expect(roster.map((s) => s.studentNumber), [3, 12]);
    expect((await repo.findStudent(4567))?.name, 'ด.ญ. สมหญิง');
    expect(await repo.findStudent(1), isNull);

    expect(await repo.hasLayout(assignmentId: 123, version: 1), isTrue);
    expect(await repo.hasLayout(assignmentId: 123, version: 3), isFalse);
    final page2 = await repo.layoutPage(assignmentId: 123, version: 2, page: 2);
    expect(page2?['page'], 2);
    expect(await repo.cachedLayoutCount(), 3);
  });

  test('findStudent copes with a student cached in two classrooms', () async {
    // Last year's room (id 1) and this year's room (id 2) both list 4567.
    await repo.replaceRoster(1, const [
      RosterStudent(studentId: 4567, studentNumber: 12, name: 'ด.ญ. สมหญิง'),
    ]);
    await repo.replaceRoster(2, const [
      RosterStudent(studentId: 4567, studentNumber: 3, name: 'ด.ญ. สมหญิง'),
      RosterStudent(studentId: 4568, studentNumber: 4, name: 'ด.ช. สมชาย'),
    ]);

    final any = await repo.findStudent(4567);
    expect(any, isNotNull);
    expect(any!.name, 'ด.ญ. สมหญิง');
    expect(any.studentNumber, 3, reason: 'newest classroom wins by default');

    expect((await repo.findStudent(4567, classroomId: 1))?.studentNumber, 12);
    expect((await repo.findStudent(4567, classroomId: 2))?.studentNumber, 3);
    expect(
      (await repo.findStudent(4567, classroomId: 99))?.studentNumber,
      3,
      reason: 'unknown classroom falls back to any row',
    );
    expect(await repo.findStudent(1, classroomId: 2), isNull);
  });

  test('re-preparing replaces the roster instead of duplicating it', () async {
    await repo.prepareClassroom(1);
    await repo.prepareClassroom(1);
    expect((await repo.cachedRoster(1)).length, 2);
    expect(await repo.cachedLayoutCount(), 3);
  });
}
