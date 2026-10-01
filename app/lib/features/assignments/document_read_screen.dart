import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/ai_guidance_field.dart';
import '../../core/widgets/content_column.dart';
import 'answer_key_models.dart';
import 'answer_key_repository.dart';

/// Asks the server for the cost of reading [documentIds] (and a page range)
/// with the teacher's [guidance] (DESIGN §21.12: it is part of the cache
/// key, so `cached` depends on it).
typedef DocumentEstimator =
    Future<KeyEstimate> Function(
      WidgetRef ref,
      List<int> documentIds,
      int? pageFrom,
      int? pageTo,
      String? guidance,
    );

/// Sends the read; what it returns is popped as the screen's result.
typedef DocumentSender =
    Future<Object?> Function(
      WidgetRef ref,
      List<int> documentIds,
      int? pageFrom,
      int? pageTo,
      String? guidance,
    );

/// Before a read or an AI draft is sent (DESIGN §19.5, §20.1): the files, a
/// page range for a single PDF (required above [kMaxDocumentPages] pages)
/// and the estimated cost of exactly that selection, asked from the server
/// every time the range or the "คำแนะนำถึง AI" (§21.12) changes. "ส่ง" sends
/// the read with that guidance and pops its result.
///
/// The default constructor reads or drafts an assignment's answer key (pops
/// the [KeyRequestResult]); [DocumentReadScreen.custom] serves other
/// documents, e.g. a course description or lesson plans.
class DocumentReadScreen extends ConsumerStatefulWidget {
  DocumentReadScreen({
    super.key,
    required int assignmentId,
    required KeyRequestKind kind,
    this.documents = const [],
    this.debounce = const Duration(milliseconds: 400),
    this.initialGuidance,
  }) : title = kind == KeyRequestKind.read
           ? 'ส่งให้ AI อ่านเฉลย'
           : 'ให้ AI ร่างเฉลย',
       filesLabel = kind == KeyRequestKind.read ? 'ไฟล์เฉลย' : 'ใบโจทย์',
       rangeHint = 'เลือกช่วงหน้าที่มีเฉลย',
       note = kind == KeyRequestKind.read
           ? null
           : documents.isEmpty
           ? 'AI จะร่างเฉลยจากโจทย์ที่พิมพ์ไว้ในการบ้านนี้ '
                 'เฉลยจะมีป้าย "AI ร่าง ไม่มีคำตอบของครู" ตรวจทุกข้อก่อนอนุมัติ'
           : 'AI จะร่างเฉลยจากใบโจทย์ที่แนบ '
                 'เฉลยจะมีป้าย "AI ร่าง ไม่มีคำตอบของครู" ตรวจทุกข้อก่อนอนุมัติ',
       cachedMessage = kind == KeyRequestKind.read
           ? 'เคยอ่านไฟล์นี้แล้ว ไม่เสียค่าใช้จ่าย'
           : 'เคยร่างเฉลยจากโจทย์นี้แล้ว ไม่เสียค่าใช้จ่าย',
       readBefore = kind == KeyRequestKind.read
           ? ((d) => d.keyReadBefore)
           : ((_) => false),
       guidanceHint = kind == KeyRequestKind.read
           ? kGuidanceHintKeyRead
           : kGuidanceHintKeyDraft,
       estimator = ((ref, ids, from, to, guidance) => ref
           .read(answerKeyRepositoryProvider)
           .estimate(
             assignmentId,
             kind: kind,
             documentIds: ids,
             pageFrom: from,
             pageTo: to,
             guidance: guidance,
           )),
       sender = ((ref, ids, from, to, guidance) => ref
           .read(answerKeyRepositoryProvider)
           .request(
             assignmentId,
             kind: kind,
             documentIds: ids,
             pageFrom: from,
             pageTo: to,
             guidance: guidance,
           ));

