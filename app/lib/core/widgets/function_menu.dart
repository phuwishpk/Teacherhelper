import 'package:flutter/material.dart';

/// One function of the app in the menu and its search (DESIGN §35).
class MenuFunction {
  const MenuFunction({
    required this.id,
    required this.title,
    required this.icon,
    required this.run,
    this.where,
    this.keywords = const [],
  });

  final String id;
  final String title;
  final IconData icon;

  /// Where it lives or what it needs first, shown under the title.
  final String? where;

  /// More words it is found by (synonyms, English, old names).
  final List<String> keywords;

  /// Opens it, with the shell's context (the menu may be a drawer that is
  /// closed first).
  final VoidCallback run;
}

/// A top-level entry of the menu: a tab of the shell ([onOpen]) or a
/// heading only (null), with its functions under it.
class MenuGroup {
  const MenuGroup({
    required this.label,
    required this.icon,
    required this.functions,
    this.selectedIcon,
    this.onOpen,
    this.badge = 0,
  });

  final String label;
  final IconData icon;
  final IconData? selectedIcon;
  final VoidCallback? onOpen;
  final int badge;
  final List<MenuFunction> functions;
}

/// The search over every function (DESIGN §35): each word of [query] must
/// appear in the title, the "where" line, the keywords or the group; titles
/// that start with the query come first.
List<(MenuGroup, MenuFunction)> searchFunctions(
  List<MenuGroup> groups,
  String query,
) {
  final words = query
      .toLowerCase()
      .split(RegExp(r'\s+'))
      .where((w) => w.isNotEmpty)
      .toList();
  if (words.isEmpty) return const [];
  final found = <(MenuGroup, MenuFunction, int)>[];
  for (final g in groups) {
    for (final f in g.functions) {
      final title = f.title.toLowerCase();
      final text = [
        title,
        f.where ?? '',
        ...f.keywords,
        g.label,
      ].join(' ').toLowerCase();
      if (!words.every(text.contains)) continue;
      final rank = title.startsWith(words.first)
          ? 0
          : words.every(title.contains)
          ? 1
          : 2;
      found.add((g, f, rank));
    }
  }
  found.sort((a, b) => a.$3.compareTo(b.$3));
  return [for (final (g, f, _) in found) (g, f)];
}

/// The searchable menu of every function, grouped under the shell's tabs
/// (DESIGN §35): in the desktop sidebar and in the drawer of a phone or a
/// tablet. A group's label opens its tab; the arrow folds out its
/// functions. Typing in the box shows the matching functions instead.
class FunctionMenu extends StatefulWidget {
  const FunctionMenu({
    super.key,
    required this.groups,
    this.selectedGroup,
    this.inDrawer = false,
  });

  final List<MenuGroup> groups;
  final int? selectedGroup;

  /// In a drawer: close it before opening anything.
  final bool inDrawer;

  @override
  State<FunctionMenu> createState() => FunctionMenuState();
}

class FunctionMenuState extends State<FunctionMenu> {
  final _query = TextEditingController();
  final focusNode = FocusNode();
  final Set<int> _open = {};

  @override
  void dispose() {
    _query.dispose();
    focusNode.dispose();
    super.dispose();
  }

  void _go(VoidCallback action) {
    if (widget.inDrawer) Navigator.of(context).maybePop();
    if (mounted) setState(_query.clear);
    action();
  }

  Widget _icon(MenuGroup g, bool selected) {
    final icon = Icon(selected ? (g.selectedIcon ?? g.icon) : g.icon);
    return g.badge > 0 ? Badge.count(count: g.badge, child: icon) : icon;
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final query = _query.text.trim();
    final results = searchFunctions(widget.groups, query);

    // Its own Material: the sidebar's coloured box would hide the tiles'
    // selection colour and ink otherwise.
    return Material(
      type: MaterialType.transparency,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 4, 12, 8),
            child: TextField(
              key: const ValueKey('function_search'),
              controller: _query,
              focusNode: focusNode,
              onChanged: (_) => setState(() {}),
              onSubmitted: (_) {
                if (results.isNotEmpty) _go(results.first.$2.run);
              },
              textInputAction: TextInputAction.search,
              decoration: InputDecoration(
                isDense: true,
                prefixIcon: const Icon(Icons.search),
                hintText: 'ค้นหาเมนูและเครื่องมือ',
                suffixIcon: query.isEmpty
                    ? null
                    : IconButton(
                        tooltip: 'ล้าง',
                        icon: const Icon(Icons.clear),
                        onPressed: () => setState(_query.clear),
                      ),
              ),
            ),
          ),
          Expanded(
            child: query.isNotEmpty
                ? (results.isEmpty
                      ? Padding(
                          padding: const EdgeInsets.all(16),
                          child: Text(
                            'ไม่พบ "$query" ลองคำอื่น หรือเปิดคู่มือการใช้งาน',
                            style: theme.textTheme.bodyMedium?.copyWith(
                              color: scheme.onSurfaceVariant,
                            ),
                          ),
                        )
                      : ListView(
                          key: const ValueKey('function_results'),
                          children: [
                            for (final (g, f) in results)
                              ListTile(
                                key: ValueKey('function_result_${f.id}'),
                                dense: true,
                                leading: Icon(f.icon),
                                title: Text(f.title),
                                subtitle: Text(
                                  f.where == null
                                      ? g.label
                                      : '${g.label} · ${f.where}',
                                  maxLines: 2,
                                  overflow: TextOverflow.ellipsis,
                                ),
                                onTap: () => _go(f.run),
                              ),
                          ],
                        ))
                : ListView(
                    key: const ValueKey('function_groups'),
                    padding: const EdgeInsets.symmetric(horizontal: 8),
                    children: [
                      for (final (i, g) in widget.groups.indexed) ...[
                        ListTile(
                          key: ValueKey('menu_group_$i'),
                          selected: widget.selectedGroup == i,
                          selectedTileColor: scheme.secondaryContainer,
                          selectedColor: scheme.onSecondaryContainer,
                          shape: const StadiumBorder(),
                          leading: _icon(g, widget.selectedGroup == i),
                          title: Text(g.label),
                          onTap: () => g.onOpen == null
                              ? setState(
                                  () => _open.contains(i)
                                      ? _open.remove(i)
                                      : _open.add(i),
                                )
                              : _go(g.onOpen!),
                          trailing: g.functions.isEmpty
                              ? null
                              : IconButton(
                                  key: ValueKey('menu_group_toggle_$i'),
                                  tooltip: _open.contains(i)
                                      ? 'ซ่อนเมนูย่อย'
                                      : 'แสดงเมนูย่อย',
                                  icon: Icon(
                                    _open.contains(i)
                                        ? Icons.expand_less
                                        : Icons.expand_more,
                                  ),
                                  onPressed: () => setState(
                                    () => _open.contains(i)
                                        ? _open.remove(i)
                                        : _open.add(i),
                                  ),
                                ),
                        ),
                        if (_open.contains(i))
                          for (final f in g.functions)
                            ListTile(
                              key: ValueKey('menu_function_${f.id}'),
                              dense: true,
                              contentPadding: const EdgeInsets.only(
                                left: 56,
                                right: 12,
                              ),
                              shape: const StadiumBorder(),
                              title: Text(f.title),
                              onTap: () => _go(f.run),
                            ),
                      ],
                    ],
                  ),
          ),
        ],
      ),
    );
  }
}
