import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../features/assignments/assignments_providers.dart';
import '../../features/google_classroom/google_providers.dart';
import '../../features/gradebook/gradebook_providers.dart';
import '../../features/home/teacher_attention.dart';
import '../../features/results/results_repository.dart';
import '../../features/review/review_providers.dart';
import '../auth/session.dart';
import '../auth/user.dart';
import '../router/app_router.dart';
import 'devices_repository.dart';
import 'push_messaging.dart';
import 'push_routes.dart';

/// Glue between FCM and the app (DESIGN §9.9): registers the token for the
/// signed-in user, drops it on sign-out, shows foreground messages in-app
/// and opens the right screen when a notification is tapped.
class PushCoordinator {
  PushCoordinator({
    required this.push,
    required this.devices,
    required this.navigate,
    required this.canNavigate,
    this.showInApp,
    this.onMessageData,
  });

  final PushMessaging push;
  final DevicesRepository devices;
  final void Function(String location) navigate;

  /// True once the router shows a signed-in screen (not splash/login).
  final bool Function() canNavigate;

  /// Foreground message; [route] is where "ดู" leads (may be null).
  final void Function(PushMessage message, String? route)? showInApp;

  /// Lets screens refresh the data a message is about.
  final void Function(Map<String, String> data)? onMessageData;

  User? _user;
  String? _registeredToken;
  int? _registeredFor;
  bool _permissionAsked = false;
  String? _pendingRoute;
  PushMessage? _pendingMessage;

  User? get user => _user;

  Future<void> onSession(SessionState state) async {
    switch (state) {
      // An admin token may not register a device (DESIGN §7.4): admins get
      // no pushes.
      case SignedIn(:final user) when user.isAdmin:
        break;
      case SignedIn(:final user):
        _user = user;
        if (_registeredFor == user.id && _registeredToken != null) break;
        if (!_permissionAsked) {
          _permissionAsked = true;
          await _guard(push.requestPermission);
        }
        final token = await _guard(push.token);
        if (token != null) await _register(token);
        _resolvePending();
      case SignedOut():
        final wasSignedIn = _user != null;
        _user = null;
        _pendingRoute = null;
        _pendingMessage = null;
        if (wasSignedIn) {
          _registeredToken = null;
          _registeredFor = null;
          await _guard(push.deleteToken);
        }
      case SessionRestoring():
        break;
    }
  }

  Future<void> onTokenRefresh(String token) async {
    if (_user == null) return;
    await _register(token);
  }

  Future<void> _register(String token) async {
    final user = _user;
    if (user == null) return;
    try {
      await devices.register(token);
      _registeredToken = token;
      _registeredFor = user.id;
    } catch (e) {
      // Retried at the next sign-in, app start or token refresh. The token
      // itself is never logged.
      debugPrint('FCM device registration failed: ${e.runtimeType}');
    }
  }

  void onForeground(PushMessage message) {
    onMessageData?.call(message.data);
    final user = _user;
    showInApp?.call(
      message,
      user == null ? null : routeForPush(message.data, user),
    );
  }

  /// A notification was tapped: open its screen now, or once the session
  /// is restored and the router has left the splash screen.
  void onOpened(PushMessage message) {
    onMessageData?.call(message.data);
    _pendingMessage = message;
    _resolvePending();
  }

  void _resolvePending() {
    final user = _user;
    final message = _pendingMessage;
    if (user != null && message != null) {
      _pendingMessage = null;
      _pendingRoute = routeForPush(message.data, user);
    }
    flushPending();
  }

  /// Called on every router change as well.
  void flushPending() {
    final route = _pendingRoute;
    if (route == null || _user == null || !canNavigate()) return;
    _pendingRoute = null;
    navigate(route);
  }

  static Future<T?> _guard<T>(Future<T> Function() action) async {
    try {
      return await action();
    } catch (e) {
      debugPrint('FCM call failed: ${e.runtimeType}');
      return null;
    }
  }
}

/// Root messenger so foreground notifications can show a SnackBar on any
/// screen.
final rootMessengerKeyProvider = Provider<GlobalKey<ScaffoldMessengerState>>(
  (ref) => GlobalKey<ScaffoldMessengerState>(),
);

final pushCoordinatorProvider = Provider<PushCoordinator>((ref) {
  final push = ref.watch(pushMessagingProvider);
  final router = ref.watch(routerProvider);
  final messenger = ref.watch(rootMessengerKeyProvider);

  bool canNavigate() {
    final location = router.routerDelegate.currentConfiguration.uri.path;
    return location.isNotEmpty &&
        location != AppRoutes.splash &&
        !AppRoutes.isPublic(location);
  }

  void refreshFor(Map<String, String> data) {
    final assignmentId = int.tryParse(data['assignment_id'] ?? '');
    switch (data['type']) {
      case 'grading_done' when assignmentId != null:
        ref.invalidate(reviewQueueProvider(assignmentId));
      case 'appeal_opened':
        ref.invalidate(openAppealsProvider);
      case 'results_published' || 'appeal_resolved':
        ref.invalidate(studentResultsProvider);
      case 'retake_requested':
        ref.invalidate(studentRetakeRequestsProvider);
      case 'grades_published':
        ref.invalidate(myGradesProvider);
        final courseId = int.tryParse(data['course_id'] ?? '');
        if (courseId != null) ref.invalidate(myCourseGradeProvider(courseId));
      case 'classroom_work_imported':
        ref.invalidate(assignmentsProvider);
        ref.invalidate(teacherAttentionProvider);
      case 'google_reconnect':
        ref.invalidate(googleStatusProvider);
        ref.invalidate(teacherAttentionProvider);
    }
  }

  final coordinator = PushCoordinator(
    push: push,
    devices: ref.watch(devicesRepositoryProvider),
    navigate: (location) => router.push(location),
    canNavigate: canNavigate,
    onMessageData: refreshFor,
    showInApp: (message, route) {
      final text = [
        ?message.title,
        ?message.body,
      ].where((s) => s.isNotEmpty).join(' · ');
      if (text.isEmpty) return;
      messenger.currentState
        ?..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(
            content: Text(text),
            action: route == null
                ? null
                : SnackBarAction(
                    label: 'ดู',
                    onPressed: () => router.push(route),
                  ),
          ),
        );
    },
  );

  if (push.enabled) {
    ref.listen<SessionState>(
      sessionProvider,
      (_, next) => coordinator.onSession(next),
      fireImmediately: true,
    );
    final subscriptions = [
      push.tokenRefreshes.listen(coordinator.onTokenRefresh),
      push.foregroundMessages.listen(coordinator.onForeground),
      push.openedMessages.listen(coordinator.onOpened),
    ];
    router.routerDelegate.addListener(coordinator.flushPending);
    unawaited(
      push.initialMessage().then((m) {
        if (m != null) coordinator.onOpened(m);
      }),
    );
    ref.onDispose(() {
      for (final s in subscriptions) {
        s.cancel();
      }
      router.routerDelegate.removeListener(coordinator.flushPending);
    });
  }
  return coordinator;
});