  const DocumentReadScreen.custom({
    super.key,
    required this.title,
    required this.filesLabel,
    required this.rangeHint,
    required this.cachedMessage,
    required this.readBefore,
    required this.estimator,
    required this.sender,
    this.guidanceHint = kGuidanceHintCourse,
    this.note,
    this.documents = const [],
    this.debounce = const Duration(milliseconds: 400),
    this.initialGuidance,
  });

  /// App bar title and the send button's label.
  final String title;

  /// Heading above the files.
  final String filesLabel;

  /// Shown when a PDF is too long, e.g. "เลือกช่วงหน้าที่มีเฉลย".
  final String rangeHint;

  /// A highlighted note above the files (AI draft, privacy warning).
  final String? note;

  /// Shown instead of the cost when the school read the selection before.
  final String cachedMessage;

  /// "เคยอ่านไฟล์นี้แล้ว" under a file.
  final bool Function(SourceDocument document) readBefore;
  final DocumentEstimator estimator;
  final DocumentSender sender;

  /// Uploaded files; empty for an AI draft from the typed questions only.
  final List<SourceDocument> documents;

  /// Wait after a keystroke in the range or the guidance before asking for
  /// the estimate.
  final Duration debounce;

  /// Examples in the empty "คำแนะนำถึง AI" field.
  final String guidanceHint;

  /// The guidance of the previous read (the server echoes it), so running
  /// the read again starts from it.
  final String? initialGuidance;

  @override
  ConsumerState<DocumentReadScreen> createState() => _DocumentReadScreenState();
}

class _DocumentReadScreenState extends ConsumerState<DocumentReadScreen> {
  late final SourceDocument? _pdf =
      widget.documents.length == 1 && widget.documents.single.isPdf
      ? widget.documents.single
      : null;
  late bool _useRange = _pdf?.needsPageRange ?? false;
  late final _from = TextEditingController(text: '1');
  late final _to = TextEditingController(
    text: '${(_pdf?.pageCount ?? 1).clamp(1, kMaxDocumentPages)}',
  );
  late final _guidance = TextEditingController(
    text: widget.initialGuidance ?? '',
  );

  Timer? _debounce;
  int _serial = 0;
  bool _estimating = false;
  KeyEstimate? _estimate;
  String? _estimateError;
  bool _sending = false;
  String? _sendError;

  /// `errors.guidance` of the last estimate or send.
  String? _guidanceError;

  @override
  void initState() {
    super.initState();
    _loadEstimate();
  }

  @override
  void dispose() {
    _debounce?.cancel();
    _from.dispose();
    _to.dispose();
    _guidance.dispose();
    super.dispose();
  }

  int get _totalPages =>
      widget.documents.fold(0, (sum, d) => sum + d.pageCount);

  /// Null when the range is valid (or not used), else a Thai reason.
  String? get _rangeError {
    final pdf = _pdf;
    if (pdf == null || !_useRange) return null;
    final from = int.tryParse(_from.text.trim());
    final to = int.tryParse(_to.text.trim());
    if (from == null || to == null) return 'กรอกเลขหน้าให้ครบ';
    if (from < 1) return 'หน้าแรกต้องเริ่มที่ 1';
    if (to < from) return 'หน้าสุดท้ายต้องไม่น้อยกว่าหน้าแรก';
    if (to > pdf.pageCount) return 'ไฟล์นี้มี ${pdf.pageCount} หน้า';
    if (to - from + 1 > kMaxDocumentPages) {
      return 'เลือกได้ไม่เกิน $kMaxDocumentPages หน้าต่อครั้ง';
    }
    return null;
  }

  /// A long selection without a range (several files together).
  String? get _lengthError {
    if (_useRange || _totalPages <= kMaxDocumentPages) return null;
    return 'ไฟล์รวมกัน $_totalPages หน้า เกิน $kMaxDocumentPages หน้า '
        'แนบทีละน้อยลง หรือแนบ PDF ไฟล์เดียวแล้วเลือกช่วงหน้า';
  }

