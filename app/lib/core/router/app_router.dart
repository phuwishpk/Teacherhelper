import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/assignments/answer_key_screen.dart';
import '../../features/assignments/assignment.dart';
import '../../features/assignments/assignment_detail_screen.dart';
import '../../features/assignments/assignment_form_screen.dart';
import '../../features/assignments/question.dart';
import '../../features/assignments/question_form_screen.dart';
import '../../features/assignments/rubric_screen.dart';
import '../../features/appeals/appeals_screen.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/register_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/auth/student_login_screen.dart';
import '../../features/auth/student_qr_scan_screen.dart';
import '../../features/classrooms/classroom.dart';
import '../../features/classrooms/classroom_detail_screen.dart';
import '../../features/classrooms/classroom_form_screen.dart';
import '../../features/classrooms/students_bulk_add_screen.dart';
import '../../features/dashboard/assignment_analytics_screen.dart';
import '../../features/google_classroom/classroom_import_screen.dart';
import '../../features/google_classroom/course_picker_screen.dart';
import '../../features/google_classroom/roster_matching_screen.dart';
import '../../features/google_classroom/submissions_screen.dart';
import '../../features/home/teacher_shell.dart';
import '../../features/mastery/classroom_mastery_screen.dart';
import '../../features/mastery/student_mastery_screen.dart';
import '../../features/practice/practice_attempt_screen.dart';
import '../../features/practice/practice_bank_screen.dart';
import '../../features/practice/practice_page.dart';
import '../../features/practice/skill_resources_screen.dart';
import '../../features/results/result_detail_screen.dart';
import '../../features/review/review_detail_screen.dart';
import '../../features/review/review_labels.dart';
import '../../features/review/review_queue_screen.dart';
import '../../features/scan/scan_screen.dart';
import '../../features/settings/settings_screen.dart';
import '../../features/student/student_shell.dart';
import '../../features/upload_queue/upload_queue_screen.dart';
import '../auth/session.dart';

abstract final class AppRoutes {
  static const splash = '/splash';
  static const login = '/login';
  static const register = '/register';
  static const studentLogin = '/student/login';
  static const studentQr = '/student/login/qr';

  /// Teacher shell.
  static const home = '/';

  /// Student shell.
  static const student = '/student';

  static const classroomNew = '/classrooms/new';
  static String classroom(int id) => '/classrooms/$id';
  static String classroomEdit(int id) => '/classrooms/$id/edit';
  static String studentsAdd(int id) => '/classrooms/$id/students/add';

  /// Google Classroom (DESIGN §18.7): course picker, student matching and
  /// the submissions of a posted assignment.
  static String classroomGoogleLink(int id) => '/classrooms/$id/google-link';

  /// Import a classroom from Google Classroom (DESIGN §19.2): course
  /// picker, then the preview of one course.
  static const classroomImportGoogle = '/classrooms/import-google';
  static String classroomImportPreview(String courseId) =>
      '$classroomImportGoogle/${Uri.encodeComponent(courseId)}';
  static String classroomGoogleRoster(int id) =>
      '/classrooms/$id/google-roster';
  static String googleSubmissions(int assignmentId) =>
      '/assignments/$assignmentId/google-submissions';

  static const assignmentNew = '/assignments/new';
  static String assignment(int id) => '/assignments/$id';
  static String assignmentEdit(int id) => '/assignments/$id/edit';
  static String questionNew(int assignmentId) =>
      '/assignments/$assignmentId/questions/new';
  static String questionEdit(int assignmentId, int questionId) =>
      '/assignments/$assignmentId/questions/$questionId/edit';

  /// The teacher's answer key: type, photo, file or AI draft, then approve
  /// (DESIGN §19.5).
  static String answerKey(int assignmentId) =>
      '/assignments/$assignmentId/answer-key';
  static String rubric(int assignmentId, int questionId) =>
      '/assignments/$assignmentId/questions/$questionId/rubric';

  static const scan = '/scan';
  static const uploadQueue = '/upload-queue';

  /// Teacher settings: Gemini API key (DESIGN §10.1), later Google (§18.7).
  static const settings = '/settings';

  /// Review queue of one assignment (§9.5, §13).
  static String review(int assignmentId) => '/assignments/$assignmentId/review';

