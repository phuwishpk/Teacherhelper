import 'package:eduvision/core/api/response_crops.dart';
import 'package:eduvision/features/results/result_detail_screen.dart';
import 'package:eduvision/features/results/results_page.dart';
import 'package:eduvision/features/results/results_repository.dart';
import 'package:eduvision/features/results/student_result.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../helpers/fake_http_adapter.dart';
import '../helpers/pump_screen.dart';
import '../review/review_fixtures.dart';

class _Results extends Fake implements ResultsRepository {
  _Results({this.retakes = const [], this.retakeError});

  final List<RetakeRequest> retakes;
  final Object? retakeError;

  @override
  Future<List<StudentResult>> list() async => const [];

  @override
  Future<List<RetakeRequest>> retakeRequests() async {
    if (retakeError case final e?) throw e;
    return retakes;
  }

  @override
  Future<StudentResultDetail> detail(int submissionId) async =>
      StudentResultDetail.fromJson({
        'submission_id': submissionId,
        'assignment': {'id': 5, 'title': 'บวกเลข'},
        'total_score': 3,
        'max_score': 5,
        'retake_reason': 'รูปหน้า 2 มืดเกินไป',
        'responses': <Object>[],
      });
}

const _retake = RetakeRequest(
  id: 31,
  assignmentId: 12,
  title: 'เศษส่วน',
  reason: 'มองไม่เห็นมุมล่างขวาของใบงาน',
  alternateLink: 'https://classroom.google.com/s/31',
);

/// DESIGN §18.2: the Classroom API has no private comment, so the reason
/// for "ตีกลับให้ถ่ายใหม่" is shown to the student in our app.
void main() {
  testWidgets('the results tab shows the teacher\'s retake reason', (
    tester,
  ) async {
    await pumpScreen(
      tester,
      const Scaffold(body: ResultsPage()),
      overrides: [
        resultsRepositoryProvider.overrideWithValue(
          _Results(retakes: const [_retake]),
        ),
      ],
    );
    expect(find.text('ครูขอให้ถ่ายรูปใหม่: เศษส่วน'), findsOneWidget);
    expect(find.text('มองไม่เห็นมุมล่างขวาของใบงาน'), findsOneWidget);
    expect(find.text('คัดลอกลิงก์งานใน Classroom'), findsOneWidget);
    expect(
      find.text('ยังไม่มีผลการบ้าน'),
      findsNothing,
      reason: 'the notice shows even before any result is published',
    );
  });

  testWidgets('no notice when the request fails', (tester) async {
    await pumpScreen(
      tester,
      const Scaffold(body: ResultsPage()),
      overrides: [
        resultsRepositoryProvider.overrideWithValue(
          _Results(retakeError: Exception('offline')),
        ),
      ],
    );
    expect(find.textContaining('ครูขอให้ถ่ายรูปใหม่'), findsNothing);
    expect(find.text('ยังไม่มีผลการบ้าน'), findsOneWidget);
  });

  testWidgets('a result detail shows retake_reason when present', (
    tester,
  ) async {
    await pumpScreen(
      tester,
      const ResultDetailScreen(submissionId: 70),
      overrides: [
        resultsRepositoryProvider.overrideWithValue(_Results()),
        cropLoaderProvider.overrideWithValue(NoCropLoader()),
      ],
    );
    expect(find.text('ครูขอให้ถ่ายรูปใหม่'), findsOneWidget);
    expect(find.text('รูปหน้า 2 มืดเกินไป'), findsOneWidget);
  });

  test('retakeRequests is GET /student/retake-requests', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(200, {
        'data': [
          {
            'id': 31,
            'assignment': {'id': 12, 'title': 'เศษส่วน'},
            'reason': 'มองไม่เห็นมุมล่างขวา',
            'requested_at': '2026-10-01T02:00:00Z',
            'alternate_link': 'https://classroom.google.com/s/31',
          },
        ],
      }),
    );
    final rows = await ApiResultsRepository(fakeDio(adapter)).retakeRequests();
    expect(adapter.requests.single.uri.path, '/api/v1/student/retake-requests');
    final r = rows.single;
    expect(r.assignmentId, 12);
    expect(r.title, 'เศษส่วน');
    expect(r.reason, 'มองไม่เห็นมุมล่างขวา');
    expect(r.requestedAt, DateTime.utc(2026, 10, 1, 2));
  });

  test('a server without Classroom (404) means no notices', () async {
    final adapter = FakeHttpAdapter(
      (_) async => jsonResponse(404, {
        'message': 'Not found',
        'errors': null,
        'code': 'not_found',
      }),
    );
    final container = ProviderContainer(
      overrides: [
        resultsRepositoryProvider.overrideWithValue(
          ApiResultsRepository(fakeDio(adapter)),
        ),
      ],
    );
    addTearDown(container.dispose);
    expect(await container.read(studentRetakeRequestsProvider.future), isEmpty);
  });
}