  (int?, int?) get _range => _useRange && _pdf != null
      ? (int.tryParse(_from.text.trim()), int.tryParse(_to.text.trim()))
      : (null, null);

  String? get _guidanceText => normalizeGuidance(_guidance.text);

  void _changed() {
    setState(() {
      _estimate = null;
      _estimateError = null;
      _sendError = null;
      _guidanceError = null;
    });
    _debounce?.cancel();
    _debounce = Timer(widget.debounce, _loadEstimate);
  }

  Future<void> _loadEstimate() async {
    if (_rangeError != null || _lengthError != null) {
      setState(() => _estimating = false);
      return;
    }
    final serial = ++_serial;
    final (from, to) = _range;
    setState(() => _estimating = true);
    try {
      final estimate = await widget.estimator(
        ref,
        [for (final d in widget.documents) d.id],
        from,
        to,
        _guidanceText,
      );
      if (!mounted || serial != _serial) return;
      setState(() {
        _estimate = estimate;
        _estimating = false;
      });
    } catch (e) {
      if (!mounted || serial != _serial) return;
      setState(() {
        _estimateError = apiErrorMessage(e);
        _guidanceError = guidanceErrorOf(e);
        _estimating = false;
      });
    }
  }

  Future<void> _send() async {
    final (from, to) = _range;
    setState(() {
      _sending = true;
      _sendError = null;
    });
    try {
      final result = await widget.sender(
        ref,
        [for (final d in widget.documents) d.id],
        from,
        to,
        _guidanceText,
      );
      if (mounted) Navigator.of(context).pop(result);
    } catch (e) {
      if (mounted) {
        setState(() {
          _sendError = apiErrorMessage(e);
          _guidanceError = guidanceErrorOf(e);
        });
      }
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final pdf = _pdf;
    final rangeError = _rangeError;
    final lengthError = _lengthError;
    final canSend =
        !_sending &&
        !_estimating &&
        _estimate != null &&
        rangeError == null &&
        lengthError == null;

    return Scaffold(
      appBar: AppBar(title: Text(widget.title)),
      body: FormColumn(
        children: [
          if (widget.note case final note?)
            Card(
              color: theme.colorScheme.tertiaryContainer,
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: Text(
                  note,
                  style: TextStyle(
                    color: theme.colorScheme.onTertiaryContainer,
                  ),
                ),
              ),
            ),
          if (widget.documents.isNotEmpty) ...[
            Text(widget.filesLabel, style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            for (final d in widget.documents)
              ListTile(
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  d.isPdf
                      ? Icons.picture_as_pdf_outlined
                      : Icons.image_outlined,
                ),
                title: Text(
                  d.originalName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                subtitle: Text(
                  [
                    '${d.pageCount} หน้า',
                    if (d.sizeBytes > 0) _size(d.sizeBytes),
                    if (widget.readBefore(d)) 'เคยอ่านไฟล์นี้แล้ว',
                  ].join(' · '),
                ),
              ),
          ],
          if (pdf != null) ...[
            const SizedBox(height: 8),
            if (pdf.needsPageRange)
              Text(
                'ไฟล์นี้ยาว ${pdf.pageCount} หน้า เกิน $kMaxDocumentPages หน้า '
                '${widget.rangeHint} (ไม่เกิน $kMaxDocumentPages หน้า)',
                style: TextStyle(color: theme.colorScheme.error),
              )
            else
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('เลือกเฉพาะช่วงหน้า'),
                subtitle: const Text('อ่านน้อยหน้าลง ค่าใช้จ่ายก็น้อยลง'),
                value: _useRange,
                onChanged: (v) {
                  _useRange = v;
                  _changed();
                },
              ),
            if (_useRange) ...[
              const SizedBox(height: 8),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      key: const ValueKey('page_from'),
                      controller: _from,
                      keyboardType: TextInputType.number,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: const InputDecoration(
                        labelText: 'ตั้งแต่หน้า',
                      ),
                      onChanged: (_) => _changed(),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextField(
                      key: const ValueKey('page_to'),
                      controller: _to,
                      keyboardType: TextInputType.number,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: InputDecoration(
                        labelText: 'ถึงหน้า',
                        helperText: 'จาก ${pdf.pageCount} หน้า',
                      ),
                      onChanged: (_) => _changed(),
                    ),
                  ),
                ],
              ),
              if (rangeError != null) ...[
                const SizedBox(height: 4),
                Text(
                  rangeError,
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ],
            ],
          ],
          if (lengthError != null) ...[
            const SizedBox(height: 8),
            Text(lengthError, style: TextStyle(color: theme.colorScheme.error)),
          ],
          const SizedBox(height: 16),
          AiGuidanceField(
            controller: _guidance,
            hintText: widget.guidanceHint,
            enabled: !_sending,
            errorText: _guidanceError,
            onChanged: (_) => _changed(),
          ),
          const SizedBox(height: 12),
          _EstimateCard(
            loading: _estimating,
            estimate: _estimate,
            error: _estimateError,
            cachedMessage: widget.cachedMessage,
            hidden: rangeError != null || lengthError != null,
          ),
          if (_sendError != null) ...[
            const SizedBox(height: 12),
            Text(_sendError!, style: TextStyle(color: theme.colorScheme.error)),
          ],
          const SizedBox(height: 24),
          FilledButton.icon(
            key: const ValueKey('send_key_request'),
            onPressed: canSend ? _send : null,
            icon: _sending
                ? const SizedBox.square(
                    dimension: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.auto_awesome),
            label: Text(widget.title),
          ),
        ],
      ),
    );
  }

  static String _size(int bytes) => bytes >= 1024 * 1024
      ? '${(bytes / 1024 / 1024).toStringAsFixed(1)} MB'
      : '${(bytes / 1024).ceil()} KB';
}