  /// One response of the queue on a phone; [band] keeps prev/next in its tab.
  static String reviewResponse(
    int assignmentId,
    int responseId, {
    PriorityBand? band,
  }) =>
      '/assignments/$assignmentId/review/$responseId'
      '${band == null ? '' : '?band=${band.apiValue}'}';

  static const appeals = '/appeals';

  /// Item analysis of one assignment (DESIGN §9.6, §14.3).
  static String assignmentAnalytics(int id) => '/assignments/$id/analytics';

  /// Student x skill mastery heatmap of a classroom (§14.3).
  static String classroomMastery(int id) => '/classrooms/$id/mastery';

  /// One student's skills and weaknesses, seen by the teacher.
  static String studentMastery(int classroomId, int studentId) =>
      '/classrooms/$classroomId/students/$studentId/mastery';

  /// The school's practice bank (§14.1).
  static const practiceBank = '/practice-bank';

  /// Review links of a skill (`learning_resources`).
  static String skillResources(int skillId) => '/skills/$skillId/resources';

  /// Student: one practice item (§9.7).
  static String studentPractice(int itemId) => '/student/practice/$itemId';

  /// Student: one published submission (§9.7).
  static String studentResult(int submissionId) =>
      '/student/results/$submissionId';

  /// Routes a signed-in student may open (everything else sends them home).
  static bool isStudentArea(String location) =>
      location == student ||
      location.startsWith('$student/results/') ||
      location.startsWith('$student/practice/');

  static bool isPublic(String location) =>
      location == login ||
      location == register ||
      location == studentLogin ||
      location == studentQr;
}

int _id(GoRouterState state, String name) =>
    int.parse(state.pathParameters[name]!);

