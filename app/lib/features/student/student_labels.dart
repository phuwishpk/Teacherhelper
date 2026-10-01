import 'package:flutter/material.dart';

import '../../core/widgets/content_column.dart';

/// The classroom label of every student endpoint (DESIGN §24.11, §24.26):
/// `{id, name, academic_year, closed}`. A student in two classrooms sees
/// "ป.5/1 · 2569" next to each item; a closed classroom is "ห้องเก่า".
class ClassroomLabel {
  const ClassroomLabel({
    required this.id,
    required this.name,
    this.academicYear,
    this.closed = false,
  });

  final int id;
  final String name;
  final int? academicYear;

  /// The classroom was closed ("ห้องเก่า", §24.6): readable, nothing to do.
  final bool closed;

  /// "ป.5/1 · 2569".
  String get text {
    final year = academicYear;
    return year == null || year <= 0 ? name : '$name · $year';
  }

  /// Null unless [json] is a label with an id.
  static ClassroomLabel? fromJson(Object? json) {
    if (json is! Map) return null;
    final id = _int(json['id']);
    if (id == null) return null;
    return ClassroomLabel(
      id: id,
      name: json['name'] as String? ?? '',
      academicYear: _int(json['academic_year']),
      closed: json['closed'] == true,
    );
  }

  static List<ClassroomLabel> listFromJson(Object? json) => [
    if (json is List)
      for (final row in json) ?ClassroomLabel.fromJson(row),
  ];
}

/// `course: {id, code, name}` of the student endpoints.
class CourseRef {
  const CourseRef({required this.id, this.code = '', this.name = ''});

  final int id;
  final String code;
  final String name;

  String get title => [code, name].where((s) => s.isNotEmpty).join(' ');

  static CourseRef? fromJson(Object? json) {
    if (json is! Map) return null;
    final id = _int(json['id']);
    if (id == null) return null;
    return CourseRef(
      id: id,
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
    );
  }
}

/// Where one item sits in the student's combined view (DESIGN §24.11): a
/// course in a classroom, or older work without a course, grouped by its
/// subject ("อื่นๆ ({ชื่อวิชา})").
class SubjectTag {
  const SubjectTag({
    this.course,
    this.subjectName,
    this.classroom,
    this.classroomName,
  });

  final CourseRef? course;
  final String? subjectName;
  final ClassroomLabel? classroom;

  /// Shown when the server sent no [classroom] label.
  final String? classroomName;

  /// The same for every item of one group of `GET /student/overview`.
  String get key {
    final room = classroom?.id ?? classroomName ?? '';
    final c = course;
    return c != null ? 'c${c.id}:$room' : 's${subjectName ?? ''}:$room';
  }

  String get title {
    final c = course;
    if (c != null) return c.title.isEmpty ? 'รายวิชา' : c.title;
    final s = subjectName;
    return s == null || s.isEmpty ? 'อื่นๆ' : 'อื่นๆ ($s)';
  }

  /// "ป.5/1 · 2569", or null when the item has no classroom.
  String? get classroomText {
    final text = classroom?.text ?? classroomName;
    return text == null || text.isEmpty ? null : text;
  }

  bool get closed => classroom?.closed ?? false;

  /// Open classrooms first, courses before "อื่นๆ", then by title, the
  /// newest academic year and the classroom name (as the server sorts the
  /// overview, §24.26).
  static int compare(SubjectTag a, SubjectTag b) {
    int flag(bool v) => v ? 1 : 0;
    final keys = <int>[
      flag(a.closed).compareTo(flag(b.closed)),
      flag(a.course == null).compareTo(flag(b.course == null)),
      (a.course?.code ?? a.title).compareTo(b.course?.code ?? b.title),
      a.title.compareTo(b.title),
      (b.classroom?.academicYear ?? 0).compareTo(
        a.classroom?.academicYear ?? 0,
      ),
      (a.classroomText ?? '').compareTo(b.classroomText ?? ''),
    ];
    return keys.firstWhere((k) => k != 0, orElse: () => 0);
  }
}

