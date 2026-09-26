import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/question.dart';
import 'practice_models.dart';
import 'practice_repository.dart';
import 'resource_links.dart';

/// True for an http(s) address with a host.
bool isWebUrl(String text) {
  final uri = Uri.tryParse(text.trim());
  return uri != null &&
      (uri.scheme == 'http' || uri.scheme == 'https') &&
      uri.host.isNotEmpty;
}

/// Review links of one skill (`learning_resources`, DESIGN §9.6, §14.1):
/// students see them next to the practice of that skill.
class SkillResourcesScreen extends ConsumerWidget {
  const SkillResourcesScreen({super.key, required this.skillId, this.skill});

  final int skillId;
  final Skill? skill;

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    final added = await showDialog<LearningResource>(
      context: context,
      builder: (_) => _AddResourceDialog(skillId: skillId),
    );
    if (added != null && context.mounted) {
      ref.invalidate(skillResourcesProvider(skillId));
      showMessage(context, 'เพิ่มลิงก์แล้ว');
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final resources = ref.watch(skillResourcesProvider(skillId));
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(skill == null ? 'ลิงก์ทบทวน' : 'ลิงก์ทบทวน ${skill!.code}'),
      ),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'resource_add',
        onPressed: () => _add(context, ref),
        icon: const Icon(Icons.add_link),
        label: const Text('เพิ่มลิงก์'),
      ),
      body: ContentColumn(
        child: ListView(
          children: [
            if (skill?.name.isNotEmpty ?? false)
              Text(skill!.name, style: theme.textTheme.bodyMedium),
            const SizedBox(height: 4),
            Text(
              'นักเรียนที่ทักษะนี้ยังไม่ผ่านจะเห็นลิงก์เหล่านี้ในหน้าแบบฝึก '
              'ใช้ร่วมกันทั้งโรงเรียน',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            const SizedBox(height: 12),
            resources.when(
              skipLoadingOnRefresh: true,
              loading: () => const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              ),
              error: (e, _) => ErrorView(
                message:
                    e is DioException &&
                        (e.response?.statusCode == 404 ||
                            e.response?.statusCode == 405)
                    ? 'เซิร์ฟเวอร์นี้ยังแสดงรายการลิงก์ไม่ได้ แต่เพิ่มลิงก์ใหม่ได้'
                    : apiErrorMessage(e),
                onRetry: () => ref.invalidate(skillResourcesProvider(skillId)),
              ),
              data: (list) => list.isEmpty
                  ? const Padding(
                      padding: EdgeInsets.symmetric(vertical: 24),
                      child: Text(
                        'ยังไม่มีลิงก์ เพิ่มคลิป บทเรียน หรือแบบฝึกออนไลน์ที่ช่วยทบทวนทักษะนี้',
                        textAlign: TextAlign.center,
                      ),
                    )
                  : Card(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        child: Column(
                          children: [
                            for (final r in list) ResourceLinkTile(resource: r),
                          ],
                        ),
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _AddResourceDialog extends ConsumerStatefulWidget {
  const _AddResourceDialog({required this.skillId});

  final int skillId;

  @override
  ConsumerState<_AddResourceDialog> createState() => _AddResourceDialogState();
}

class _AddResourceDialogState extends ConsumerState<_AddResourceDialog> {
  final _form = GlobalKey<FormState>();
  final _title = TextEditingController();
  final _url = TextEditingController();
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _title.dispose();
    _url.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_form.currentState!.validate()) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final added = await ref
          .read(practiceBankRepositoryProvider)
          .addResource(
            widget.skillId,
            title: _title.text.trim(),
            url: _url.text.trim(),
          );
      if (mounted) Navigator.of(context).pop(added);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เพิ่มลิงก์ทบทวน'),
      content: Form(
        key: _form,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextFormField(
              key: const ValueKey('resource_title'),
              controller: _title,
              maxLength: 255,
              decoration: const InputDecoration(labelText: 'ชื่อ'),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'ต้องกรอก' : null,
            ),
            TextFormField(
              key: const ValueKey('resource_url'),
              controller: _url,
              keyboardType: TextInputType.url,
              decoration: const InputDecoration(
                labelText: 'ลิงก์',
                hintText: 'https://…',
              ),
              validator: (v) => isWebUrl(v ?? '')
                  ? null
                  : 'ต้องเป็นลิงก์ http:// หรือ https://',
            ),
            if (_error != null)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton(
          onPressed: _saving ? null : _save,
          child: const Text('เพิ่ม'),
        ),
      ],
    );
  }
}