final routerProvider = Provider<GoRouter>((ref) {
  // Bump a ValueNotifier whenever the session changes so redirect() reruns.
  final refresh = ValueNotifier<int>(0);
  ref.onDispose(refresh.dispose);
  ref.listen(sessionProvider, (_, _) => refresh.value++);

  return GoRouter(
    initialLocation: AppRoutes.splash,
    refreshListenable: refresh,
    redirect: (context, state) {
      final session = ref.read(sessionProvider);
      final location = state.matchedLocation;
      final public = AppRoutes.isPublic(location);
      final inStudentArea = AppRoutes.isStudentArea(location);
      return switch (session) {
        SessionRestoring() =>
          location == AppRoutes.splash ? null : AppRoutes.splash,
        SignedOut() => public ? null : AppRoutes.login,
        SignedIn(:final user) when user.isStudent =>
          inStudentArea ? null : AppRoutes.student,
        SignedIn() =>
          (public || location == AppRoutes.splash || inStudentArea)
              ? AppRoutes.home
              : null,
      };
    },
    routes: [
      GoRoute(
        path: AppRoutes.splash,
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: AppRoutes.login,
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: AppRoutes.register,
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: AppRoutes.studentLogin,
        builder: (context, state) => const StudentLoginScreen(),
      ),
      GoRoute(
        path: AppRoutes.studentQr,
        builder: (context, state) => const StudentQrScanScreen(),
      ),
      GoRoute(
        path: AppRoutes.student,
        builder: (context, state) => const StudentShell(),
      ),
      GoRoute(
        path: '/student/results/:sid',
        builder: (context, state) =>
            ResultDetailScreen(submissionId: _id(state, 'sid')),
      ),
      GoRoute(
        path: '/student/practice/:itemId',
        builder: (context, state) => PracticeAttemptScreen(
          itemId: _id(state, 'itemId'),
          args: state.extra is PracticeAttemptArgs
              ? state.extra as PracticeAttemptArgs
              : null,
        ),
      ),
      GoRoute(
        path: AppRoutes.home,
        builder: (context, state) => const TeacherShell(),
      ),
      GoRoute(
        path: AppRoutes.classroomNew,
        builder: (context, state) => const ClassroomFormScreen(),
      ),
      // Before '/classrooms/:id', which would take "import-google" as an id.
      GoRoute(
        path: AppRoutes.classroomImportGoogle,
        builder: (context, state) => const GoogleCoursePickerScreen.forImport(),
        routes: [
          GoRoute(
            path: ':courseId',
            builder: (context, state) => ClassroomImportScreen(
              courseId: state.pathParameters['courseId']!,
            ),
          ),
        ],
      ),
      GoRoute(
        path: '/classrooms/:id',
        builder: (context, state) =>
            ClassroomDetailScreen(classroomId: _id(state, 'id')),
        routes: [
          GoRoute(
            path: 'edit',
            builder: (context, state) => ClassroomEditScreen(
              classroomId: _id(state, 'id'),
              initial: state.extra is Classroom
                  ? state.extra as Classroom
                  : null,
            ),
          ),
          GoRoute(
            path: 'students/add',
            builder: (context, state) =>
                StudentsBulkAddScreen(classroomId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'google-link',
            builder: (context, state) =>
                GoogleCoursePickerScreen(classroomId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'google-roster',
            builder: (context, state) =>
                GoogleRosterScreen(classroomId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'mastery',
            builder: (context, state) =>
                ClassroomMasteryScreen(classroomId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'students/:sid/mastery',
            builder: (context, state) => StudentMasteryScreen(
              classroomId: _id(state, 'id'),
              studentId: _id(state, 'sid'),
            ),
          ),
        ],
      ),
      GoRoute(
        path: AppRoutes.assignmentNew,
        builder: (context, state) => AssignmentFormScreen(
          initialClassroomId: int.tryParse(
            state.uri.queryParameters['classroom'] ?? '',
          ),
        ),
      ),
      GoRoute(
        path: '/assignments/:id',
        builder: (context, state) =>
            AssignmentDetailScreen(assignmentId: _id(state, 'id')),
        routes: [
          GoRoute(
            path: 'edit',
            builder: (context, state) => AssignmentEditScreen(
              assignmentId: _id(state, 'id'),
              initial: state.extra is Assignment
                  ? state.extra as Assignment
                  : null,
            ),
          ),
          GoRoute(
            path: 'questions/new',
            builder: (context, state) {
              final args = state.extra is QuestionFormArgs
                  ? state.extra as QuestionFormArgs
                  : null;
              return QuestionFormScreen(
                assignmentId: _id(state, 'id'),
                subjectId: args?.subjectId,
                gradeLevel: args?.gradeLevel,
                freeform: args?.freeform,
              );
            },
          ),
          GoRoute(
            path: 'questions/:qid/edit',
            builder: (context, state) {
              final args = state.extra is QuestionFormArgs
                  ? state.extra as QuestionFormArgs
                  : null;
              return QuestionEditScreen(
                assignmentId: _id(state, 'id'),
                questionId: _id(state, 'qid'),
                initial: args?.question,
                subjectId: args?.subjectId,
                gradeLevel: args?.gradeLevel,
                freeform: args?.freeform,
              );
            },
          ),
          GoRoute(
            path: 'answer-key',
            builder: (context, state) =>
                AnswerKeyScreen(assignmentId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'questions/:qid/rubric',
            builder: (context, state) => RubricScreen(
              assignmentId: _id(state, 'id'),
              questionId: _id(state, 'qid'),
            ),
          ),
          GoRoute(
            path: 'analytics',
            builder: (context, state) =>
                AssignmentAnalyticsScreen(assignmentId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'google-submissions',
            builder: (context, state) =>
                GoogleSubmissionsScreen(assignmentId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'review',
            builder: (context, state) =>
                ReviewQueueScreen(assignmentId: _id(state, 'id')),
            routes: [
              GoRoute(
                path: ':rid',
                builder: (context, state) => ReviewDetailScreen(
                  assignmentId: _id(state, 'id'),
                  responseId: _id(state, 'rid'),
                  band: PriorityBand.fromApi(state.uri.queryParameters['band']),
                ),
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: AppRoutes.scan,
        builder: (context, state) => const ScanScreen(),
      ),
      GoRoute(
        path: AppRoutes.uploadQueue,
        builder: (context, state) => const UploadQueueScreen(),
      ),
      GoRoute(
        path: AppRoutes.settings,
        builder: (context, state) => const SettingsScreen(),
      ),
      GoRoute(
        path: AppRoutes.appeals,
        builder: (context, state) => const AppealsScreen(),
      ),
      GoRoute(
        path: AppRoutes.practiceBank,
        builder: (context, state) => PracticeBankScreen(
          initialSkill: state.extra is Skill ? state.extra as Skill : null,
        ),
      ),
      GoRoute(
        path: '/skills/:id/resources',
        builder: (context, state) => SkillResourcesScreen(
          skillId: _id(state, 'id'),
          skill: state.extra is Skill ? state.extra as Skill : null,
        ),
      ),
    ],
  );
});