class _EstimateCard extends StatelessWidget {
  const _EstimateCard({
    required this.loading,
    required this.estimate,
    required this.error,
    required this.cachedMessage,
    required this.hidden,
  });

  final bool loading;
  final KeyEstimate? estimate;
  final String? error;
  final String cachedMessage;
  final bool hidden;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    if (hidden) return const SizedBox.shrink();
    final Widget body;
    if (error != null) {
      body = Text(error!, style: TextStyle(color: theme.colorScheme.error));
    } else if (loading || estimate == null) {
      body = const Row(
        children: [
          SizedBox.square(
            dimension: 16,
            child: CircularProgressIndicator(strokeWidth: 2),
          ),
          SizedBox(width: 12),
          Text('กำลังคำนวณค่าใช้จ่าย...'),
        ],
      );
    } else if (estimate!.cached) {
      body = Row(
        children: [
          Icon(Icons.check_circle_outline, color: Colors.green.shade700),
          const SizedBox(width: 12),
          Expanded(child: Text(cachedMessage)),
        ],
      );
    } else {
      final e = estimate!;
      body = Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('ค่าใช้จ่ายโดยประมาณ', style: theme.textTheme.labelLarge),
          const SizedBox(height: 4),
          Text(
            e.estimate.thb == null
                ? 'ยังไม่ได้ตั้งราคาที่เครื่องแม่ข่าย แสดงเฉพาะจำนวน token'
                : e.estimate.label,
            key: const ValueKey('cost_estimate'),
          ),
          if (e.estimate.thb == null) Text(e.estimate.label),
          const SizedBox(height: 4),
          Text(
            [
              if (e.pages > 0) 'AI อ่าน ${e.pages} หน้า',
              'ค่าใช้จ่ายจริงอาจต่างจากนี้เล็กน้อย',
            ].join(' · '),
            style: theme.textTheme.bodySmall,
          ),
        ],
      );
    }
    return Card(
      child: Padding(padding: const EdgeInsets.all(16), child: body),
    );
  }
}
