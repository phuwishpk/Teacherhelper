import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../../core/widgets/response_crop_image.dart';
import '../review/review_labels.dart';
import 'results_repository.dart';
import 'retake_notice.dart';
import 'student_result.dart';

/// A student's published submission, question by question (DESIGN §9.7,
/// §13, §14.1): score, understanding, their own answer crop, the teacher-
/// approved explanation, what to practise next and "ขอให้ครูตรวจใหม่".
class ResultDetailScreen extends ConsumerWidget {
  const ResultDetailScreen({super.key, required this.submissionId});

  final int submissionId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(studentResultDetailProvider(submissionId));
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(detail.value?.summary.title ?? 'ผลการบ้าน')),
      body: AsyncView(
        value: detail,
        onRetry: () =>
            ref.invalidate(studentResultDetailProvider(submissionId)),
        data: (d) {
          final s = d.summary;
          return RefreshIndicator(
            onRefresh: () =>
                ref.refresh(studentResultDetailProvider(submissionId).future),
            child: ContentColumn(
              child: ListView(
                children: [
                  if (d.retakeReason case final reason?)
                    RetakeNotice(reason: reason),
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  s.title,
                                  style: theme.textTheme.titleLarge,
                                ),
                                if (s.subjectName != null) Text(s.subjectName!),
                                if (s.publishedAt != null)
                                  Text(
                                    'เผยแพร่ ${formatThaiDate(s.publishedAt!)}',
                                    style: theme.textTheme.bodySmall,
                                  ),
                              ],
                            ),
                          ),
                          if (s.totalScore != null)
                            Text(
                              '${formatScore(s.totalScore)}'
                              '${s.maxScore == null ? '' : '/${formatScore(s.maxScore)}'}',
                              style: theme.textTheme.headlineMedium,
                            ),
                        ],
                      ),
                    ),
                  ),
                  if (d.answers.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(24),
                      child: Text(
                        'ยังไม่มีรายละเอียดรายข้อ',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  for (final a in d.answers)
                    _AnswerCard(submissionId: submissionId, answer: a),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _AnswerCard extends ConsumerWidget {
  const _AnswerCard({required this.submissionId, required this.answer});

  final int submissionId;
  final StudentAnswer answer;

  Future<void> _appeal(BuildContext context, WidgetRef ref) async {
    final reason = await showDialog<String>(
      context: context,
      builder: (_) => _AppealDialog(position: answer.position),
    );
    if (reason == null || !context.mounted) return;
    try {
      await ref
          .read(resultsRepositoryProvider)
          .appeal(answer.responseId, reason: reason.isEmpty ? null : reason);
      ref.invalidate(studentResultDetailProvider(submissionId));
      if (context.mounted) {
        showMessage(context, 'ส่งคำขอแล้ว ครูจะตรวจข้อนี้อีกครั้ง');
      }
    } catch (e) {
      if (!context.mounted) return;
      showMessage(
        context,
        apiStatusCode(e) == 409 || apiErrorCode(e) == 'appeal_exists'
            ? 'ข้อนี้ขอให้ครูตรวจใหม่ไปแล้ว'
            : apiErrorMessage(e),
      );
      ref.invalidate(studentResultDetailProvider(submissionId));
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final a = answer;
    final appeal = a.appeal;
    return Card(
      margin: const EdgeInsets.only(top: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    'ข้อ ${a.position}',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                Text(
                  '${formatScore(a.score)}/${formatScore(a.maxPoints)} คะแนน',
                  style: theme.textTheme.titleMedium?.copyWith(
                    color: a.fullMarks ? Colors.green.shade700 : null,
                  ),
                ),
              ],
            ),
            if (a.promptText.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(a.promptText, style: theme.textTheme.bodySmall),
              ),
            if (a.understanding != null) ...[
              const SizedBox(height: 8),
              UnderstandingChip(understanding: a.understanding!),
            ],
            if (a.hasCrop) ...[
              const SizedBox(height: 12),
              ResponseCropImage(responseId: a.responseId, height: 140),
            ],
            if (a.hasFinalCrop) ...[
              const SizedBox(height: 8),
              ResponseCropImage(
                responseId: a.responseId,
                finalPart: true,
                height: 80,
              ),
            ],
            if (a.errorTypes.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(
                'จุดที่ควรระวัง: ${a.errorTypes.map((e) => e.label).join(', ')}',
              ),
            ],
            if (a.explanation case final text? when text.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(text),
            ],
            if (a.nextStep case final next? when next.isNotEmpty) ...[
              const SizedBox(height: 8),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    Icons.lightbulb_outline,
                    size: 20,
                    color: theme.colorScheme.primary,
                  ),
                  const SizedBox(width: 8),
                  Expanded(child: Text('ขั้นต่อไป: $next')),
                ],
              ),
            ],
            const SizedBox(height: 12),
            if (appeal != null)
              Container(
                key: const ValueKey('appeal_status'),
                width: double.infinity,
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: theme.colorScheme.surfaceContainerHighest,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Icon(Icons.feedback_outlined, size: 18),
                        const SizedBox(width: 8),
                        Text('คำขอให้ครูตรวจใหม่: ${appeal.statusLabel}'),
                      ],
                    ),
                    if (appeal.teacherNote case final note?
                        when note.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text('ครู: $note'),
                      ),
                  ],
                ),
              )
            else if (a.canAppeal)
              Align(
                alignment: Alignment.centerRight,
                child: OutlinedButton.icon(
                  onPressed: () => _appeal(context, ref),
                  icon: const Icon(Icons.feedback_outlined),
                  label: const Text('ขอให้ครูตรวจใหม่'),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

/// Returns the (possibly empty) reason, or null when cancelled.
class _AppealDialog extends StatefulWidget {
  const _AppealDialog({required this.position});

  final int position;

  @override
  State<_AppealDialog> createState() => _AppealDialogState();
}

class _AppealDialogState extends State<_AppealDialog> {
  final _reason = TextEditingController();

  @override
  void dispose() {
    _reason.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text('ขอให้ครูตรวจข้อ ${widget.position} ใหม่?'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'ขอได้ข้อละ 1 ครั้ง ครูจะดูคำตอบข้อนี้อีกครั้งแล้วแจ้งผลในแอป',
          ),
          const SizedBox(height: 12),
          TextField(
            key: const ValueKey('appeal_reason'),
            controller: _reason,
            maxLength: 500,
            minLines: 2,
            maxLines: 4,
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              labelText: 'เหตุผล (ไม่บังคับ)',
              hintText: 'เช่น ครูช่วยดูบรรทัดที่ 2 อีกครั้ง',
            ),
          ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          onPressed: () => Navigator.of(context).pop(_reason.text.trim()),
          child: const Text('ส่งคำขอ'),
        ),
      ],
    );
  }
}
