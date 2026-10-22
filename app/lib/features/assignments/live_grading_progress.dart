import 'package:cloud_firestore/cloud_firestore.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/live/live_scope.dart';

/// users/{teacher}/assignments/{id} (DESIGN §38.9): how many hand-ins of the
/// assignment are in each status, written by the server's queue whenever one
/// changes.
class GradingProgress {
  const GradingProgress({
    required this.total,
    required this.awaitingScan,
    required this.grading,
    required this.needsReview,
    required this.reviewed,
    required this.published,
    required this.awaitingAiKey,
  });

  final int total;
  final int awaitingScan;
  final int grading;
  final int needsReview;
  final int reviewed;
  final int published;

  /// Answers left for the teacher because no usable Gemini key was set.
  final int awaitingAiKey;

  /// Hand-ins the AI is done with (waiting for, or past, the teacher).
  int get aiDone => needsReview + reviewed + published;

  /// Hand-ins that have something to grade (scanned or uploaded).
  int get handedIn => total - awaitingScan;

  double get fraction => handedIn == 0 ? 0 : aiDone / handedIn;

  factory GradingProgress.fromMap(Map<String, dynamic> m) {
    int n(String key) => (m[key] as num?)?.toInt() ?? 0;
    return GradingProgress(
      total: n('total'),
      awaitingScan: n('awaiting_scan'),
      grading: n('grading'),
      needsReview: n('needs_review'),
      reviewed: n('reviewed'),
      published: n('published'),
      awaitingAiKey: n('awaiting_ai_key'),
    );
  }
}

/// Null while the live layer is unavailable or nothing was handed in yet.
final liveGradingProgressProvider = StreamProvider.autoDispose
    .family<GradingProgress?, int>((ref, assignmentId) {
      final scope = liveUserScope(ref);
      if (scope == null) return Stream.value(null);
      return scope.db
          .doc('users/${scope.uid}/assignments/$assignmentId')
          .snapshots()
          .map(
            (DocumentSnapshot<Map<String, dynamic>> doc) =>
                doc.exists ? GradingProgress.fromMap(doc.data()!) : null,
          );
    });

/// "ความคืบหน้าการตรวจ" on the assignment page: moves on its own while the
/// AI grades. Takes no space when the live layer is off or nothing came in.
class LiveGradingProgressCard extends ConsumerWidget {
  const LiveGradingProgressCard({super.key, required this.assignmentId});

  final int assignmentId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final progress = ref.watch(liveGradingProgressProvider(assignmentId)).value;
    if (progress == null || progress.handedIn == 0) {
      return const SizedBox.shrink();
    }
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final parts = [
      if (progress.grading > 0) 'AI กำลังตรวจ ${progress.grading}',
      if (progress.needsReview > 0) 'รอครูตรวจทาน ${progress.needsReview}',
      if (progress.reviewed > 0) 'ตรวจทานครบ รอเผยแพร่ ${progress.reviewed}',
      if (progress.published > 0) 'เผยแพร่ผลแล้ว ${progress.published}',
    ];
    return Padding(
      padding: const EdgeInsets.only(top: 12),
      child: Card(
        key: const ValueKey('live_grading_progress'),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'ความคืบหน้าการตรวจ',
                      style: theme.textTheme.titleMedium,
                    ),
                  ),
                  Icon(Icons.bolt_outlined, size: 16, color: scheme.primary),
                  const SizedBox(width: 4),
                  Text(
                    'อัปเดตสด',
                    style: theme.textTheme.labelSmall?.copyWith(
                      color: scheme.primary,
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 12),
              LinearProgressIndicator(
                key: const ValueKey('live_grading_bar'),
                value: progress.fraction,
              ),
              const SizedBox(height: 8),
              Text(
                'AI ตรวจแล้ว ${progress.aiDone} จาก ${progress.handedIn} คนที่ส่ง',
                key: const ValueKey('live_grading_summary'),
                style: theme.textTheme.bodyMedium,
              ),
              if (parts.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text(
                  parts.join(' · '),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.onSurfaceVariant,
                  ),
                ),
              ],
              if (progress.awaitingAiKey > 0) ...[
                const SizedBox(height: 4),
                Text(
                  'รอคีย์ Gemini ${progress.awaitingAiKey} ข้อ ใส่คีย์ในหน้าตั้งค่าแล้ว AI จะตรวจต่อ',
                  key: const ValueKey('live_grading_ai_key'),
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: scheme.error,
                  ),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
