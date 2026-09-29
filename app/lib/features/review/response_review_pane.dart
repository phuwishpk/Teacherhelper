import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../../core/widgets/response_crop_image.dart';
import '../../core/widgets/submission_page_image.dart';
import 'extraction_view.dart';
import 'fuzzy_trace.dart';
import 'review_labels.dart';
import 'review_models.dart';
import 'review_providers.dart';
import 'review_repository.dart';
import 'score_stepper.dart';

/// Detail of one response in the review queue (DESIGN §13): crop, what was
/// read, why the AI gave this score, the explanation and the teacher's
/// decision. Used as the right pane on tablets and as a page on phones.
class ResponseReviewPane extends ConsumerWidget {
  const ResponseReviewPane({
    super.key,
    required this.responseId,
    required this.onSaved,
    this.onPrev,
    this.onNext,
    this.positionLabel,
  });

  final int responseId;

  /// Called after a successful save; [advance] = the teacher asked to move
  /// on to the next response.
  final void Function(bool advance) onSaved;
  final VoidCallback? onPrev;
  final VoidCallback? onNext;

  /// e.g. "3 จาก 12" in the current tab.
  final String? positionLabel;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final detail = ref.watch(responseDetailProvider(responseId));
    return AsyncView(
      value: detail,
      onRetry: () => ref.invalidate(responseDetailProvider(responseId)),
      data: (d) => ReviewEditor(
        key: ValueKey('${d.id}-${d.reviewedAt?.toIso8601String()}'),
        detail: d,
        onPrev: onPrev,
        onNext: onNext,
        positionLabel: positionLabel,
        onSaved: (advance) {
          ref.invalidate(responseDetailProvider(responseId));
          onSaved(advance);
        },
      ),
    );
  }
}

/// The editable part of the pane; state is reset per response (keyed).
class ReviewEditor extends ConsumerStatefulWidget {
  const ReviewEditor({
    super.key,
    required this.detail,
    required this.onSaved,
    this.onPrev,
    this.onNext,
    this.positionLabel,
  });

  final ResponseDetail detail;
  final void Function(bool advance) onSaved;
  final VoidCallback? onPrev;
  final VoidCallback? onNext;
  final String? positionLabel;

  @override
  ConsumerState<ReviewEditor> createState() => _ReviewEditorState();
}

class _ReviewEditorState extends ConsumerState<ReviewEditor> {
  late double? _score = widget.detail.startScore;
  late Understanding? _understanding = widget.detail.startUnderstanding;
  late final Set<ErrorType> _errors = {...widget.detail.startErrorTypes};
  OverrideReason? _reason;
  late final _reasonText = TextEditingController();
  late final _explanation = TextEditingController(
    text: widget.detail.explanation ?? '',
  );
  String? _formError;
  bool _busy = false;
  bool _regenerating = false;

  ResponseDetail get d => widget.detail;

  bool get _scoreChanged =>
      d.aiScore != null &&
      _score != null &&
      (_score! - d.aiScore!).abs() > 1e-9;

  bool get _anyChangeFromAi =>
      _scoreChanged ||
      (d.aiUnderstanding != null && _understanding != d.aiUnderstanding) ||
      !_sameErrors(_errors, d.aiErrorTypes);

  static bool _sameErrors(Set<ErrorType> a, List<ErrorType> b) =>
      a.length == b.toSet().length && a.containsAll(b);

  @override
  void dispose() {
    _reasonText.dispose();
    _explanation.dispose();
    super.dispose();
  }

  String? _composedReason() {
    final text = _reasonText.text.trim();
    return switch (_reason) {
      null => text.isEmpty ? null : text,
      OverrideReason.other => text.isEmpty ? null : text,
      final r => text.isEmpty ? r.label : '${r.label}: $text',
    };
  }

