import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'classrooms_providers.dart';
import 'classrooms_repository.dart';
import 'school_student_search.dart';
import 'school_students.dart';

/// "รวมบัญชีนักเรียน" step 1 (DESIGN §24.5, §24.13): the student of the
/// roster is the account that stays; the teacher searches the school for
/// the other account of the same child, then compares both.
class MergeStudentSearchScreen extends ConsumerWidget {
  const MergeStudentSearchScreen({
    super.key,
    required this.classroomId,
    required this.studentId,
  });

  final int classroomId;
  final int studentId;

  Future<void> _compare(
    BuildContext context,
    WidgetRef ref,
    SchoolStudent other,
  ) async {
    final merged = await context.push<bool>(
      AppRoutes.studentsMergePreview(
        classroomId,
        keepId: studentId,
        mergeId: other.id,
      ),
    );
    if (merged == true && context.mounted) context.pop(true);
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final roster = ref.watch(rosterProvider(classroomId)).value ?? const [];
    final name = roster
        .where((s) => s.studentId == studentId)
        .map((s) => s.name)
        .firstOrNull;
    return Scaffold(
      appBar: AppBar(title: const Text('รวมบัญชีนักเรียน')),
      body: FormColumn(
        maxWidth: 640,
        children: [
          Text(
            name == null
                ? 'ค้นหาอีกบัญชีของนักเรียนคนเดียวกัน'
                : 'ค้นหาอีกบัญชีของ $name',
            style: Theme.of(context).textTheme.titleMedium,
          ),
          const SizedBox(height: 4),
          const Text(
            'ใช้เมื่อนักเรียนคนเดียวมีสองบัญชี หน้าถัดไปเทียบข้อมูลของทั้งสองบัญชีก่อนรวม '
            'รวมได้เมื่อคุณเป็นครูประจำชั้นของห้องที่ทั้งสองบัญชีอยู่',
          ),
          const SizedBox(height: 16),
          SchoolStudentSearch(
            onPick: (s) => _compare(context, ref, s),
            unavailable: (s) => s.id == studentId ? 'บัญชีนี้' : null,
          ),
        ],
      ),
    );
  }
}

/// "รวมบัญชีนักเรียน" step 2 (DESIGN §24.5): both accounts side by side
/// (`GET /students/merge-preview`), why they cannot be merged, and the
/// merge itself after a second confirmation (`POST /students/merge`, no
/// undo). Pops `true` once merged.
class MergePreviewScreen extends ConsumerStatefulWidget {
  const MergePreviewScreen({
    super.key,
    required this.classroomId,
    required this.keepId,
    required this.mergeId,
  });

  final int classroomId;
  final int keepId;
  final int mergeId;

  @override
  ConsumerState<MergePreviewScreen> createState() => _MergePreviewScreenState();
}

class _MergePreviewScreenState extends ConsumerState<MergePreviewScreen> {
  late (int, int) _pair = (widget.keepId, widget.mergeId);
  bool _busy = false;
  String? _error;
  List<String> _errorDetails = const [];

  void _swap() => setState(() {
    _pair = (_pair.$2, _pair.$1);
    _error = null;
    _errorDetails = const [];
  });

