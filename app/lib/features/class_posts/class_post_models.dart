/// Class posts (DESIGN §30): announcements and materials a teacher writes
/// for a classroom, and the student's feed.
library;

enum PostKind {
  announcement('announcement', 'ประกาศ'),
  material('material', 'เอกสารประกอบ');

  const PostKind(this.api, this.label);

  final String api;
  final String label;

  static PostKind parse(Object? value) =>
      value == 'material' ? material : announcement;
}

enum PostState {
  draft('draft', 'ฉบับร่าง'),
  scheduled('scheduled', 'ตั้งเวลาไว้'),
  published('published', 'เผยแพร่แล้ว');

  const PostState(this.api, this.label);

  final String api;
  final String label;

  static PostState parse(Object? value) => switch (value) {
    'draft' => draft,
    'scheduled' => scheduled,
    _ => published,
  };
}

DateTime? _time(Object? value) =>
    value is String ? DateTime.tryParse(value) : null;

/// An uploaded file of a post (`class_post_files`).
class PostFile {
  const PostFile({
    required this.id,
    required this.name,
    required this.mimeType,
    required this.sizeBytes,
    required this.downloadUrl,
  });

  factory PostFile.fromJson(Map<String, dynamic> json) => PostFile(
    id: json['id'] as int,
    name: json['name'] as String? ?? '',
    mimeType: json['mime_type'] as String? ?? '',
    sizeBytes: (json['size_bytes'] as num?)?.toInt() ?? 0,
    downloadUrl: json['download_url'] as String? ?? '',
  );

  final int id;
  final String name;
  final String mimeType;
  final int sizeBytes;
  final String downloadUrl;

  /// "1.2 MB", "340 KB".
  String get sizeLabel => formatBytes(sizeBytes);

  bool get isImage => mimeType.startsWith('image/');
  bool get isPdf => mimeType == 'application/pdf';
}

String formatBytes(int bytes) {
  if (bytes >= 1024 * 1024 * 1024) {
    return '${(bytes / (1024 * 1024 * 1024)).toStringAsFixed(1)} GB';
  }
  if (bytes >= 1024 * 1024) {
    return '${(bytes / (1024 * 1024)).toStringAsFixed(1)} MB';
  }
  return '${(bytes / 1024).ceil()} KB';
}

/// A file or a link of a post.
class PostAttachment {
  const PostAttachment({required this.title, this.url, this.file});

  factory PostAttachment.fromJson(Map<String, dynamic> json) => PostAttachment(
    title: json['title'] as String? ?? '',
    url: json['url'] as String?,
    file: json['file'] is Map<String, dynamic>
        ? PostFile.fromJson(json['file'] as Map<String, dynamic>)
        : null,
  );

  final String title;
  final String? url;
  final PostFile? file;

  bool get isLink => file == null;

  Map<String, dynamic> toJson() => file != null
      ? {'file_id': file!.id, 'title': title}
      : {'url': url, 'title': title};
}

class PostRef {
  const PostRef({required this.id, required this.name, this.code});

  static PostRef? parse(Object? json, {String nameKey = 'name'}) {
    if (json is! Map<String, dynamic>) return null;
    return PostRef(
      id: json['id'] as int,
      name: json[nameKey] as String? ?? '',
      code: json['code'] as String?,
    );
  }

  final int id;
  final String name;
  final String? code;

  /// "ค15101 คณิตศาสตร์" for a course.
  String get label => [?code, name].where((s) => s.isNotEmpty).join(' ');
}

/// The Google Classroom copy of a post (`google` of the teacher's payload,
/// DESIGN §30.11).
enum GoogleSendState {
  notSent('not_sent'),
  queued('queued'),
  sent('sent'),
  failed('failed');

  const GoogleSendState(this.api);

  final String api;

  static GoogleSendState parse(Object? value) => switch (value) {
    'queued' => queued,
    'sent' => sent,
    'failed' => failed,
    _ => notSent,
  };
}

class PostGoogle {
  const PostGoogle({
    this.linked = false,
    this.state = GoogleSendState.notSent,
    this.lastError,
    this.link,
    this.sentAt,
    this.inClassroom = false,
  });

  factory PostGoogle.fromJson(Map<String, dynamic> json) => PostGoogle(
    linked: json['linked'] == true,
    state: GoogleSendState.parse(json['state']),
    lastError: json['last_error'] as String?,
    link: json['link'] as String?,
    sentAt: _time(json['sent_at']),
    inClassroom: json['in_classroom'] == true,
  );

  /// The author linked the classroom to a course of theirs.
  final bool linked;
  final GoogleSendState state;
  final String? lastError;

  /// The post in Classroom (`alternateLink`).
  final String? link;
  final DateTime? sentAt;

  /// The copy exists: its files can no longer be changed (DESIGN §30.4).
  final bool inClassroom;
}

class ClassPost {
  const ClassPost({
    required this.id,
    required this.kind,
    required this.authorName,
    this.title,
    this.body,
    this.classroom,
    this.course,
    this.unit,
    this.topic,
    this.forStudents = false,
    this.publishedAt,
    this.attachments = const [],
    this.state = PostState.published,
    this.scheduledAt,
    this.studentIds = const [],
    this.batchId,
    this.sendToGoogle = true,
    this.isMine = false,
    this.google = const PostGoogle(),
  });

