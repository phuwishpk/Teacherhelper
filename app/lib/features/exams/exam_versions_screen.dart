import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'exam_models.dart';
import 'exam_providers.dart';

/// "ชุดข้อสอบ" (DESIGN §22.5): how many versions (1–4, fixed once
/// printed), "สุ่มใหม่", and per version the question order, the option
/// order of every shuffled mcq and the key as printed on that version, so
/// the teacher can check them before printing.
class ExamVersionsScreen extends ConsumerStatefulWidget {
  const ExamVersionsScreen({super.key, required this.examId});

  final int examId;

  @override
  ConsumerState<ExamVersionsScreen> createState() => _ExamVersionsScreenState();
}

class _ExamVersionsScreenState extends ConsumerState<ExamVersionsScreen> {
  bool _busy = false;

  Future<void> _run(Future<void> Function() op, String done) async {
    setState(() => _busy = true);
    try {
      await op();
      if (mounted) showMessage(context, done);
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _setCount(int n) => _run(
    () => ref.read(examDetailProvider(widget.examId).notifier).updateSettings({
      'version_count': n,
    }),
    'ใช้ $n ชุดแล้ว',
  );

  Future<void> _reshuffle() async {
    final ok = await confirm(
      context,
      title: 'สุ่มชุดใหม่?',
      message:
          'ลำดับข้อและตัวเลือกของชุด ข เป็นต้นไปจะเปลี่ยน ชุด ก คงลำดับต้นฉบับ',
      confirmLabel: 'สุ่มใหม่',
    );
    if (!ok || !mounted) return;
    await _run(() async {
      await ref.read(examVersionsProvider(widget.examId).notifier).reshuffle();
      ref.invalidate(examDetailProvider(widget.examId));
    }, 'สุ่มชุดใหม่แล้ว');
  }

  @override
  Widget build(BuildContext context) {
    final detail = ref.watch(examDetailProvider(widget.examId));
    final versions = ref.watch(examVersionsProvider(widget.examId));
    final theme = Theme.of(context);
    final d = detail.value;
    final locked = d?.structureLocked ?? false;
    final count = d?.exam.versionCount ?? versions.value?.versionCount ?? 1;
    final list = versions.value?.versions ?? const <ExamVersion>[];

    final header = Padding(
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('จำนวนชุด', style: theme.textTheme.labelLarge),
          const SizedBox(height: 8),
          SegmentedButton<int>(
            key: const ValueKey('versions_count'),
            showSelectedIcon: false,
            segments: [
              for (var n = 1; n <= kExamMaxVersions; n++)
                ButtonSegment(value: n, label: Text('$n ชุด')),
            ],
            selected: {count},
            onSelectionChanged: locked || _busy || d == null
                ? null
                : (s) => _setCount(s.first),
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: Text(
                  locked
                      ? 'พิมพ์แล้ว ลำดับของทุกชุดคงที่ '
                            'ปลดล็อกโครงสร้างที่หน้าข้อสอบเพื่อเปลี่ยน'
                      : 'ชุด ก = ลำดับต้นฉบับ ชุดอื่นสลับข้อภายในตอน '
                            'และสลับตัวเลือกปรนัยที่ไม่ได้ห้ามสลับ',
                  style: theme.textTheme.bodySmall,
                ),
              ),
              TextButton.icon(
                key: const ValueKey('versions_reshuffle'),
                onPressed: locked || _busy || count < 2 ? null : _reshuffle,
                icon: const Icon(Icons.shuffle),
                label: const Text('สุ่มใหม่'),
              ),
            ],
          ),
        ],
      ),
    );

    return DefaultTabController(
      key: ValueKey('versions_tabs_${list.length}'),
      length: list.isEmpty ? 1 : list.length,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('ชุดข้อสอบ'),
          bottom: list.length < 2
              ? null
              : TabBar(
                  isScrollable: list.length > 4,
                  tabs: [for (final v in list) Tab(text: 'ชุด ${v.label}')],
                ),
        ),
        body: ContentColumn(
          padding: EdgeInsets.zero,
          child: Column(
            children: [
              header,
              const Divider(height: 1),
              Expanded(
                child: AsyncView(
                  value: versions,
                  onRetry: () =>
                      ref.invalidate(examVersionsProvider(widget.examId)),
                  data: (v) {
                    if (v.versions.isEmpty) {
                      return const EmptyView(
                        icon: Icons.shuffle,
                        title: 'ยังไม่มีชุด',
                        message: 'เพิ่มข้อก่อน แล้วชุดจะสร้างให้อัตโนมัติ',
                      );
                    }
                    if (v.versions.length == 1) {
                      return _VersionList(
                        version: v.versions.single,
                        detail: d,
                      );
                    }
                    return TabBarView(
                      children: [
                        for (final version in v.versions)
                          _VersionList(version: version, detail: d),
                      ],
                    );
                  },
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// The rows of one version: its number → the original number, the option
/// order and the key as printed.
class _VersionList extends StatelessWidget {
  const _VersionList({required this.version, required this.detail});

  final ExamVersion version;
  final ExamDetail? detail;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final rows = <Widget>[];
    int? lastSection;
    for (final item in version.items) {
      if (item.sectionId != lastSection) {
        lastSection = item.sectionId;
        final s = detail?.section(item.sectionId);
        if (s != null) {
          rows.add(
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 12, 16, 4),
              child: Text(s.heading, style: theme.textTheme.titleSmall),
            ),
          );
        }
      }
      final moved = item.sheetNo != item.originalPosition;
      final order = item.shuffled ? item.orderLabel : null;
      rows.add(
        ListTile(
          key: ValueKey('version_${version.versionNo}_item_${item.sheetNo}'),
          dense: true,
          leading: CircleAvatar(radius: 16, child: Text('${item.sheetNo}')),
          title: Text(
            moved
                ? 'ข้อต้นฉบับ ${item.originalPosition}'
                : 'ข้อต้นฉบับ ${item.originalPosition} (ตำแหน่งเดิม)',
          ),
          subtitle: order == null
              ? null
              : Text('ตัวเลือกที่พิมพ์ ก ข ค ง… = ต้นฉบับ $order'),
          trailing: Text(
            'เฉลย ${item.keyLabel}',
            style: theme.textTheme.titleSmall,
          ),
        ),
      );
    }
    return ListView(
      padding: const EdgeInsets.only(bottom: 24),
      children: [
        Padding(
          padding: const EdgeInsets.fromLTRB(16, 12, 16, 0),
          child: Text(
            version.versionNo == 1
                ? 'ชุด ${version.label}: ลำดับต้นฉบับ'
                : 'ชุด ${version.label}: เลขข้อบนกระดาษของชุดนี้ และเฉลยตามชุดนี้',
            style: theme.textTheme.bodySmall,
          ),
        ),
        ...rows,
      ],
    );
  }
}