  /// Client-side rules of §9.5 / §13; returns a Thai message or null.
  String? _validate() {
    if (_score == null) return 'ให้คะแนนก่อนบันทึก';
    if (_score! < 0 || _score! > d.maxPoints) {
      return 'คะแนนต้องอยู่ระหว่าง 0 ถึง ${formatScore(d.maxPoints)}';
    }
    if (_understanding == null) return 'เลือกระดับความเข้าใจก่อนบันทึก';
    if (_reason == OverrideReason.other && _reasonText.text.trim().isEmpty) {
      return 'พิมพ์เหตุผลที่แก้คะแนน';
    }
    if (_scoreChanged && _composedReason() == null) {
      return 'คะแนนต่างจากที่ AI ให้ ต้องเลือกเหตุผลก่อนบันทึก';
    }
    return null;
  }

  Future<void> _save({required bool advance}) async {
    final error = _validate();
    if (error != null) {
      setState(() => _formError = error);
      return;
    }
    if (d.totalOverridden &&
        d.startScore != null &&
        (_score! - d.startScore!).abs() > 1e-9) {
      final ok = await confirm(
        context,
        title: 'เปลี่ยนคะแนนข้อนี้?',
        message: totalOverrideClearWarning,
        confirmLabel: 'บันทึก',
      );
      if (!ok || !mounted) return;
    }
    final text = _explanation.text.trim();
    final decision = ReviewDecision(
      finalScore: _score!,
      understanding: _understanding!,
      errorTypes: [
        for (final e in ErrorType.values)
          if (_errors.contains(e)) e,
      ],
      explanation: text == (d.explanation ?? '').trim() ? null : text,
      reason: _composedReason(),
    );
    setState(() {
      _busy = true;
      _formError = null;
    });
    try {
      await ref.read(reviewRepositoryProvider).saveReview(d.id, decision);
      if (!mounted) return;
      showMessage(context, 'บันทึกผลข้อนี้แล้ว');
      widget.onSaved(advance);
    } catch (e) {
      if (mounted) setState(() => _formError = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _regenerate() async {
    setState(() => _regenerating = true);
    try {
      final text = await ref
          .read(reviewRepositoryProvider)
          .regenerateExplanation(d.id);
      if (!mounted) return;
      if (text != null) {
        _explanation.text = text;
        showMessage(context, 'AI เขียนคำอธิบายใหม่แล้ว ตรวจก่อนบันทึก');
      } else {
        showMessage(
          context,
          'กำลังเขียนคำอธิบายใหม่ เปิดข้อนี้อีกครั้งในอีกสักครู่',
        );
      }
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _regenerating = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final q = d.question;
    final trace = FuzzyTrace.parse(d.fuzzyTrace);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );

    return ListView(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
      children: [
        Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    'ข้อ ${q.position} · ${questionTypeLabel(q.type)} · '
                    '${formatScore(q.maxPoints)} คะแนน',
                    style: theme.textTheme.titleMedium,
                  ),
                  if (d.student != null) Text(d.student!.label),
                ],
              ),
            ),
            if (widget.positionLabel != null)
              Text(widget.positionLabel!, style: muted),
            IconButton(
              tooltip: 'ข้อก่อนหน้า',
              onPressed: widget.onPrev,
              icon: const Icon(Icons.chevron_left),
            ),
            IconButton(
              tooltip: 'ข้อถัดไป',
              onPressed: widget.onNext,
              icon: const Icon(Icons.chevron_right),
            ),
          ],
        ),
        const SizedBox(height: 4),
        Wrap(
          spacing: 8,
          runSpacing: 4,
          children: [
            if (d.isManual)
              StatusChip(label: 'ตรวจเอง', color: theme.colorScheme.error)
            else if (d.band != null)
              StatusChip(label: d.band!.label),
            if (d.late)
              StatusChip(label: 'ส่งช้า', color: Colors.orange.shade800),
            if (d.isWholePage)
              StatusChip(
                label: 'ตรวจจากรูปทั้งหน้า',
                color: theme.colorScheme.secondary,
              ),
            if (d.isCnnMatch)
              StatusChip(
                label: 'อ่านด้วย CNN',
                color: theme.colorScheme.secondary,
              ),
            if (d.isAutoBlank || d.extraction?['blank'] == true)
              const StatusChip(label: 'ไม่ได้ตอบ', color: Colors.grey),
            if (d.isReviewed)
              StatusChip(label: 'ตรวจทานแล้ว', color: Colors.green.shade700),
            if (d.isPublished)
              StatusChip(
                label: 'เผยแพร่แล้ว',
                color: theme.colorScheme.outline,
              ),
          ],
        ),
        if (d.isSuspicious)
          _Notice(
            icon: Icons.gpp_maybe_outlined,
            error: true,
            text:
                'ลายมือในข้อนี้มีข้อความที่พยายามสั่งผู้ตรวจหรือขอคะแนน '
                'ตรวจด้วยตัวเองก่อนยืนยัน ข้อนี้อนุมัติแบบกลุ่มไม่ได้',
          ),
        if (d.answerNotFound)
          const _Notice(
            key: ValueKey('answer_not_found_notice'),
            icon: Icons.search_off,
            error: true,
            text:
                'หาคำตอบข้อนี้ในภาพไม่เจอ AI จับคู่คำตอบกับข้อนี้ไม่ได้ในทุกหน้าที่ส่ง '
                'ดูภาพทั้งหน้าแล้วให้คะแนนเอง ข้อนี้อนุมัติแบบกลุ่มไม่ได้',
          )
        else if (d.isManual)
          _Notice(
            icon: Icons.back_hand_outlined,
            error: d.manualReason == 'ai_key_missing',
            text: d.manualReason == 'ai_key_missing'
                ? 'AI ยังไม่ได้ตรวจเพราะไม่มี Gemini API key '
                      'ใส่ key แล้วกด "ตรวจข้อที่ค้างใหม่" ที่หน้าคิว หรือให้คะแนนเองได้เลย'
                : 'ตรวจเอง: ${manualReasonLabel(d.manualReason)}',
          ),
        if (d.identityMismatch)
          const _Notice(
            icon: Icons.person_search_outlined,
            error: true,
            text:
                'QR บนใบงานไม่ตรงกับนักเรียนที่ส่งงานใน Google Classroom '
                'ตรวจว่าเป็นงานของนักเรียนคนนี้จริง ข้อนี้อนุมัติแบบกลุ่มไม่ได้',
          ),
        if (d.appeal case final appeal? when appeal.isOpen)
          _Notice(
            icon: Icons.feedback_outlined,
            text:
                'นักเรียนขอให้ตรวจใหม่'
                '${appeal.reason == null ? '' : ': "${appeal.reason}"'} '
                '(ตอบได้ที่หน้าคำขอให้ตรวจใหม่)',
          ),
        if (d.isPublished)
          const _Notice(
            icon: Icons.info_outline,
            text: 'ผลนี้เผยแพร่แล้ว ถ้าแก้ นักเรียนจะเห็นผลใหม่',
          ),
        _Section(
          title: 'โจทย์',
          children: [
            Text(q.promptText.isEmpty ? '-' : q.promptText),
            if (q.keySummary != null) Text(q.keySummary!, style: muted),
          ],
        ),
        _Section(
          title: d.isWholePage ? 'ภาพงานทั้งหน้า' : 'ภาพคำตอบ',
          children: [
            if (d.submissionPageId case final pageId?) ...[
              SubmissionPageImage(
                key: ValueKey('page_$pageId'),
                pageId: pageId,
                mimeType: d.pageMimeType,
                answerBox: d.answerBox,
              ),
              const SizedBox(height: 4),
              Text(
                d.answerBox != null
                    ? 'กรอบสีแดงคือตำแหน่งคำตอบที่ AI พบ แตะภาพเพื่อขยาย'
                    : d.answerNotFound
                    ? 'AI ไม่พบตำแหน่งคำตอบของข้อนี้ในภาพ แตะภาพเพื่อขยาย'
                    : 'แตะภาพเพื่อขยาย',
                style: muted,
              ),
            ] else if (d.hasCrop)
              ResponseCropImage(responseId: d.id)
            else
              Text('ไม่มีภาพของข้อนี้', style: muted),
            if (d.hasFinalCrop) ...[
              const SizedBox(height: 8),
              Text('กรอบคำตอบสุดท้าย', style: muted),
              ResponseCropImage(responseId: d.id, finalPart: true, height: 96),
            ],
          ],
        ),
        _Section(
          title: 'สิ่งที่อ่านได้',
          children: [ExtractionView(detail: d)],
        ),
        _Section(
          title: 'เหตุผลของคะแนน',
          children: [
            ScoreReasoningView(
              trace: trace,
              maxPoints: q.maxPoints,
              aiScore: d.aiScore,
              aiUnderstanding: d.aiUnderstanding,
              band: d.band,
              reviewPriority: d.reviewPriority,
            ),
          ],
        ),
        _Section(
          title: 'คำอธิบายสำหรับนักเรียน',
          children: [
            TextField(
              key: const ValueKey('explanation_field'),
              controller: _explanation,
              minLines: 3,
              maxLines: null,
              decoration: InputDecoration(
                border: const OutlineInputBorder(),
                helperText: d.explanationEdited
                    ? 'แก้โดยครูแล้ว · ใช้แทนข้อความของ AI ในหน้าผลของนักเรียนและในประกาศ Classroom'
                    : 'แก้ได้ก่อนเผยแพร่ · ข้อความที่แก้ใช้แทนของ AI ในหน้าผลของนักเรียนและในประกาศ Classroom',
                helperMaxLines: 3,
              ),
            ),
            if (d.hasAiOriginal)
              _AiOriginal(
                text: d.aiExplanation!,
                onUse: _busy
                    ? null
                    : () {
                        _explanation.text = d.aiExplanation!;
                        showMessage(
                          context,
                          'ใส่ข้อความเดิมของ AI แล้ว กดบันทึกเพื่อใช้ข้อความนี้',
                        );
                      },
              ),
            if (d.nextStep case final next? when next.isNotEmpty)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text('ขั้นต่อไป: $next'),
              ),
            Align(
              alignment: Alignment.centerRight,
              child: TextButton.icon(
                onPressed: _regenerating ? null : _regenerate,
                icon: const Icon(Icons.auto_fix_high_outlined),
                label: const Text('ให้ AI เขียนคำอธิบายใหม่'),
              ),
            ),
          ],
        ),
        _Section(
          title: 'ผลการตรวจ',
          children: [
            Text('คะแนน', style: theme.textTheme.labelLarge),
            ScoreStepper(
              value: _score,
              max: q.maxPoints,
              enabled: !_busy,
              onChanged: (v) => setState(() {
                _score = v;
                _formError = null;
              }),
            ),
            Text(
              d.aiScore == null
                  ? 'AI ยังไม่ได้ให้คะแนนข้อนี้'
                  : 'AI ให้ ${formatScore(d.aiScore)} คะแนน'
                        '${d.aiUnderstanding == null ? '' : ' · ${d.aiUnderstanding!.label}'}',
              style: muted,
            ),
            const SizedBox(height: 12),
            Text('ระดับความเข้าใจ', style: theme.textTheme.labelLarge),
            const SizedBox(height: 4),
            SegmentedButton<Understanding>(
              emptySelectionAllowed: true,
              showSelectedIcon: false,
              segments: [
                for (final u in Understanding.values)
                  ButtonSegment(value: u, label: Text(u.label)),
              ],
              selected: {?_understanding},
              onSelectionChanged: _busy
                  ? null
                  : (s) => setState(() {
                      _understanding = s.firstOrNull;
                      _formError = null;
                    }),
            ),
            const SizedBox(height: 12),
            Text('ประเภทข้อผิดพลาด', style: theme.textTheme.labelLarge),
            const SizedBox(height: 4),
            Wrap(
              spacing: 6,
              runSpacing: 4,
              children: [
                for (final e in ErrorType.values)
                  FilterChip(
                    label: Text(e.label),
                    selected: _errors.contains(e),
                    onSelected: _busy
                        ? null
                        : (on) => setState(
                            () => on ? _errors.add(e) : _errors.remove(e),
                          ),
                  ),
              ],
            ),
            if (_anyChangeFromAi || _reason != null) ...[
              const SizedBox(height: 12),
              Text(
                _scoreChanged
                    ? 'เหตุผลที่แก้คะแนน (จำเป็น)'
                    : 'เหตุผลที่แก้ (ไม่บังคับ)',
                style: theme.textTheme.labelLarge,
              ),
              const SizedBox(height: 4),
              DropdownButtonFormField<OverrideReason>(
                key: const ValueKey('reason_picker'),
                isExpanded: true,
                initialValue: _reason,
                hint: const Text('เลือกเหตุผล'),
                items: [
                  for (final r in OverrideReason.values)
                    DropdownMenuItem(value: r, child: Text(r.label)),
                ],
                onChanged: _busy
                    ? null
                    : (r) => setState(() {
                        _reason = r;
                        _formError = null;
                      }),
                decoration: const InputDecoration(border: OutlineInputBorder()),
              ),
              const SizedBox(height: 8),
              TextField(
                key: const ValueKey('reason_text'),
                controller: _reasonText,
                enabled: !_busy,
                decoration: InputDecoration(
                  border: const OutlineInputBorder(),
                  labelText: _reason == OverrideReason.other
                      ? 'พิมพ์เหตุผล'
                      : 'รายละเอียดเพิ่มเติม (ไม่บังคับ)',
                ),
              ),
            ],
            if (_formError != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  _formError!,
                  key: const ValueKey('review_form_error'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            const SizedBox(height: 12),
            Wrap(
              alignment: WrapAlignment.end,
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton(
                  onPressed: _busy ? null : () => _save(advance: false),
                  child: Text(d.isReviewed ? 'บันทึกการแก้ไข' : 'บันทึก'),
                ),
                if (widget.onNext != null)
                  FilledButton.icon(
                    onPressed: _busy ? null : () => _save(advance: true),
                    icon: _busy
                        ? const SizedBox.square(
                            dimension: 16,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Icon(Icons.check),
                    label: const Text('บันทึกและข้อถัดไป'),
                  ),
              ],
            ),
          ],
        ),
      ],
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.children});

  final String title;
  final List<Widget> children;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(top: 12),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: Theme.of(context).textTheme.titleSmall),
            const SizedBox(height: 8),
            ...children,
          ],
        ),
      ),
    );
  }
}

