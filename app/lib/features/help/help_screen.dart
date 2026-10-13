import 'package:flutter/material.dart';

import '../../core/theme/breakpoints.dart';
import '../../core/widgets/content_column.dart';
import 'help_content.dart';

/// "คู่มือการใช้งาน": the in-app guide opened from the shell's sidebar or
/// app bar (DESIGN §27). One list of topics per role (help_content.dart),
/// searchable; a topic shows its steps, the situations it covers and the
/// notes. Phones and tablets expand topics in place, a desktop shows the
/// topic list beside the open topic.
class HelpScreen extends StatefulWidget {
  const HelpScreen({super.key, required this.role});

  final HelpRole role;

  @override
  State<HelpScreen> createState() => _HelpScreenState();
}

class _HelpScreenState extends State<HelpScreen> {
  final _query = TextEditingController();
  String? _openId;

  @override
  void dispose() {
    _query.dispose();
    super.dispose();
  }

  List<HelpTopic> get _topics {
    final q = _query.text.trim().toLowerCase();
    final all = helpTopicsFor(widget.role);
    if (q.isEmpty) return all;
    return [
      for (final t in all)
        if (t.searchText.contains(q)) t,
    ];
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final topics = _topics;
    final expanded = context.windowSize == WindowSize.expanded;
    final roleLabel = switch (widget.role) {
      HelpRole.teacher => 'สำหรับครู',
      HelpRole.student => 'สำหรับนักเรียน',
    };

    final search = TextField(
      key: const ValueKey('help_search'),
      controller: _query,
      decoration: InputDecoration(
        prefixIcon: const Icon(Icons.search),
        hintText: 'ค้นหา เช่น รหัสผ่าน, สแกน, เกรด',
        suffixIcon: _query.text.isEmpty
            ? null
            : IconButton(
                icon: const Icon(Icons.clear),
                onPressed: () => setState(_query.clear),
              ),
      ),
      onChanged: (_) => setState(() {}),
    );

    final header = Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('คู่มือการใช้งาน $roleLabel', style: theme.textTheme.titleLarge),
        const SizedBox(height: 4),
        Text(
          'แต่ละหัวข้อมีขั้นตอน สถานการณ์ที่พบบ่อยพร้อมวิธีจัดการ และข้อควรรู้ '
          'ชื่อปุ่มและเมนูเขียนใน «» ตามที่แสดงในระบบ',
          style: theme.textTheme.bodyMedium?.copyWith(
            color: theme.colorScheme.onSurfaceVariant,
          ),
        ),
        const SizedBox(height: 12),
        search,
        const SizedBox(height: 12),
      ],
    );

    final empty = Padding(
      padding: const EdgeInsets.symmetric(vertical: 32),
      child: Center(
        child: Text(
          'ไม่พบหัวข้อที่ตรงกับคำค้น',
          key: const ValueKey('help_empty'),
          style: theme.textTheme.bodyLarge,
        ),
      ),
    );

    Widget body;
    if (expanded) {
      final open = topics.isEmpty
          ? null
          : topics.firstWhere(
              (t) => t.id == _openId,
              orElse: () => topics.first,
            );
      body = Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          header,
          if (open == null)
            empty
          else
            Expanded(
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 300,
                    child: ListView(
                      children: [
                        for (final t in topics)
                          ListTile(
                            key: ValueKey('help_topic_${t.id}'),
                            leading: Icon(t.icon),
                            title: Text(t.title),
                            selected: t.id == open.id,
                            onTap: () => setState(() => _openId = t.id),
                          ),
                      ],
                    ),
                  ),
                  const VerticalDivider(width: 1),
                  Expanded(
                    child: SingleChildScrollView(
                      padding: const EdgeInsets.fromLTRB(24, 0, 0, 24),
                      child: _TopicBody(topic: open, showTitle: true),
                    ),
                  ),
                ],
              ),
            ),
        ],
      );
    } else {
      body = ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        children: [
          header,
          if (topics.isEmpty) empty,
          for (final t in topics)
            Card(
              clipBehavior: Clip.antiAlias,
              child: ExpansionTile(
                key: ValueKey('help_topic_${t.id}'),
                leading: Icon(t.icon),
                title: Text(t.title),
                subtitle: Text(
                  t.intro,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
                initiallyExpanded: t.id == _openId,
                onExpansionChanged: (open) =>
                    setState(() => _openId = open ? t.id : null),
                childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
                children: [_TopicBody(topic: t, showTitle: false)],
              ),
            ),
        ],
      );
    }

    return Scaffold(
      appBar: AppBar(title: const Text('คู่มือการใช้งาน')),
      body: expanded
          ? ContentColumn(maxWidth: 1100, child: body)
          : ContentColumn(child: body),
    );
  }
}

/// A topic's steps, situations and notes.
class _TopicBody extends StatelessWidget {
  const _TopicBody({required this.topic, required this.showTitle});

  final HelpTopic topic;
  final bool showTitle;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodyMedium?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (showTitle) ...[
          Row(
            children: [
              Icon(topic.icon, color: theme.colorScheme.primary),
              const SizedBox(width: 8),
              Expanded(
                child: Text(topic.title, style: theme.textTheme.titleLarge),
              ),
            ],
          ),
          const SizedBox(height: 8),
        ],
        Text(topic.intro, style: showTitle ? theme.textTheme.bodyLarge : muted),
        if (topic.steps.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text('ขั้นตอน', style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          for (final (i, step) in topic.steps.indexed)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 4),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  CircleAvatar(
                    radius: 12,
                    backgroundColor: theme.colorScheme.primaryContainer,
                    child: Text(
                      '${i + 1}',
                      style: theme.textTheme.labelMedium?.copyWith(
                        color: theme.colorScheme.onPrimaryContainer,
                      ),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(child: Text(step)),
                ],
              ),
            ),
        ],
        if (topic.useCases.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text('สถานการณ์ที่พบบ่อย', style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          for (final u in topic.useCases)
            Card.filled(
              margin: const EdgeInsets.symmetric(vertical: 4),
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(u.title, style: theme.textTheme.titleSmall),
                    const SizedBox(height: 4),
                    Text(u.story),
                  ],
                ),
              ),
            ),
        ],
        if (topic.notes.isNotEmpty) ...[
          const SizedBox(height: 16),
          Text('ข้อควรรู้', style: theme.textTheme.titleMedium),
          const SizedBox(height: 4),
          for (final n in topic.notes)
            Padding(
              padding: const EdgeInsets.symmetric(vertical: 3),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.info_outline,
                    size: 18,
                    color: theme.colorScheme.onSurfaceVariant,
                  ),
                  const SizedBox(width: 8),
                  Expanded(child: Text(n, style: muted)),
                ],
              ),
            ),
        ],
      ],
    );
  }
}
