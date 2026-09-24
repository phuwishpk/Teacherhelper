import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'classroom.dart';
import 'classrooms_repository.dart';

class ClassroomsNotifier extends AsyncNotifier<List<Classroom>> {
  @override
  Future<List<Classroom>> build() =>
      ref.watch(classroomsRepositoryProvider).list();

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  Future<Classroom> create({
    required String name,
    required int gradeLevel,
    required int academicYear,
  }) async {
    final created = await ref
        .read(classroomsRepositoryProvider)
        .create(name: name, gradeLevel: gradeLevel, academicYear: academicYear);
    state = AsyncData([...state.value ?? const [], created]);
    return created;
  }

  Future<Classroom> edit(
    int id, {
    String? name,
    int? gradeLevel,
    int? academicYear,
  }) async {
    final updated = await ref
        .read(classroomsRepositoryProvider)
        .update(
          id,
          name: name,
          gradeLevel: gradeLevel,
          academicYear: academicYear,
        );
    state = AsyncData([
      for (final c in state.value ?? const <Classroom>[])
        c.id == id ? updated : c,
    ]);
    return updated;
  }
}

final classroomsProvider =
    AsyncNotifierProvider<ClassroomsNotifier, List<Classroom>>(
      ClassroomsNotifier.new,
    );

/// A single classroom from the list cache, fetched on its own if missing.
final classroomProvider = FutureProvider.family<Classroom, int>((
  ref,
  id,
) async {
  final list = await ref.watch(classroomsProvider.future);
  for (final c in list) {
    if (c.id == id) return c;
  }
  return ref.watch(classroomsRepositoryProvider).get(id);
});

class RosterNotifier extends AsyncNotifier<List<RosterStudent>> {
  RosterNotifier(this.classroomId);

  final int classroomId;

  @override
  Future<List<RosterStudent>> build() =>
      ref.watch(classroomsRepositoryProvider).roster(classroomId);

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  Future<void> addStudents(List<NewStudent> students) async {
    await ref
        .read(classroomsRepositoryProvider)
        .addStudents(classroomId, students);
    await refresh();
    ref.invalidate(classroomsProvider);
  }
}

final rosterProvider =
    AsyncNotifierProvider.family<RosterNotifier, List<RosterStudent>, int>(
      RosterNotifier.new,
    );
