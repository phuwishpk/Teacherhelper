import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import '../../core/widgets/scan_page_image.dart';
import 'exam_answer.dart';
import 'review_models.dart';
import 'review_repository.dart';

/// The answer-sheet part of the review pane for an exam answer (DESIGN
/// §22.11): the warped page with the row highlighted, what code read and
/// why it is in doubt, and "คำตอบที่นักเรียนตั้งใจ" sent to
/// `POST /exam-responses/{id}/resolve`, which scores it by code against
/// the key (not a teacher override).
class ExamAnswerPanel extends ConsumerStatefulWidget {
  const ExamAnswerPanel({
    super.key,
    required this.detail,
    required this.onResolved,
    this.canAdvance = false,
  });

  final ResponseDetail detail;

  /// Called after a successful resolve; [advance] = go to the next answer.
  final void Function(bool advance) onResolved;
  final bool canAdvance;

  @override
  ConsumerState<ExamAnswerPanel> createState() => _ExamAnswerPanelState();
}

class _ExamAnswerPanelState extends ConsumerState<ExamAnswerPanel> {
  ExamAnswerView get e => widget.detail.exam!;

  late final Set<int> _options = {...(e.resolved?.selected ?? e.selected)};
  late final _value = TextEditingController(
    text: e.resolved?.value ?? e.value ?? '',
  );
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _value.dispose();
    super.dispose();
  }

  Future<void> _resolve({required bool advance}) async {
    final resolution = e.isNumeric
        ? ExamResolution.number(
            _value.text.trim().isEmpty ? null : _value.text.trim(),
          )
        : ExamResolution.options(_options.toList()..sort());
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await ref
          .read(reviewRepositoryProvider)
          .resolveExamAnswer(widget.detail.id, resolution);
      if (!mounted) return;
      showMessage(context, 'ใช้คำตอบนี้แล้ว คิดคะแนนตามเฉลย');
      widget.onResolved(advance);
    } catch (err) {
      if (mounted) setState(() => _error = apiErrorMessage(err));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final published = widget.detail.isPublished;
    final doubts = e.reviewDoubts;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          [
            if (e.versionLabel.isNotEmpty) 'ชุด ${e.versionLabel}',
            if (e.pageNo != null) 'หน้า ${e.pageNo}',
            'ข้อ ${e.sheetNo} บนกระดาษ',
          ].join(' · '),
          style: theme.textTheme.labelLarge,
        ),
        const SizedBox(height: 8),
        if (e.scanId case final scanId?) ...[
          ScanPageImage(
            key: ValueKey('exam_page_$scanId'),
            scanId: scanId,
            box: e.box,
          ),
          const SizedBox(height: 4),
          Text(
            e.box == null
                ? 'แตะภาพเพื่อขยาย'
                : 'กรอบสีแดงคือข้อ ${e.sheetNo} แตะภาพเพื่อขยาย',
            style: muted,
          ),
        ] else
          Text('ไม่มีภาพหน้ากระดาษของข้อนี้', style: muted),
        const SizedBox(height: 12),
        Text('อ่านได้: ${e.readText}', key: const ValueKey('exam_read_text')),
        if (doubts.isNotEmpty) ...[
          const SizedBox(height: 4),
          Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              for (final d in doubts)
                StatusChip(
                  label: examDoubtLabel(d),
                  color: theme.colorScheme.error,
                ),
            ],
          ),
        ],
        if (e.resolvedText case final text?) ...[
          const SizedBox(height: 4),
          Text(
            'ครูอ่านรอยฝนแล้ว: $text',
            key: const ValueKey('exam_resolved_text'),
            style: TextStyle(color: Colors.green.shade800),
          ),
        ],
        const Divider(height: 24),
        Text('คำตอบที่นักเรียนตั้งใจ', style: theme.textTheme.labelLarge),
        const SizedBox(height: 4),
        if (e.isNumeric)
          TextField(
            key: const ValueKey('exam_value'),
            controller: _value,
            enabled: !_busy && !published,
            keyboardType: const TextInputType.numberWithOptions(
              signed: true,
              decimal: true,
            ),
            decoration: const InputDecoration(
              border: OutlineInputBorder(),
              hintText: 'เช่น 12.5 (เว้นว่าง = ไม่ได้ตอบ)',
            ),
          )
        else ...[
          Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              for (var p = 1; p <= e.labels.length; p++)
                FilterChip(
                  key: ValueKey('exam_option_$p'),
                  label: Text(e.labels[p - 1]),
                  selected: _options.contains(p),
                  onSelected: _busy || published
                      ? null
                      : (on) => setState(
                          () => on ? _options.add(p) : _options.remove(p),
                        ),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'ไม่เลือกเลย = ไม่ได้ตอบ · เลือกหลายตัว = ฝนหลายตัว (ได้ 0 คะแนน)',
            style: muted,
          ),
        ],
        const SizedBox(height: 4),
        Text(
          'คะแนนคิดด้วยเฉลยเหมือนรอยฝน ไม่นับเป็นการแก้คะแนน '
          '"ตรวจใหม่ทั้งห้อง" ภายหลังจะคิดคำตอบนี้กับเฉลยใหม่',
          style: muted,
        ),
        if (_error != null)
          Padding(
            padding: const EdgeInsets.only(top: 8),
            child: Text(
              _error!,
              key: const ValueKey('exam_resolve_error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ),
        const SizedBox(height: 8),
        Wrap(
          alignment: WrapAlignment.end,
          spacing: 8,
          runSpacing: 8,
          children: [
            OutlinedButton(
              key: const ValueKey('exam_resolve'),
              onPressed: _busy || published
                  ? null
                  : () => _resolve(advance: false),
              child: const Text('ใช้คำตอบนี้'),
            ),
            if (widget.canAdvance)
              FilledButton.icon(
                key: const ValueKey('exam_resolve_next'),
                onPressed: _busy || published
                    ? null
                    : () => _resolve(advance: true),
                icon: const Icon(Icons.check),
                label: const Text('ใช้คำตอบนี้และข้อถัดไป'),
              ),
          ],
        ),
      ],
    );
  }
}
