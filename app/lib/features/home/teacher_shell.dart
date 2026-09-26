import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/router/app_router.dart';
import '../../ml/ml_providers.dart';
import '../assignments/assignments_page.dart';
import '../auth/sign_out_action.dart';
import '../classrooms/classrooms_page.dart';
import '../review/review_home_page.dart';
import '../upload_queue/upload_queue_providers.dart';
import 'dashboard_page.dart';

/// Teacher-side navigation shell: a bottom NavigationBar on phones and a
/// NavigationRail from tablet width up (the review queue is meant for
/// tablets, DESIGN §13).
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
];

/// Material 3 "expanded" breakpoint: rail instead of bottom bar.
const _railBreakpoint = 840.0;

class _TeacherShellState extends ConsumerState<TeacherShell> {
  int _index = 0;

  @override
  void initState() {
    super.initState();
    // Fetch a newer on-device digit model (DESIGN §9.8) while the teacher
    // is online, so scanning later works offline with it.
    syncDigitModelInBackground(ref);
  }

  void _select(int index) => setState(() => _index = index);

  @override
  Widget build(BuildContext context) {
    final user = ref.watch(currentUserProvider);
    final wide = MediaQuery.sizeOf(context).width >= _railBreakpoint;
    final queueOpen = ref.watch(uploadQueueOpenCountProvider);

    final pages = <Widget>[
      DashboardPage(user: user, onNavigate: _select),
      const ClassroomsPage(),
      const AssignmentsPage(),
      const ReviewHomePage(),
    ];
    final body = IndexedStack(index: _index, children: pages);

    final scanButton = wide
        ? FloatingActionButton(
            heroTag: 'scan_fab',
            tooltip: 'สแกนใบงาน',
            onPressed: () => context.push(AppRoutes.scan),
            child: const Icon(Icons.document_scanner_outlined),
          )
        : FloatingActionButton.extended(
            heroTag: 'scan_fab',
            onPressed: () => context.push(AppRoutes.scan),
            icon: const Icon(Icons.document_scanner_outlined),
            label: const Text('สแกนใบงาน'),
          );

    // The tabs are bodies only and this Scaffold owns their FAB, so the root
    // ScaffoldMessenger has exactly one Scaffold here to show a SnackBar on
    // (the FAB moves up for it instead of being covered).
    final tabFab = switch (_index) {
      0 => wide ? null : scanButton,
      1 => const ClassroomsFab(),
      2 => const AssignmentsFab(),
      _ => null,
    };

    return Scaffold(
      appBar: AppBar(
        title: const Text('EduVision'),
        actions: [
          IconButton(
            tooltip: 'คิวอัปโหลด',
            icon: Badge.count(
              count: queueOpen,
              isLabelVisible: queueOpen > 0,
              child: const Icon(Icons.cloud_upload_outlined),
            ),
            onPressed: () => context.push(AppRoutes.uploadQueue),
          ),
          IconButton(
            tooltip: 'ตั้งค่า',
            icon: const Icon(Icons.settings_outlined),
            onPressed: () => context.push(AppRoutes.settings),
          ),
          IconButton(
            tooltip: 'ออกจากระบบ',
            icon: const Icon(Icons.logout),
            onPressed: () => confirmSignOut(context, ref),
          ),
        ],
      ),
      body: wide
          ? Row(
              children: [
                NavigationRail(
                  selectedIndex: _index,
                  onDestinationSelected: _select,
                  labelType: NavigationRailLabelType.all,
                  leading: Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: scanButton,
                  ),
                  destinations: [
                    for (final d in _destinations)
                      NavigationRailDestination(
                        icon: Icon(d.icon),
                        selectedIcon: Icon(d.selectedIcon),
                        label: Text(d.label),
                      ),
                  ],
                ),
                const VerticalDivider(width: 1),
                Expanded(child: body),
              ],
            )
          : body,
      bottomNavigationBar: wide
          ? null
          : NavigationBar(
              selectedIndex: _index,
              onDestinationSelected: _select,
              destinations: [
                for (final d in _destinations)
                  NavigationDestination(
                    icon: Icon(d.icon),
                    selectedIcon: Icon(d.selectedIcon),
                    label: d.label,
                  ),
              ],
            ),
      floatingActionButton: tabFab,
    );
  }
}
