import 'dart:async';
import 'dart:typed_data';

import 'package:dio/dio.dart';

import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/live/live_session.dart';
import 'package:eduvision/core/live/live_token_api.dart';
import 'package:eduvision/features/notices/notice.dart';
import 'package:eduvision/features/notices/notices_providers.dart';
import 'package:eduvision/features/notices/notices_repository.dart';
import 'package:eduvision/features/notices/notices_screen.dart';
import 'package:fake_cloud_firestore/fake_cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

import '../helpers/pump_screen.dart';

/// A live layer whose Firebase session is whatever uid the test says.
class _Live implements LiveSession {
  _Live({required this.db, this.uid});

  final FirebaseFirestore? db;
  final String? uid;
  @override
  bool get enabled => true;

  @override
  FirebaseFirestore? get firestore => db;
  @override
  String? get currentUid => uid;
  @override
  Stream<String?> get uidChanges => Stream.value(uid);
  @override
  Future<LiveSignInOutcome> signIn(
    int userId,
    FetchFirebaseToken fetchToken,
  ) async => LiveSignInOutcome.alreadySignedIn;
  @override
  Future<void> signOut() async {}
}

class _Repo implements NoticesRepository {
  final marked = <List<String>>[];

  @override
  Future<void> markRead(List<String> ids) async => marked.add(ids);
}

const _teacher = User(id: 12, name: 'ครูเอ', role: 'teacher');
const _student = User(id: 11, name: 'ด.ญ. เอ', role: 'student');

Future<void> _seed(
  FakeFirebaseFirestore db,
  String uid,
  String id, {
  required String kind,
  required String body,
  required Map<String, String> data,
  required DateTime at,
  bool read = false,
}) => db.collection('users/$uid/notices').doc(id).set({
  'kind': kind,
  'title': 'Krucheck',
  'body': body,
  'data': data,
  'read': read,
  'created_at': Timestamp.fromDate(at),
  'expires_at': Timestamp.fromDate(at.add(const Duration(days: 90))),
});

