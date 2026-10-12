import 'dart:typed_data';

import 'package:eduvision/features/class_posts/class_post_form_screen.dart';
import 'package:eduvision/features/class_posts/class_post_models.dart';
import 'package:eduvision/features/class_posts/class_posts_repository.dart';
import 'package:eduvision/features/class_posts/class_posts_screen.dart';
import 'package:eduvision/features/class_posts/post_files.dart';
import 'package:eduvision/features/class_posts/student_class_feed.dart';
import 'package:eduvision/features/classrooms/classroom.dart';
import 'package:eduvision/features/classrooms/classrooms_repository.dart';
import 'package:eduvision/features/courses/courses_repository.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../classrooms/classroom_fakes.dart';
import '../courses/course_fakes.dart';
import '../helpers/pump_screen.dart';
import 'class_post_fakes.dart';

class _FakeFiles implements PostFiles {
  final links = <String>[];
  final files = <int>[];

  @override
  Future<bool> openLink(String url) async {
    links.add(url);
    return true;
  }

  @override
  Future<String?> openFile(PostFile file) async {
    files.add(file.id);
    return null;
  }
}

List<Override> _teacher(
  FakeClassPosts posts, {
  FakeSchoolClassrooms? classrooms,
  FakeCoursesRepository? courses,
  _FakeFiles? files,
  List<PickedPostFile> picked = const [],
}) => [
  classPostsRepositoryProvider.overrideWithValue(posts),
  classroomsRepositoryProvider.overrideWithValue(
    classrooms ?? FakeSchoolClassrooms(),
  ),
  coursesRepositoryProvider.overrideWithValue(
    courses ??
        FakeCoursesRepository([
          course(
            classrooms: const [
              {'id': 7, 'name': 'ป.5/2'},
            ],
            units: const [
              {'id': 31, 'position': 1, 'title': 'เศษส่วน'},
            ],
          ),
        ]),
  ),
  postFilesProvider.overrideWithValue(files ?? _FakeFiles()),
  postFilePickerProvider.overrideWithValue(() async => picked),
];

final _linkedRoom = Classroom(
  id: 7,
  name: 'ป.5/2',
  gradeLevel: 5,
  academicYear: 2569,
  classCode: 'K7Q3M2',
  studentCount: 2,
  googleLink: const ClassroomGoogleLink(
    courseId: '612',
    courseName: 'คณิต ป.5',
  ),
);

ClassPost _withGoogle(ClassPost p, PostGoogle google) => ClassPost(
  id: p.id,
  kind: p.kind,
  title: p.title,
  body: p.body,
  classroom: p.classroom,
  course: p.course,
  authorName: p.authorName,
  publishedAt: p.publishedAt,
  attachments: p.attachments,
  state: p.state,
  isMine: p.isMine,
  google: google,
);

void _size(WidgetTester tester, [Size size = const Size(820, 2400)]) {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1;
  addTearDown(tester.view.reset);
}

final _routes = [
  GoRoute(
    path: '/classrooms/:id/posts/new',
    builder: (_, _) => const Scaffold(body: Text('compose-stub')),
  ),
  GoRoute(
    path: '/classrooms/:id/posts/:pid/edit',
    builder: (_, s) => Scaffold(body: Text('edit ${s.pathParameters['pid']}')),
  ),
];

