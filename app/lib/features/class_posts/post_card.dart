import 'package:flutter/material.dart';

import '../../core/util/thai_date.dart';
import '../../core/widgets/content_column.dart';
import 'class_post_models.dart';
import 'post_files.dart';

/// One post as it reads in the stream: who and when, the text, the
/// attachments. [menu] is the teacher's edit/delete button; [teacherView]
/// adds the state and the recipients.
class PostCard extends StatelessWidget {
  const PostCard({
    super.key,
    required this.post,
    this.menu,
    this.teacherView = false,
    this.showClassroom = false,
    this.onTap,
  });

  final ClassPost post;
  final Widget? menu;
  final bool teacherView;
  final bool showClassroom;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final when = post.isPublished ? post.publishedAt : post.scheduledAt;
    final byline = [
      post.authorName,
      if (post.course case final c?) c.label,
      if (showClassroom) ?post.classroom?.name,
      if (when != null)
        post.state == PostState.scheduled
            ? 'เผยแพร่ ${formatThaiDateTime(when)}'
            : formatThaiDateTime(when),
    ].where((s) => s.isNotEmpty).join(' · ');
    final body = post.body;
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 8, 16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Padding(
                    padding: const EdgeInsets.only(top: 2, right: 12),
                    child: Icon(
                      post.kind == PostKind.material
                          ? Icons.menu_book_outlined
                          : Icons.campaign_outlined,
                      color: theme.colorScheme.primary,
                    ),
                  ),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          post.title ?? post.kind.label,
                          style: theme.textTheme.titleMedium,
                        ),
                        if (byline.isNotEmpty) Text(byline, style: muted),
                      ],
                    ),
                  ),
                  ?menu,
                  if (menu == null) const SizedBox(width: 8),
                ],
              ),
              if (_chips(context).isNotEmpty) ...[
                const SizedBox(height: 8),
                Wrap(spacing: 8, runSpacing: 4, children: _chips(context)),
              ],
              if (body != null && body.isNotEmpty) ...[
                const SizedBox(height: 12),
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: SelectableText(body, style: theme.textTheme.bodyLarge),
                ),
              ],
              if (post.attachments.isNotEmpty) ...[
                const SizedBox(height: 12),
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: Column(
                    children: [
                      for (final (i, a) in post.attachments.indexed) ...[
                        if (i > 0) const SizedBox(height: 8),
                        PostAttachmentTile(attachment: a),
                      ],
                    ],
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  List<Widget> _chips(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return [
      if (teacherView && !post.isPublished)
        StatusChip(label: post.state.label, color: scheme.tertiary),
      if (post.topic case final t? when t.isNotEmpty) StatusChip(label: t),
      if (teacherView && post.forStudents)
        StatusChip(label: 'เฉพาะ ${post.studentIds.length} คน'),
      if (!teacherView && post.forStudents)
        const StatusChip(label: 'ถึงคุณโดยเฉพาะ'),
    ];
  }
}
