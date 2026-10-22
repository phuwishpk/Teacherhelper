import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/live/live_session.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/adaptive_shell.dart';
import '../auth/sign_out_action.dart';
import '../hand_in/hand_in_repository.dart';
import '../hand_in/student_assignments_page.dart';
import '../mastery/mastery_page.dart';
import '../notices/notices_providers.dart';
import '../practice/practice_page.dart';
import '../results/results_page.dart';
import '../results/results_repository.dart';
import 'live_refresh.dart';
import 'my_subjects_page.dart';
import 'student_overview.dart';
import '../../core/widgets/impersonation_banner.dart';

class _Destination {
  const _Destination(this.label, this.icon, this.selectedIcon);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

const _destinations = [
  _Destination('วิชาของฉัน', Icons.menu_book_outlined, Icons.menu_book),
  _Destination('ส่งงาน', Icons.upload_file_outlined, Icons.upload_file),
  _Destination(
    'ผลการบ้าน',
    Icons.assignment_turned_in_outlined,
    Icons.assignment_turned_in,
  ),
  _Destination('แบบฝึก', Icons.fitness_center_outlined, Icons.fitness_center),
  _Destination('ทักษะ', Icons.insights_outlined, Icons.insights),
];

/// The index of "แบบฝึก".
const _practiceIndex = 3;

/// Student-side shell: "วิชาของฉัน" first, one card per course of every
/// classroom with its grade (DESIGN §24.11, §24.13; every grade under
/// "เกรดทั้งหมด", §23.9), then work to hand in (§19.6) and published results
/// of every classroom grouped by subject, practice by weak skill and
/// mastery per skill (§9.7, §14.1, §14.2).
class StudentShell extends ConsumerStatefulWidget {
  const StudentShell({super.key});

  @override
  ConsumerState<StudentShell> createState() => _StudentShellState();
}

class _StudentShellState extends ConsumerState<StudentShell> {
  int _index = 0;

  void _select(int i) => setState(() => _index = i);

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);

    final pages = <Widget>[
      const MySubjectsPage(),
      const StudentAssignmentsPage(),
      const ResultsPage(),
      const PracticePage(),
      MasteryPage(onPractice: () => setState(() => _index = _practiceIndex)),
    ];

    // A hand-in changed status (DESIGN §38.9): refetch what shows it.
    ref.listen(liveSubmissionTicksProvider, (_, next) {
      if (!next.hasValue) return;
      ref.invalidate(studentAssignmentsProvider);
      ref.invalidate(studentResultsProvider);
      ref.invalidate(studentOverviewProvider);
    });

    return AdaptiveShell(
      title: user == null ? 'Krucheck' : 'สวัสดี ${user.name}',
      accountName: user?.name,
      accountCaption: user?.username,
      destinations: [
        for (final d in _destinations)
          ShellDestination(d.label, d.icon, d.selectedIcon),
      ],
      selectedIndex: _index,
      onSelected: _select,
      actions: [
        if (ref.watch(liveSessionProvider).enabled)
          ShellAction(
            key: const ValueKey('notices_button'),
            tooltip: 'การแจ้งเตือน',
            icon: Icons.notifications_outlined,
            badge: ref.watch(unreadNoticesProvider),
            onPressed: () => context.push(AppRoutes.studentNotices),
          ),
        ShellAction(
          key: const ValueKey('help_button'),
          tooltip: 'คู่มือการใช้งาน',
          icon: Icons.help_outline,
          onPressed: () => context.push(AppRoutes.studentHelp),
        ),
        ShellAction(
          key: const ValueKey('student_account_button'),
          tooltip: 'บัญชีของฉัน',
          icon: Icons.account_circle_outlined,
          onPressed: () => context.push(AppRoutes.studentAccount),
        ),
        ShellAction(
          tooltip: 'ออกจากระบบ',
          icon: Icons.logout,
          onPressed: () => confirmSignOut(context, ref),
        ),
      ],
      body: withImpersonationBanner(
        IndexedStack(index: _index, children: pages),
      ),
    );
  }
}
