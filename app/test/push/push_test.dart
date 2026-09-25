import 'dart:async';

import 'package:eduvision/core/auth/session.dart';
import 'package:eduvision/core/auth/user.dart';
import 'package:eduvision/core/push/devices_repository.dart';
import 'package:eduvision/core/push/push_coordinator.dart';
import 'package:eduvision/core/push/push_messaging.dart';
import 'package:eduvision/core/push/push_routes.dart';
import 'package:flutter_test/flutter_test.dart';

const _teacher = User(id: 1, name: 'ครู', role: 'teacher');
const _student = User(id: 2, name: 'นักเรียน', role: 'student');

class _FakePush implements PushMessaging {
  final refreshes = StreamController<String>.broadcast();
  final calls = <String>[];
  String? currentToken = 'tok-1';

  @override
  bool get enabled => true;
  @override
  Future<void> requestPermission() async => calls.add('permission');
  @override
  Future<String?> token() async {
    calls.add('token');
    return currentToken;
  }

  @override
  Future<void> deleteToken() async => calls.add('delete');
  @override
  Stream<String> get tokenRefreshes => refreshes.stream;
  @override
  Stream<PushMessage> get foregroundMessages => const Stream.empty();
  @override
  Stream<PushMessage> get openedMessages => const Stream.empty();
  @override
  Future<PushMessage?> initialMessage() async => null;
}

class _FakeDevices implements DevicesRepository {
  final registered = <String>[];
  bool fail = false;

  @override
  Future<void> register(String fcmToken) async {
    if (fail) throw StateError('offline');
    registered.add(fcmToken);
  }
}

void main() {
  group('routeForPush (DESIGN §9.9 deep links)', () {
    test('teacher messages open the review queue or the appeals', () {
      expect(
        routeForPush({'type': 'grading_done', 'assignment_id': '5'}, _teacher),
        '/assignments/5/review',
      );
      expect(routeForPush({'type': 'appeal_opened'}, _teacher), '/appeals');
      expect(routeForPush({'type': 'grading_done'}, _teacher), isNull);
      expect(
        routeForPush({'type': 'results_published'}, _teacher),
        isNull,
        reason: 'student messages never route a teacher',
      );
    });

    test('student messages open the published result', () {
      expect(
        routeForPush({
          'type': 'results_published',
          'submission_id': '70',
        }, _student),
        '/student/results/70',
      );
      expect(
        routeForPush({
          'type': 'appeal_resolved',
          'submission_id': '70',
        }, _student),
        '/student/results/70',
      );
      expect(routeForPush({'type': 'results_published'}, _student), '/student');
      expect(
        routeForPush({'type': 'grading_done', 'assignment_id': '5'}, _student),
        isNull,
      );
      expect(
        routeForPush({'type': 'unknown', 'route': '/x'}, _student),
        isNull,
      );
    });
  });

  group('PushCoordinator', () {
    late _FakePush push;
    late _FakeDevices devices;
    late List<String> navigated;
    late bool ready;
    late PushCoordinator c;

    setUp(() {
      push = _FakePush();
      devices = _FakeDevices();
      navigated = [];
      ready = false;
      c = PushCoordinator(
        push: push,
        devices: devices,
        navigate: navigated.add,
        canNavigate: () => ready,
      );
    });

    test('registers the token on sign-in, once per user', () async {
      await c.onSession(const SessionRestoring());
      expect(devices.registered, isEmpty);

      await c.onSession(const SignedIn(_teacher));
      expect(push.calls, ['permission', 'token']);
      expect(devices.registered, ['tok-1']);

      await c.onSession(const SignedIn(_teacher));
      expect(devices.registered, ['tok-1'], reason: 'same user: no repeat');

      await c.onTokenRefresh('tok-2');
      expect(devices.registered, ['tok-1', 'tok-2']);
    });

    test(
      'a failed registration is retried at the next session event',
      () async {
        devices.fail = true;
        await c.onSession(const SignedIn(_teacher));
        expect(devices.registered, isEmpty);
        devices.fail = false;
        await c.onSession(const SignedIn(_teacher));
        expect(devices.registered, ['tok-1']);
      },
    );

    test(
      'sign-out deletes the token; refreshes are ignored afterwards',
      () async {
        await c.onSession(const SignedIn(_student));
        await c.onSession(const SignedOut());
        expect(push.calls.last, 'delete');
        await c.onTokenRefresh('tok-3');
        expect(devices.registered, ['tok-1']);

        // Another user on the same phone registers again.
        push.currentToken = 'tok-4';
        await c.onSession(const SignedIn(_teacher));
        expect(devices.registered, ['tok-1', 'tok-4']);
      },
    );

    test('a tap before the session is restored waits for the router', () async {
      c.onOpened(
        const PushMessage(
          data: {'type': 'results_published', 'submission_id': '70'},
        ),
      );
      expect(navigated, isEmpty);

      await c.onSession(const SignedIn(_student));
      expect(navigated, isEmpty, reason: 'still on the splash screen');

      ready = true;
      c.flushPending();
      expect(navigated, ['/student/results/70']);
      c.flushPending();
      expect(navigated, hasLength(1));
    });

    test('foreground messages refresh data and offer the route', () async {
      final shown = <(String?, String?)>[];
      final refreshed = <Map<String, String>>[];
      c = PushCoordinator(
        push: push,
        devices: devices,
        navigate: navigated.add,
        canNavigate: () => true,
        showInApp: (m, route) => shown.add((m.title, route)),
        onMessageData: refreshed.add,
      );
      await c.onSession(const SignedIn(_teacher));
      c.onForeground(
        const PushMessage(
          title: 'ตรวจ บวกเลข เสร็จแล้ว มี 3 ข้อรอตรวจทาน',
          data: {'type': 'grading_done', 'assignment_id': '5'},
        ),
      );
      expect(shown, [
        ('ตรวจ บวกเลข เสร็จแล้ว มี 3 ข้อรอตรวจทาน', '/assignments/5/review'),
      ]);
      expect(refreshed.single['assignment_id'], '5');
      expect(navigated, isEmpty, reason: 'foreground never navigates alone');
    });
  });

  test(
    'without a Firebase config, push stays off and nothing throws',
    () async {
      TestWidgetsFlutterBinding.ensureInitialized();
      // flutter_test reports Android as the platform, so this runs the real
      // Firebase.initializeApp() path, which fails like a build without
      // google-services.json does.
      final push = await initPushMessaging();
      expect(push.enabled, isFalse);
      expect(await push.token(), isNull);
    },
  );
}
