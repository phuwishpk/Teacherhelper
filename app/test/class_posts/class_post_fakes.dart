import 'dart:typed_data';

import 'package:eduvision/features/class_posts/class_post_models.dart';
import 'package:eduvision/features/class_posts/class_posts_repository.dart';

/// In-memory [ClassPostsRepository]: what the screens asked for is kept in
/// [created], [updated], [deleted] and [uploaded].
class FakeClassPosts implements ClassPostsRepository {
  FakeClassPosts({List<ClassPost>? posts, List<FeedItem>? feed})
    : posts = posts ?? [],
      feedItems = feed ?? [];

  List<ClassPost> posts;
  List<FeedItem> feedItems;
  Object? error;

  final created = <(List<int>, PostDraft)>[];
  final updated = <(int, PostDraft)>[];
  final deleted = <int>[];
  final uploaded = <String>[];
  final feedKeys = <FeedKey>[];
  int _nextFileId = 900;

  void _throwOnce() {
    if (error case final e?) {
      error = null;
      throw e;
    }
  }

  @override
  Future<List<ClassPost>> ofClassroom(int classroomId) async {
    _throwOnce();
    return posts;
  }

  @override
  Future<ClassPost> post(int id) async => posts.firstWhere((p) => p.id == id);

  @override
  Future<List<ClassPost>> create(
    List<int> classroomIds,
    PostDraft draft,
  ) async {
    _throwOnce();
    created.add((classroomIds, draft));
    return const [];
  }

  @override
  Future<ClassPost> update(int id, PostDraft draft) async {
    _throwOnce();
    updated.add((id, draft));
    return posts.firstWhere((p) => p.id == id);
  }

  @override
  Future<void> delete(int id) async {
    _throwOnce();
    deleted.add(id);
    posts = [
      for (final p in posts)
        if (p.id != id) p,
    ];
  }

  @override
  Future<PostFile> upload({
    required String name,
    required Uint8List bytes,
  }) async {
    _throwOnce();
    uploaded.add(name);
    return PostFile(
      id: _nextFileId++,
      name: name,
      mimeType: 'application/pdf',
      sizeBytes: bytes.length,
      downloadUrl: '/api/v1/class-post-files/$_nextFileId/file',
    );
  }

  @override
  Future<void> deleteFile(int id) async {}

  @override
  Future<StorageUsage> usage() async => const StorageUsage(
    usedBytes: 1024 * 1024,
    limitBytes: 2048 * 1024 * 1024,
  );

  @override
  Future<List<FeedItem>> feed({int? courseId, int? classroomId}) async {
    _throwOnce();
    feedKeys.add((courseId: courseId, classroomId: classroomId));
    return feedItems;
  }

  @override
  Future<ClassPost> studentPost(int id) async =>
      feedItems.map((i) => i.post).nonNulls.firstWhere((p) => p.id == id);
}

ClassPost announcementFixture({
  int id = 1,
  String body = 'พรุ่งนี้สอบย่อย เตรียมดินสอ 2B',
  PostState state = PostState.published,
  bool isMine = true,
  List<PostAttachment> attachments = const [],
  List<int> studentIds = const [],
}) => ClassPost(
  id: id,
  kind: PostKind.announcement,
  body: body,
  authorName: 'ครูสมศรี',
  classroom: const PostRef(id: 1, name: 'ป.5/1'),
  publishedAt: state == PostState.published
      ? DateTime.utc(2026, 10, 12, 2)
      : null,
  scheduledAt: state == PostState.scheduled
      ? DateTime.utc(2026, 10, 20, 1)
      : null,
  state: state,
  isMine: isMine,
  attachments: attachments,
  forStudents: studentIds.isNotEmpty,
  studentIds: studentIds,
);

ClassPost materialFixture({int id = 2, String? topic = 'เศษส่วน'}) => ClassPost(
  id: id,
  kind: PostKind.material,
  title: 'ใบความรู้ที่ 1',
  body: 'อ่านก่อนเข้าเรียน',
  authorName: 'ครูสมศรี',
  classroom: const PostRef(id: 1, name: 'ป.5/1'),
  course: const PostRef(id: 7, name: 'คณิตศาสตร์ 5', code: 'ค15101'),
  topic: topic,
  publishedAt: DateTime.utc(2026, 10, 11, 2),
  isMine: true,
  attachments: const [
    PostAttachment(
      title: 'ใบความรู้.pdf',
      file: PostFile(
        id: 5,
        name: 'ใบความรู้.pdf',
        mimeType: 'application/pdf',
        sizeBytes: 340 * 1024,
        downloadUrl: '/api/v1/class-post-files/5/file',
      ),
    ),
    PostAttachment(title: 'วิดีโออธิบาย', url: 'https://youtu.be/abc'),
  ],
);
