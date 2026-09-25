import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_riverpod/misc.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:go_router/go_router.dart';

/// Pumps [screen] pushed on top of a stub home route so `context.pop()` and
/// `context.push*()` inside the screen work like in the app.
Future<ProviderContainer> pumpScreen(
  WidgetTester tester,
  Widget screen, {
  List<Override> overrides = const [],
  List<GoRoute> extraRoutes = const [],
}) async {
  final router = GoRouter(
    initialLocation: '/',
    routes: [
      GoRoute(
        path: '/',
        builder: (_, _) => const Scaffold(body: Text('stub-home')),
      ),
      GoRoute(path: '/screen', builder: (_, _) => screen),
      ...extraRoutes,
    ],
  );
  await tester.pumpWidget(
    ProviderScope(
      overrides: overrides,
      child: MaterialApp.router(routerConfig: router),
    ),
  );
  router.push('/screen');
  await tester.pumpAndSettle();
  return ProviderScope.containerOf(tester.element(find.byWidget(screen)));
}

/// Replaces the pumped tree with nothing and advances the fake clock, so
/// providers that hold a drift query stream get disposed INSIDE the test
/// body. drift schedules a zero-length `Timer.run` when a stream loses its
/// last listener; without this the timer is still pending when flutter_test
/// checks invariants ("A Timer is still pending") and a later `db.close()`
/// in tearDown waits forever for it.
///
/// `tester.pump()` without a duration does NOT elapse fake time, so it never
/// fires that timer; an explicit [Duration.zero] does.
Future<void> unmountScreen(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox());
  await tester.pump(Duration.zero);
}
