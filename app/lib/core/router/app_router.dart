import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/assignments/answer_key_screen.dart';
import '../../features/assignments/assignment.dart';
import '../../features/assignments/assignment_detail_screen.dart';
import '../../features/assignments/assignment_form_screen.dart';
import '../../features/assignments/indicator_mapping_screen.dart';
import '../../features/assignments/question.dart';
import '../../features/assignments/question_form_screen.dart';
import '../../features/assignments/rubric_screen.dart';
import '../../features/admin/admin_home_screen.dart';
import '../../features/analysis/classroom_analyses_screen.dart';
import '../../features/analysis/student_analysis_screen.dart';
import '../../features/appeals/appeals_screen.dart';
import '../../features/auth/login_screen.dart';
import '../../features/auth/register_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/auth/student_qr_scan_screen.dart';
import '../../features/charts/course_charts_screen.dart';
import '../../features/classrooms/classroom.dart';
import '../../features/classrooms/classroom_detail_screen.dart';
import '../../features/classrooms/classroom_form_screen.dart';
import '../../features/classrooms/students_bulk_add_screen.dart';
import '../../features/courses/course_detail_screen.dart';
import '../../features/courses/course_form_screen.dart';
import '../../features/courses/course_models.dart';
import '../../features/courses/courses_screen.dart';
import '../../features/dashboard/assignment_analytics_screen.dart';
import '../../features/exams/exam_answer_key_screen.dart';
import '../../features/exams/exam_copy_questions_screen.dart';
import '../../features/exams/exam_form_screen.dart';
import '../../features/exams/exam_import_review_screen.dart';
import '../../features/exams/exam_key_sheet_scan_screen.dart';
import '../../features/exams/exam_print_screen.dart';
import '../../features/exams/exam_results_screen.dart';
import '../../features/exams/exam_scan_screen.dart';
import '../../features/exams/exam_question_screen.dart';
import '../../features/exams/exam_screen.dart';
import '../../features/exams/exam_versions_screen.dart';
import '../../features/google_classroom/classroom_feedback_screen.dart';
import '../../features/gradebook/gradebook_screen.dart';
import '../../features/gradebook/gradebook_settings_screen.dart';
import '../../features/gradebook/student_grades.dart';
import '../../features/google_classroom/classroom_import_screen.dart';
import '../../features/google_classroom/course_picker_screen.dart';
import '../../features/google_classroom/grade_conflicts_screen.dart';
import '../../features/google_classroom/roster_matching_screen.dart';
import '../../features/google_classroom/submissions_screen.dart';
import '../../features/hand_in/hand_in_models.dart';
import '../../features/hand_in/student_hand_in_screen.dart';
import '../../features/hand_in/teacher_upload_screen.dart';
import '../../features/home/teacher_shell.dart';
import '../../features/mastery/classroom_mastery_screen.dart';
import '../../features/mastery/student_mastery_screen.dart';
import '../../features/practice/practice_attempt_screen.dart';
import '../../features/practice/practice_bank_screen.dart';
import '../../features/practice/practice_page.dart';
import '../../features/practice/skill_practice_screen.dart';
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

  /// The one login page of every role (DESIGN §7.4); `?tab=student` opens
  /// the student tab.
  static const login = '/login';
  static String loginAt(LoginTab tab) =>
      Uri(path: login, queryParameters: {'tab': tab.name}).toString();
  static final loginStudent = loginAt(LoginTab.student);
  static const register = '/register';

  /// The old student login screen: now redirects to [loginStudent], kept
  /// so existing links and bookmarks still work.
  static const studentLogin = '/student/login';
  static const studentQr = '/student/login/qr';

  /// Admin: one screen that opens the web panel (DESIGN §7.4).
  static const adminHome = '/admin-home';

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

  /// "คะแนนไม่ตรงกัน": grades changed on the Classroom website (§19.3).
  static String gradeConflicts(int assignmentId) =>
      '/assignments/$assignmentId/grade-conflicts';

  /// "ประกาศผลรายคน": the private result announcements in Classroom (§19.7).
  static String googleFeedback(int assignmentId) =>
      '/assignments/$assignmentId/google-feedback';

  /// Courses, units and lesson plans (DESIGN §20.1).
  static const courses = '/courses';
  static const courseNew = '/courses/new';

  /// The course form with [classroomId] ticked. With [pick] (the
  /// assignment form) it pops the new course instead of opening it.
  static String courseNewFor(int? classroomId, {bool pick = false}) {
    final query = {
      'classroom': ?classroomId?.toString(),
      if (pick) 'pick': '1',
    };
    return query.isEmpty
        ? courseNew
        : Uri(path: courseNew, queryParameters: query).toString();
  }

  static String course(int id) => '/courses/$id';
  static String courseEdit(int id) => '/courses/$id/edit';

  /// The gradebook of a course (DESIGN §23.9), opened on [classroomId] and
  /// scrolled to [column] (`a{assignment id}` or `i{item id}`) when given.
  static String gradebook(int courseId, {int? classroomId, String? column}) {
    final query = {'classroom': ?classroomId?.toString(), 'column': ?column};
    return Uri(
      path: '/courses/$courseId/gradebook',
      queryParameters: query.isEmpty ? null : query,
    ).toString();
  }

  static String gradebookSettings(int courseId) =>
      '/courses/$courseId/gradebook/settings';

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

  /// Exams (DESIGN §22): settings, sections and questions, the key grid
  /// and the shuffled versions.
  static const examNew = '/exams/new';
  static String examNewFor(int? classroomId) =>
      classroomId == null ? examNew : '$examNew?classroom=$classroomId';
  static String exam(int id) => '/exams/$id';
  static String examEdit(int id) => '/exams/$id/edit';
  static String examAnswerKey(int id) => '/exams/$id/answer-key';
  static String examVersions(int id) => '/exams/$id/versions';
  static String examPrint(int id) => '/exams/$id/print';
  static String examScan(int id) => '/exams/$id/scan';
  static String examResults(int id) => '/exams/$id/results';
  static String examKeySheetScan(int id) => '/exams/$id/key-sheet-scan';
  static String examReadReview(int id) => '/exams/$id/read-review';
  static String examCopyQuestions(int id) => '/exams/$id/copy-questions';
  static String examQuestion(int examId, int questionId) =>
      '/exams/$examId/questions/$questionId';
  static String examQuestionNew(int examId, int sectionId) =>
      '/exams/$examId/sections/$sectionId/questions/new';

  static const scan = '/scan';

  /// "อัปโหลดรูปเพื่อตรวจ": the teacher hands in a student's work from
  /// files (DESIGN §19.6). `extra` may carry [TeacherUploadArgs].
  static const teacherUpload = '/hand-ins/upload';
  static String teacherUploadFor(int assignmentId) =>
      '$teacherUpload?assignment=$assignmentId';
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

  /// Question → indicator mapping with AI suggestions (DESIGN §20.3).
  static String indicatorMapping(int id) => '/assignments/$id/indicators';

  /// Student x skill mastery heatmap of a classroom (§14.3), optionally
  /// opened on a course's indicators (§20.4 chart 3).
  static String classroomMastery(int id, {int? courseId}) => courseId == null
      ? '/classrooms/$id/mastery'
      : '/classrooms/$id/mastery?course=$courseId';

  /// The charts of a course in a classroom (DESIGN §20.4).
  static String courseCharts(int courseId, {int? classroomId}) =>
      classroomId == null
      ? '/courses/$courseId/charts'
      : '/courses/$courseId/charts?classroom=$classroomId';

  /// One student's spider and progress in a course, seen by the teacher.
  static String studentCourseCharts(
    int courseId,
    int studentId, {
    int? classroomId,
  }) =>
      '/courses/$courseId/students/$studentId/charts'
      '${classroomId == null ? '' : '?classroom=$classroomId'}';

  /// Student: their own charts of one course (§20.4, §20.9).
  static String myCourseCharts(int courseId) => '/student/courses/$courseId';

  /// Student: their own published grade of one course (§23.7).
  static String myCourseGrade(int courseId) =>
      '/student/courses/$courseId/grade';

  /// One student's skills and weaknesses, seen by the teacher.
  static String studentMastery(int classroomId, int studentId) =>
      '/classrooms/$classroomId/students/$studentId/mastery';

  /// Every student's AI analysis in a classroom and its auto-share switch
  /// (DESIGN §20.5).
  static String classroomAnalyses(int id) => '/classrooms/$id/analyses';

  /// One student's AI analysis in a classroom: texts, approve, edit, run.
  static String studentAnalysis(int classroomId, int studentId) =>
      '/classrooms/$classroomId/students/$studentId/analysis';

  /// The school's practice bank (§14.1).
  static const practiceBank = '/practice-bank';

  /// Review links of a skill (`learning_resources`).
  static String skillResources(int skillId) => '/skills/$skillId/resources';

  /// Student: one practice item (§9.7).
  static String studentPractice(int itemId) => '/student/practice/$itemId';

  /// Student: the practice of one indicator, from an analysis next step
  /// (§20.5). `extra` may carry the [Skill].
  static String studentSkillPractice(int skillId) =>
      '/student/practice/skills/$skillId';

  /// Student: one published submission (§9.7).
  static String studentResult(int submissionId) =>
      '/student/results/$submissionId';

  /// Student: hand in one assignment from the app (§19.6).
  static String studentHandIn(int assignmentId) =>
      '/student/assignments/$assignmentId/hand-in';

  /// Routes a signed-in student may open (everything else sends them home).
  static bool isStudentArea(String location) =>
      location == student ||
      location.startsWith('$student/results/') ||
      location.startsWith('$student/assignments/') ||
      location.startsWith('$student/practice/') ||
      location.startsWith('$student/courses/');

  /// The app location of a result link from a Classroom announcement
  /// (DESIGN §19.7): `eduvision://r/{submission_id}` (what the server's
  /// `/r/{id}` page opens) or a bare `/r/{id}`; null for anything else.
  static String? fromResultLink(Uri uri) {
    final segments = uri.pathSegments.where((s) => s.isNotEmpty).toList();
    final List<String> rest;
    if (uri.scheme == 'eduvision' && uri.host == 'r') {
      rest = segments;
    } else if ((uri.scheme.isEmpty || uri.scheme == 'eduvision') &&
        uri.host.isEmpty &&
        segments.length == 2 &&
        segments.first == 'r') {
      rest = segments.sublist(1);
    } else {
      return null;
    }
    if (rest.length != 1) return null;
    final id = int.tryParse(rest.single);
    return id == null || id <= 0 ? null : studentResult(id);
  }

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

  // A result link that waits for the student to sign in.
  String? pendingResult;

  return GoRouter(
    initialLocation: AppRoutes.splash,
    refreshListenable: refresh,
    redirect: (context, state) {
      final session = ref.read(sessionProvider);
      // A result link from Classroom (§19.7) opens the student's result,
      // after the student signs in when needed.
      final link = AppRoutes.fromResultLink(state.uri);
      if (link != null) pendingResult = link;
      final location = link ?? state.matchedLocation;
      final public = AppRoutes.isPublic(location);
      final inStudentArea = AppRoutes.isStudentArea(location);
      final String? target = switch (session) {
        SessionRestoring() =>
          location == AppRoutes.splash ? null : AppRoutes.splash,
        SignedOut() =>
          public
              ? null
              : pendingResult != null
              ? AppRoutes.loginStudent
              : AppRoutes.login,
        // An admin's token opens nothing but the panel handoff.
        SignedIn(:final user) when user.isAdmin => () {
          pendingResult = null;
          return location == AppRoutes.adminHome ? null : AppRoutes.adminHome;
        }(),
        SignedIn(:final user) when user.isStudent => () {
          final pending = pendingResult;
          if (pending != null) {
            if (pending != location) return pending;
            pendingResult = null;
          }
          return inStudentArea ? null : AppRoutes.student;
        }(),
        SignedIn() => () {
          pendingResult = null;
          return (public ||
                  location == AppRoutes.splash ||
                  location == AppRoutes.adminHome ||
                  inStudentArea)
              ? AppRoutes.home
              : null;
        }(),
      };
      // The link itself matches no route: always leave it.
      return target ?? link;
    },
    routes: [
      GoRoute(
        path: AppRoutes.splash,
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: AppRoutes.login,
        builder: (context, state) => LoginScreen(
          initialTab: LoginTab.parse(state.uri.queryParameters['tab']),
        ),
      ),
      GoRoute(
        path: AppRoutes.register,
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: AppRoutes.studentLogin,
        redirect: (context, state) => AppRoutes.loginStudent,
      ),
      GoRoute(
        path: AppRoutes.adminHome,
        builder: (context, state) => const AdminHomeScreen(),
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
        path: '/student/assignments/:aid/hand-in',
        builder: (context, state) => StudentHandInScreen(
          assignmentId: _id(state, 'aid'),
          initial: state.extra is StudentAssignment
              ? state.extra as StudentAssignment
              : null,
        ),
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
        path: '/student/practice/skills/:skillId',
        builder: (context, state) => SkillPracticeScreen(
          skillId: _id(state, 'skillId'),
          skill: state.extra is Skill ? state.extra as Skill : null,
        ),
      ),
      GoRoute(
        path: '/student/courses/:id',
        builder: (context, state) =>
            MyCourseChartsScreen(courseId: _id(state, 'id')),
        routes: [
          GoRoute(
            path: 'grade',
            builder: (context, state) =>
                StudentGradeScreen(courseId: _id(state, 'id')),
          ),
        ],
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
            builder: (context, state) => ClassroomMasteryScreen(
              classroomId: _id(state, 'id'),
              initialCourseId: int.tryParse(
                state.uri.queryParameters['course'] ?? '',
              ),
            ),
          ),
          GoRoute(
            path: 'students/:sid/mastery',
            builder: (context, state) => StudentMasteryScreen(
              classroomId: _id(state, 'id'),
              studentId: _id(state, 'sid'),
            ),
          ),
          GoRoute(
            path: 'analyses',
            builder: (context, state) =>
                ClassroomAnalysesScreen(classroomId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'students/:sid/analysis',
            builder: (context, state) => StudentAnalysisScreen(
              classroomId: _id(state, 'id'),
              studentId: _id(state, 'sid'),
            ),
          ),
        ],
      ),
      GoRoute(
        path: AppRoutes.courses,
        builder: (context, state) => const CoursesScreen(),
      ),
      // Before '/courses/:id', which would take "new" as an id.
      GoRoute(
        path: AppRoutes.courseNew,
        builder: (context, state) => CourseFormScreen(
          initialClassroomId: int.tryParse(
            state.uri.queryParameters['classroom'] ?? '',
          ),
          popOnCreate: state.uri.queryParameters['pick'] == '1',
        ),
      ),
      GoRoute(
        path: '/courses/:id',
        builder: (context, state) =>
            CourseDetailScreen(courseId: _id(state, 'id')),
        routes: [
          GoRoute(
            path: 'gradebook',
            builder: (context, state) => GradebookScreen(
              courseId: _id(state, 'id'),
              initialClassroomId: int.tryParse(
                state.uri.queryParameters['classroom'] ?? '',
              ),
              focusColumn: state.uri.queryParameters['column'],
            ),
            routes: [
              GoRoute(
                path: 'settings',
                builder: (context, state) =>
                    GradebookSettingsScreen(courseId: _id(state, 'id')),
              ),
            ],
          ),
          GoRoute(
            path: 'charts',
            builder: (context, state) => CourseChartsScreen(
              courseId: _id(state, 'id'),
              initialClassroomId: int.tryParse(
                state.uri.queryParameters['classroom'] ?? '',
              ),
            ),
          ),
          GoRoute(
            path: 'students/:sid/charts',
            builder: (context, state) => StudentCourseChartsScreen(
              courseId: _id(state, 'id'),
              studentId: _id(state, 'sid'),
              classroomId: int.tryParse(
                state.uri.queryParameters['classroom'] ?? '',
              ),
            ),
          ),
          GoRoute(
            path: 'edit',
            builder: (context, state) => CourseEditScreen(
              courseId: _id(state, 'id'),
              initial: state.extra is Course ? state.extra as Course : null,
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
            path: 'indicators',
            builder: (context, state) =>
                IndicatorMappingScreen(assignmentId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'google-submissions',
            builder: (context, state) =>
                GoogleSubmissionsScreen(assignmentId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'grade-conflicts',
            builder: (context, state) =>
                GradeConflictsScreen(assignmentId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'google-feedback',
            builder: (context, state) =>
                ClassroomFeedbackScreen(assignmentId: _id(state, 'id')),
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
      // Before '/exams/:id', which would take "new" as an id.
      GoRoute(
        path: AppRoutes.examNew,
        builder: (context, state) => ExamFormScreen(
          initialClassroomId: int.tryParse(
            state.uri.queryParameters['classroom'] ?? '',
          ),
        ),
      ),
      GoRoute(
        path: '/exams/:id',
        builder: (context, state) => ExamScreen(examId: _id(state, 'id')),
        routes: [
          GoRoute(
            path: 'edit',
            builder: (context, state) =>
                ExamEditScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'answer-key',
            builder: (context, state) =>
                ExamAnswerKeyScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'versions',
            builder: (context, state) =>
                ExamVersionsScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'print',
            builder: (context, state) =>
                ExamPrintScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'scan',
            builder: (context, state) =>
                ExamScanScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'results',
            builder: (context, state) =>
                ExamResultsScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'key-sheet-scan',
            builder: (context, state) =>
                ExamKeySheetScanScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'read-review',
            builder: (context, state) =>
                ExamImportReviewScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'copy-questions',
            builder: (context, state) =>
                ExamCopyQuestionsScreen(examId: _id(state, 'id')),
          ),
          GoRoute(
            path: 'questions/:qid',
            builder: (context, state) => ExamQuestionScreen(
              examId: _id(state, 'id'),
              questionId: _id(state, 'qid'),
            ),
          ),
          GoRoute(
            path: 'sections/:sid/questions/new',
            builder: (context, state) => ExamQuestionScreen(
              examId: _id(state, 'id'),
              sectionId: _id(state, 'sid'),
            ),
          ),
        ],
      ),
      GoRoute(
        path: AppRoutes.scan,
        builder: (context, state) => const ScanScreen(),
      ),
      GoRoute(
        path: AppRoutes.teacherUpload,
        builder: (context, state) {
          final extra = state.extra is TeacherUploadArgs
              ? state.extra as TeacherUploadArgs
              : null;
          return TeacherUploadScreen(
            args: TeacherUploadArgs(
              assignmentId:
                  extra?.assignmentId ??
                  int.tryParse(state.uri.queryParameters['assignment'] ?? ''),
              files: extra?.files ?? const [],
            ),
          );
        },
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
