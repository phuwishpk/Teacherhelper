import 'dart:convert';

import 'package:drift/drift.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/db/app_database.dart';
import '../../core/db/database_provider.dart';
import '../assignments/assignments_repository.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_repository.dart';

class OfflinePrepSummary {
  const OfflinePrepSummary({
    required this.students,
    required this.assignments,
    required this.layoutPages,
  });

  final int students;
  final int assignments;
  final int layoutPages;
}

/// "เตรียมสแกนออฟไลน์" (DESIGN §6.3): pulls the roster and every layout
/// version of a classroom's assignments into drift so the scan screen can
/// crop and label pages without a network.
class OfflineCacheRepository {
  OfflineCacheRepository(
    this._db, {
    required this._classrooms,
    required this._assignments,
    DateTime Function()? clock,
  }) : _clock = clock ?? DateTime.now;

  final AppDatabase _db;
  final ClassroomsRepository _classrooms;
  final AssignmentsRepository _assignments;
  final DateTime Function() _clock;

  Future<OfflinePrepSummary> prepareClassroom(int classroomId) async {
    final roster = await _classrooms.roster(classroomId);
    await replaceRoster(classroomId, roster);

    final assignments = await _assignments.list(classroomId: classroomId);
    var pages = 0;
    for (final a in assignments) {
      if (a.currentLayoutVersion == null) continue;
      for (final layout in await _assignments.layouts(a.id)) {
        pages += await cacheLayout(a.id, layout.version, layout.pages);
      }
    }
    return OfflinePrepSummary(
      students: roster.length,
      assignments: assignments.length,
      layoutPages: pages,
    );
  }

  Future<void> replaceRoster(
    int classroomId,
    List<RosterStudent> roster,
  ) async {
    await _db.transaction(() async {
      await (_db.delete(
        _db.cachedRosters,
      )..where((t) => t.classroomId.equals(classroomId))).go();
      await _db.batch((b) {
        b.insertAll(_db.cachedRosters, [
          for (final s in roster)
            CachedRostersCompanion.insert(
              classroomId: classroomId,
              studentId: s.studentId,
              studentNumber: s.studentNumber,
              name: s.name,
            ),
        ]);
      });
    });
  }

  /// Stores one page row per entry of [pages]; returns how many were stored.
  Future<int> cacheLayout(
    int assignmentId,
    int version,
    List<Map<String, dynamic>> pages,
  ) async {
    final now = _clock();
    await _db.batch((b) {
      for (var i = 0; i < pages.length; i++) {
        final page = (pages[i]['page'] as num?)?.toInt() ?? i + 1;
        b.insert(
          _db.cachedLayouts,
          CachedLayoutsCompanion.insert(
            assignmentId: assignmentId,
            version: version,
            page: page,
            json: jsonEncode(pages[i]),
            cachedAt: now,
          ),
          mode: InsertMode.insertOrReplace,
        );
      }
    });
    return pages.length;
  }

  Future<Map<String, dynamic>?> layoutPage({
    required int assignmentId,
    required int version,
    required int page,
  }) async {
    final row =
        await (_db.select(_db.cachedLayouts)..where(
              (t) =>
                  t.assignmentId.equals(assignmentId) &
                  t.version.equals(version) &
                  t.page.equals(page),
            ))
            .getSingleOrNull();
    if (row == null) return null;
    return (jsonDecode(row.json) as Map).cast<String, dynamic>();
  }

  Future<bool> hasLayout({
    required int assignmentId,
    required int version,
  }) async {
    final row =
        await (_db.select(_db.cachedLayouts)
              ..where(
                (t) =>
                    t.assignmentId.equals(assignmentId) &
                    t.version.equals(version),
              )
              ..limit(1))
            .getSingleOrNull();
    return row != null;
  }

  Future<List<RosterStudent>> cachedRoster(int classroomId) async {
    final rows =
        await (_db.select(_db.cachedRosters)
              ..where((t) => t.classroomId.equals(classroomId))
              ..orderBy([(t) => OrderingTerm.asc(t.studentNumber)]))
            .get();
    return [
      for (final r in rows)
        RosterStudent(
          studentId: r.studentId,
          studentNumber: r.studentNumber,
          name: r.name,
        ),
    ];
  }

  /// Name lookup for a scanned QR (the QR carries only the student id).
  Future<RosterStudent?> findStudent(int studentId) async {
    final row = await (_db.select(
      _db.cachedRosters,
    )..where((t) => t.studentId.equals(studentId))).getSingleOrNull();
    if (row == null) return null;
    return RosterStudent(
      studentId: row.studentId,
      studentNumber: row.studentNumber,
      name: row.name,
    );
  }

  Future<int> cachedLayoutCount() async {
    final count = _db.cachedLayouts.assignmentId.count();
    final q = _db.selectOnly(_db.cachedLayouts)..addColumns([count]);
    return (await q.getSingle()).read(count) ?? 0;
  }
}

final offlineCacheRepositoryProvider = Provider<OfflineCacheRepository>(
  (ref) => OfflineCacheRepository(
    ref.watch(appDatabaseProvider),
    classrooms: ref.watch(classroomsRepositoryProvider),
    assignments: ref.watch(assignmentsRepositoryProvider),
  ),
);
