import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'class_post_models.dart';
import 'class_posts_repository.dart';
import 'post_card.dart';

/// The "โพสต์" tab of a subject (DESIGN §30.3): the stream (everything of
/// the classroom, newest first) and "งานของชั้นเรียน" (materials and
/// homework grouped by topic). Homework and results open their own pages.
class StudentClassFeedBody extends ConsumerStatefulWidget {
  const StudentClassFeedBody({super.key, this.courseId, this.classroomId});

  final int? courseId;
  final int? classroomId;

  @override
  ConsumerState<StudentClassFeedBody> createState() =>
      _StudentClassFeedBodyState();
}

class _StudentClassFeedBodyState extends ConsumerState<StudentClassFeedBody> {
  bool _classwork = false;

  FeedKey get _key =>
      (courseId: widget.courseId, classroomId: widget.classroomId);

  @override
  Widget build(BuildContext context) {
    final feed = ref.watch(classFeedProvider(_key));
    return AsyncView(
      value: feed,
      onRetry: () => ref.invalidate(classFeedProvider(_key)),
      data: (items) {
        if (items.isEmpty) {
          return const EmptyView(
            icon: Icons.campaign_outlined,
            title: 'ยังไม่มีโพสต์',
            message: 'ประกาศ เอกสารประกอบ และงานจากครูจะแสดงที่นี่',
          );
        }
        final shown = _classwork
            ? [
                for (final i in items)
                  if (i.isClasswork) i,
              ]
            : items;
        return RefreshIndicator(
          onRefresh: () => ref.refresh(classFeedProvider(_key).future),
          child: ContentColumn(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
            child: ListView(
              children: [
                Align(
                  alignment: AlignmentDirectional.centerStart,
                  child: SegmentedButton<bool>(
                    key: const ValueKey('feed_view'),
                    showSelectedIcon: false,
                    segments: const [
                      ButtonSegment(value: false, label: Text('สตรีม')),
                      ButtonSegment(
                        value: true,
                        label: Text('งานของชั้นเรียน'),
                      ),
                    ],
                    selected: {_classwork},
                    onSelectionChanged: (s) =>
                        setState(() => _classwork = s.single),
                  ),
                ),
                const SizedBox(height: 12),
                if (shown.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 48),
                    child: EmptyView(
                      icon: Icons.menu_book_outlined,
                      title: 'ยังไม่มีงานของชั้นเรียน',
                      message: 'เอกสารประกอบและการบ้านจะจัดตามหัวข้อที่นี่',
                    ),
                  )
                else if (_classwork)
                  ..._byTopic(context, shown)
                else
                  for (final item in shown) ...[
                    FeedItemCard(item: item),
                    const SizedBox(height: 12),
                  ],
              ],
            ),
          ),
        );
      },
    );
  }

  /// Topics in the order they first appear; work without a topic last.
  List<Widget> _byTopic(BuildContext context, List<FeedItem> items) {
    final groups = <String, List<FeedItem>>{};
    for (final item in items) {
      groups.putIfAbsent(item.topic ?? '', () => []).add(item);
    }
    final topics = [
      ...groups.keys.where((t) => t.isNotEmpty),
      if (groups.containsKey('')) '',
    ];
    final theme = Theme.of(context);
    return [
      for (final topic in topics) ...[
        Padding(
          padding: const EdgeInsets.only(top: 8, bottom: 8),
          child: Text(
            topic.isEmpty ? 'ไม่มีหัวข้อ' : topic,
            key: ValueKey('feed_topic_$topic'),
            style: theme.textTheme.titleMedium,
          ),
        ),
        for (final item in groups[topic]!) ...[
          FeedItemCard(item: item),
          const SizedBox(height: 12),
        ],
      ],
    ];
  }
}

/// One row of the feed: a post in full, or a line that opens the homework
/// or the published result.
class FeedItemCard extends StatelessWidget {
  const FeedItemCard({
    super.key,
    required this.item,
    this.showClassroom = false,
  });

  final FeedItem item;
  final bool showClassroom;

  @override
  Widget build(BuildContext context) {
    if (item.post case final post?) {
      return PostCard(
        key: ValueKey('feed_post_${post.id}'),
        post: post,
        showClassroom: showClassroom,
      );
    }
    final result = item.type == FeedType.result;
    final where = [
      ?item.course?.label,
      if (showClassroom) ?item.classroom?.name,
    ];
    final detail = [
      ...where,
      if (result)
        if (item.at case final at?) 'ประกาศผล ${formatThaiDateTime(at)}',
      if (!result)
        if (item.dueAt case final due?)
          'ส่งภายใน ${formatThaiDateTime(due)}'
        else
          'ไม่กำหนดวันส่ง',
    ].join(' · ');
    return Card(
      clipBehavior: Clip.antiAlias,
      child: ListTile(
        key: ValueKey(
          result
              ? 'feed_result_${item.submissionId}'
              : 'feed_assignment_${item.assignmentId}',
        ),
        leading: Icon(
          result ? Icons.fact_check_outlined : Icons.assignment_outlined,
          color: Theme.of(context).colorScheme.primary,
        ),
        title: Text(
          result ? 'ผลตรวจ: ${item.title}' : 'การบ้าน: ${item.title}',
        ),
        subtitle: detail.isEmpty ? null : Text(detail),
        trailing: const Icon(Icons.chevron_right),
        onTap: () {
          if (result && item.submissionId != null) {
            context.push(AppRoutes.studentResult(item.submissionId!));
          } else if (item.assignmentId != null) {
            context.push(AppRoutes.studentHandIn(item.assignmentId!));
          }
        },
      ),
    );
  }
}

/// "โพสต์ล่าสุด" on the student's home: the newest posts of every
/// classroom. Hidden while there is none.
class LatestPostsCard extends ConsumerWidget {
  const LatestPostsCard({super.key});

  static const _key = (courseId: null, classroomId: null);
  static const _shown = 3;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final posts = [
      for (final i
          in ref.watch(classFeedProvider(_key)).value ?? const <FeedItem>[])
        if (i.post != null) i.post!,
    ];
    if (posts.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Card(
      key: const ValueKey('latest_posts'),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
            child: Text('โพสต์ล่าสุด', style: theme.textTheme.titleMedium),
          ),
          for (final p in posts.take(_shown))
            ListTile(
              key: ValueKey('latest_post_${p.id}'),
              leading: Icon(
                p.kind == PostKind.material
                    ? Icons.menu_book_outlined
                    : Icons.campaign_outlined,
              ),
              title: Text(
                p.title ?? (p.body ?? p.kind.label),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              subtitle: Text(
                [
                  ?p.classroom?.name,
                  p.authorName,
                  if (p.publishedAt case final at?) formatThaiDate(at),
                ].where((s) => s.isNotEmpty).join(' · '),
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push(AppRoutes.studentClassPost(p.id)),
            ),
          const SizedBox(height: 4),
        ],
      ),
    );
  }
}

/// `/student/posts/:id`: one post (from "โพสต์ล่าสุด" or a notification).
class StudentClassPostScreen extends ConsumerWidget {
  const StudentClassPostScreen({super.key, required this.postId});

  final int postId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final post = ref.watch(studentClassPostProvider(postId));
    return Scaffold(
      appBar: AppBar(title: Text(post.value?.kind.label ?? 'โพสต์')),
      body: AsyncView(
        value: post,
        onRetry: () => ref.invalidate(studentClassPostProvider(postId)),
        data: (p) => ContentColumn(
          padding: const EdgeInsets.all(16),
          child: ListView(children: [PostCard(post: p, showClassroom: true)]),
        ),
      ),
    );
  }
}
