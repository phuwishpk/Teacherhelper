import 'package:eduvision/features/hand_in/hand_in_models.dart';
import 'package:eduvision/features/hand_in/hand_in_repository.dart';
import 'package:eduvision/features/results/results_repository.dart';
import 'package:eduvision/features/results/student_result.dart';
import 'package:eduvision/features/student/student_overview.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';

import '../gradebook/gradebook_fakes.dart';
import '../hand_in/hand_in_fakes.dart';

/// A student in two classrooms (DESIGN §24.11): ป.5/1 of 2569 (open) and
/// ป.4/1 of 2568 (closed, "ห้องเก่า").
const room51 = {
  'id': 7,
  'name': 'ป.5/1',
  'academic_year': 2569,
  'closed': false,
};
const room41 = {
  'id': 3,
  'name': 'ป.4/1',
  'academic_year': 2568,
  'closed': true,
};

const math5 = {'id': 4, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'};
const science5 = {'id': 5, 'code': 'ว15101', 'name': 'วิทยาศาสตร์ 5'};
const math4 = {'id': 2, 'code': 'ค14101', 'name': 'คณิตศาสตร์ 4'};

/// The keys of the groups below (`SubjectTag.key`).
const keyMath5 = 'c4:7';
const keyScience5 = 'c5:7';
const keyThai = 'sภาษาไทย:7';
const keyMath4 = 'c2:3';

/// `GET /student/overview` (DESIGN §24.26), in the server's order.
Map<String, dynamic> studentOverviewJson() => {
  'classrooms': [room51, room41],
  'groups': [
    {
      'course': math5,
      'subject': {'id': 1, 'code': 'ค', 'name': 'คณิตศาสตร์'},
      'classroom': room51,
      'teacher_name': 'ครูสมศรี',
      'todo_count': 2,
      'results_count': 1,
      'latest_published_at': '2026-09-30T03:00:00+00:00',
      'grade': {'grade': 3.0, 'special': null},
    },
    {
      'course': science5,
      'subject': {'id': 2, 'code': 'ว', 'name': 'วิทยาศาสตร์'},
      'classroom': room51,
      'teacher_name': 'ครูมานะ',
      'todo_count': 0,
      'results_count': 0,
      'latest_published_at': null,
      'grade': null,
    },
    {
      'course': null,
      'subject': {'id': 3, 'code': 'ท', 'name': 'ภาษาไทย'},
      'classroom': room51,
      'teacher_name': 'ครูสมศรี',
      'todo_count': 1,
      'results_count': 0,
      'latest_published_at': null,
      'grade': null,
    },
    {
      'course': math4,
      'subject': {'id': 1, 'code': 'ค', 'name': 'คณิตศาสตร์'},
      'classroom': room41,
      'teacher_name': 'ครูวิไล',
      'todo_count': 0,
      'results_count': 1,
      'latest_published_at': '2026-03-01T03:00:00+00:00',
      'grade': {'grade': null, 'special': 'r'},
    },
  ],
};

/// `GET /student/assignments` rows of the same student.
List<StudentAssignment> assignmentsFixture() => [
  for (final row in <Map<String, dynamic>>[
    {
      'id': 1,
      'title': 'เศษส่วน ชุดที่ 1',
      'course': math5,
      'classroom': room51,
      'subject_name': 'คณิตศาสตร์',
      'due_at': '2026-10-05T02:00:00+00:00',
      'status': 'not_submitted',
    },
    {
      'id': 3,
      'title': 'อ่านจับใจความ',
      'course': null,
      'classroom': room51,
      'subject_name': 'ภาษาไทย',
      'status': 'not_submitted',
    },
    {
      'id': 2,
      'title': 'เศษส่วน ชุดที่ 2',
      'course': math5,
      'classroom': room51,
      'subject_name': 'คณิตศาสตร์',
      'due_at': '2026-10-06T02:00:00+00:00',
      'status': 'not_submitted',
    },
    {
      'id': 4,
      'title': 'ทบทวนการคูณ',
      'course': math4,
      'classroom': room41,
      'subject_name': 'คณิตศาสตร์',
      'can_submit': false,
      'status': 'not_submitted',
    },
  ])
    StudentAssignment.fromJson(row),
];

/// `GET /student/results` rows of the same student.
List<StudentResult> resultsFixture() => [
  for (final row in <Map<String, dynamic>>[
    {
      'submission_id': 70,
      'title': 'บวกเศษส่วน',
      'subject_name': 'คณิตศาสตร์',
      'course': math5,
      'classroom': room51,
      'total_score': 8,
      'max_score': 10,
      'published_at': '2026-09-30T03:00:00+00:00',
    },
    {
      'submission_id': 71,
      'title': 'สูตรคูณแม่ 7',
      'subject_name': 'คณิตศาสตร์',
      'course': math4,
      'classroom': room41,
      'total_score': 5,
      'max_score': 10,
      'published_at': '2026-03-01T03:00:00+00:00',
    },
  ])
    StudentResult.fromJson(row),
];

class FakeOverviewRepository implements StudentOverviewRepository {
  FakeOverviewRepository([Map<String, dynamic>? body])
    : body = body ?? studentOverviewJson();

  Map<String, dynamic> body;
  Object? error;
  int calls = 0;

  @override
  Future<StudentOverview> overview() async {
    calls++;
    if (error case final e?) throw e;
    return StudentOverview.fromJson(body);
  }
}

class FakeStudentResults extends Fake implements ResultsRepository {
  FakeStudentResults([List<StudentResult>? results])
    : results = results ?? resultsFixture();

  final List<StudentResult> results;

  @override
  Future<List<StudentResult>> list() async => results;

  @override
  Future<List<RetakeRequest>> retakeRequests() async => const [];
}

/// Every repository the student's combined view reads.
List<Override> studentViewOverrides({
  FakeOverviewRepository? overview,
  FakeHandIn? handIn,
  FakeStudentResults? results,
  FakeGradebookRepository? gradebook,
}) => [
  studentOverviewRepositoryProvider.overrideWithValue(
    overview ?? FakeOverviewRepository(),
  ),
  handInRepositoryProvider.overrideWithValue(
    handIn ?? FakeHandIn(assignments: assignmentsFixture()),
  ),
  resultsRepositoryProvider.overrideWithValue(results ?? FakeStudentResults()),
  ...gradebookOverrides(gradebook ?? FakeGradebookRepository()),
];
