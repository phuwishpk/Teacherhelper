import 'package:flutter/material.dart';

import '../theme/breakpoints.dart';
import 'auth_layout.dart';
import 'content_column.dart';

/// One tab of an [AdaptiveShell].
class ShellDestination {
  const ShellDestination(
    this.label,
    this.icon,
    this.selectedIcon, {
    this.badge = 0,
  });

  final String label;
  final IconData icon;
  final IconData selectedIcon;

  /// A count on the icon (0 = none).
  final int badge;
}

/// An action next to the tabs: an icon in the app bar, or in the sidebar.
class ShellAction {
  const ShellAction({
    required this.tooltip,
    required this.icon,
    required this.onPressed,
    this.badge = 0,
    this.key,
  });

  final String tooltip;
  final IconData icon;
  final VoidCallback onPressed;
  final int badge;
  final Key? key;
}

/// The navigation shell of every role (DESIGN §27.3), laid out by window
/// size:
///
/// - compact (below 600 px): app bar and a bottom NavigationBar;
/// - medium (600–1199 px): app bar and a NavigationRail with the labels
///   under the icons;
/// - expanded (from 1200 px): a sidebar with the brand, the main action,
///   the labels beside the icons and the account at the bottom; no app bar,
///   the page starts with the name of the tab.
///
/// The tabs are bodies only: this Scaffold owns the floating action button,
/// so a SnackBar has exactly one Scaffold to show on.
class AdaptiveShell extends StatelessWidget {
  const AdaptiveShell({
    super.key,
    required this.title,
    required this.destinations,
    required this.selectedIndex,
    required this.onSelected,
    required this.body,
    this.actions = const [],
    this.primaryAction,
    this.accountName,
    this.accountCaption,
    this.floatingActionButton,
    this.headerActions,
  });

  /// The app bar title on a phone and a tablet.
  final String title;
  final List<ShellDestination> destinations;
  final int selectedIndex;
  final ValueChanged<int> onSelected;
  final Widget body;
  final List<ShellAction> actions;

  /// The role's main action (the teacher's "สแกนใบงาน"): above the tabs of
  /// the rail and the sidebar. On a phone the caller shows it as the
  /// floating action button.
  final ShellAction? primaryAction;

  /// Who is signed in, at the bottom of the sidebar.
  final String? accountName;
  final String? accountCaption;
  final Widget? floatingActionButton;

  /// The current tab's actions as buttons, next to its name at the top of
  /// the page. Shown on the expanded layout only, where a floating button
  /// in the far corner would sit a long way from the content; the caller
  /// passes no [floatingActionButton] then.
  final Widget? headerActions;

  static const sidebarWidth = 248.0;

  Widget _icon(int index, IconData data) {
    final badge = destinations[index].badge;
    return badge > 0
        ? Badge.count(
            key: ValueKey('nav_badge_$index'),
            count: badge,
            child: Icon(data),
          )
        : Icon(data);
  }

  Widget _actionButton(ShellAction action) => IconButton(
    key: action.key,
    tooltip: action.tooltip,
    onPressed: action.onPressed,
    icon: Badge.count(
      count: action.badge,
      isLabelVisible: action.badge > 0,
      child: Icon(action.icon),
    ),
  );

  @override
  Widget build(BuildContext context) {
    final size = context.windowSize;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;

    if (size == WindowSize.compact) {
      return Scaffold(
        appBar: AppBar(
          title: Text(title),
          actions: [for (final a in actions) _actionButton(a)],
        ),
        body: body,
        bottomNavigationBar: DecoratedBox(
          decoration: BoxDecoration(
            border: Border(top: BorderSide(color: scheme.outlineVariant)),
          ),
          child: NavigationBar(
            selectedIndex: selectedIndex,
            onDestinationSelected: onSelected,
            destinations: [
              for (final (i, d) in destinations.indexed)
                NavigationDestination(
                  icon: _icon(i, d.icon),
                  selectedIcon: _icon(i, d.selectedIcon),
                  label: d.label,
                ),
            ],
          ),
        ),
        floatingActionButton: floatingActionButton,
      );
    }

    final primary = primaryAction;
    if (size == WindowSize.medium) {
      return Scaffold(
        appBar: AppBar(
          title: Text(title),
          actions: [for (final a in actions) _actionButton(a)],
        ),
        body: Row(
          children: [
            NavigationRail(
              selectedIndex: selectedIndex,
              onDestinationSelected: onSelected,
              labelType: NavigationRailLabelType.all,
              leading: primary == null
                  ? null
                  : Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8),
                      child: FloatingActionButton(
                        heroTag: 'shell_primary',
                        tooltip: primary.tooltip,
                        elevation: 0,
                        onPressed: primary.onPressed,
                        child: Icon(primary.icon),
                      ),
                    ),
              destinations: [
                for (final (i, d) in destinations.indexed)
                  NavigationRailDestination(
                    icon: _icon(i, d.icon),
                    selectedIcon: _icon(i, d.selectedIcon),
                    label: Text(d.label),
                  ),
              ],
            ),
            const VerticalDivider(width: 1),
            Expanded(child: body),
          ],
        ),
        floatingActionButton: floatingActionButton,
      );
    }

    return Scaffold(
      body: SafeArea(
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            SizedBox(
              width: sidebarWidth,
              child: ColoredBox(
                color: scheme.surfaceContainerLow,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 20, 20, 16),
                      child: const Align(
                        alignment: Alignment.centerLeft,
                        child: BrandMark(),
                      ),
                    ),
                    if (primary != null)
                      Padding(
                        padding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                        child: FilledButton.icon(
                          onPressed: primary.onPressed,
                          icon: Icon(primary.icon, size: 20),
                          label: Text(primary.tooltip),
                        ),
                      ),
                    Expanded(
                      child: NavigationRail(
                        extended: true,
                        minExtendedWidth: sidebarWidth,
                        selectedIndex: selectedIndex,
                        onDestinationSelected: onSelected,
                        destinations: [
                          for (final (i, d) in destinations.indexed)
                            NavigationRailDestination(
                              icon: _icon(i, d.icon),
                              selectedIcon: _icon(i, d.selectedIcon),
                              label: Text(d.label),
                            ),
                        ],
                      ),
                    ),
                    const Divider(),
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 12, 12, 12),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          if (accountName != null)
                            Text(
                              accountName!,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.titleSmall,
                            ),
                          if (accountCaption != null)
                            Text(
                              accountCaption!,
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                              style: theme.textTheme.bodySmall?.copyWith(
                                color: scheme.onSurfaceVariant,
                              ),
                            ),
                          const SizedBox(height: 4),
                          Transform.translate(
                            offset: const Offset(-10, 0),
                            child: Row(
                              children: [
                                for (final a in actions) _actionButton(a),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const VerticalDivider(width: 1),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // On the same column as the tab's content (ContentColumn).
                  ContentColumn(
                    padding: const EdgeInsets.fromLTRB(32, 28, 32, 4),
                    child: Row(
                      children: [
                        Expanded(
                          child: Text(
                            destinations[selectedIndex].label,
                            style: theme.textTheme.headlineSmall,
                          ),
                        ),
                        ?headerActions,
                      ],
                    ),
                  ),
                  Expanded(child: body),
                ],
              ),
            ),
          ],
        ),
      ),
      floatingActionButton: floatingActionButton,
    );
  }
}
