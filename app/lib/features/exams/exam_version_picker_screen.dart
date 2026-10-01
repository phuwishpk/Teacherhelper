import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/content_column.dart';
import '../../core/widgets/scan_page_image.dart';
import 'exam_models.dart';
import 'exam_scan_repository.dart';

/// "ให้ครูเลือกชุด" (DESIGN §22.11): the teacher looks at a scanned page
/// whose version bubble was blank, double or unclear (or a page 2 without
/// page 1) and picks the version; the server scores the student at once
/// (`POST /exam-sheets/{scan_id}/version`). Pops with the page result.
class ExamVersionPickerScreen extends ConsumerStatefulWidget {
  const ExamVersionPickerScreen({
    super.key,
    required this.scanId,
    required this.pageNo,
    required this.versionCount,
    this.studentLabel,
    this.currentVersion,
    this.pageOneVersion,
    this.versionDoubtful = false,
  });

  final int scanId;
  final int pageNo;
  final int versionCount;
  final String? studentLabel;

  /// The version the server uses for this page now, null while unknown.
  final int? currentVersion;

  /// Page 1's version of the same student, for a later page (§22.11: every
  /// page of a sheet has page 1's version).
  final int? pageOneVersion;
  final bool versionDoubtful;

  @override
  ConsumerState<ExamVersionPickerScreen> createState() =>
      _ExamVersionPickerScreenState();
}

class _ExamVersionPickerScreenState
    extends ConsumerState<ExamVersionPickerScreen> {
  late int? _version = widget.currentVersion ?? widget.pageOneVersion;
  bool _busy = false;
  String? _error;

  Future<void> _save() async {
    final version = _version;
    if (version == null) {
      setState(() => _error = 'เลือกชุดก่อนบันทึก');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(examScanRepositoryProvider)
          .chooseVersion(widget.scanId, version);
      if (mounted) Navigator.of(context).pop(result);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
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
    final pageOne = widget.pageOneVersion;
    final notice = switch (widget) {
      _ when widget.pageNo > 1 && pageOne != null =>
        'หน้า 1 ของนักเรียนคนนี้เป็นชุด ${examVersionLabel(pageOne)} '
            'ทุกหน้าต้องเป็นชุดเดียวกัน ถ้าชุดผิด ให้เลือกชุดที่หน้า 1',
      _ when widget.pageNo > 1 =>
        'หน้า 2 ไม่มีวงชุด ยังไม่ได้สแกนหน้า 1 ของนักเรียนคนนี้ '
            'ดูชุดจากเล่มข้อสอบหรือหน้า 1 แล้วเลือกชุด',
      _ when widget.currentVersion == null =>
        'วงชุดว่างหรือฝนหลายวง ดูภาพหน้าแล้วเลือกชุดที่นักเรียนทำ',
      _ when widget.versionDoubtful =>
        'วงชุดไม่ชัด ระบบใช้ชุด ${examVersionLabel(widget.currentVersion!)} '
            'ถ้าไม่ใช่ เลือกชุดที่ถูกต้อง',
      _ =>
        'ระบบอ่านได้ชุด ${examVersionLabel(widget.currentVersion!)} '
            'ถ้าอ่านผิด เลือกชุดที่ถูกต้อง',
    };
    return Scaffold(
      appBar: AppBar(title: Text('เลือกชุด · หน้า ${widget.pageNo}')),
      body: ContentColumn(
        child: ListView(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
          children: [
            if (widget.studentLabel case final label?)
              Text(label, style: theme.textTheme.titleMedium),
            const SizedBox(height: 4),
            Text(notice, key: const ValueKey('version_notice')),
            const SizedBox(height: 12),
            ScanPageImage(scanId: widget.scanId),
            const SizedBox(height: 4),
            Text('วงชุดอยู่ที่ส่วนหัวของหน้า 1 แตะภาพเพื่อขยาย', style: muted),
            const SizedBox(height: 16),
            Text('ชุดข้อสอบ', style: theme.textTheme.labelLarge),
            const SizedBox(height: 4),
            Wrap(
              spacing: 8,
              runSpacing: 4,
              children: [
                for (var v = 1; v <= widget.versionCount; v++)
                  ChoiceChip(
                    key: ValueKey('version_choice_$v'),
                    label: Text('ชุด ${examVersionLabel(v)}'),
                    selected: _version == v,
                    onSelected: _busy
                        ? null
                        : (_) => setState(() {
                            _version = v;
                            _error = null;
                          }),
                  ),
              ],
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  _error!,
                  key: const ValueKey('version_error'),
                  style: TextStyle(color: theme.colorScheme.error),
                ),
              ),
            const SizedBox(height: 16),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton.icon(
                key: const ValueKey('version_save'),
                onPressed: _busy ? null : _save,
                icon: _busy
                    ? const SizedBox.square(
                        dimension: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.check),
                label: const Text('ใช้ชุดนี้และคิดคะแนน'),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// "ใช้ชุด ข แล้ว หน้านี้ได้ 8/10 คะแนน" after a version was picked.
String versionChosenMessage(ExamSheetPageResult r) {
  if (r.versionNo == null) return 'บันทึกแล้ว';
  final score = r.score == null || r.maxScore == null
      ? ''
      : ' หน้านี้ได้ ${formatPoints(r.score!)}/${formatPoints(r.maxScore!)} คะแนน';
  return 'ใช้ชุด ${examVersionLabel(r.versionNo!)} แล้ว$score';
}
