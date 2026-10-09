import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/ai_guidance_field.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../../platform/document_page_renderer.dart';
import '../assignments/answer_key_models.dart';
import 'exam_figure_crop_screen.dart';
import 'exam_image_field.dart';
import 'exam_import_models.dart';
import 'exam_import_repository.dart';
import 'exam_models.dart';
import 'exam_page_renders.dart';
import 'exam_providers.dart';

/// "ตรวจข้อที่อ่านจากไฟล์" (DESIGN §22.4): waits for a queued read, renders
/// the pages the figures are cropped from, then lists every draft question
/// read from a file with its figures, options and key. The teacher edits a
/// question, boxes a figure again on the page image, and approves one
/// question or the selected ones ("อนุมัติที่เลือก"); nothing is printed
/// before every question is approved.
///
/// [result] is the answer of the import just sent (null when opened from
/// the exam later); [localFiles] are the files picked in this session by
/// source document id, so their pages render without a download.
class ExamImportReviewScreen extends ConsumerStatefulWidget {
  const ExamImportReviewScreen({
    super.key,
    required this.examId,
    this.result,
    this.localFiles = const {},
  });

  final int examId;
  final ExamImportResult? result;
  final Map<int, PickedDocument> localFiles;

  @override
  ConsumerState<ExamImportReviewScreen> createState() =>
      _ExamImportReviewScreenState();
}