/// The items of one subject, in the order they came.
class SubjectSection<T> {
  const SubjectSection(this.tag, this.items);

  final SubjectTag tag;
  final List<T> items;
}

/// Groups [items] by [tagOf] into sections sorted by [SubjectTag.compare];
/// each section keeps the order of [items].
List<SubjectSection<T>> groupBySubject<T>(
  Iterable<T> items,
  SubjectTag Function(T item) tagOf,
) {
  final tags = <String, SubjectTag>{};
  final groups = <String, List<T>>{};
  for (final item in items) {
    final tag = tagOf(item);
    tags.putIfAbsent(tag.key, () => tag);
    (groups[tag.key] ??= []).add(item);
  }
  final keys = tags.keys.toList()
    ..sort((a, b) => SubjectTag.compare(tags[a]!, tags[b]!));
  return [for (final k in keys) SubjectSection(tags[k]!, groups[k]!)];
}

/// The chip label of [tag] among [all]: its title, plus the classroom when
/// another tag has the same title (the same subject in two classrooms).
String subjectChipLabel(SubjectTag tag, Iterable<SubjectTag> all) {
  final shared = all.where((t) => t.title == tag.title).length > 1;
  final room = tag.classroomText;
  return shared && room != null ? '${tag.title} · $room' : tag.title;
}

/// "ป.5/1 · 2569" as a small chip; a closed classroom reads "ห้องเก่า".
class ClassroomChip extends StatelessWidget {
  const ClassroomChip({super.key, required this.tag});

  final SubjectTag tag;

  @override
  Widget build(BuildContext context) {
    final text = tag.classroomText;
    final scheme = Theme.of(context).colorScheme;
    if (text == null) return const SizedBox.shrink();
    return StatusChip(
      label: tag.closed ? 'ห้องเก่า · $text' : text,
      color: tag.closed ? scheme.outline : scheme.tertiary,
    );
  }
}

/// The heading of one subject in a combined list: title and classroom.
class SubjectSectionHeader extends StatelessWidget {
  const SubjectSectionHeader({super.key, required this.tag});

  final SubjectTag tag;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 16, 4, 6),
      child: Wrap(
        spacing: 8,
        runSpacing: 4,
        crossAxisAlignment: WrapCrossAlignment.center,
        children: [
          Text(
            tag.title,
            style: theme.textTheme.titleMedium?.copyWith(
              color: tag.closed ? theme.colorScheme.outline : null,
            ),
          ),
          ClassroomChip(tag: tag),
        ],
      ),
    );
  }
}

/// "ทุกวิชา" and one chip per subject: switches a combined list to one
/// subject. Nothing when there is only one subject.
class SubjectFilterBar extends StatelessWidget {
  const SubjectFilterBar({
    super.key,
    required this.tags,
    required this.selected,
    required this.onSelected,
  });

  final List<SubjectTag> tags;

  /// The [SubjectTag.key] shown, or null for every subject.
  final String? selected;
  final ValueChanged<String?> onSelected;

  @override
  Widget build(BuildContext context) {
    if (tags.length < 2) return const SizedBox.shrink();
    return SingleChildScrollView(
      key: const ValueKey('subject_filter'),
      scrollDirection: Axis.horizontal,
      padding: const EdgeInsets.only(bottom: 4),
      child: Row(
        children: [
          ChoiceChip(
            key: const ValueKey('subject_filter_all'),
            label: const Text('ทุกวิชา'),
            selected: selected == null,
            onSelected: (_) => onSelected(null),
          ),
          for (final t in tags) ...[
            const SizedBox(width: 8),
            ChoiceChip(
              key: ValueKey('subject_filter_${t.key}'),
              label: Text(subjectChipLabel(t, tags)),
              selected: selected == t.key,
              onSelected: (_) => onSelected(t.key),
            ),
          ],
        ],
      ),
    );
  }
}

int? _int(Object? v) => switch (v) {
  int i => i,
  num n => n.toInt(),
  String s => int.tryParse(s),
  _ => null,
};
