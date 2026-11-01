import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/widgets/small_spinner.dart';
import 'assistant.dart';

/// The files picked for the next question (DESIGN §37.10), with the
/// warning that only the teacher's own documents belong here.
class AssistantAttachedFiles extends ConsumerWidget {
  const AssistantAttachedFiles({super.key, required this.enabled});

  final bool enabled;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final state = ref.watch(assistantAttachmentsProvider);
    if (state.files.isEmpty && !state.uploading && state.error == null) {
      return const SizedBox.shrink();
    }
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 4, 12, 0),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Wrap(
            spacing: 6,
            runSpacing: 4,
            children: [
              for (final f in state.files)
                InputChip(
                  key: ValueKey('assistant_file_${f.id}'),
                  avatar: const Icon(Icons.description_outlined),
                  label: Text('${f.originalName} (${f.pageCount} หน้า)'),
                  deleteIcon: const Icon(Icons.close),
                  deleteButtonTooltipMessage: 'เอาไฟล์ออก',
                  onDeleted: enabled
                      ? () => ref
                            .read(assistantAttachmentsProvider.notifier)
                            .remove(f.id)
                      : null,
                ),
              if (state.uploading)
                const Chip(
                  avatar: SmallSpinner(size: 16),
                  label: Text('กำลังอัปโหลด…'),
                ),
            ],
          ),
          if (state.error case final error?)
            Text(
              error,
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.error,
              ),
            ),
          Text(
            'แนบเฉพาะเอกสารของครู อย่าแนบไฟล์ที่มีชื่อหรือคำตอบของนักเรียน',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}