void main() {
  group('the posts of a classroom (teacher)', () {
    testWidgets('an empty classroom invites the first post', (tester) async {
      _size(tester);
      await pumpScreen(
        tester,
        const ClassPostsScreen(classroomId: 7),
        overrides: _teacher(FakeClassPosts()),
        extraRoutes: _routes,
      );
      expect(find.text('โพสต์ · ป.5/2'), findsOneWidget);
      expect(find.text('ยังไม่มีโพสต์'), findsOneWidget);

      await tester.tap(find.text('เขียนโพสต์'));
      await tester.pumpAndSettle();
      expect(find.text('compose-stub'), findsOneWidget);
    });

    testWidgets('shows the state, the recipients and the attachments', (
      tester,
    ) async {
      _size(tester);
      final files = _FakeFiles();
      await pumpScreen(
        tester,
        const ClassPostsScreen(classroomId: 7),
        overrides: _teacher(
          FakeClassPosts(
            posts: [
              announcementFixture(state: PostState.draft, studentIds: [502]),
              materialFixture(),
              announcementFixture(id: 3, body: 'ของครูอีกคน', isMine: false),
            ],
          ),
          files: files,
        ),
        extraRoutes: _routes,
      );
      expect(find.text('ฉบับร่าง'), findsOneWidget);
      expect(find.text('เฉพาะ 1 คน'), findsOneWidget);
      expect(find.text('ใบความรู้ที่ 1'), findsOneWidget);
      expect(find.text('เศษส่วน'), findsOneWidget);
      expect(find.text('340 KB'), findsOneWidget);
      // Only the author gets the menu.
      expect(find.byKey(const ValueKey('post_menu_1')), findsOneWidget);
      expect(find.byKey(const ValueKey('post_menu_3')), findsNothing);

      await tester.tap(find.text('ใบความรู้.pdf'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('วิดีโออธิบาย'));
      await tester.pumpAndSettle();
      expect(files.files, [5]);
      expect(files.links, ['https://youtu.be/abc']);
    });

    testWidgets('a draft is published or deleted from its menu', (
      tester,
    ) async {
      _size(tester);
      final posts = FakeClassPosts(
        posts: [announcementFixture(state: PostState.draft)],
      );
      await pumpScreen(
        tester,
        const ClassPostsScreen(classroomId: 7),
        overrides: _teacher(posts),
        extraRoutes: _routes,
      );

      await tester.tap(find.byKey(const ValueKey('post_menu_1')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('เผยแพร่ตอนนี้'));
      await tester.pumpAndSettle();
      expect(posts.updated.single.$1, 1);
      expect(posts.updated.single.$2.state, PostState.published);
      expect(posts.updated.single.$2.body, 'พรุ่งนี้สอบย่อย เตรียมดินสอ 2B');

      await tester.tap(find.byKey(const ValueKey('post_menu_1')));
      await tester.pumpAndSettle();
      await tester.tap(find.text('ลบ'));
      await tester.pumpAndSettle();
      expect(find.text('ลบโพสต์นี้?'), findsOneWidget);
      await tester.tap(find.widgetWithText(FilledButton, 'ลบ'));
      await tester.pumpAndSettle();
      expect(posts.deleted, [1]);
      expect(find.text('ยังไม่มีโพสต์'), findsOneWidget);
    });

    testWidgets('a closed classroom is read-only', (tester) async {
      _size(tester);
      await pumpScreen(
        tester,
        const ClassPostsScreen(classroomId: 7),
        overrides: _teacher(
          FakeClassPosts(posts: [announcementFixture()]),
          classrooms: FakeSchoolClassrooms(
            open: [],
            closed: [room(closedAt: DateTime.utc(2026, 4, 1))],
          ),
        ),
        extraRoutes: _routes,
      );
      expect(find.text('เขียนโพสต์'), findsNothing);
      expect(find.byKey(const ValueKey('post_menu_1')), findsNothing);
    });

    testWidgets('the Classroom copy: open it, or send a failed one again', (
      tester,
    ) async {
      _size(tester);
      final files = _FakeFiles();
      final posts = FakeClassPosts(
        posts: [
          _withGoogle(
            announcementFixture(id: 1),
            const PostGoogle(
              linked: true,
              state: GoogleSendState.sent,
              link: 'https://classroom.google.com/c/1/p/9',
              inClassroom: true,
            ),
          ),
          _withGoogle(
            announcementFixture(id: 2, body: 'ส่งไม่ผ่าน'),
            const PostGoogle(
              linked: true,
              state: GoogleSendState.failed,
              lastError: 'ต้องเชื่อมบัญชี Google ใหม่ แล้วกดส่งอีกครั้ง',
            ),
          ),
          _withGoogle(
            announcementFixture(id: 3, body: 'ของครูอีกคน', isMine: false),
            const PostGoogle(linked: true, state: GoogleSendState.failed),
          ),
        ],
      );
      await pumpScreen(
        tester,
        const ClassPostsScreen(classroomId: 7),
        overrides: _teacher(posts, files: files),
        extraRoutes: _routes,
      );
      expect(find.text('อยู่ใน Classroom แล้ว'), findsOneWidget);
      expect(find.text('ส่งไป Classroom ไม่สำเร็จ'), findsNWidgets(2));
      expect(find.byKey(const ValueKey('post_google_error_2')), findsOneWidget);
      // Only the author sends again.
      expect(find.byKey(const ValueKey('post_google_retry_2')), findsOneWidget);
      expect(find.byKey(const ValueKey('post_google_retry_3')), findsNothing);

      await tester.tap(find.byKey(const ValueKey('post_open_classroom_1')));
      await tester.pumpAndSettle();
      expect(files.links, ['https://classroom.google.com/c/1/p/9']);

      await tester.tap(find.byKey(const ValueKey('post_google_retry_2')));
      await tester.pumpAndSettle();
      expect(posts.googleRetries, [2]);
      expect(find.text('ส่งไป Google Classroom แล้ว'), findsOneWidget);
    });

    for (final width in [390.0, 820.0, 1440.0]) {
      testWidgets('fits a $width px window', (tester) async {
        _size(tester, Size(width, 900));
        await pumpScreen(
          tester,
          const ClassPostsScreen(classroomId: 7),
          overrides: _teacher(
            FakeClassPosts(
              posts: [
                materialFixture(),
                announcementFixture(body: 'ก' * 600),
              ],
            ),
          ),
          extraRoutes: _routes,
        );
        expect(tester.takeException(), isNull);
      });
    }
  });

  group('writing a post', () {
    Future<FakeClassPosts> pump(
      WidgetTester tester, {
      FakeClassPosts? posts,
      int? postId,
      FakeSchoolClassrooms? classrooms,
      List<PickedPostFile> picked = const [],
    }) async {
      _size(tester);
      final fake = posts ?? FakeClassPosts();
      await pumpScreen(
        tester,
        ClassPostFormScreen(classroomId: 7, postId: postId),
        overrides: _teacher(fake, classrooms: classrooms, picked: picked),
      );
      return fake;
    }

    Future<void> submit(WidgetTester tester) async {
      final button = find.byKey(const ValueKey('post_submit'));
      await tester.ensureVisible(button);
      await tester.tap(button);
      await tester.pumpAndSettle();
    }

    testWidgets('an announcement needs text or an attachment', (tester) async {
      final posts = await pump(tester);
      await submit(tester);
      expect(
        find.text('พิมพ์ข้อความหรือแนบไฟล์อย่างน้อยหนึ่งอย่าง'),
        findsOneWidget,
      );
      expect(posts.created, isEmpty);

      await tester.enterText(
        find.byKey(const ValueKey('post_body')),
        ' พรุ่งนี้สอบย่อย ',
      );
      await submit(tester);
      final (classrooms, draft) = posts.created.single;
      expect(classrooms, [7]);
      expect(draft.kind, PostKind.announcement);
      expect(draft.state, PostState.published);
      expect(draft.body, 'พรุ่งนี้สอบย่อย');
      expect(draft.title, isNull);
      expect(draft.studentIds, isNull);
      expect(draft.toJson(classroomIds: classrooms)['audience'], 'all');
      expect(find.text('stub-home'), findsOneWidget, reason: 'the form closes');
    });

    testWidgets('a material takes a title, a course unit, a file and a link', (
      tester,
    ) async {
      final posts = await pump(
        tester,
        picked: [
          (name: 'ใบงาน.pdf', bytes: Uint8List.fromList([1, 2, 3])),
        ],
      );
      await tester.tap(find.text('เอกสารประกอบ'));
      await tester.pumpAndSettle();
      await submit(tester);
      expect(find.text('กรอกชื่อเรื่อง'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('post_title')),
        'ใบความรู้ที่ 1',
      );
      await tester.tap(find.byKey(const ValueKey('post_attach_file')));
      await tester.pumpAndSettle();
      expect(posts.uploaded, ['ใบงาน.pdf']);
      expect(find.text('ใบงาน.pdf'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('post_attach_link')));
      await tester.pumpAndSettle();
      await tester.enterText(
        find.byKey(const ValueKey('link_url')),
        'youtu.be',
      );
      await tester.tap(find.byKey(const ValueKey('link_add')));
      await tester.pumpAndSettle();
      expect(
        find.text('ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://'),
        findsOneWidget,
      );
      await tester.enterText(
        find.byKey(const ValueKey('link_url')),
        'https://youtu.be/abc',
      );
      await tester.tap(find.byKey(const ValueKey('link_add')));
      await tester.pumpAndSettle();

      await tester.ensureVisible(find.byKey(const ValueKey('post_course')));
      await tester.tap(find.byKey(const ValueKey('post_course')));
      await tester.pumpAndSettle();
      await tester.tap(find.textContaining('ค15101').last);
      await tester.pumpAndSettle();
      await tester.tap(find.text('ไม่มีหัวข้อ'));
      await tester.pumpAndSettle();
      await tester.tap(find.text('เศษส่วน').last);
      await tester.pumpAndSettle();
      await submit(tester);

      final draft = posts.created.single.$2;
      expect(draft.kind, PostKind.material);
      expect(draft.title, 'ใบความรู้ที่ 1');
      expect(draft.courseId, 4);
      expect(draft.unitId, 31);
      expect(draft.topic, isNull);
      expect(draft.attachments.map((a) => a.title), [
        'ใบงาน.pdf',
        'https://youtu.be/abc',
      ]);
      expect(draft.toJson()['attachments'], [
        {'file_id': 900, 'title': 'ใบงาน.pdf'},
        {'url': 'https://youtu.be/abc', 'title': 'https://youtu.be/abc'},
      ]);
    });

    testWidgets('chosen students, a draft and other classrooms', (
      tester,
    ) async {
      final posts = await pump(
        tester,
        classrooms: FakeSchoolClassrooms(
          open: [
            room(),
            room(id: 8, name: 'ป.5/3'),
          ],
        ),
      );
      await tester.enterText(
        find.byKey(const ValueKey('post_body')),
        'งานซ่อม',
      );
      expect(find.byKey(const ValueKey('post_also_8')), findsOneWidget);

      await tester.ensureVisible(find.text('เลือกนักเรียน'));
      await tester.tap(find.text('เลือกนักเรียน'));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('pick_student_503')));
      await tester.pumpAndSettle();
      await tester.tap(find.byKey(const ValueKey('pick_students_done')));
      await tester.pumpAndSettle();
      expect(find.text('นักเรียน 1 คน · แก้ไข'), findsOneWidget);
      // Single students belong to one classroom.
      expect(find.byKey(const ValueKey('post_also_8')), findsNothing);

      await tester.ensureVisible(find.text('ฉบับร่าง'));
      await tester.tap(find.text('ฉบับร่าง'));
      await tester.pumpAndSettle();
      await submit(tester);

      final (classrooms, draft) = posts.created.single;
      expect(classrooms, [7]);
      expect(draft.state, PostState.draft);
      expect(draft.studentIds, [503]);
      expect(draft.toJson(classroomIds: classrooms)['audience'], 'students');
    });

    testWidgets('one post goes to several classrooms', (tester) async {
      final posts = await pump(
        tester,
        classrooms: FakeSchoolClassrooms(
          open: [
            room(),
            room(id: 8, name: 'ป.5/3'),
          ],
        ),
      );
      await tester.enterText(
        find.byKey(const ValueKey('post_body')),
        'หยุดเรียน',
      );
      await tester.ensureVisible(find.byKey(const ValueKey('post_also_8')));
      await tester.tap(find.byKey(const ValueKey('post_also_8')));
      await tester.pumpAndSettle();
      await submit(tester);
      expect(posts.created.single.$1, [7, 8]);
    });

    testWidgets('editing a published post keeps it published', (tester) async {
      final posts = await pump(
        tester,
        posts: FakeClassPosts(posts: [materialFixture()]),
        postId: 2,
      );
      expect(find.text('แก้ไขโพสต์'), findsOneWidget);
      expect(find.byKey(const ValueKey('post_kind')), findsNothing);
      expect(find.byKey(const ValueKey('post_state')), findsNothing);
      expect(find.text('ใบความรู้.pdf'), findsOneWidget);

      await tester.enterText(
        find.byKey(const ValueKey('post_title')),
        'ใบความรู้ที่ 1 (แก้)',
      );
      await submit(tester);
      final (id, draft) = posts.updated.single;
      expect(id, 2);
      expect(draft.state, PostState.published);
      expect(draft.title, 'ใบความรู้ที่ 1 (แก้)');
      expect(draft.attachments, hasLength(2));
      expect(draft.toJson().containsKey('classroom_ids'), isFalse);
      expect(draft.toJson().containsKey('kind'), isFalse);
    });

    testWidgets('a linked classroom offers "ส่งไป Google Classroom ด้วย"', (
      tester,
    ) async {
      final posts = await pump(
        tester,
        classrooms: FakeSchoolClassrooms(open: [_linkedRoom]),
      );
      expect(find.byKey(const ValueKey('post_send_google')), findsOneWidget);
      expect(
        find.text('คอร์ส คณิต ป.5 ที่คุณผูกไว้กับห้องนี้'),
        findsOneWidget,
      );
      await tester.enterText(find.byKey(const ValueKey('post_body')), 'x');
      await submit(tester);
      expect(posts.created.single.$2.sendToGoogle, isTrue);
      expect(posts.created.single.$2.toJson()['send_to_google'], isTrue);
    });

    testWidgets('the checkbox switched off keeps the post in Krucheck only', (
      tester,
    ) async {
      final posts = await pump(
        tester,
        classrooms: FakeSchoolClassrooms(open: [_linkedRoom]),
      );
      await tester.enterText(find.byKey(const ValueKey('post_body')), 'x');
      await tester.ensureVisible(
        find.byKey(const ValueKey('post_send_google')),
      );
      await tester.tap(find.byKey(const ValueKey('post_send_google')));
      await tester.pumpAndSettle();
      await submit(tester);
      expect(posts.created.single.$2.sendToGoogle, isFalse);
    });

    testWidgets('an unlinked classroom has no checkbox', (tester) async {
      await pump(tester);
      expect(find.byKey(const ValueKey('post_send_google')), findsNothing);
    });

    testWidgets('the files of a copy in Classroom are locked', (tester) async {
      await pump(
        tester,
        posts: FakeClassPosts(
          posts: [
            _withGoogle(
              materialFixture(),
              const PostGoogle(
                linked: true,
                state: GoogleSendState.sent,
                inClassroom: true,
              ),
            ),
          ],
        ),
        postId: 2,
        classrooms: FakeSchoolClassrooms(open: [_linkedRoom]),
      );
      expect(find.byKey(const ValueKey('post_files_locked')), findsOneWidget);
      expect(find.byKey(const ValueKey('post_attach_file')), findsNothing);
      expect(find.byKey(const ValueKey('post_attach_link')), findsNothing);
      expect(find.byTooltip('เอาออก'), findsNothing);
      expect(find.text('ใบความรู้.pdf'), findsOneWidget);
    });

    testWidgets('a server refusal stays on the form', (tester) async {
      final posts = FakeClassPosts()..error = Exception('boom');
      await pump(tester, posts: posts);
      await tester.enterText(find.byKey(const ValueKey('post_body')), 'x');
      await submit(tester);
      expect(find.byKey(const ValueKey('post_error')), findsOneWidget);
      expect(find.byKey(const ValueKey('post_submit')), findsOneWidget);
    });

    for (final width in [390.0, 820.0, 1440.0]) {
      testWidgets('fits a $width px window', (tester) async {
        await pump(tester);
        tester.view.physicalSize = Size(width, 900);
        await tester.pumpAndSettle();
        await tester.tap(find.text('เอกสารประกอบ'));
        await tester.pumpAndSettle();
        expect(tester.takeException(), isNull);
      });
    }
  });

  group('the student feed', () {
    final feed = [
      FeedItem(
        type: FeedType.post,
        at: DateTime.utc(2026, 10, 12, 2),
        post: announcementFixture(isMine: false),
      ),
      FeedItem(
        type: FeedType.assignment,
        at: DateTime.utc(2026, 10, 11, 5),
        course: const PostRef(id: 7, name: 'คณิตศาสตร์ 5', code: 'ค15101'),
        assignmentId: 41,
        title: 'เศษส่วน ชุดที่ 1',
        dueAt: DateTime.utc(2026, 10, 15, 9),
      ),
      FeedItem(
        type: FeedType.post,
        at: DateTime.utc(2026, 10, 11, 2),
        topic: 'เศษส่วน',
        post: materialFixture(),
      ),
      FeedItem(
        type: FeedType.result,
        at: DateTime.utc(2026, 10, 10, 2),
        submissionId: 88,
        assignmentId: 40,
        title: 'การบวก',
      ),
    ];
    final routes = [
      GoRoute(
        path: '/student/assignments/:aid/hand-in',
        builder: (_, s) => Text('hand-in ${s.pathParameters['aid']}'),
      ),
      GoRoute(
        path: '/student/results/:sid',
        builder: (_, s) => Text('result ${s.pathParameters['sid']}'),
      ),
      GoRoute(
        path: '/student/posts/:id',
        builder: (_, s) => Text('post ${s.pathParameters['id']}'),
      ),
    ];

    testWidgets('the stream lists everything and opens homework and results', (
      tester,
    ) async {
      _size(tester);
      final posts = FakeClassPosts(feed: feed);
      await pumpScreen(
        tester,
        const Scaffold(body: StudentClassFeedBody(courseId: 7, classroomId: 1)),
        overrides: [
          classPostsRepositoryProvider.overrideWithValue(posts),
          postFilesProvider.overrideWithValue(_FakeFiles()),
        ],
        extraRoutes: routes,
      );
      expect(posts.feedKeys, [(courseId: 7, classroomId: 1)]);
      expect(find.text('พรุ่งนี้สอบย่อย เตรียมดินสอ 2B'), findsOneWidget);
      expect(find.text('การบ้าน: เศษส่วน ชุดที่ 1'), findsOneWidget);
      expect(find.text('ใบความรู้ที่ 1'), findsOneWidget);
      expect(find.text('ผลตรวจ: การบวก'), findsOneWidget);
      // A student never sees the teacher's state or menu.
      expect(find.byType(PopupMenuButton<String>), findsNothing);

      await tester.tap(find.byKey(const ValueKey('feed_assignment_41')));
      await tester.pumpAndSettle();
      expect(find.text('hand-in 41'), findsOneWidget);
    });

    testWidgets('"งานของชั้นเรียน" groups materials and homework by topic', (
      tester,
    ) async {
      _size(tester);
      await pumpScreen(
        tester,
        const Scaffold(body: StudentClassFeedBody(classroomId: 1)),
        overrides: [
          classPostsRepositoryProvider.overrideWithValue(
            FakeClassPosts(feed: feed),
          ),
          postFilesProvider.overrideWithValue(_FakeFiles()),
        ],
        extraRoutes: routes,
      );
      await tester.tap(find.text('งานของชั้นเรียน'));
      await tester.pumpAndSettle();

      expect(find.byKey(const ValueKey('feed_topic_เศษส่วน')), findsOneWidget);
      expect(find.byKey(const ValueKey('feed_topic_')), findsOneWidget);
      expect(find.text('ใบความรู้ที่ 1'), findsOneWidget);
      expect(find.text('การบ้าน: เศษส่วน ชุดที่ 1'), findsOneWidget);
      // Announcements and results are stream-only.
      expect(find.text('พรุ่งนี้สอบย่อย เตรียมดินสอ 2B'), findsNothing);
      expect(find.text('ผลตรวจ: การบวก'), findsNothing);
    });

    testWidgets('an empty feed says so', (tester) async {
      _size(tester);
      await pumpScreen(
        tester,
        const Scaffold(body: StudentClassFeedBody(classroomId: 1)),
        overrides: [
          classPostsRepositoryProvider.overrideWithValue(FakeClassPosts()),
        ],
      );
      expect(find.text('ยังไม่มีโพสต์'), findsOneWidget);
    });

    testWidgets('"โพสต์ล่าสุด" shows the newest posts and opens one', (
      tester,
    ) async {
      _size(tester);
      await pumpScreen(
        tester,
        const Scaffold(body: LatestPostsCard()),
        overrides: [
          classPostsRepositoryProvider.overrideWithValue(
            FakeClassPosts(feed: feed),
          ),
        ],
        extraRoutes: routes,
      );
      expect(find.text('โพสต์ล่าสุด'), findsOneWidget);
      expect(find.byKey(const ValueKey('latest_post_1')), findsOneWidget);
      expect(find.byKey(const ValueKey('latest_post_2')), findsOneWidget);
      expect(find.textContaining('การบวก'), findsNothing);

      await tester.tap(find.byKey(const ValueKey('latest_post_2')));
      await tester.pumpAndSettle();
      expect(find.text('post 2'), findsOneWidget);
    });

    testWidgets('one post opens on its own page', (tester) async {
      _size(tester);
      await pumpScreen(
        tester,
        const StudentClassPostScreen(postId: 2),
        overrides: [
          classPostsRepositoryProvider.overrideWithValue(
            FakeClassPosts(feed: feed),
          ),
          postFilesProvider.overrideWithValue(_FakeFiles()),
        ],
      );
      expect(find.text('เอกสารประกอบ'), findsOneWidget);
      expect(find.text('ใบความรู้ที่ 1'), findsOneWidget);
      expect(find.textContaining('ป.5/1'), findsOneWidget);
    });

    for (final width in [390.0, 820.0, 1440.0]) {
      testWidgets('fits a $width px window', (tester) async {
        _size(tester, Size(width, 900));
        await pumpScreen(
          tester,
          const Scaffold(body: StudentClassFeedBody(classroomId: 1)),
          overrides: [
            classPostsRepositoryProvider.overrideWithValue(
              FakeClassPosts(feed: feed),
            ),
            postFilesProvider.overrideWithValue(_FakeFiles()),
          ],
          extraRoutes: routes,
        );
        expect(tester.takeException(), isNull);
      });
    }
  });

  test('a post reads the API payload of a teacher and of a student', () {
    final teacher = ClassPost.fromJson({
      'id': 9,
      'kind': 'material',
      'title': 'ใบความรู้',
      'body': null,
      'classroom': {
        'id': 1,
        'name': 'ป.5/1',
        'academic_year': 2569,
        'closed': false,
      },
      'course': {'id': 7, 'code': 'ค15101', 'name': 'คณิตศาสตร์ 5'},
      'unit': {'id': 31, 'title': 'เศษส่วน'},
      'topic': 'เศษส่วน',
      'author': {'id': 2, 'name': 'ครูสมศรี'},
      'audience': 'students',
      'published_at': null,
      'attachments': [
        {
          'id': 1,
          'kind': 'link',
          'title': 'อ่านเพิ่ม',
          'url': 'https://example.com',
          'file': null,
        },
      ],
      'state': 'scheduled',
      'scheduled_at': '2026-10-20T01:00:00+00:00',
      'student_ids': [502, 503],
      'batch_id': null,
      'send_to_google': false,
      'is_mine': true,
    });
    expect(teacher.kind, PostKind.material);
    expect(teacher.state, PostState.scheduled);
    expect(teacher.course?.label, 'ค15101 คณิตศาสตร์ 5');
    expect(teacher.unit?.name, 'เศษส่วน');
    expect(teacher.studentIds, [502, 503]);
    expect(teacher.sendToGoogle, isFalse);
    expect(teacher.attachments.single.isLink, isTrue);
    expect(teacher.google.state, GoogleSendState.notSent);

    final failed = ClassPost.fromJson({
      'id': 10,
      'kind': 'announcement',
      'author': {'id': 2, 'name': 'ครูสมศรี'},
      'audience': 'all',
      'attachments': <Object?>[],
      'google': {
        'linked': true,
        'state': 'failed',
        'last_error': 'ติดต่อ Google ไม่ได้',
        'link': null,
        'sent_at': null,
        'in_classroom': false,
      },
    });
    expect(failed.google.linked, isTrue);
    expect(failed.google.state, GoogleSendState.failed);
    expect(failed.google.lastError, 'ติดต่อ Google ไม่ได้');
    expect(failed.google.inClassroom, isFalse);

    final student = ClassPost.fromJson({
      'id': 9,
      'kind': 'announcement',
      'author': {'id': 2, 'name': 'ครูสมศรี'},
      'audience': 'all',
      'published_at': '2026-10-12T02:00:00+00:00',
      'attachments': <Object?>[],
    });
    expect(student.isPublished, isTrue);
    expect(student.forStudents, isFalse);
    expect(formatBytes(340 * 1024), '340 KB');
    expect(formatBytes(3 * 1024 * 1024 ~/ 2), '1.5 MB');
  });
}
