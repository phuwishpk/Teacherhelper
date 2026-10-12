import 'dart:typed_data';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/api_retry.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import 'class_post_models.dart';

/// The class post endpoints of DESIGN §30.8.
abstract class ClassPostsRepository {
  /// `GET /classrooms/{id}/posts`.
  Future<List<ClassPost>> ofClassroom(int classroomId);

  /// `GET /class-posts/{id}`.
  Future<ClassPost> post(int id);

  /// `POST /class-posts`: one post per classroom.
  Future<List<ClassPost>> create(List<int> classroomIds, PostDraft draft);

  /// `PUT /class-posts/{id}`.
  Future<ClassPost> update(int id, PostDraft draft);

  /// `DELETE /class-posts/{id}`.
  Future<void> delete(int id);

  /// `POST /class-post-files`.
  Future<PostFile> upload({required String name, required Uint8List bytes});

  /// `DELETE /class-post-files/{id}` (a file no post attaches).
  Future<void> deleteFile(int id);

  /// `GET /class-post-files/usage`.
  Future<StorageUsage> usage();

  /// `GET /student/class-feed`.
  Future<List<FeedItem>> feed({int? courseId, int? classroomId});

  /// `GET /student/class-posts/{id}`.
  Future<ClassPost> studentPost(int id);
}

class ApiClassPostsRepository implements ClassPostsRepository {
  ApiClassPostsRepository(this._dio);

  final Dio _dio;

  @override
  Future<List<ClassPost>> ofClassroom(int classroomId) async {
    final res = await _dio.get<Object?>('/classrooms/$classroomId/posts');
    return unwrapList(res.data).map(ClassPost.fromJson).toList();
  }

  @override
  Future<ClassPost> post(int id) async {
    final res = await _dio.get<Object?>('/class-posts/$id');
    return ClassPost.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<ClassPost>> create(
    List<int> classroomIds,
    PostDraft draft,
  ) async {
    final res = await _dio.post<Object?>(
      '/class-posts',
      data: draft.toJson(classroomIds: classroomIds),
    );
    return unwrapList(res.data).map(ClassPost.fromJson).toList();
  }

  @override
  Future<ClassPost> update(int id, PostDraft draft) async {
    final res = await _dio.put<Object?>(
      '/class-posts/$id',
      data: draft.toJson(),
    );
    return ClassPost.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> delete(int id) => _dio.delete<Object?>('/class-posts/$id');

  @override
  Future<PostFile> upload({
    required String name,
    required Uint8List bytes,
  }) async {
    final res = await _dio.post<Object?>(
      '/class-post-files',
      data: FormData.fromMap({
        'file': MultipartFile.fromBytes(bytes, filename: name),
      }),
      options: Options(
        sendTimeout: const Duration(minutes: 2),
        receiveTimeout: const Duration(minutes: 2),
      ),
    );
    return PostFile.fromJson(unwrapJson(res.data));
  }

  @override
  Future<void> deleteFile(int id) =>
      _dio.delete<Object?>('/class-post-files/$id');

  @override
  Future<StorageUsage> usage() async {
    final res = await _dio.get<Object?>('/class-post-files/usage');
    return StorageUsage.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<FeedItem>> feed({int? courseId, int? classroomId}) async {
    final res = await _dio.get<Object?>(
      '/student/class-feed',
      queryParameters: {'course_id': ?courseId, 'classroom_id': ?classroomId},
    );
    return unwrapList(res.data).map(FeedItem.fromJson).toList();
  }

  @override
  Future<ClassPost> studentPost(int id) async {
    final res = await _dio.get<Object?>('/student/class-posts/$id');
    return ClassPost.fromJson(unwrapJson(res.data));
  }
}

final classPostsRepositoryProvider = Provider<ClassPostsRepository>(
  (ref) => ApiClassPostsRepository(ref.watch(dioProvider)),
);

/// Teacher: the posts of one classroom.
final classroomPostsProvider = FutureProvider.autoDispose
    .family<List<ClassPost>, int>((ref, classroomId) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(classPostsRepositoryProvider).ofClassroom(classroomId);
    }, retry: apiRetry);

/// Teacher: one post (the edit form).
final classPostProvider = FutureProvider.autoDispose.family<ClassPost, int>((
  ref,
  id,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(classPostsRepositoryProvider).post(id);
}, retry: apiRetry);

final postStorageUsageProvider = FutureProvider.autoDispose<StorageUsage>((
  ref,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(classPostsRepositoryProvider).usage();
}, retry: apiRetry);

typedef FeedKey = ({int? courseId, int? classroomId});

/// Student: the feed of everything ([FeedKey] both null) or of one subject.
final classFeedProvider = FutureProvider.autoDispose
    .family<List<FeedItem>, FeedKey>((ref, key) {
      watchSignedInUser(ref, keepAlive: false);
      return ref
          .watch(classPostsRepositoryProvider)
          .feed(courseId: key.courseId, classroomId: key.classroomId);
    }, retry: apiRetry);

/// Student: one post (opened from the feed or a notification).
final studentClassPostProvider = FutureProvider.autoDispose
    .family<ClassPost, int>((ref, id) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(classPostsRepositoryProvider).studentPost(id);
    }, retry: apiRetry);