void main() {
  late FakeFirebaseFirestore db;
  late _Repo repo;

  setUp(() async {
    db = FakeFirebaseFirestore();
    repo = _Repo();
    await _seed(
      db,
      'u12',
      '01aaaaaaaaaaaaaaaaaaaaaaaa',
      kind: 'grading_done',
      body: 'ตรวจการบ้าน "เศษส่วน" เสร็จแล้ว',
      data: {'type': 'grading_done', 'assignment_id': '7'},
      at: DateTime.utc(2026, 10, 21, 3, 0),
    );
    await _seed(
      db,
      'u12',
      '01bbbbbbbbbbbbbbbbbbbbbbbb',
      kind: 'appeal_opened',
      body: 'มีคำขอให้ตรวจใหม่ 2 รายการ',
      data: {'type': 'appeal_opened'},
      at: DateTime.utc(2026, 10, 20, 3, 0),
      read: true,
    );
    // Someone else's inbox never shows.
    await _seed(
      db,
      'u99',
      '01cccccccccccccccccccccccc',
      kind: 'class_post',
      body: 'ของคนอื่น',
      data: {'type': 'class_post'},
      at: DateTime.utc(2026, 10, 21),
    );
  });

  List<Override> overrides(LiveSession live, User user) => [
    liveSessionProvider.overrideWithValue(live),
    currentUserProvider.overrideWithValue(user),
    noticesRepositoryProvider.overrideWithValue(repo),
  ];

  testWidgets('lists the inbox newest first, marks read on tap and routes', (
    tester,
  ) async {
    final opened = <String>[];
    final container = await pumpScreen(
      tester,
      const NoticesScreen(),
      overrides: overrides(_Live(db: db, uid: 'u12'), _teacher),
      extraRoutes: [
        GoRoute(
          path: '/assignments/:id/review',
          builder: (context, state) {
            opened.add(state.uri.path);
            return const Scaffold(body: Text('review-stub'));
          },
        ),
      ],
    );
    await tester.pumpAndSettle();

    expect(container.read(unreadNoticesProvider), 1);
    final tiles = find.byType(ListTile);
    expect(tiles, findsNWidgets(2));
    expect(
      tester.widget<ListTile>(tiles.first).key,
      const ValueKey('notice_01aaaaaaaaaaaaaaaaaaaaaaaa'),
    );
    expect(find.text('ตรวจการบ้าน "เศษส่วน" เสร็จแล้ว'), findsOneWidget);
    expect(find.text('ของคนอื่น'), findsNothing);
    expect(
      find.byKey(const ValueKey('notice_unread_01aaaaaaaaaaaaaaaaaaaaaaaa')),
      findsOneWidget,
    );
    expect(
      find.byKey(const ValueKey('notice_unread_01bbbbbbbbbbbbbbbbbbbbbbbb')),
      findsNothing,
    );
    expect(find.textContaining('21 ต.ค. 2569'), findsOneWidget);

    await tester.tap(find.text('ตรวจการบ้าน "เศษส่วน" เสร็จแล้ว'));
    await tester.pumpAndSettle();

    expect(repo.marked, [
      ['01aaaaaaaaaaaaaaaaaaaaaaaa'],
    ]);
    expect(opened, ['/assignments/7/review']);
  });

  testWidgets('"อ่านทั้งหมด" sends every unread id once and dims them', (
    tester,
  ) async {
    await _seed(
      db,
      'u12',
      '01dddddddddddddddddddddddd',
      kind: 'class_post',
      body: 'โพสต์ใหม่',
      data: {'type': 'class_post', 'post_id': '3', 'classroom_id': '1'},
      at: DateTime.utc(2026, 10, 19),
    );
    await pumpScreen(
      tester,
      const NoticesScreen(),
      overrides: overrides(_Live(db: db, uid: 'u12'), _teacher),
    );
    await tester.pumpAndSettle();

    await tester.tap(find.byKey(const ValueKey('notices_read_all')));
    await tester.pumpAndSettle();

    expect(repo.marked, [
      ['01aaaaaaaaaaaaaaaaaaaaaaaa', '01dddddddddddddddddddddddd'],
    ]);
    expect(find.byKey(const ValueKey('notices_read_all')), findsNothing);
    expect(find.byIcon(Icons.circle), findsNothing);

    // A second tap on a dimmed tile asks the server nothing more.
    await tester.tap(find.text('โพสต์ใหม่'));
    await tester.pumpAndSettle();
    expect(repo.marked.length, 1);
  });

  testWidgets('a new document arrives live', (tester) async {
    final container = await pumpScreen(
      tester,
      const NoticesScreen(),
      overrides: overrides(_Live(db: db, uid: 'u12'), _teacher),
    );
    await tester.pumpAndSettle();
    expect(container.read(unreadNoticesProvider), 1);

    await _seed(
      db,
      'u12',
      '01eeeeeeeeeeeeeeeeeeeeeeee',
      kind: 'course_request',
      body: 'มีคำขอผูกรายวิชา',
      data: {'type': 'course_request'},
      at: DateTime.utc(2026, 10, 22),
    );
    await tester.pumpAndSettle();

    expect(find.text('มีคำขอผูกรายวิชา'), findsOneWidget);
    expect(container.read(unreadNoticesProvider), 2);
    expect(
      tester.widget<ListTile>(find.byType(ListTile).first).key,
      const ValueKey('notice_01eeeeeeeeeeeeeeeeeeeeeeee'),
    );
  });

  testWidgets('an empty inbox and a student route', (tester) async {
    await pumpScreen(
      tester,
      const NoticesScreen(),
      overrides: overrides(_Live(db: db, uid: 'u11'), _student),
    );
    await tester.pumpAndSettle();
    expect(find.text('ยังไม่มีการแจ้งเตือน'), findsOneWidget);
    expect(find.byKey(const ValueKey('notices_read_all')), findsNothing);
  });

  testWidgets('explains when Firebase is off in this build', (tester) async {
    final container = await pumpScreen(
      tester,
      const NoticesScreen(),
      overrides: overrides(const DisabledLiveSession(), _teacher),
    );
    await tester.pumpAndSettle();
    expect(find.text('กล่องแจ้งเตือนยังไม่พร้อม'), findsOneWidget);
    expect(find.textContaining('ไม่ได้ตั้งค่า Firebase'), findsOneWidget);
    expect(container.read(unreadNoticesProvider), 0);
  });

  testWidgets('explains when the server has no Firestore (no Firebase uid)', (
    tester,
  ) async {
    await pumpScreen(
      tester,
      const NoticesScreen(),
      overrides: overrides(_Live(db: db, uid: null), _teacher),
    );
    await tester.pumpAndSettle();
    expect(find.textContaining('ยังไม่ได้เปิดข้อมูลสด'), findsOneWidget);
  });

  testWidgets('another user\'s Firebase session shows nothing of theirs', (
    tester,
  ) async {
    // Firebase still holds u12 while u11 is signed in to Krucheck.
    await pumpScreen(
      tester,
      const NoticesScreen(),
      overrides: overrides(_Live(db: db, uid: 'u12'), _student),
    );
    await tester.pumpAndSettle();
    expect(find.text('ตรวจการบ้าน "เศษส่วน" เสร็จแล้ว'), findsNothing);
    expect(find.text('กล่องแจ้งเตือนยังไม่พร้อม'), findsOneWidget);
  });

  test('Notice.fromDoc tolerates missing and odd fields', () async {
    final ref = db.collection('users/u1/notices').doc('x');
    await ref.set({
      'data': {'type': 'grading_done', 'assignment_id': 5},
    });
    final notice = Notice.fromDoc(await ref.get());
    expect(notice.kind, '');
    expect(notice.title, 'Krucheck');
    expect(notice.read, isFalse);
    expect(notice.data, {'type': 'grading_done', 'assignment_id': '5'});
    expect(notice.copyWith(read: true).read, isTrue);
  });

  test('markRead sends the ids in chunks of 100', () async {
    final sent = <List<dynamic>>[];
    final dio = _recordingDio((data) => sent.add(data['ids'] as List));
    await ApiNoticesRepository(dio).markRead([]);
    await ApiNoticesRepository(dio).markRead(List.generate(150, (i) => 'id$i'));
    expect(sent.map((l) => l.length), [100, 50]);
  });
}

Dio _recordingDio(void Function(Map<String, dynamic>) onPost) {
  final dio = Dio(BaseOptions(baseUrl: 'http://x'));
  dio.httpClientAdapter = _Adapter(onPost);
  return dio;
}

class _Adapter implements HttpClientAdapter {
  _Adapter(this.onPost);
  final void Function(Map<String, dynamic>) onPost;

  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async {
    onPost(options.data as Map<String, dynamic>);
    return ResponseBody.fromString(
      '{"data":{"queued":1}}',
      202,
      headers: {
        'content-type': ['application/json'],
      },
    );
  }

  @override
  void close({bool force = false}) {}
}
