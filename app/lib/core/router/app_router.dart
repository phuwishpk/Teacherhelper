import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/assignments/assignment.dart';
import '../../features/assignments/assignment_detail_screen.dart';
import '../../features/assignments/assignment_form_screen.dart';
import '../../features/assignments/question_form_screen.dart';
import '../../features/assignments/rubric_screen.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/register_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/auth/student_login_screen.dart';
import '../../features/auth/student_qr_scan_screen.dart';
import '../../features/classrooms/classroom.dart';
import '../../features/classrooms/classroom_detail_screen.dart';
import '../../features/classrooms/classroom_form_screen.dart';
import '../../features/classrooms/students_bulk_add_screen.dart';
import '../../features/home/teacher_shell.dart';
import '../../features/scan/scan_screen.dart';
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

  static const assignmentNew = '/assignments/new';
  static String assignment(int id) => '/assignments/$id';
  static String assignmentEdit(int id) => '/assignments/$id/edit';
  static String questionNew(int assignmentId) =>
      '/assignments/$assignmentId/questions/new';
  static String questionEdit(int assignmentId, int questionId) =>
      '/assignments/$assignmentId/questions/$questionId/edit';
  static String rubric(int assignmentId, int questionId) =>
      '/assignments/$assignmentId/questions/$questionId/rubric';

  static const scan = '/scan';
  static const uploadQueue = '/upload-queue';

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
      final inStudentArea = location == AppRoutes.student;
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
        path: AppRoutes.home,
        builder: (context, state) => const TeacherShell(),
      ),
      GoRoute(
        path: AppRoutes.classroomNew,
        builder: (context, state) => const ClassroomFormScreen(),
      ),
      GoRoute(
        path: '/classrooms/:id',
        builder: (context, state) =>
            ClassroomDetailScreen(classroomId: _id(state, 'id')),
        routes: [
          GoRoute(
            path: 'edit',
            builder: (context, state) =>
                ClassroomFormScreen(existing: state.extra as Classroom?),
          ),
          GoRoute(
            path: 'students/add',
            builder: (context, state) =>
                StudentsBulkAddScreen(classroomId: _id(state, 'id')),
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
            builder: (context, state) =>
                AssignmentFormScreen(existing: state.extra as Assignment?),
          ),
          GoRoute(
            path: 'questions/new',
            builder: (context, state) {
              final args = state.extra as QuestionFormArgs?;
              return QuestionFormScreen(
                assignmentId: _id(state, 'id'),
                subjectId: args?.subjectId,
                gradeLevel: args?.gradeLevel,
              );
            },
          ),
          GoRoute(
            path: 'questions/:qid/edit',
            builder: (context, state) {
              final args = state.extra as QuestionFormArgs?;
              return QuestionFormScreen(
                assignmentId: _id(state, 'id'),
                existing: args?.question,
                subjectId: args?.subjectId,
                gradeLevel: args?.gradeLevel,
              );
            },
          ),
          GoRoute(
            path: 'questions/:qid/rubric',
            builder: (context, state) => RubricScreen(
              assignmentId: _id(state, 'id'),
              questionId: _id(state, 'qid'),
            ),
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
    ],
  );
});
