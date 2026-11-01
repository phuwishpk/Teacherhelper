import 'package:eduvision/core/live/live_scope.dart';
import 'package:eduvision/core/widgets/live_badge.dart';
import 'package:eduvision/features/attendance/attendance_models.dart';
import 'package:eduvision/features/attendance/attendance_repository.dart';
import 'package:eduvision/features/attendance/today_attendance_card.dart';
import 'package:eduvision/features/class_posts/class_post_models.dart';
import 'package:eduvision/features/class_posts/class_posts_repository.dart';
import 'package:eduvision/features/class_posts/student_class_feed.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

import '../class_posts/class_post_fakes.dart';
import '../helpers/pump_screen.dart';

final _today = DateTime(2026, 11, 1);
const _viewports = [Size(390, 844), Size(820, 1180), Size(1440, 900)];

void _setViewport(WidgetTester tester, Size size) {
  tester.view.devicePixelRatio = 1;
  tester.view.physicalSize = size;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);
}

MyAttendanceCourse _attendanceCourse(DateTime heldOn) => MyAttendanceCourse(
  courseId: 7,
  courseTitle: 'ค15101 คณิตศาสตร์ 5',
  classroomName: 'ป.5/1',
  counts: const AttendanceCounts({}),
  counted: 1,
  sessions: [
    MyAttendanceEntry(
      heldOn: heldOn,
      periodNo: 2,
      status: AttendanceStatus.late,
    ),
  ],
);

List<FeedItem> _feedItems(int count) => [
  for (var id = 1; id <= count; id++)
    FeedItem(
      type: FeedType.assignment,
      assignmentId: id,
      title: 'งานชิ้นที่ $id',
    ),
];

Future<void> _pumpAttendanceCard(
  WidgetTester tester,
  List<MyAttendanceCourse> courses,
) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        myAttendanceProvider.overrideWith((ref) async => courses),
        liveAvailableProvider.overrideWithValue(false),
      ],
      child: MaterialApp(
        home: Scaffold(body: TodayAttendanceCard(now: _today)),
      ),
    ),
  );
  await tester.pumpAndSettle();
}

Future<ProviderContainer> _pumpFeed(
  WidgetTester tester,
  FakeClassPosts posts,
) => pumpScreen(
  tester,
  const Scaffold(body: StudentClassFeedBody(courseId: 7, classroomId: 1)),
  overrides: [
    classPostsRepositoryProvider.overrideWithValue(posts),
    liveAvailableProvider.overrideWithValue(false),
  ],
);

void main() {
  testWidgets('LiveBadge is visible only while live updates are available', (
    tester,
  ) async {
    Widget badge(bool available) => ProviderScope(
      overrides: [liveAvailableProvider.overrideWithValue(available)],
      child: const MaterialApp(
        home: Scaffold(
          body: Row(
            key: ValueKey('badge_row'),
            children: [LiveBadge(), Text('ถัดไป')],
          ),
        ),
      ),
    );

    await tester.pumpWidget(badge(true));
    expect(find.byKey(const ValueKey('live_badge')), findsOneWidget);

    await tester.pumpWidget(badge(false));
    expect(find.byKey(const ValueKey('live_badge')), findsNothing);
    expect(find.text('อัปเดตสด'), findsNothing);
    expect(
      tester.getTopLeft(find.text('ถัดไป')).dx,
      tester.getTopLeft(find.byKey(const ValueKey('badge_row'))).dx,
    );
  });

  testWidgets('new feed items show a count and the button returns to the top', (
    tester,
  ) async {
    final posts = FakeClassPosts(feed: _feedItems(16));
    final container = await _pumpFeed(tester, posts);

    await tester.drag(find.byType(ListView).first, const Offset(0, -600));
    await tester.pumpAndSettle();
    posts.feedItems = [
      ...posts.feedItems,
      const FeedItem(
        type: FeedType.assignment,
        assignmentId: 17,
        title: 'งานชิ้นที่ 17',
      ),
    ];
    container.invalidate(classFeedProvider((courseId: 7, classroomId: 1)));
    await tester.pumpAndSettle();

    expect(find.text('มีรายการใหม่ 1 รายการ'), findsOneWidget);
    await tester.tap(find.byKey(const ValueKey('feed_new_items')));
    await tester.pumpAndSettle();
    expect(find.byKey(const ValueKey('feed_new_items')), findsNothing);
    expect(
      tester.getTopLeft(find.byKey(const ValueKey('feed_assignment_1'))).dy,
      lessThan(900),
    );
  });

  testWidgets('items added while at the top do not show the new-items button', (
    tester,
  ) async {
    final posts = FakeClassPosts(feed: _feedItems(2));
    final container = await _pumpFeed(tester, posts);
    posts.feedItems = [
      ...posts.feedItems,
      const FeedItem(
        type: FeedType.assignment,
        assignmentId: 3,
        title: 'งานชิ้นที่ 3',
      ),
    ];
    container.invalidate(classFeedProvider((courseId: 7, classroomId: 1)));
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('feed_new_items')), findsNothing);
  });

  testWidgets('today attendance shows course, period and status', (
    tester,
  ) async {
    await _pumpAttendanceCard(tester, [_attendanceCourse(_today)]);

    expect(find.byKey(const ValueKey('today_attendance')), findsOneWidget);
    expect(find.text('ค15101 คณิตศาสตร์ 5'), findsOneWidget);
    expect(find.text('คาบ 2'), findsOneWidget);
    expect(find.text('มาสาย'), findsOneWidget);
  });

  testWidgets('attendance from yesterday does not show the card', (
    tester,
  ) async {
    await _pumpAttendanceCard(tester, [
      _attendanceCourse(DateTime(2026, 10, 31)),
    ]);

    expect(find.byKey(const ValueKey('today_attendance')), findsNothing);
  });

  testWidgets('attendance request errors leave the card hidden', (
    tester,
  ) async {
    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          myAttendanceProvider.overrideWith(
            (ref) async => throw StateError('offline'),
          ),
          liveAvailableProvider.overrideWithValue(false),
        ],
        child: MaterialApp(
          home: Scaffold(body: TodayAttendanceCard(now: _today)),
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.byKey(const ValueKey('today_attendance')), findsNothing);
    expect(tester.takeException(), isNull);
  });

  for (final size in _viewports) {
    testWidgets('feed fits ${size.width}x${size.height}', (tester) async {
      _setViewport(tester, size);
      await _pumpFeed(tester, FakeClassPosts(feed: _feedItems(5)));

      expect(tester.takeException(), isNull);
    });

    testWidgets('attendance card fits ${size.width}x${size.height}', (
      tester,
    ) async {
      _setViewport(tester, size);
      await _pumpAttendanceCard(tester, [_attendanceCourse(_today)]);

      expect(tester.takeException(), isNull);
    });
  }
}
