import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../features/auth/login_screen.dart';
import '../../features/auth/register_screen.dart';
import '../../features/auth/splash_screen.dart';
import '../../features/home/teacher_shell.dart';
import '../auth/session.dart';

abstract final class AppRoutes {
  static const splash = '/splash';
  static const login = '/login';
  static const register = '/register';
  static const home = '/';
}

final routerProvider = Provider<GoRouter>((ref) {
  // Bump a ValueNotifier whenever the session changes so redirect() reruns.
  final refresh = ValueNotifier<int>(0);
  ref.onDispose(refresh.dispose);
  ref.listen(sessionProvider, (_, _) => refresh.value++);

  return GoRouter(
    initialLocation: AppRoutes.splash,
    refreshListenable: refresh,
    redirect: (context, state) {
      final session = ref.read(sessionProvider);
      final location = state.matchedLocation;
      final onAuthPage =
          location == AppRoutes.login || location == AppRoutes.register;
      return switch (session) {
        SessionRestoring() =>
          location == AppRoutes.splash ? null : AppRoutes.splash,
        SignedOut() => onAuthPage ? null : AppRoutes.login,
        SignedIn() =>
          (onAuthPage || location == AppRoutes.splash) ? AppRoutes.home : null,
      };
    },
    routes: [
      GoRoute(
        path: AppRoutes.splash,
        builder: (context, state) => const SplashScreen(),
      ),
      GoRoute(
        path: AppRoutes.login,
        builder: (context, state) => const LoginScreen(),
      ),
      GoRoute(
        path: AppRoutes.register,
        builder: (context, state) => const RegisterScreen(),
      ),
      GoRoute(
        path: AppRoutes.home,
        builder: (context, state) => const TeacherShell(),
      ),
    ],
  );
});
