import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import 'classroom.dart';
import 'classrooms_repository.dart';

class ClassroomsNotifier extends AsyncNotifier<List<Classroom>> {
  @override
  Future<List<Classroom>> build() {
    watchSignedInUser(ref);
    return ref.watch(classroomsRepositoryProvider).list();
  }

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

  /// After linking or unlinking a Google Classroom course (DESIGN §18.6),
  /// so the screens update without waiting for a reload.
  void setGoogleLink(int id, ClassroomGoogleLink? link) {
    final list = state.value;
    if (list == null) return;
    state = AsyncData([
      for (final c in list) c.id == id ? c.withGoogleLink(link) : c,
    ]);
  }
}

final classroomsProvider =
    AsyncNotifierProvider.autoDispose<ClassroomsNotifier, List<Classroom>>(
      ClassroomsNotifier.new,
    );

/// A single classroom from the list cache, fetched on its own if missing.
final classroomProvider = FutureProvider.autoDispose.family<Classroom, int>((
  ref,
  id,
) async {
  watchSignedInUser(ref);
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
  Future<List<RosterStudent>> build() {
    watchSignedInUser(ref);
    return ref.watch(classroomsRepositoryProvider).roster(classroomId);
  }

  Future<void> refresh() async {
    ref.invalidateSelf();
    await future;
  }

  /// Returns the created rows with their one-time PINs for the teacher.
  Future<List<EnrolledStudent>> addStudents(List<NewStudent> students) async {
    final enrolled = await ref
        .read(classroomsRepositoryProvider)
        .addStudents(classroomId, students);
    await refresh();
    ref.invalidate(classroomsProvider);
    return enrolled;
  }
}

final rosterProvider = AsyncNotifierProvider.autoDispose
    .family<RosterNotifier, List<RosterStudent>, int>(RosterNotifier.new);