  factory ClassPost.fromJson(Map<String, dynamic> json) => ClassPost(
    id: json['id'] as int,
    kind: PostKind.parse(json['kind']),
    title: json['title'] as String?,
    body: json['body'] as String?,
    classroom: PostRef.parse(json['classroom']),
    course: PostRef.parse(json['course']),
    unit: PostRef.parse(json['unit'], nameKey: 'title'),
    topic: json['topic'] as String?,
    authorName:
        (json['author'] as Map<String, dynamic>?)?['name'] as String? ?? '',
    forStudents: json['audience'] == 'students',
    publishedAt: _time(json['published_at']),
    attachments: [
      for (final a in json['attachments'] as List? ?? const [])
        PostAttachment.fromJson(a as Map<String, dynamic>),
    ],
    state: PostState.parse(json['state']),
    scheduledAt: _time(json['scheduled_at']),
    studentIds: [
      for (final id in json['student_ids'] as List? ?? const []) id as int,
    ],
    batchId: json['batch_id'] as String?,
    sendToGoogle: json['send_to_google'] as bool? ?? true,
    isMine: json['is_mine'] as bool? ?? false,
    google: json['google'] is Map<String, dynamic>
        ? PostGoogle.fromJson(json['google'] as Map<String, dynamic>)
        : const PostGoogle(),
  );

  final int id;
  final PostKind kind;
  final String? title;
  final String? body;
  final PostRef? classroom;
  final PostRef? course;
  final PostRef? unit;

  /// The unit's title, or the free topic the teacher typed.
  final String? topic;
  final String authorName;

  /// Sent to chosen students only ([studentIds], teacher view).
  final bool forStudents;
  final DateTime? publishedAt;
  final List<PostAttachment> attachments;

  // Teacher view only.
  final PostState state;
  final DateTime? scheduledAt;
  final List<int> studentIds;
  final String? batchId;
  final bool sendToGoogle;
  final bool isMine;
  final PostGoogle google;

  bool get isPublished => state == PostState.published;
}

/// What the compose form sends (`POST /class-posts`, `PUT /class-posts/{id}`).
class PostDraft {
  const PostDraft({
    required this.kind,
    required this.state,
    this.title,
    this.body,
    this.courseId,
    this.unitId,
    this.topic,
    this.studentIds,
    this.scheduledAt,
    this.attachments = const [],
    this.sendToGoogle = true,
  });

  final PostKind kind;
  final PostState state;
  final String? title;
  final String? body;
  final int? courseId;
  final int? unitId;
  final String? topic;

  /// Null = the whole classroom.
  final List<int>? studentIds;
  final DateTime? scheduledAt;
  final List<PostAttachment> attachments;

  /// "ส่งไป Google Classroom ด้วย" (DESIGN §30.4), meaningful when the
  /// teacher linked the classroom.
  final bool sendToGoogle;

  Map<String, dynamic> toJson({List<int>? classroomIds}) => {
    'classroom_ids': ?classroomIds,
    if (classroomIds != null) 'kind': kind.api,
    'state': state.api,
    'title': title,
    'body': body,
    'course_id': courseId,
    'unit_id': unitId,
    'topic': unitId == null ? topic : null,
    'audience': studentIds == null ? 'all' : 'students',
    'student_ids': ?studentIds,
    'scheduled_at': scheduledAt?.toUtc().toIso8601String(),
    'send_to_google': sendToGoogle,
    'attachments': [for (final a in attachments) a.toJson()],
  };
}

class StorageUsage {
  const StorageUsage({required this.usedBytes, required this.limitBytes});

  factory StorageUsage.fromJson(Map<String, dynamic> json) => StorageUsage(
    usedBytes: (json['used_bytes'] as num?)?.toInt() ?? 0,
    limitBytes: (json['limit_bytes'] as num?)?.toInt() ?? 0,
  );

  final int usedBytes;
  final int limitBytes;

  String get label =>
      'ใช้ไป ${formatBytes(usedBytes)} จาก ${formatBytes(limitBytes)}';
}

enum FeedType { post, assignment, result }

/// One row of `GET /student/class-feed`.
class FeedItem {
  const FeedItem({
    required this.type,
    this.at,
    this.classroom,
    this.course,
    this.topic,
    this.post,
    this.assignmentId,
    this.submissionId,
    this.title = '',
    this.dueAt,
  });

  factory FeedItem.fromJson(Map<String, dynamic> json) {
    final type = switch (json['type']) {
      'assignment' => FeedType.assignment,
      'result' => FeedType.result,
      _ => FeedType.post,
    };
    final assignment = json['assignment'] as Map<String, dynamic>?;
    final result = json['result'] as Map<String, dynamic>?;
    return FeedItem(
      type: type,
      at: _time(json['at']),
      classroom: PostRef.parse(json['classroom']),
      course: PostRef.parse(json['course']),
      topic: json['topic'] as String?,
      post: json['post'] is Map<String, dynamic>
          ? ClassPost.fromJson(json['post'] as Map<String, dynamic>)
          : null,
      assignmentId: (assignment?['id'] ?? result?['assignment_id']) as int?,
      submissionId: result?['submission_id'] as int?,
      title: (assignment?['title'] ?? result?['title']) as String? ?? '',
      dueAt: _time(assignment?['due_at']),
    );
  }

  final FeedType type;
  final DateTime? at;
  final PostRef? classroom;
  final PostRef? course;
  final String? topic;
  final ClassPost? post;
  final int? assignmentId;
  final int? submissionId;

  /// The homework's title (assignment and result rows).
  final String title;
  final DateTime? dueAt;

  /// Shown under "งานของชั้นเรียน": materials and homework, not
  /// announcements or results.
  bool get isClasswork =>
      type == FeedType.assignment ||
      (type == FeedType.post && post?.kind == PostKind.material);
}
