import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import 'classrooms_providers.dart';
import 'school_students.dart';

/// Searches the school's students by name or student code (DESIGN §24.4
/// `GET /school-students`) and hands the picked one to [onPick]. Used by
/// "เลือกนักเรียนที่มีอยู่" and by "รวมบัญชีนักเรียน".
class SchoolStudentSearch extends ConsumerStatefulWidget {
  const SchoolStudentSearch({
    super.key,
    required this.onPick,
    this.unavailable,
  });

  final ValueChanged<SchoolStudent> onPick;

  /// Why a student cannot be picked (shown on the row), null when they can.
  final String? Function(SchoolStudent student)? unavailable;

  @override
  ConsumerState<SchoolStudentSearch> createState() =>
      _SchoolStudentSearchState();
}

class _SchoolStudentSearchState extends ConsumerState<SchoolStudentSearch> {
  static const _debounce = Duration(milliseconds: 400);

  final _text = TextEditingController();
  Timer? _timer;
  String _query = '';

  @override
  void dispose() {
    _timer?.cancel();
    _text.dispose();
    super.dispose();
  }

  void _searchNow() {
    _timer?.cancel();
    setState(() => _query = _text.text.trim());
  }

  void _onChanged(String _) {
    _timer?.cancel();
    _timer = Timer(_debounce, () {
      if (mounted) _searchNow();
    });
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        TextField(
          key: const ValueKey('school_student_query'),
          controller: _text,
          textInputAction: TextInputAction.search,
          decoration: InputDecoration(
            labelText: 'ค้นชื่อหรือเลขประจำตัวนักเรียน',
            prefixIcon: const Icon(Icons.person_search_outlined),
            suffixIcon: IconButton(
              key: const ValueKey('school_student_search'),
              tooltip: 'ค้นหา',
              icon: const Icon(Icons.search),
              onPressed: _searchNow,
            ),
          ),
          onChanged: _onChanged,
          onSubmitted: (_) => _searchNow(),
        ),
        const SizedBox(height: 8),
        if (_query.length < 2)
          Text(
            'พิมพ์อย่างน้อย 2 ตัวอักษร ค้นได้ทั้งโรงเรียน',
            style: theme.textTheme.bodySmall,
          )
        else
          ...ref
              .watch(schoolStudentSearchProvider(_query))
              .when(
                skipLoadingOnRefresh: true,
                loading: () => const [LinearProgressIndicator()],
                error: (e, _) => [
                  Text(
                    apiErrorMessage(e),
                    style: TextStyle(color: theme.colorScheme.error),
                  ),
                ],
                data: (list) => list.isEmpty
                    ? [const Text('ไม่พบนักเรียน')]
                    : [
                        Card(
                          clipBehavior: Clip.antiAlias,
                          child: Column(
                            children: [for (final s in list) _row(context, s)],
                          ),
                        ),
                      ],
              ),
      ],
    );
  }

  Widget _row(BuildContext context, SchoolStudent s) {
    final reason = widget.unavailable?.call(s);
    return ListTile(
      key: ValueKey('school_student_${s.id}'),
      enabled: reason == null,
      leading: const Icon(Icons.person_outline),
      title: Text(s.name),
      subtitle: Text(s.details),
      trailing: reason == null
          ? const Icon(Icons.add_circle_outline)
          : Text(reason, style: Theme.of(context).textTheme.labelSmall),
      onTap: reason == null ? () => widget.onPick(s) : null,
    );
  }
}
