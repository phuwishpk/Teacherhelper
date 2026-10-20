import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/auth/session.dart';
import '../../core/live/live_session.dart';
import '../../core/push/push_routes.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'notice.dart';
import 'notices_providers.dart';
import 'notices_repository.dart';

/// The notification inbox (DESIGN §38 step 2): every push the server sent,
/// live from Firestore, for teachers and students alike. A tap opens what
/// the push would have opened (`routeForPush`) and marks it read; the
/// server flips `read` from its queue, so the tile is dimmed locally first.
class NoticesScreen extends ConsumerStatefulWidget {
  const NoticesScreen({super.key});

  @override
  ConsumerState<NoticesScreen> createState() => _NoticesScreenState();
}

class _NoticesScreenState extends ConsumerState<NoticesScreen> {
  /// Ids marked read here, ahead of the server's write.
  final _readLocally = <String>{};

  Future<void> _markRead(List<String> ids) async {
    final pending = ids.where((id) => !_readLocally.contains(id)).toList();
    if (pending.isEmpty) return;
    setState(() => _readLocally.addAll(pending));
    try {
      await ref.read(noticesRepositoryProvider).markRead(pending);
    } catch (e) {
      // The next listing still shows it unread; nothing to recover here.
      debugPrint('markRead failed: $e');
    }
  }

  void _open(Notice notice) {
    _markRead([notice.id]);
    final user = ref.read(currentUserProvider);
    final route = user == null ? null : routeForPush(notice.data, user);
    if (route != null) context.push(route);
  }

  @override
  Widget build(BuildContext context) {
    final notices = ref.watch(noticesProvider);
    final unread = [
      for (final n in notices.value ?? const <Notice>[])
        if (!n.read && !_readLocally.contains(n.id)) n.id,
    ];
    return Scaffold(
      appBar: AppBar(
        title: const Text('การแจ้งเตือน'),
        actions: [
          if (unread.isNotEmpty)
            TextButton(
              key: const ValueKey('notices_read_all'),
              onPressed: () => _markRead(unread),
              child: const Text('อ่านทั้งหมด'),
            ),
        ],
      ),
      body: AsyncView(
        value: notices,
        onRetry: () => ref.invalidate(noticesProvider),
        data: (items) {
          if (items == null) {
            return _Unavailable(ref.watch(liveSessionProvider));
          }
          if (items.isEmpty) {
            return const EmptyView(
              icon: Icons.notifications_none_outlined,
              title: 'ยังไม่มีการแจ้งเตือน',
              message:
                  'เมื่อตรวจการบ้านเสร็จ มีคำขอ หรือมีโพสต์ใหม่ จะแสดงที่นี่',
            );
          }
          return ContentColumn(
            child: ListView.separated(
              padding: const EdgeInsets.symmetric(vertical: 8),
              itemCount: items.length,
              separatorBuilder: (_, _) => const Divider(height: 1),
              itemBuilder: (context, i) {
                final notice = items[i];
                final read = notice.read || _readLocally.contains(notice.id);
                return _NoticeTile(
                  notice: notice,
                  read: read,
                  onTap: () => _open(notice),
                );
              },
            ),
          );
        },
      ),
    );
  }
}

class _NoticeTile extends StatelessWidget {
  const _NoticeTile({
    required this.notice,
    required this.read,
    required this.onTap,
  });

  final Notice notice;
  final bool read;
  final VoidCallback onTap;

  static IconData iconFor(String kind) => switch (kind) {
    'grading_done' => Icons.task_alt_outlined,
    'appeal_opened' || 'appeal_resolved' => Icons.forum_outlined,
    'results_published' => Icons.grading_outlined,
    'retake_requested' => Icons.replay_outlined,
    'classroom_work_imported' => Icons.cloud_download_outlined,
    'google_reconnect' => Icons.link_off_outlined,
    'grades_published' => Icons.school_outlined,
    'course_request' || 'course_request_decided' => Icons.inbox_outlined,
    'class_post' => Icons.campaign_outlined,
    _ => Icons.notifications_outlined,
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    return ListTile(
      key: ValueKey('notice_${notice.id}'),
      leading: CircleAvatar(
        backgroundColor: read
            ? scheme.surfaceContainerHighest
            : scheme.primaryContainer,
        foregroundColor: read
            ? scheme.onSurfaceVariant
            : scheme.onPrimaryContainer,
        child: Icon(iconFor(notice.kind)),
      ),
      title: Text(
        notice.body,
        style: theme.textTheme.bodyLarge?.copyWith(
          fontWeight: read ? FontWeight.normal : FontWeight.w600,
        ),
      ),
      subtitle: Text(formatThaiDateTime(notice.createdAt)),
      trailing: read
          ? null
          : Icon(
              Icons.circle,
              size: 10,
              color: scheme.primary,
              key: ValueKey('notice_unread_${notice.id}'),
            ),
      onTap: onTap,
    );
  }
}

/// The inbox needs Firebase in the app and Firestore on the server.
class _Unavailable extends StatelessWidget {
  const _Unavailable(this.live);

  final LiveSession live;

  @override
  Widget build(BuildContext context) {
    return EmptyView(
      icon: Icons.cloud_off_outlined,
      title: 'กล่องแจ้งเตือนยังไม่พร้อม',
      message: live.enabled
          ? 'เซิร์ฟเวอร์นี้ยังไม่ได้เปิดข้อมูลสด (Firestore) '
                'การแจ้งเตือนยังส่งทางระบบของเครื่องตามปกติ'
          : 'แอปรุ่นนี้ไม่ได้ตั้งค่า Firebase จึงไม่มีกล่องแจ้งเตือน',
    );
  }
}