class _ExamImportReviewScreenState
    extends ConsumerState<ExamImportReviewScreen> {
  late ExamRead? _read = widget.result?.read;
  Timer? _poll;

  /// Reloads the exam while the server crops figures (no pull needed).
  Timer? _cropPoll;

  final _selected = <int>{};
  bool _busy = false;

  bool _rendering = false;
  int _renderDone = 0;
  int _renderTotal = 0;
  PageRenderReport? _report;

  ExamDetailNotifier get _notifier =>
      ref.read(examDetailProvider(widget.examId).notifier);

  DocumentPageRenderer get _renderer => ref.read(documentPageRendererProvider);

  @override
  void initState() {
    super.initState();
    final read = _read;
    if (read == null) return;
    if (read.queued) {
      _schedulePoll();
    } else if (read.done) {
      // Applied at once (read before in this school).
      scheduleMicrotask(_afterRead);
    }
  }

  @override
  void dispose() {
    _poll?.cancel();
    _cropPoll?.cancel();
    super.dispose();
  }

  /// While a figure shows "กำลังตัดภาพ", reload the exam every
  /// [examCropPollIntervalProvider]; each reload rebuilds and checks again.
  void _pollWhileCropping(ExamDetail? d) {
    if (d == null || _cropPoll != null || !anyFigureCropping(d)) return;
    _cropPoll = Timer(ref.read(examCropPollIntervalProvider), () async {
      try {
        await _notifier.refresh();
      } catch (_) {
        // A network blip: the next build schedules another reload.
      }
      _cropPoll = null;
      if (mounted) setState(() {});
    });
  }

  void _schedulePoll() {
    _poll?.cancel();
    _poll = Timer(ref.read(examReadPollIntervalProvider), () async {
      final read = _read;
      if (read == null) return;
      try {
        final next = await ref.read(examImportRepositoryProvider).read(read.id);
        if (!mounted) return;
        setState(() => _read = next);
        if (next.done) {
          await _afterRead();
        } else if (next.queued) {
          _schedulePoll();
        }
      } catch (_) {
        // A network blip: ask again at the next tick.
        if (mounted) _schedulePoll();
      }
    });
  }

  /// The read is in the exam: reload it and render the waiting pages.
  Future<void> _afterRead() async {
    if (!mounted) return;
    try {
      final d = await _notifier.refresh();
      if (!mounted) return;
      if (_renderer.isSupported && d.figuresPending.any((p) => p.needsRender)) {
        await _render(d.figuresPending);
      }
    } catch (_) {
      // The exam view shows the error with a retry.
    }
  }

  Future<void> _render(List<FigurePending> pending) async {
    if (_rendering) return;
    setState(() {
      _rendering = true;
      _renderDone = 0;
      _renderTotal = pending.where((p) => p.needsRender).length;
      _report = null;
    });
    final report = await renderPendingPages(
      repo: ref.read(examImportRepositoryProvider),
      renderer: _renderer,
      examId: widget.examId,
      pending: pending,
      localFiles: widget.localFiles,
      onProgress: (done, total) {
        if (mounted) setState(() => _renderDone = done);
      },
    );
    if (!mounted) return;
    setState(() {
      _rendering = false;
      _report = report;
    });
    try {
      await _notifier.refresh();
    } catch (_) {}
  }

  Future<void> _approve(List<int> ids) async {
    if (ids.isEmpty) return;
    setState(() => _busy = true);
    try {
      await _notifier.approveQuestions(ids);
      if (!mounted) return;
      setState(() => _selected.removeAll(ids));
      showMessage(
        context,
        ids.length == 1 ? 'อนุมัติข้อนี้แล้ว' : 'อนุมัติ ${ids.length} ข้อแล้ว',
      );
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _crop(ExamQuestion q, {ExamOption? option}) async {
    await openFigureCrop(
      context,
      examId: widget.examId,
      target: option == null
          ? (option: false, id: q.id)
          : (option: true, id: option.id),
      title: option == null
          ? 'ภาพโจทย์ข้อ ${q.position}'
          : 'ภาพตัวเลือก ${option.label} ข้อ ${q.position}',
      source: option == null ? q.figureSource : option.figureSource,
    );
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(examDetailProvider(widget.examId));
    final d = detail.value;
    _pollWhileCropping(d);
    final drafts = d?.unapprovedDrafts ?? const <ExamQuestion>[];
    _selected.retainAll(drafts.map((q) => q.id));
    return Scaffold(
      appBar: AppBar(title: const Text('ตรวจข้อที่อ่านจากไฟล์')),
      bottomNavigationBar: drafts.isEmpty
          ? null
          : BottomActionBar(
              child: Row(
                children: [
                  TextButton(
                    key: const ValueKey('read_review_select_all'),
                    onPressed: _busy
                        ? null
                        : () => setState(() {
                            if (_selected.length == drafts.length) {
                              _selected.clear();
                            } else {
                              _selected.addAll(drafts.map((q) => q.id));
                            }
                          }),
                    child: Text(
                      _selected.length == drafts.length
                          ? 'ไม่เลือกเลย'
                          : 'เลือกทั้งหมด',
                    ),
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: FilledButton.icon(
                      key: const ValueKey('read_review_approve_selected'),
                      onPressed: _busy || _selected.isEmpty
                          ? null
                          : () => _approve(_selected.toList()..sort()),
                      icon: const Icon(Icons.done_all),
                      label: Text(
                        'อนุมัติที่เลือก (${_selected.length})',
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                  ),
                ],
              ),
            ),
      body: AsyncView(
        value: detail,
        onRetry: () => ref.invalidate(examDetailProvider(widget.examId)),
        data: (d) => RefreshIndicator(
          onRefresh: () => _notifier.refresh(),
          child: ContentColumn(
            padding: EdgeInsets.zero,
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
              children: [
                ?_readCard(context),
                ?_figuresCard(context, d),
                if (drafts.isEmpty && !(_read?.queued ?? false))
                  EmptyView(
                    icon: Icons.task_alt,
                    title: 'ไม่มีข้อที่รออนุมัติ',
                    message: d.questions.any((q) => q.fromDocument)
                        ? 'ข้อที่อ่านจากไฟล์อนุมัติครบแล้ว '
                              'แก้ข้อหรือภาพประกอบได้จากหน้าข้อสอบ'
                        : 'ยังไม่มีข้อที่อ่านจากไฟล์ในข้อสอบนี้',
                    action: FilledButton.tonal(
                      onPressed: () => Navigator.of(context).maybePop(),
                      child: const Text('กลับไปหน้าข้อสอบ'),
                    ),
                  ),
                if (drafts.isNotEmpty) ...[
                  Text(
                    'รออนุมัติ ${drafts.length} ข้อ ตรวจโจทย์ ตัวเลือก ภาพ และเฉลย '
                    'ทุกข้อก่อนอนุมัติ',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                  const SizedBox(height: 8),
                ],
                for (final s in d.sections)
                  if (s.questions.any((q) => drafts.contains(q))) ...[
                    Padding(
                      padding: const EdgeInsets.only(top: 8, bottom: 4),
                      child: Text(
                        '${s.heading} · ${s.typeSummary}',
                        style: Theme.of(context).textTheme.titleSmall,
                      ),
                    ),
                    for (final q in s.questions)
                      if (drafts.contains(q))
                        _DraftCard(
                          key: ValueKey('read_review_q_${q.id}'),
                          detail: d,
                          question: q,
                          selected: _selected.contains(q.id),
                          busy: _busy,
                          onSelect: (on) => setState(() {
                            if (on) {
                              _selected.add(q.id);
                            } else {
                              _selected.remove(q.id);
                            }
                          }),
                          onApprove: () => _approve([q.id]),
                          onEdit: () => context.push(
                            AppRoutes.examQuestion(widget.examId, q.id),
                          ),
                          onCrop: (option) => _crop(q, option: option),
                        ),
                  ],
              ],
            ),
          ),
        ),
      ),
    );
  }

  /// The state of the read just sent: reading, failed, or what it made.
  Widget? _readCard(BuildContext context) {
    final read = _read;
    if (read == null) return null;
    final theme = Theme.of(context);
    if (read.queued) {
      return const Card(
        key: ValueKey('read_review_reading'),
        child: ListTile(
          leading: SizedBox.square(
            dimension: 24,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          title: Text('AI กำลังอ่านไฟล์ข้อสอบ...'),
          subtitle: Text(
            'ใช้เวลาประมาณ 1–3 นาที ออกจากหน้านี้ได้ '
            'ข้อที่อ่านได้จะเข้ามาในข้อสอบเมื่ออ่านเสร็จ',
          ),
        ),
      );
    }
    if (read.failed) {
      return Card(
        key: const ValueKey('read_review_failed'),
        color: theme.colorScheme.errorContainer,
        child: ListTile(
          leading: Icon(Icons.error_outline, color: theme.colorScheme.error),
          title: const Text('อ่านไฟล์ไม่สำเร็จ'),
          subtitle: Text(
            read.error ?? 'ลองส่งใหม่อีกครั้ง หรือแนบไฟล์ที่ชัดขึ้น',
          ),
        ),
      );
    }
    final applied = widget.result?.applied;
    final skipped = applied?.skipped ?? read.skipped;
    return Card(
      key: const ValueKey('read_review_summary'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              applied == null
                  ? 'อ่านไฟล์เสร็จแล้ว'
                  : 'อ่านได้ ${applied.sections} ตอน ${applied.questions} ข้อ'
                        '${widget.result!.cached ? ' (เคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย)' : ''}',
              style: theme.textTheme.titleSmall,
            ),
            if (read.notesTh case final notes?) ...[
              const SizedBox(height: 4),
              Text('หมายเหตุจาก AI: $notes', style: theme.textTheme.bodySmall),
            ],
            if (read.guidance case final guidance?) ...[
              const SizedBox(height: 4),
              GuidanceUsedNote(guidance: guidance),
            ],
            if (skipped.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text(
                'ไม่ได้สร้าง ${skipped.length} ข้อ (ฝนไม่ได้ หรือเกินขีดจำกัด)',
                key: const ValueKey('read_review_skipped'),
                style: theme.textTheme.bodyMedium,
              ),
              for (final s in skipped.take(10))
                Text('• ${s.label}', style: theme.textTheme.bodySmall),
              if (skipped.length > 10)
                Text(
                  'และอีก ${skipped.length - 10} ข้อ',
                  style: theme.textTheme.bodySmall,
                ),
            ],
          ],
        ),
      ),
    );
  }

  /// Pages whose figures still wait: render them here, or say why not.
  Widget? _figuresCard(BuildContext context, ExamDetail d) {
    final theme = Theme.of(context);
    final pending = d.figuresPending;
    final report = _report;
    if (pending.isEmpty && !_rendering && report == null) return null;
    final render = [
      for (final p in pending)
        if (p.needsRender) p,
    ];
    final missing = [
      for (final p in pending)
        if (!p.needsRender) p,
    ];
    final figures = pending.fold(0, (n, p) => n + p.figures);
    return Card(
      key: const ValueKey('read_review_figures'),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_rendering) ...[
              Text(
                'กำลังสร้างภาพหน้าเอกสาร $_renderDone/$_renderTotal หน้า',
                style: theme.textTheme.titleSmall,
              ),
              const SizedBox(height: 8),
              LinearProgressIndicator(
                value: _renderTotal == 0 ? null : _renderDone / _renderTotal,
              ),
            ] else if (pending.isEmpty)
              Text(
                'สร้างภาพหน้าเอกสารแล้ว ${report?.uploaded ?? 0} หน้า '
                'ภาพประกอบจะขึ้นเมื่อตัดเสร็จ',
                style: theme.textTheme.titleSmall,
              )
            else
              Text(
                'ยังไม่มีภาพประกอบ $figures ภาพ จาก ${pending.length} หน้า',
                style: theme.textTheme.titleSmall,
              ),
            if (!_rendering && render.isNotEmpty) ...[
              const SizedBox(height: 4),
              if (_renderer.isSupported) ...[
                Text(
                  'แอปต้องสร้างภาพหน้าจากไฟล์ (PDF หรือ HEIC) แล้วส่งให้ server ตัดภาพประกอบ',
                  style: theme.textTheme.bodySmall,
                ),
                Align(
                  alignment: Alignment.centerRight,
                  child: TextButton.icon(
                    key: const ValueKey('read_review_render'),
                    onPressed: () => _render(pending),
                    icon: const Icon(Icons.image_search),
                    label: Text(
                      report == null
                          ? 'สร้างภาพประกอบ'
                          : 'ลองสร้างภาพประกอบอีกครั้ง',
                    ),
                  ),
                ),
              ] else
                Text(
                  'สร้างภาพหน้าเอกสารได้เฉพาะในแอป Android '
                  'เปิดข้อสอบนี้ในแอปบนมือถือ หรือแนบรูปภาพประกอบเองในแต่ละข้อ',
                  key: const ValueKey('read_review_render_unsupported'),
                  style: theme.textTheme.bodySmall,
                ),
            ],
            if (missing.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(
                'ไฟล์ ${missing.map((p) => p.originalName ?? 'เอกสาร').toSet().join(', ')} '
                'ถูกลบแล้ว (เก็บไว้ 30 วัน) แนบรูปภาพประกอบเองในแต่ละข้อ',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.error,
                ),
              ),
            ],
            if (report != null && report.failures.isNotEmpty) ...[
              const SizedBox(height: 4),
              for (final f in report.failures)
                Text(
                  f,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: theme.colorScheme.error,
                  ),
                ),
            ],
          ],
        ),
      ),
    );
  }
}

