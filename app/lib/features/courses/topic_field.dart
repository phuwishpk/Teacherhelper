import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'courses_providers.dart';

/// "หัวข้อ" of a material or an assignment (DESIGN §30.1, §30.12): a unit
/// of the course, or a topic the teacher types. Reports a unit id or a
/// free topic, never both.
class TopicField extends ConsumerStatefulWidget {
  const TopicField({
    super.key,
    required this.courseId,
    required this.unitId,
    required this.topic,
    required this.onChanged,
    this.enabled = true,
  });

  /// Null: no course yet, so only a free topic can be typed.
  final int? courseId;
  final int? unitId;
  final String? topic;
  final void Function(int? unitId, String? topic) onChanged;
  final bool enabled;

  @override
  ConsumerState<TopicField> createState() => _TopicFieldState();
}

class _TopicFieldState extends ConsumerState<TopicField> {
  late bool _own = widget.unitId == null && (widget.topic ?? '').isNotEmpty;
  late final _topic = TextEditingController(text: widget.topic);

  @override
  void dispose() {
    _topic.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final courseId = widget.courseId;
    final units = courseId == null
        ? null
        : ref.watch(courseDetailProvider(courseId)).value?.units;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DropdownButtonFormField<int>(
          key: ValueKey('topic_unit_${courseId}_$_own'),
          initialValue: _own
              ? -1
              : (units?.any((u) => u.id == widget.unitId) ?? false)
              ? widget.unitId
              : 0,
          isExpanded: true,
          decoration: const InputDecoration(labelText: 'หัวข้อ'),
          items: [
            const DropdownMenuItem(value: 0, child: Text('ไม่มีหัวข้อ')),
            for (final u in units ?? const [])
              DropdownMenuItem(
                value: u.id,
                child: Text(u.title, overflow: TextOverflow.ellipsis),
              ),
            const DropdownMenuItem(value: -1, child: Text('พิมพ์หัวข้อเอง…')),
          ],
          onChanged: !widget.enabled
              ? null
              : (v) {
                  setState(() => _own = v == -1);
                  if (v == -1) {
                    widget.onChanged(null, _topic.text.trim());
                  } else {
                    widget.onChanged(v == null || v <= 0 ? null : v, null);
                  }
                },
        ),
        if (_own) ...[
          const SizedBox(height: 16),
          TextFormField(
            key: const ValueKey('topic_text'),
            controller: _topic,
            maxLength: 100,
            enabled: widget.enabled,
            decoration: const InputDecoration(labelText: 'ชื่อหัวข้อ'),
            onChanged: (v) => widget.onChanged(null, v.trim()),
            validator: (v) =>
                (v == null || v.trim().isEmpty) ? 'กรอกชื่อหัวข้อ' : null,
          ),
        ],
      ],
    );
  }
}
