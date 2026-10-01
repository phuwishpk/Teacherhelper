import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import 'classroom.dart';
import 'classrooms_providers.dart';

/// `/classrooms/:id/edit`: uses the classroom handed over as route `extra`
/// when there is one, otherwise loads it by id so a deep link or a restored
/// route never silently opens the form in create mode.
class ClassroomEditScreen extends ConsumerWidget {
  const ClassroomEditScreen({
    super.key,
    required this.classroomId,
    this.initial,
  });

  final int classroomId;
  final Classroom? initial;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (initial case final c? when c.id == classroomId) {
      return ClassroomFormScreen(existing: c);
    }
    final classroom = ref.watch(classroomProvider(classroomId));
    return classroom.when(
      skipLoadingOnRefresh: true,
      data: (c) => ClassroomFormScreen(existing: c),
      loading: () => Scaffold(
        appBar: AppBar(title: const Text('แก้ไขห้องเรียน')),
        body: const Center(child: CircularProgressIndicator()),
      ),
      error: (e, _) => Scaffold(
        appBar: AppBar(title: const Text('แก้ไขห้องเรียน')),
        body: ErrorView(
          message: apiErrorMessage(e),
          onRetry: () => ref.invalidate(classroomProvider(classroomId)),
        ),
      ),
    );
  }
}

/// Create (when [existing] is null) or edit a classroom.
class ClassroomFormScreen extends ConsumerStatefulWidget {
  const ClassroomFormScreen({super.key, this.existing});

  final Classroom? existing;

  @override
  ConsumerState<ClassroomFormScreen> createState() =>
      _ClassroomFormScreenState();
}

class _ClassroomFormScreenState extends ConsumerState<ClassroomFormScreen> {
  final _formKey = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.existing?.name ?? '');
  late final _year = TextEditingController(
    text: (widget.existing?.academicYear ?? currentThaiYear()).toString(),
  );
  late int _grade = widget.existing?.gradeLevel ?? 1;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _year.dispose();
    super.dispose();
  }

  /// Saves the form. With [copyStudents] (create only) it opens the new
  /// room and then "นำนักเรียนจากห้องเดิม" (DESIGN §24.6).
  Future<void> _submit({bool copyStudents = false}) async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    final notifier = ref.read(classroomsProvider.notifier);
    try {
      final name = _name.text.trim();
      final year = int.parse(_year.text.trim());
      if (widget.existing case final c?) {
        await notifier.edit(
          c.id,
          name: name,
          gradeLevel: _grade,
          academicYear: year,
        );
      } else {
        final created = await notifier.create(
          name: name,
          gradeLevel: _grade,
          academicYear: year,
        );
        if (copyStudents && mounted) {
          final router = GoRouter.of(context);
          showMessage(context, 'สร้างห้องเรียนแล้ว');
          router.pushReplacement(AppRoutes.classroom(created.id));
          router.push(AppRoutes.studentsFromClassroom(created.id));
          return;
        }
      }
      if (!mounted) return;
      showMessage(
        context,
        widget.existing == null ? 'สร้างห้องเรียนแล้ว' : 'บันทึกแล้ว',
      );
      context.pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final editing = widget.existing != null;
    return Scaffold(
      appBar: AppBar(
        title: Text(editing ? 'แก้ไขห้องเรียน' : 'สร้างห้องเรียน'),
      ),
      body: Form(
        key: _formKey,
        child: FormColumn(
          children: [
            TextFormField(
              controller: _name,
              decoration: const InputDecoration(
                labelText: 'ชื่อห้อง',
                hintText: 'เช่น ป.5/2',
              ),
              textInputAction: TextInputAction.next,
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อห้อง' : null,
            ),
            const SizedBox(height: 16),
            DropdownButtonFormField<int>(
              initialValue: _grade,
              decoration: const InputDecoration(labelText: 'ระดับชั้น'),
              items: [
                for (var level = 1; level <= 12; level++)
                  DropdownMenuItem(
                    value: level,
                    child: Text(gradeLevelLabel(level)),
                  ),
              ],
              onChanged: (v) => setState(() => _grade = v ?? _grade),
            ),
            const SizedBox(height: 16),
            TextFormField(
              controller: _year,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'ปีการศึกษา (พ.ศ.)'),
              validator: (v) {
                final n = int.tryParse(v?.trim() ?? '');
                if (n == null || n < 2500 || n > 2700) {
                  return 'กรอกปี พ.ศ. เช่น ${currentThaiYear()}';
                }
                return null;
              },
              onFieldSubmitted: (_) => _submit(),
            ),
            if (_error != null) ...[
              const SizedBox(height: 16),
              Text(
                _error!,
                style: TextStyle(color: Theme.of(context).colorScheme.error),
              ),
            ],
            const SizedBox(height: 24),
            FilledButton(
              onPressed: _busy ? null : _submit,
              child: Text(editing ? 'บันทึก' : 'สร้างห้องเรียน'),
            ),
            if (!editing) ...[
              const SizedBox(height: 8),
              OutlinedButton.icon(
                key: const ValueKey('classroom_create_copy'),
                onPressed: _busy ? null : () => _submit(copyStudents: true),
                icon: const Icon(Icons.group_add_outlined),
                label: const Text('สร้างแล้วนำนักเรียนจากห้องเดิม'),
              ),
              const SizedBox(height: 4),
              Text(
                'เช่น ห้องเดิมของปีการศึกษาก่อน นักเรียนใช้บัญชีเดิม '
                'ประวัติและคะแนนต่อเนื่อง',
                style: Theme.of(context).textTheme.bodySmall,
                textAlign: TextAlign.center,
              ),
            ],
          ],
        ),
      ),
    );
  }
}
