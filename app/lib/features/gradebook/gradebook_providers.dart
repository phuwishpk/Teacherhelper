import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:share_plus/share_plus.dart';

import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import 'gradebook_models.dart';
import 'gradebook_repository.dart';

final gradebookTemplatesProvider =
    FutureProvider.autoDispose<List<GradebookTemplate>>((ref) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(gradebookRepositoryProvider).templates();
    }, retry: apiRetry);

/// The settings of a course (also the category picker of the assignment
/// and exam forms).
final gradebookSettingsProvider = FutureProvider.autoDispose
    .family<GradebookSettings, int>((ref, courseId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(gradebookRepositoryProvider).settings(courseId);
    }, retry: apiRetry);

/// "ตัดเกรด": every own course with each classroom's grading status.
/// The page filters by year and semester itself, so one load serves them all.
final gradebookOverviewProvider =
    FutureProvider.autoDispose<List<GradebookOverviewCourse>>((ref) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(gradebookRepositoryProvider).overview();
    }, retry: apiRetry);

typedef GradebookKey = ({int courseId, int classroomId});

/// The live grid of one classroom in one course.
final gradebookGridProvider = FutureProvider.autoDispose
    .family<GradebookGrid, GradebookKey>((ref, key) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(gradebookRepositoryProvider)
          .grid(key.courseId, key.classroomId);
    }, retry: apiRetry);

/// Student: "เกรดของฉัน".
final myGradesProvider = FutureProvider.autoDispose<List<StudentGradeSummary>>((
  ref,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(gradebookRepositoryProvider).myGrades();
}, retry: apiRetry);

/// Student: one course's published grade and breakdown, in one classroom
/// of theirs or (null) the newest publication (DESIGN §24.26).
typedef MyCourseGradeQuery = ({int courseId, int? classroomId});

final myCourseGradeProvider = FutureProvider.autoDispose
    .family<StudentGradeDetail, MyCourseGradeQuery>((ref, q) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(gradebookRepositoryProvider)
          .myCourseGrade(q.courseId, classroomId: q.classroomId);
    }, retry: apiRetry);

/// Hands the exported CSV to the share sheet (LINE, Drive, e-mail …) with
/// the existing share_plus (§23.8). Behind a provider so tests can fake it.
class GradebookFileSharer {
  const GradebookFileSharer();

  Future<void> share(CsvExport file, {required String subject}) async {
    await SharePlus.instance.share(
      ShareParams(
        files: [
          XFile.fromData(file.bytes, mimeType: 'text/csv', name: file.fileName),
        ],
        fileNameOverrides: [file.fileName],
        subject: subject,
      ),
    );
  }
}

final gradebookFileSharerProvider = Provider<GradebookFileSharer>(
  (ref) => const GradebookFileSharer(),
);

/// Downloads the CSV of one classroom (§23.8) and hands it to the share
/// sheet: "ส่งออก CSV" of the gradebook and of the "ตัดเกรด" page.
Future<void> shareGradebookCsv(
  WidgetRef ref, {
  required int courseId,
  required int classroomId,
  required String courseCode,
  required String classroomName,
}) async {
  final file = await ref
      .read(gradebookRepositoryProvider)
      .exportCsv(courseId, classroomId);
  await ref
      .read(gradebookFileSharerProvider)
      .share(file, subject: 'สมุดคะแนน $courseCode $classroomName');
}

/// After a change to a course's gradebook: settings, grids and the
/// "ตัดเกรด" overview load again.
void invalidateGradebook(WidgetRef ref) {
  ref.invalidate(gradebookSettingsProvider);
  ref.invalidate(gradebookGridProvider);
  ref.invalidate(gradebookOverviewProvider);
}