  Future<void> _merge(MergePreview preview) async {
    final ok = await confirm(
      context,
      title: 'รวม ${preview.merge.name} เข้ากับ ${preview.keep.name}?',
      message:
          'งาน คะแนน ทักษะ และห้องเรียนของ "${preview.merge.name}" จะย้ายไปอยู่ในบัญชี '
          '"${preview.keep.name}" บัญชีที่รวมเข้ามาจะถูกปิด PIN และบัตร QR ของบัญชีนั้นใช้ไม่ได้ทันที '
          'ย้อนกลับไม่ได้',
      confirmLabel: 'รวมบัญชี',
      destructive: true,
    );
    if (!ok || !mounted) return;
    setState(() {
      _busy = true;
      _error = null;
      _errorDetails = const [];
    });
    try {
      final kept = await ref
          .read(classroomsRepositoryProvider)
          .merge(keepId: _pair.$1, mergeId: _pair.$2);
      if (!mounted) return;
      ref.invalidate(rosterProvider(widget.classroomId));
      ref.invalidate(duplicateCandidatesProvider);
      ref.invalidate(classroomsProvider);
      final messenger = ScaffoldMessenger.of(context);
      context.pop(true);
      messenger
        ..hideCurrentSnackBar()
        ..showSnackBar(
          SnackBar(content: Text('รวมบัญชีแล้ว เก็บบัญชี ${kept.name} ไว้')),
        );
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _busy = false;
        _error = apiErrorMessage(e);
        _errorDetails = apiErrorMessages(e);
      });
      // Something changed since the preview: show the current state.
      if (apiErrorCode(e) == 'merge_conflict') {
        ref.invalidate(mergePreviewProvider(_pair));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final preview = ref.watch(mergePreviewProvider(_pair));
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(title: const Text('เทียบบัญชีก่อนรวม')),
      body: AsyncView(
        value: preview,
        onRetry: () => ref.invalidate(mergePreviewProvider(_pair)),
        data: (p) => FormColumn(
          maxWidth: 760,
          children: [
            Wrap(
              spacing: 12,
              runSpacing: 12,
              children: [
                _AccountCard(
                  key: const ValueKey('merge_keep'),
                  title: 'บัญชีที่เก็บไว้',
                  note: 'ข้อมูลทั้งหมดจะอยู่ในบัญชีนี้',
                  account: p.keep,
                  color: theme.colorScheme.primaryContainer,
                ),
                _AccountCard(
                  key: const ValueKey('merge_merge'),
                  title: 'บัญชีที่จะรวมเข้ามา',
                  note: 'บัญชีนี้จะถูกปิด PIN และบัตร QR ใช้ไม่ได้',
                  account: p.merge,
                  color: theme.colorScheme.surfaceContainerHighest,
                ),
              ],
            ),
            const SizedBox(height: 8),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                key: const ValueKey('merge_swap'),
                onPressed: _busy ? null : _swap,
                icon: const Icon(Icons.swap_horiz),
                label: const Text('สลับบัญชีที่เก็บไว้'),
              ),
            ),
            if (p.conflicts.isNotEmpty) ...[
              const SizedBox(height: 8),
              _Problems(
                key: const ValueKey('merge_conflicts'),
                title: 'รวมไม่ได้ เพราะข้อมูลของสองบัญชีชนกัน',
                lines: [for (final c in p.conflicts) c.message],
              ),
            ],
            if (_error != null) ...[
              const SizedBox(height: 8),
              _Problems(
                key: const ValueKey('merge_error'),
                title: _error!,
                lines: _errorDetails,
              ),
            ],
            const SizedBox(height: 16),
            Text(
              'การรวมย้ายงาน คะแนน ร/มส เกรดที่ประกาศ แบบฝึก ทักษะ และห้องเรียนไปที่บัญชีที่เก็บไว้ '
              'ห้องเดียวกันใช้เลขที่ของบัญชีที่เก็บไว้ ย้อนกลับไม่ได้',
              style: theme.textTheme.bodySmall,
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              key: const ValueKey('merge_submit'),
              style: FilledButton.styleFrom(
                backgroundColor: theme.colorScheme.error,
                foregroundColor: theme.colorScheme.onError,
              ),
              onPressed: p.canMerge && !_busy ? () => _merge(p) : null,
              icon: const Icon(Icons.merge_type),
              label: const Text('รวมบัญชี'),
            ),
          ],
        ),
      ),
    );
  }
}

class _AccountCard extends StatelessWidget {
  const _AccountCard({
    super.key,
    required this.title,
    required this.note,
    required this.account,
    required this.color,
  });

  final String title;
  final String note;
  final MergeAccount account;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return ConstrainedBox(
      constraints: const BoxConstraints(minWidth: 280, maxWidth: 360),
      child: Card(
        color: color,
        child: Padding(
          padding: const EdgeInsets.all(12),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(title, style: theme.textTheme.labelLarge),
              Text(account.name, style: theme.textTheme.titleMedium),
              Text(note, style: theme.textTheme.bodySmall),
              const SizedBox(height: 8),
              Text('ห้องเรียน', style: theme.textTheme.labelMedium),
              if (account.classrooms.isEmpty) const Text('-'),
              for (final c in account.classrooms) Text('• ${c.label}'),
              const SizedBox(height: 8),
              for (final (label, value) in account.facts)
                Padding(
                  padding: const EdgeInsets.only(bottom: 2),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(
                        flex: 3,
                        child: Text(label, style: theme.textTheme.bodySmall),
                      ),
                      Expanded(flex: 4, child: Text(value)),
                    ],
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _Problems extends StatelessWidget {
  const _Problems({super.key, required this.title, required this.lines});

  final String title;
  final List<String> lines;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final style = TextStyle(color: scheme.onErrorContainer);
    return Card(
      color: scheme.errorContainer,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: style.copyWith(fontWeight: FontWeight.bold)),
            for (final l in lines) Text('• $l', style: style),
          ],
        ),
      ),
    );
  }
}
