import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/auth/session.dart';
import 'classroom.dart';
import 'classrooms_repository.dart';
import 'school_students.dart';

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

  /// A room created elsewhere (imported from Google Classroom, DESIGN
  /// §19.2), so the list and the detail screen show it without a reload.
  void addCreated(Classroom created) {
    final list = state.value;
    if (list == null) return;
    state = AsyncData([
      for (final c in list)
        if (c.id != created.id) c,
      created,
    ]);
  }

  /// `POST /classrooms/{id}/close` (DESIGN §24.6): the room leaves this list
  /// for "ห้องเก่า".
  Future<Classroom> closeRoom(int id) async {
    final closed = await ref.read(classroomsRepositoryProvider).close(id);
    _remove(id);
    ref.invalidate(closedClassroomsProvider);
    return closed;
  }

  /// `POST /classrooms/{id}/reopen`: back from "ห้องเก่า".
  Future<Classroom> reopenRoom(int id) async {
    final reopened = await ref.read(classroomsRepositoryProvider).reopen(id);
    addCreated(reopened);
    ref.invalidate(closedClassroomsProvider);
    return reopened;
  }

  /// `DELETE /classrooms/{id}`; throws 409 `classroom_has_data` as is.
  Future<void> deleteRoom(int id) async {
    await ref.read(classroomsRepositoryProvider).delete(id);
    _remove(id);
    ref.invalidate(closedClassroomsProvider);
  }

  void _remove(int id) {
    final list = state.value;
    if (list == null) return;
    state = AsyncData([
      for (final c in list)
        if (c.id != id) c,
    ]);
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

/// "ห้องเก่า" (DESIGN §24.6), loaded when the teacher opens the section.
final closedClassroomsProvider = FutureProvider.autoDispose<List<Classroom>>((
  ref,
) {
  watchSignedInUser(ref);
  return ref.watch(classroomsRepositoryProvider).listClosed();
});

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

  /// Returns the enrolled rows with the one-time PINs for the teacher.
  Future<List<EnrolledStudent>> addStudents(
    List<StudentEnrolment> students,
  ) async {
    final enrolled = await ref
        .read(classroomsRepositoryProvider)
        .addStudents(classroomId, students);
    await refresh();
    ref.invalidate(classroomsProvider);
    return enrolled;
  }

  /// "นำนักเรียนจากห้องเดิม" (DESIGN §24.6); the answer carries any new PINs.
  Future<StudentsCopyResult> copyFrom(
    int sourceClassroomId, {
    required List<int> studentIds,
    required CopyNumbering numbering,
    required bool newPins,
  }) async {
    final result = await ref
        .read(classroomsRepositoryProvider)
        .copyStudents(
          classroomId,
          sourceClassroomId: sourceClassroomId,
          studentIds: studentIds,
          numbering: numbering,
          newPins: newPins,
        );
    await refresh();
    ref.invalidate(classroomsProvider);
    return result;
  }
}

final rosterProvider = AsyncNotifierProvider.autoDispose
    .family<RosterNotifier, List<RosterStudent>, int>(RosterNotifier.new);

/// `GET /school-students?q=` (DESIGN §24.4); empty below 2 characters.
final schoolStudentSearchProvider = FutureProvider.autoDispose
    .family<List<SchoolStudent>, String>((ref, query) async {
      watchSignedInUser(ref);
      if (query.trim().length < 2) return const [];
      return ref
          .watch(classroomsRepositoryProvider)
          .searchSchoolStudents(query.trim());
    });

/// Pairs of accounts that may be one child (DESIGN §24.4 "บัญชีที่อาจซ้ำ").
final duplicateCandidatesProvider =
    FutureProvider.autoDispose<List<DuplicateCandidate>>((ref) {
      watchSignedInUser(ref);
      return ref.watch(classroomsRepositoryProvider).duplicateCandidates();
    });

/// The two sides of a merge, keyed by (keep id, merge id) (DESIGN §24.5).
final mergePreviewProvider = FutureProvider.autoDispose
    .family<MergePreview, (int, int)>((ref, pair) {
      watchSignedInUser(ref);
      return ref
          .watch(classroomsRepositoryProvider)
          .mergePreview(keepId: pair.$1, mergeId: pair.$2);
    });
