import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import 'gradebook_models.dart';
import 'gradebook_providers.dart';

/// "หมวดคะแนน" of the assignment and exam forms (DESIGN §23.3, §23.9): the
/// categories of the course's gradebook. An exam must pick one once the
/// course is set up; homework may stay "ยังไม่ระบุหมวด" (not counted).
///
/// With [showHomeworkDefault] and no [value] the field shows the course's
/// homework default: what the server gives new homework when the form does
/// not send a category.
class GradebookCategoryField extends ConsumerWidget {
  const GradebookCategoryField({
    super.key,
    required this.courseId,
    required this.value,
    required this.onChanged,
    this.isExam = false,
    this.showHomeworkDefault = false,
    this.allowNone = true,
  });

  final int courseId;
  final int? value;
  final ValueChanged<int?> onChanged;
  final bool isExam;
  final bool showHomeworkDefault;

  /// Offers "ยังไม่ระบุหมวด" (homework only; not when creating, where no
  /// category means the homework default).
  final bool allowNone;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final theme = Theme.of(context);
    final settings = ref.watch(gradebookSettingsProvider(courseId));
    return settings.when(
      skipLoadingOnRefresh: true,
      loading: () => const LinearProgressIndicator(),
      error: (e, _) => Text(
        'โหลดหมวดคะแนนไม่ได้: ${apiErrorMessage(e)}',
        style: TextStyle(color: theme.colorScheme.error),
      ),
      data: (s) {
        if (!s.configured) {
          return const InputDecorator(
            key: ValueKey('gradebook_category_unconfigured'),
            decoration: InputDecoration(
              labelText: 'หมวดคะแนน',
              helperText:
                  'รายวิชานี้ยังไม่ได้ตั้งค่าสมุดคะแนน '
                  'ตั้งค่าได้ที่หน้ารายวิชา > สมุดคะแนน',
              enabled: false,
            ),
            child: Text('ยังไม่ระบุหมวด'),
          );
        }
        final shown =
            value ?? (showHomeworkDefault ? s.homeworkDefault?.id : null);
        final current = s.categories.any((c) => c.id == shown) ? shown : null;
        return DropdownButtonFormField<int?>(
          key: ValueKey('gradebook_category_$courseId'),
          initialValue: current,
          isExpanded: true,
          decoration: InputDecoration(
            labelText: 'หมวดคะแนน',
            helperText: isExam
                ? 'เช่น กลางภาค หรือ ปลายภาค'
                : 'การบ้านใหม่อยู่ในหมวดตั้งต้นของการบ้าน',
          ),
          items: [
            if (!isExam && allowNone)
              const DropdownMenuItem<int?>(
                value: null,
                child: Text('ยังไม่ระบุหมวด (ไม่นับ)'),
              ),
            for (final c in s.categories)
              DropdownMenuItem<int?>(
                value: c.id,
                child: Text(
                  '${c.name} (${formatGbNumber(c.weight)}%)',
                  overflow: TextOverflow.ellipsis,
                ),
              ),
          ],
          onChanged: onChanged,
          validator: (v) =>
              isExam && v == null ? 'เลือกหมวดคะแนนของข้อสอบ' : null,
        );
      },
    );
  }
}

/// "ไม่นับเกรด" (`excluded_from_grade`, §23.3): practice work shown faint.
class ExcludedFromGradeSwitch extends StatelessWidget {
  const ExcludedFromGradeSwitch({
    super.key,
    required this.value,
    required this.onChanged,
  });

  final bool value;
  final ValueChanged<bool> onChanged;

  @override
  Widget build(BuildContext context) => SwitchListTile(
    key: const ValueKey('excluded_from_grade'),
    contentPadding: EdgeInsets.zero,
    title: const Text('ไม่นับเกรด'),
    subtitle: const Text('งานฝึก: แสดงในสมุดคะแนนแบบจาง ไม่เข้าสูตรคะแนน'),
    value: value,
    onChanged: onChanged,
  );
}
