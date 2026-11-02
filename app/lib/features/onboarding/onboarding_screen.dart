import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/theme/breakpoints.dart';
import '../../core/widgets/mascot.dart';
import 'onboarding_pages.dart';
import 'onboarding_store.dart';

/// Swipeable first-run onboarding screen (DESIGN §44, §43).
class OnboardingScreen extends ConsumerStatefulWidget {
  const OnboardingScreen({super.key});

  @override
  ConsumerState<OnboardingScreen> createState() => _OnboardingScreenState();
}

class _OnboardingScreenState extends ConsumerState<OnboardingScreen> {
  /// How long "ข้าม" and "เริ่มใช้งาน" wait for Firestore (DESIGN §44.2).
  static const _cloudWait = Duration(seconds: 2);

  final PageController _controller = PageController();
  int _currentPage = 0;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _finish() async {
    final user = ref.read(currentUserProvider);
    try {
      await ref.read(onboardingStoreProvider)?.markDone().timeout(_cloudWait);
    } on TimeoutException {
      // The device already knows; Firestore holds its write while offline.
    }
    if (!mounted) return;
    ref.invalidate(onboardingNeededProvider);
    final target = (user?.isStudent == true)
        ? AppRoutes.student
        : AppRoutes.home;
    try {
      context.go(target);
    } catch (_) {
      if (context.canPop()) {
        context.pop();
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final pages = (user?.isStudent == true)
        ? studentOnboarding
        : teacherOnboarding;
    final isCompact = context.windowSize == WindowSize.compact;
    final isLastPage = _currentPage >= pages.length - 1;

    return Scaffold(
      body: SafeArea(
        child: Column(
          children: [
            // Top: Skip button
            Align(
              alignment: Alignment.topRight,
              child: Padding(
                padding: const EdgeInsets.only(top: 8, right: 16),
                child: TextButton(
                  key: const Key('onboarding_skip'),
                  onPressed: _finish,
                  child: const Text('ข้าม'),
                ),
              ),
            ),
            // Center: Swipeable pages
            Expanded(
              child: PageView.builder(
                controller: _controller,
                itemCount: pages.length,
                onPageChanged: (index) {
                  setState(() => _currentPage = index);
                },
                itemBuilder: (context, index) {
                  final page = pages[index];
                  if (isCompact) {
                    return Center(
                      child: SingleChildScrollView(
                        padding: const EdgeInsets.symmetric(horizontal: 24),
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            Mascot(mood: page.mood, size: 160),
                            const SizedBox(height: 32),
                            Text(
                              page.title,
                              style: Theme.of(context).textTheme.headlineSmall,
                              textAlign: TextAlign.center,
                            ),
                            const SizedBox(height: 12),
                            Text(
                              page.body,
                              style: Theme.of(context).textTheme.bodyLarge,
                              textAlign: TextAlign.center,
                            ),
                          ],
                        ),
                      ),
                    );
                  }

                  return Center(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.symmetric(horizontal: 32),
                      child: ConstrainedBox(
                        constraints: const BoxConstraints(maxWidth: 720),
                        child: Row(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Mascot(mood: page.mood, size: 160),
                            const SizedBox(width: 48),
                            Expanded(
                              child: Column(
                                mainAxisSize: MainAxisSize.min,
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(
                                    page.title,
                                    style: Theme.of(
                                      context,
                                    ).textTheme.headlineSmall,
                                  ),
                                  const SizedBox(height: 12),
                                  Text(
                                    page.body,
                                    style: Theme.of(
                                      context,
                                    ).textTheme.bodyLarge,
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  );
                },
              ),
            ),
            // Bottom: Dots and Next / Start button
            Padding(
              padding: const EdgeInsets.fromLTRB(24, 16, 24, 24),
              child: Stack(
                alignment: Alignment.center,
                children: [
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: List.generate(pages.length, (index) {
                      final isCurrent = index == _currentPage;
                      return AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        margin: const EdgeInsets.symmetric(horizontal: 4),
                        height: 8,
                        width: isCurrent ? 24 : 8,
                        decoration: BoxDecoration(
                          color: isCurrent
                              ? Theme.of(context).colorScheme.primary
                              : Theme.of(
                                  context,
                                ).colorScheme.surfaceContainerHighest,
                          borderRadius: BorderRadius.circular(4),
                        ),
                      );
                    }),
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: FilledButton(
                      key: const Key('onboarding_next'),
                      onPressed: () {
                        if (!isLastPage) {
                          _controller.nextPage(
                            duration: const Duration(milliseconds: 300),
                            curve: Curves.easeInOut,
                          );
                        } else {
                          _finish();
                        }
                      },
                      child: Text(isLastPage ? 'เริ่มใช้งาน' : 'ถัดไป'),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
