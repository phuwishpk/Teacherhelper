import 'package:flutter/foundation.dart' show kIsWeb;
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../core/theme/breakpoints.dart';
import '../../core/widgets/adaptive_shell.dart';
import '../../ml/ml_providers.dart';
import '../assignments/assignments_page.dart';
import '../auth/sign_out_action.dart';
import '../classrooms/classrooms_page.dart';
import '../gradebook/grades_home_page.dart';
import '../review/review_home_page.dart';
import '../upload_queue/upload_queue_providers.dart';
import 'dashboard_page.dart';
import 'teacher_attention.dart';

/// Teacher-side navigation shell ([AdaptiveShell], DESIGN §27.3): a bottom
/// NavigationBar on phones, a NavigationRail from tablet width up (the
/// review queue is meant for tablets, DESIGN §13) and a sidebar on a desktop.
/// Five destinations: หน้าหลัก, ห้องเรียน, การบ้าน, ตรวจทาน and ตัดเกรด (§23.9).
class TeacherShell extends ConsumerStatefulWidget {
  const TeacherShell({super.key});

  @override
  ConsumerState<TeacherShell> createState() => _TeacherShellState();
}

class _Destination {
  const _Destination(this.label, this.icon, this.selectedIcon);

  final String label;
  final IconData icon;
  final IconData selectedIcon;
}

const _destinations = [
  _Destination('หน้าหลัก', Icons.home_outlined, Icons.home),
  _Destination('ห้องเรียน', Icons.groups_outlined, Icons.groups),
  _Destination('การบ้าน', Icons.assignment_outlined, Icons.assignment),
  _Destination('ตรวจทาน', Icons.rate_review_outlined, Icons.rate_review),
  _Destination('ตัดเกรด', Icons.grading_outlined, Icons.grading),
];

/// The index of "ตัดเกรด" (DESIGN §23.9): the gradebook overview of every
/// course, also opened by the dashboard's shortcut.
const teacherGradesIndex = 4;

class _TeacherShellState extends ConsumerState<TeacherShell> {
  int _index = 0;

  /// "ตัดเกรด" loads only once opened, like the student's "เกรด".
  bool _gradesOpened = false;

  @override
  void initState() {
    super.initState();
    // Fetch a newer on-device digit model (DESIGN §9.8) while the teacher
    // is online, so scanning later works offline with it.
    syncDigitModelInBackground(ref);
  }

  void _select(int index) => setState(() {
    _index = index;
    if (index == teacherGradesIndex) _gradesOpened = true;
  });

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    // From tablet width the scan button lives in the rail or the sidebar.
    final wide = context.windowSize != WindowSize.compact;
    // On a desktop the tab's actions are buttons next to its title.
    final expanded = context.windowSize == WindowSize.expanded;
    // The upload queue is on the phone only (DESIGN §25).
    final queueOpen = kIsWeb ? 0 : ref.watch(uploadQueueOpenCountProvider);
    // Course requests waiting for the homeroom teacher (DESIGN §24.7): a
    // badge on "ห้องเรียน", where "คำขอผูกรายวิชา" is.
    final requests =
        ref.watch(teacherAttentionProvider).value?.courseRequestsPending ?? 0;

    final pages = <Widget>[
      DashboardPage(user: user, onNavigate: _select),
      const ClassroomsPage(),
      const AssignmentsPage(),
      const ReviewHomePage(),
      if (_gradesOpened) const GradesHomePage() else const SizedBox.shrink(),
    ];

    void openScan() => context.push(AppRoutes.scan);

    // The tabs are bodies only and the shell's Scaffold owns their FAB, so
    // the root ScaffoldMessenger has exactly one Scaffold here to show a
    // SnackBar on (the FAB moves up for it instead of being covered).
    final tabFab = expanded
        ? null
        : switch (_index) {
            0 =>
              wide
                  ? null
                  : FloatingActionButton.extended(
                      heroTag: 'scan_fab',
                      onPressed: openScan,
                      icon: const Icon(Icons.document_scanner_outlined),
                      label: const Text('สแกนใบงาน'),
                    ),
            1 => const ClassroomsFab(),
            2 => const AssignmentsFab(),
            _ => null,
          };
    final headerActions = !expanded
        ? null
        : switch (_index) {
            1 => const ClassroomsFab(inline: true),
            2 => const AssignmentsFab(inline: true),
            _ => null,
          };

    return AdaptiveShell(
      title: 'EduVision',
      accountName: user?.name,
      accountCaption: user?.schoolName,
      destinations: [
        for (final (i, d) in _destinations.indexed)
          ShellDestination(
            d.label,
            d.icon,
            d.selectedIcon,
            badge: i == 1 ? requests : 0,
          ),
      ],
      selectedIndex: _index,
      onSelected: _select,
      primaryAction: ShellAction(
        tooltip: 'สแกนใบงาน',
        icon: Icons.document_scanner_outlined,
        onPressed: openScan,
      ),
      actions: [
        if (!kIsWeb)
          ShellAction(
            tooltip: 'คิวอัปโหลด',
            icon: Icons.cloud_upload_outlined,
            badge: queueOpen,
            onPressed: () => context.push(AppRoutes.uploadQueue),
          ),
        ShellAction(
          tooltip: 'ตั้งค่า',
          icon: Icons.settings_outlined,
          onPressed: () => context.push(AppRoutes.settings),
        ),
        ShellAction(
          tooltip: 'ออกจากระบบ',
          icon: Icons.logout,
          onPressed: () => confirmSignOut(context, ref),
        ),
      ],
      body: IndexedStack(index: _index, children: pages),
      floatingActionButton: tabFab,
      headerActions: headerActions,
    );
  }
}
