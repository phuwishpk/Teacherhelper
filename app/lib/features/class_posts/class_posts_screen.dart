import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classrooms_providers.dart';
import 'class_post_models.dart';
import 'class_posts_repository.dart';
import 'post_card.dart';

/// `/classrooms/:id/posts`: the posts of a classroom (DESIGN §30.1). Every
/// teacher of the room reads the published ones; each sees their own drafts
/// and scheduled posts and edits or deletes only their own.
class ClassPostsScreen extends ConsumerWidget {
  const ClassPostsScreen({super.key, required this.classroomId});

  final int classroomId;

  Future<void> _publish(
    BuildContext context,
    WidgetRef ref,
    ClassPost p,
  ) async {
    try {
      await ref
          .read(classPostsRepositoryProvider)
          .update(p.id, _asDraft(p, PostState.published));
      ref.invalidate(classroomPostsProvider(classroomId));
      if (context.mounted) showMessage(context, 'เผยแพร่แล้ว');
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _retryGoogle(
    BuildContext context,
    WidgetRef ref,
    ClassPost p,
  ) async {
    try {
      final g = await ref.read(classPostsRepositoryProvider).retryGoogle(p.id);
      ref.invalidate(classroomPostsProvider(classroomId));
      if (!context.mounted) return;
      showMessage(context, switch (g.state) {
        GoogleSendState.sent => 'ส่งไป Google Classroom แล้ว',
        GoogleSendState.failed => g.lastError ?? 'ส่งไม่สำเร็จ',
        _ => 'กำลังส่งไป Google Classroom',
      });
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _delete(BuildContext context, WidgetRef ref, ClassPost p) async {
    final ok = await confirm(
      context,
      title: 'ลบโพสต์นี้?',
      message: p.isPublished
          ? 'นักเรียนจะไม่เห็นโพสต์นี้อีก ไฟล์ที่แนบเฉพาะโพสต์นี้จะถูกลบด้วย'
          : 'ไฟล์ที่แนบเฉพาะโพสต์นี้จะถูกลบด้วย',
      confirmLabel: 'ลบ',
      destructive: true,
    );
    if (!ok || !context.mounted) return;
    try {
      await ref.read(classPostsRepositoryProvider).delete(p.id);
      ref.invalidate(classroomPostsProvider(classroomId));
      ref.invalidate(postStorageUsageProvider);
      if (context.mounted) showMessage(context, 'ลบโพสต์แล้ว');
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final classroom = ref.watch(classroomProvider(classroomId));
    final posts = ref.watch(classroomPostsProvider(classroomId));
    final open = classroom.value != null && !classroom.value!.isClosed;
    final name = classroom.value?.name;

    return Scaffold(
      appBar: AppBar(title: Text(name == null ? 'โพสต์' : 'โพสต์ · $name')),
      floatingActionButtonLocation: const ContentFabLocation(),
      floatingActionButton: open
          ? FloatingActionButton.extended(
              heroTag: 'post_new',
              onPressed: () =>
                  context.push(AppRoutes.classPostNew(classroomId)),
              icon: const Icon(Icons.edit_outlined),
              label: const Text('เขียนโพสต์'),
            )
          : null,
      body: AsyncView(
        value: posts,
        onRetry: () => ref.invalidate(classroomPostsProvider(classroomId)),
        data: (list) {
          if (list.isEmpty) {
            return EmptyView(
              icon: Icons.campaign_outlined,
              title: 'ยังไม่มีโพสต์',
              message: open
                  ? 'กด "เขียนโพสต์" เพื่อประกาศหรือแจกเอกสารประกอบให้นักเรียนห้องนี้'
                  : 'ห้องนี้ปิดแล้ว เขียนโพสต์ใหม่ไม่ได้',
            );
          }
          return RefreshIndicator(
            onRefresh: () =>
                ref.refresh(classroomPostsProvider(classroomId).future),
            child: ContentColumn(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
              child: ListView.separated(
                itemCount: list.length,
                separatorBuilder: (_, _) => const SizedBox(height: 12),
                itemBuilder: (context, i) {
                  final p = list[i];
                  return PostCard(
                    key: ValueKey('post_${p.id}'),
                    post: p,
                    teacherView: true,
                    onRetryGoogle: p.isMine && open
                        ? () => _retryGoogle(context, ref, p)
                        : null,
                    menu: p.isMine && open
                        ? PopupMenuButton<String>(
                            key: ValueKey('post_menu_${p.id}'),
                            tooltip: 'ตัวเลือก',
                            onSelected: (v) => switch (v) {
                              'edit' => context.push(
                                AppRoutes.classPostEdit(classroomId, p.id),
                              ),
                              'publish' => _publish(context, ref, p),
                              _ => _delete(context, ref, p),
                            },
                            itemBuilder: (context) => [
                              const PopupMenuItem(
                                value: 'edit',
                                child: Text('แก้ไข'),
                              ),
                              if (!p.isPublished)
                                const PopupMenuItem(
                                  value: 'publish',
                                  child: Text('เผยแพร่ตอนนี้'),
                                ),
                              const PopupMenuItem(
                                value: 'delete',
                                child: Text('ลบ'),
                              ),
                            ],
                          )
                        : null,
                  );
                },
              ),
            ),
          );
        },
      ),
    );
  }
}

/// The post as it is, with another [state] (the "เผยแพร่ตอนนี้" action).
PostDraft _asDraft(ClassPost p, PostState state) => PostDraft(
  kind: p.kind,
  state: state,
  title: p.title,
  body: p.body,
  courseId: p.course?.id,
  unitId: p.unit?.id,
  topic: p.unit == null ? p.topic : null,
  studentIds: p.forStudents ? p.studentIds : null,
  attachments: p.attachments,
);