/// One draft question with its figures, options and key.
class _DraftCard extends StatelessWidget {
  const _DraftCard({
    super.key,
    required this.detail,
    required this.question,
    required this.selected,
    required this.busy,
    required this.onSelect,
    required this.onApprove,
    required this.onEdit,
    required this.onCrop,
  });

  final ExamDetail detail;
  final ExamQuestion question;
  final bool selected;
  final bool busy;
  final ValueChanged<bool> onSelect;
  final VoidCallback onApprove;
  final VoidCallback onEdit;

  /// Boxes the figure again: the prompt's (null) or an option's.
  final ValueChanged<ExamOption?> onCrop;

  @override
  Widget build(BuildContext context) {
    final q = question;
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final canCrop = detail.availablePages.isNotEmpty;
    final promptStatus = figureStatus(
      detail,
      source: q.figureSource,
      pending: q.figurePending,
      hasImage: q.hasPromptImage,
    );
    final key = q.key;
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(4, 4, 12, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Checkbox(
                  key: ValueKey('read_review_select_${q.id}'),
                  value: selected,
                  onChanged: busy ? null : (v) => onSelect(v ?? false),
                ),
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.only(top: 12),
                    child: Text(
                      'ข้อ ${q.position}  ${q.promptText.trim().isEmpty ? '(ไม่มีข้อความโจทย์)' : q.promptText.trim()}',
                      style: theme.textTheme.bodyLarge,
                    ),
                  ),
                ),
              ],
            ),
            Padding(
              padding: const EdgeInsets.only(left: 12),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  _FigureLine(
                    status: promptStatus,
                    imageKey: q.hasPromptImage
                        ? (option: false, id: q.id)
                        : null,
                    label: 'ภาพโจทย์',
                    cropKey: 'read_review_crop_${q.id}',
                    onCrop: canCrop && promptStatus != FigureStatus.none
                        ? () => onCrop(null)
                        : null,
                  ),
                  for (final o in q.options) _option(context, o, canCrop),
                  const SizedBox(height: 6),
                  Text(
                    key == null
                        ? 'ยังไม่มีเฉลย (ไฟล์ไม่มีเฉลย หรืออ่านไม่ได้)'
                        : 'เฉลย ${key.describe(q.type)}',
                    style: TextStyle(color: key == null ? scheme.error : null),
                  ),
                  if (q.lockOptionsSuggested)
                    Padding(
                      padding: const EdgeInsets.only(top: 4),
                      child: StatusChip(
                        label: 'แนะนำห้ามสลับตัวเลือก',
                        color: scheme.tertiary,
                      ),
                    ),
                  const SizedBox(height: 4),
                  Wrap(
                    alignment: WrapAlignment.end,
                    spacing: 8,
                    children: [
                      TextButton.icon(
                        key: ValueKey('read_review_edit_${q.id}'),
                        onPressed: busy ? null : onEdit,
                        icon: const Icon(Icons.edit_outlined),
                        label: const Text('แก้ไข'),
                      ),
                      FilledButton.tonalIcon(
                        key: ValueKey('read_review_approve_${q.id}'),
                        onPressed: busy ? null : onApprove,
                        icon: const Icon(Icons.check),
                        label: const Text('อนุมัติข้อนี้'),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _option(BuildContext context, ExamOption o, bool canCrop) {
    final status = figureStatus(
      detail,
      source: o.figureSource,
      pending: o.figurePending,
      hasImage: o.hasImage,
    );
    final text = o.text?.trim() ?? '';
    return Padding(
      padding: const EdgeInsets.only(top: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            '${o.label}. ${text.isEmpty ? (status == FigureStatus.none ? '(ว่าง)' : '(ภาพ)') : text}',
          ),
          if (status != FigureStatus.none)
            Padding(
              padding: const EdgeInsets.only(left: 16),
              child: _FigureLine(
                status: status,
                imageKey: o.hasImage ? (option: true, id: o.id) : null,
                label: 'ภาพตัวเลือก ${o.label}',
                cropKey: 'read_review_crop_option_${o.id}',
                compact: true,
                onCrop: canCrop ? () => onCrop(o) : null,
              ),
            ),
        ],
      ),
    );
  }
}

/// A figure's preview or status with "ลากกรอบใหม่".
class _FigureLine extends StatelessWidget {
  const _FigureLine({
    required this.status,
    required this.imageKey,
    required this.label,
    required this.cropKey,
    this.onCrop,
    this.compact = false,
  });

  final FigureStatus status;
  final ({bool option, int id})? imageKey;
  final String label;
  final String cropKey;
  final VoidCallback? onCrop;
  final bool compact;

  @override
  Widget build(BuildContext context) {
    if (status == FigureStatus.none) return const SizedBox.shrink();
    final scheme = Theme.of(context).colorScheme;
    final image = imageKey;
    return Padding(
      padding: const EdgeInsets.only(top: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (status == FigureStatus.ready && image != null)
            ExamImageView(imageKey: image, height: compact ? 80 : 140)
          else
            StatusChip(
              label: status == FigureStatus.cropping
                  ? '$label: กำลังตัดภาพ'
                  : '$label: ยังไม่มีภาพประกอบ',
              color: status == FigureStatus.cropping
                  ? scheme.secondary
                  : scheme.outline,
            ),
          if (onCrop != null)
            TextButton.icon(
              key: ValueKey(cropKey),
              onPressed: onCrop,
              icon: const Icon(Icons.crop, size: 18),
              label: const Text('ลากกรอบใหม่'),
            ),
        ],
      ),
    );
  }
}
