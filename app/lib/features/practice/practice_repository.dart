import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/session.dart';
import 'practice_models.dart';

/// Student practice (DESIGN §9.7, §14.1).
abstract class StudentPracticeRepository {
  /// `GET /student/practice`: weak skills with their items and links.
  Future<List<PracticeRecommendation>> recommendations();

  /// `POST /student/practice/{item_id}/attempts {answer}`.
  Future<PracticeAttemptResult> attempt(int itemId, String answer);
}

class ApiStudentPracticeRepository implements StudentPracticeRepository {
  ApiStudentPracticeRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<PracticeRecommendation>> recommendations() async {
    final res = await _dio.get<Object?>('/student/practice');
    return PracticeRecommendation.listFromJson(unwrapList(res.data));
  }

  @override
  Future<PracticeAttemptResult> attempt(int itemId, String answer) async {
    final res = await _dio.post<Object?>(
      '/student/practice/$itemId/attempts',
      data: {'answer': answer},
    );
    return PracticeAttemptResult.fromJson(unwrapJson(res.data));
  }
}

/// The fields of `PATCH /practice-items/{id}`; null ones are not sent.
class PracticeItemPatch {
  const PracticeItemPatch({
    this.status,
    this.promptText,
    this.options,
    this.answerKey,
    this.explanation,
  });

  final PracticeItemStatus? status;
  final String? promptText;
  final List<PracticeOption>? options;
  final Map<String, dynamic>? answerKey;
  final String? explanation;

  Map<String, dynamic> toJson() => {
    'status': ?status?.apiValue,
    'prompt_text': ?promptText,
    if (options != null) 'options': [for (final o in options!) o.toJson()],
    'answer_key': ?answerKey,
    'explanation': ?explanation,
  };
}

/// Teacher side of the school's practice bank (DESIGN §9.6, §14.1).
abstract class PracticeBankRepository {
  /// `GET /practice-items?skill=&status=` (all pages).
  Future<List<PracticeItem>> list({int? skillId, PracticeItemStatus? status});

  /// `POST /skills/{id}/practice-items/generate {count}`: queues Gemini;
  /// the new items appear later as drafts.
  Future<void> generate(int skillId, {int count = 5});

  /// `PATCH /practice-items/{id}`: edit, approve or retire.
  Future<PracticeItem> update(int itemId, PracticeItemPatch patch);

  /// `GET /skills/{id}/resources`.
  Future<List<LearningResource>> resources(int skillId);

  /// `POST /skills/{id}/resources {title, url}`.
  Future<LearningResource> addResource(
    int skillId, {
    required String title,
    required String url,
  });
}

class ApiPracticeBankRepository implements PracticeBankRepository {
  ApiPracticeBankRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<PracticeItem>> list({
    int? skillId,
    PracticeItemStatus? status,
  }) async {
    final rows = await fetchAllPages(
      _dio,
      '/practice-items',
      query: {'skill': ?skillId, 'status': ?status?.apiValue},
    );
    return rows.map(PracticeItem.fromJson).toList();
  }

  @override
  Future<void> generate(int skillId, {int count = 5}) async {
    await _dio.post<Object?>(
      '/skills/$skillId/practice-items/generate',
      data: {'count': count},
    );
  }

  @override
  Future<PracticeItem> update(int itemId, PracticeItemPatch patch) async {
    final res = await _dio.patch<Object?>(
      '/practice-items/$itemId',
      data: patch.toJson(),
    );
    return PracticeItem.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<LearningResource>> resources(int skillId) async {
    final res = await _dio.get<Object?>('/skills/$skillId/resources');
    return unwrapList(res.data).map(LearningResource.fromJson).toList();
  }

  @override
  Future<LearningResource> addResource(
    int skillId, {
    required String title,
    required String url,
  }) async {
    final res = await _dio.post<Object?>(
      '/skills/$skillId/resources',
      data: {'title': title, 'url': url},
    );
    return LearningResource.fromJson(unwrapJson(res.data));
  }
}

final studentPracticeRepositoryProvider = Provider<StudentPracticeRepository>(
  (ref) => ApiStudentPracticeRepository(ref.watch(dioProvider)),
);

final practiceBankRepositoryProvider = Provider<PracticeBankRepository>(
  (ref) => ApiPracticeBankRepository(ref.watch(dioProvider)),
);

/// The student's recommendations; refreshed after practising.
final practiceRecommendationsProvider =
    FutureProvider.autoDispose<List<PracticeRecommendation>>((ref) {
      watchSignedInUser(ref);
      return ref.watch(studentPracticeRepositoryProvider).recommendations();
    }, retry: apiRetry);

/// Filter of the teacher's bank screen.
typedef PracticeBankFilter = ({int? skillId, PracticeItemStatus status});

final practiceBankProvider = FutureProvider.autoDispose
    .family<List<PracticeItem>, PracticeBankFilter>((ref, filter) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(practiceBankRepositoryProvider)
          .list(skillId: filter.skillId, status: filter.status);
    }, retry: apiRetry);

final skillResourcesProvider = FutureProvider.autoDispose
    .family<List<LearningResource>, int>((ref, skillId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(practiceBankRepositoryProvider).resources(skillId);
    }, retry: apiRetry);
