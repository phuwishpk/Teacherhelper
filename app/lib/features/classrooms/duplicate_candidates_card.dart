import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/router/app_router.dart';
import 'classrooms_providers.dart';
import 'school_students.dart';

/// "บัญชีที่อาจซ้ำ" on the classroom page (DESIGN §24.4, §24.13): pairs of
/// accounts, at least one in this room, that look like one child. A
/// suggestion only: tapping a pair opens the merge preview. Hidden when
/// there is none (or the list cannot be loaded).
class DuplicateCandidatesCard extends ConsumerWidget {
  const DuplicateCandidatesCard({
    super.key,
    required this.classroomId,
    required this.studentIds,
  });

  final int classroomId;

  /// The students of this room.
  final Set<int> studentIds;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pairs = [
      for (final p
          in ref.watch(duplicateCandidatesProvider).value ??
              const <DuplicateCandidate>[])
        if (p.involves(studentIds)) p,
    ];
    if (pairs.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Card(
        key: const ValueKey('duplicate_candidates'),
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            ListTile(
              leading: Icon(
                Icons.people_alt_outlined,
                color: theme.colorScheme.tertiary,
              ),
              title: Text('บัญชีที่อาจซ้ำ', style: theme.textTheme.titleMedium),
              subtitle: const Text(
                'นักเรียนคนเดียวกันอาจมีสองบัญชี เทียบข้อมูลแล้วรวมเป็นบัญชีเดียวได้',
              ),
            ),
            for (final p in pairs) ...[
              const Divider(height: 1),
              ListTile(
                key: ValueKey('duplicate_${p.a.id}_${p.b.id}'),
                title: Text('${p.a.name} · ${p.b.name}'),
                subtitle: Text(p.reasons.map(duplicateReasonLabel).join(', ')),
                trailing: const Icon(Icons.compare_arrows),
                onTap: () {
                  // The account of this room stays by default; the preview
                  // can swap.
                  final keepA = studentIds.contains(p.a.id);
                  context.push(
                    AppRoutes.studentsMergePreview(
                      classroomId,
                      keepId: keepA ? p.a.id : p.b.id,
                      mergeId: keepA ? p.b.id : p.a.id,
                    ),
                  );
                },
              ),
            ],
          ],
        ),
      ),
    );
  }
}