/// Gemini's original explanation, kept once the teacher edited it
/// (`responses.ai_explanation`, DESIGN §19.4).
class _AiOriginal extends StatelessWidget {
  const _AiOriginal({required this.text, required this.onUse});

  final String text;
  final VoidCallback? onUse;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return ExpansionTile(
      key: const ValueKey('ai_original'),
      tilePadding: EdgeInsets.zero,
      childrenPadding: const EdgeInsets.only(bottom: 8),
      expandedCrossAxisAlignment: CrossAxisAlignment.start,
      leading: const Icon(Icons.history),
      title: const Text('ดูข้อความเดิมของ AI'),
      children: [
        Container(
          width: double.infinity,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: theme.colorScheme.surfaceContainerHighest,
            borderRadius: BorderRadius.circular(8),
          ),
          child: SelectableText(text, key: const ValueKey('ai_original_text')),
        ),
        TextButton.icon(
          key: const ValueKey('use_ai_original'),
          onPressed: onUse,
          icon: const Icon(Icons.restore),
          label: const Text('ใช้ข้อความของ AI'),
        ),
      ],
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({
    super.key,
    required this.icon,
    required this.text,
    this.error = false,
  });

  final IconData icon;
  final String text;
  final bool error;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      margin: const EdgeInsets.only(top: 8),
      color: error ? scheme.errorContainer : scheme.secondaryContainer,
      child: ListTile(
        leading: Icon(
          icon,
          color: error ? scheme.onErrorContainer : scheme.onSecondaryContainer,
        ),
        title: Text(
          text,
          style: TextStyle(
            color: error
                ? scheme.onErrorContainer
                : scheme.onSecondaryContainer,
          ),
        ),
      ),
    );
  }
}
