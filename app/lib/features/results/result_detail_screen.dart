import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../../core/widgets/response_crop_image.dart';
import '../review/review_labels.dart';
import '../review/review_models.dart';
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
                                if (s.totalOverridden)
                                  Text(
                                    totalOverriddenNote,
                                    key: const ValueKey('total_overridden'),
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
                  if (d.exam case final exam?)
                    ..._examChildren(context, exam)
                  else if (d.answers.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(24),
                      child: Text(
                        'ยังไม่มีรายละเอียดรายข้อ',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  if (d.exam == null)
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

  /// The exam part (DESIGN §22.12): version, score per section, and the
  /// per-question result only when the teacher shows the key.
  List<Widget> _examChildren(BuildContext context, ExamStudentResult exam) {
    final theme = Theme.of(context);
    final items = exam.items;
    return [
      Card(
        margin: const EdgeInsets.only(top: 12),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              if (exam.versionLabel case final label?)
                Text(
                  'ชุด $label',
                  key: const ValueKey('exam_version_label'),
                  style: theme.textTheme.titleMedium,
                ),
              Text('คะแนนรายตอน', style: theme.textTheme.titleSmall),
              const SizedBox(height: 4),
              for (final s in exam.sections)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: 2),
                  child: Row(
                    children: [
                      Expanded(child: Text(s.title)),
                      Text('${formatScore(s.score)}/${formatScore(s.max)}'),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
      if (items == null)
        const Padding(
          key: ValueKey('exam_key_hidden'),
          padding: EdgeInsets.all(24),
          child: Text(
            'ครูยังไม่เปิดให้ดูเฉลยและผลรายข้อของข้อสอบนี้',
            textAlign: TextAlign.center,
          ),
        )
      else
        for (final item in items)
          _ExamItemCard(submissionId: submissionId, item: item),
    ];
  }
}

/// "ขอให้ครูตรวจใหม่" of one answer (once per answer).
Future<void> _appeal(
  BuildContext context,
  WidgetRef ref, {
  required int submissionId,
  required int responseId,
  required int number,
}) async {
  final reason = await showDialog<String>(
    context: context,
    builder: (_) => _AppealDialog(position: number),
  );
  if (reason == null || !context.mounted) return;
  try {
    await ref
        .read(resultsRepositoryProvider)
        .appeal(responseId, reason: reason.isEmpty ? null : reason);
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

/// The status of an appeal, or the button that sends one.
class _AppealRow extends ConsumerWidget {
  const _AppealRow({
    required this.submissionId,
    required this.responseId,
    required this.number,
    required this.appeal,
    required this.canAppeal,
  });

  final int submissionId;
  final int responseId;
  final int number;
  final Appeal? appeal;
  final bool canAppeal;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    if (appeal case final appeal?) {
      return Container(
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
            if (appeal.teacherNote case final note? when note.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text('ครู: $note'),
              ),
          ],
        ),
      );
    }
    if (!canAppeal) return const SizedBox.shrink();
    return Align(
      alignment: Alignment.centerRight,
      child: OutlinedButton.icon(
        onPressed: () => _appeal(
          context,
          ref,
          submissionId: submissionId,
          responseId: responseId,
          number: number,
        ),
        icon: const Icon(Icons.feedback_outlined),
        label: const Text('ขอให้ครูตรวจใหม่'),
      ),
    );
  }
}

/// One exam question with the key shown (§22.12): prompt, what the student
/// marked, the right answer and the score, in the student's own version.
class _ExamItemCard extends StatelessWidget {
  const _ExamItemCard({required this.submissionId, required this.item});

  final int submissionId;
  final ExamResultItem item;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final i = item;
    return Card(
      key: ValueKey('exam_item_${i.responseId}'),
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
                    'ข้อ ${i.number}',
                    style: theme.textTheme.titleMedium,
                  ),
                ),
                Text(
                  '${formatScore(i.score)}/${formatScore(i.maxPoints)} คะแนน',
                  style: theme.textTheme.titleMedium?.copyWith(
                    color: i.fullMarks ? Colors.green.shade700 : null,
                  ),
                ),
              ],
            ),
            if (i.sectionTitle case final t? when t.isNotEmpty)
              Text(t, style: theme.textTheme.bodySmall),
            if (i.promptText.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 4),
                child: Text(i.promptText),
              ),
            const SizedBox(height: 8),
            Text('คำตอบของเรา: ${i.markedText}'),
            Text('คำตอบที่ถูก: ${i.correctText}'),
            const SizedBox(height: 12),
            _AppealRow(
              submissionId: submissionId,
              responseId: i.responseId,
              number: i.number,
              appeal: i.appeal,
              canAppeal: i.canAppeal,
            ),
          ],
        ),
      ),
    );
  }
}

class _AnswerCard extends ConsumerWidget {
  const _AnswerCard({required this.submissionId, required this.answer});

  final int submissionId;
  final StudentAnswer answer;

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
            _AppealRow(
              submissionId: submissionId,
              responseId: a.responseId,
              number: a.position,
              appeal: appeal,
              canAppeal: a.canAppeal,
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
