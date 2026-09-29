import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../review/review_labels.dart';
import '../review/review_models.dart';
import '../review/review_providers.dart';
import '../review/score_stepper.dart';

/// Open appeals of the teacher's classrooms (DESIGN §13, §9.5). Accepting
/// changes the score (logged as `appeal_accepted`), rejecting keeps it;
/// either way the student is notified.
class AppealsScreen extends ConsumerWidget {
  const AppealsScreen({super.key});

  Future<void> _resolve(
    BuildContext context,
    WidgetRef ref,
    Appeal appeal,
  ) async {
    final decision = await showDialog<_AppealDecision>(
      context: context,
      builder: (_) => _ResolveAppealDialog(appeal: appeal),
    );
    if (decision == null || !context.mounted) return;
    try {
      await ref
          .read(openAppealsProvider.notifier)
          .resolve(
            appeal,
            accept: decision.accept,
            teacherNote: decision.note,
            finalScore: decision.score,
          );
      if (context.mounted) {
        showMessage(context, 'ตอบคำขอแล้ว ระบบแจ้งนักเรียนให้ทราบ');
      }
    } catch (e) {
      if (context.mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final appeals = ref.watch(openAppealsProvider);
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('คำขอให้ตรวจใหม่')),
      body: AsyncView(
        value: appeals,
        onRetry: () => ref.invalidate(openAppealsProvider),
        data: (list) {
          if (list.isEmpty) {
            return const EmptyView(
              icon: Icons.feedback_outlined,
              title: 'ไม่มีคำขอที่รอตอบ',
              message:
                  'หลังเผยแพร่ผล นักเรียนขอให้ตรวจใหม่ได้ข้อละครั้ง คำขอจะมาอยู่ที่นี่',
            );
          }
          return RefreshIndicator(
            onRefresh: () => ref.read(openAppealsProvider.notifier).refresh(),
            child: ContentColumn(
              child: ListView(
                children: [
                  for (final a in list)
                    Card(
                      child: Padding(
                        padding: const EdgeInsets.all(16),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              [
                                a.assignmentTitle ?? 'การบ้าน',
                                if (a.questionPosition != null)
                                  'ข้อ ${a.questionPosition}',
                              ].join(' · '),
                              style: theme.textTheme.titleMedium,
                            ),
                            if (a.student != null) Text(a.student!.label),
                            if (a.currentScore != null)
                              Text(
                                'คะแนนตอนนี้ ${formatScore(a.currentScore)}'
                                '${a.maxPoints == null ? '' : '/${formatScore(a.maxPoints)}'}',
                              ),
                            const SizedBox(height: 8),
                            Text(
                              a.reason == null || a.reason!.isEmpty
                                  ? 'นักเรียนไม่ได้ระบุเหตุผล'
                                  : 'เหตุผลของนักเรียน: "${a.reason}"',
                            ),
                            if (a.createdAt != null)
                              Text(
                                'ขอเมื่อ ${formatThaiDateTime(a.createdAt!)}',
                                style: theme.textTheme.bodySmall,
                              ),
                            Align(
                              alignment: Alignment.centerRight,
                              child: Wrap(
                                spacing: 8,
                                children: [
                                  if (a.assignmentId != null &&
                                      a.responseId != null)
                                    TextButton(
                                      onPressed: () => context.push(
                                        AppRoutes.reviewResponse(
                                          a.assignmentId!,
                                          a.responseId!,
                                        ),
                                      ),
                                      child: const Text('ดูคำตอบ'),
                                    ),
                                  FilledButton(
                                    onPressed: () => _resolve(context, ref, a),
                                    child: const Text('ตอบคำขอ'),
                                  ),
                                ],
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _AppealDecision {
  const _AppealDecision({required this.accept, this.score, this.note});

  final bool accept;
  final double? score;
  final String? note;
}

class _ResolveAppealDialog extends StatefulWidget {
  const _ResolveAppealDialog({required this.appeal});

  final Appeal appeal;

  @override
  State<_ResolveAppealDialog> createState() => _ResolveAppealDialogState();
}

class _ResolveAppealDialogState extends State<_ResolveAppealDialog> {
  bool _accept = true;
  late double? _score = widget.appeal.currentScore;
  final _note = TextEditingController();
  String? _error;

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  void _submit() {
    final note = _note.text.trim();
    if (_accept && _score == null) {
      setState(() => _error = 'ให้คะแนนใหม่ก่อน');
      return;
    }
    if (!_accept && note.isEmpty) {
      setState(() => _error = 'บอกเหตุผลให้นักเรียนทราบว่าทำไมยืนยันคะแนนเดิม');
      return;
    }
    Navigator.of(context).pop(
      _AppealDecision(
        accept: _accept,
        score: _accept ? _score : null,
        note: note.isEmpty ? null : note,
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final max = widget.appeal.maxPoints;
    return AlertDialog(
      title: const Text('ตอบคำขอให้ตรวจใหม่'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SegmentedButton<bool>(
              segments: const [
                ButtonSegment(value: true, label: Text('ปรับคะแนน')),
                ButtonSegment(value: false, label: Text('ยืนยันคะแนนเดิม')),
              ],
              selected: {_accept},
              onSelectionChanged: (s) => setState(() {
                _accept = s.first;
                _error = null;
              }),
            ),
            const SizedBox(height: 12),
            if (_accept)
              ScoreStepper(
                value: _score,
                max: max ?? 100,
                onChanged: (v) => setState(() => _score = v),
              ),
            if (_accept &&
                widget.appeal.totalOverridden &&
                _score != widget.appeal.currentScore)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  totalOverrideClearWarning,
                  key: const ValueKey('total_override_warning'),
                  style: TextStyle(
                    color: Theme.of(context).colorScheme.tertiary,
                  ),
                ),
              ),
            const SizedBox(height: 8),
            TextField(
              controller: _note,
              maxLines: 3,
              minLines: 2,
              decoration: InputDecoration(
                border: const OutlineInputBorder(),
                labelText: _accept
                    ? 'ข้อความถึงนักเรียน (ไม่บังคับ)'
                    : 'เหตุผลถึงนักเรียน',
              ),
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(onPressed: _submit, child: const Text('ส่งคำตอบ')),
      ],
    );
  }
}
