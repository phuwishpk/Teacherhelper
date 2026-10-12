import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/util/thai_date.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../classrooms/classroom.dart';
import '../classrooms/classrooms_providers.dart';
import '../courses/courses_providers.dart';
import 'class_post_models.dart';
import 'class_posts_repository.dart';
import 'post_files.dart';

/// "เขียนโพสต์" and "แก้ไขโพสต์" (DESIGN §30.1): an announcement or a
/// material with files and links, for the whole classroom or chosen
/// students, published now, kept as a draft or scheduled. A new post can
/// go to several classrooms at once.
class ClassPostFormScreen extends ConsumerWidget {
  const ClassPostFormScreen({
    super.key,
    required this.classroomId,
    this.postId,
  });

  final int classroomId;

  /// Null = a new post.
  final int? postId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final id = postId;
    final title = id == null ? 'เขียนโพสต์' : 'แก้ไขโพสต์';
    final classroom = ref.watch(classroomProvider(classroomId));
    if (id == null) {
      return Scaffold(
        appBar: AppBar(title: Text(title)),
        body: AsyncView(
          value: classroom,
          onRetry: () => ref.invalidate(classroomProvider(classroomId)),
          data: (c) => _PostForm(classroom: c),
        ),
      );
    }
    final post = ref.watch(classPostProvider(id));
    return Scaffold(
      appBar: AppBar(title: Text(title)),
      body: AsyncView(
        value: classroom,
        onRetry: () => ref.invalidate(classroomProvider(classroomId)),
        data: (c) => AsyncView(
          value: post,
          onRetry: () => ref.invalidate(classPostProvider(id)),
          data: (p) => _PostForm(classroom: c, post: p),
        ),
      ),
    );
  }
}

class _PostForm extends ConsumerStatefulWidget {
  const _PostForm({required this.classroom, this.post});

  final Classroom classroom;
  final ClassPost? post;

  @override
  ConsumerState<_PostForm> createState() => _PostFormState();
}

class _PostFormState extends ConsumerState<_PostForm> {
  final _formKey = GlobalKey<FormState>();
  late final _title = TextEditingController(text: widget.post?.title);
  late final _body = TextEditingController(text: widget.post?.body);
  late final _topic = TextEditingController(
    text: widget.post?.unit == null ? widget.post?.topic : null,
  );

  late PostKind _kind = widget.post?.kind ?? PostKind.announcement;
  late int? _courseId = widget.post?.course?.id;
  late int? _unitId = widget.post?.unit?.id;
  late bool _ownTopic =
      widget.post?.unit == null && (widget.post?.topic ?? '').isNotEmpty;
  late List<int>? _studentIds = (widget.post?.forStudents ?? false)
      ? [...widget.post!.studentIds]
      : null;
  late final List<PostAttachment> _attachments = [...?widget.post?.attachments];
  late PostState _state = widget.post?.state ?? PostState.published;
  late DateTime? _scheduledAt = widget.post?.scheduledAt?.toLocal();

  /// Other classrooms a new post also goes to.
  final _alsoClassrooms = <int>{};
  bool _busy = false;
  bool _uploading = false;
  String? _error;

  bool get _editing => widget.post != null;
  bool get _published => widget.post?.isPublished ?? false;

  @override
  void dispose() {
    _title.dispose();
    _body.dispose();
    _topic.dispose();
    super.dispose();
  }

  Future<void> _pickFiles() async {
    final picked = await ref.read(postFilePickerProvider)();
    if (picked.isEmpty || !mounted) return;
    setState(() {
      _uploading = true;
      _error = null;
    });
    final repo = ref.read(classPostsRepositoryProvider);
    try {
      for (final f in picked) {
        final file = await repo.upload(name: f.name, bytes: f.bytes);
        if (!mounted) return;
        setState(
          () => _attachments.add(PostAttachment(title: file.name, file: file)),
        );
      }
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      ref.invalidate(postStorageUsageProvider);
      if (mounted) setState(() => _uploading = false);
    }
  }

  Future<void> _addLink() async {
    final link = await showDialog<PostAttachment>(
      context: context,
      builder: (context) => const _LinkDialog(),
    );
    if (link != null && mounted) setState(() => _attachments.add(link));
  }

  Future<void> _pickStudents() async {
    final roster = await ref.read(rosterProvider(widget.classroom.id).future);
    if (!mounted) return;
    final chosen = await showDialog<List<int>>(
      context: context,
      builder: (context) =>
          _StudentsDialog(roster: roster, selected: _studentIds ?? const []),
    );
    if (chosen != null && mounted) {
      setState(() {
        _studentIds = chosen;
        _alsoClassrooms.clear();
      });
    }
  }

  Future<void> _pickTime() async {
    final now = DateTime.now();
    final start = _scheduledAt ?? now.add(const Duration(hours: 1));
    final day = await showDatePicker(
      context: context,
      initialDate: start.isBefore(now) ? now : start,
      firstDate: now,
      lastDate: now.add(const Duration(days: 365)),
    );
    if (day == null || !mounted) return;
    final time = await showTimePicker(
      context: context,
      initialTime: TimeOfDay.fromDateTime(start),
    );
    if (time == null || !mounted) return;
    setState(
      () => _scheduledAt = DateTime(
        day.year,
        day.month,
        day.day,
        time.hour,
        time.minute,
      ),
    );
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    final body = _body.text.trim();
    if (_kind == PostKind.announcement &&
        body.isEmpty &&
        _attachments.isEmpty) {
      setState(() => _error = 'พิมพ์ข้อความหรือแนบไฟล์อย่างน้อยหนึ่งอย่าง');
      return;
    }
    if (_state == PostState.scheduled &&
        (_scheduledAt == null || !_scheduledAt!.isAfter(DateTime.now()))) {
      setState(() => _error = 'ตั้งเวลาเผยแพร่เป็นเวลาในอนาคต');
      return;
    }
    if (_studentIds case final ids? when ids.isEmpty) {
      setState(() => _error = 'เลือกนักเรียนอย่างน้อยหนึ่งคน');
      return;
    }
    final material = _kind == PostKind.material;
    final draft = PostDraft(
      kind: _kind,
      state: _published ? PostState.published : _state,
      title: material ? _title.text.trim() : null,
      body: body.isEmpty ? null : body,
      courseId: _courseId,
      unitId: material && !_ownTopic ? _unitId : null,
      topic: material && _ownTopic ? _topic.text.trim() : null,
      studentIds: _studentIds,
      scheduledAt: _state == PostState.scheduled ? _scheduledAt : null,
      attachments: _attachments,
    );
    setState(() {
      _busy = true;
      _error = null;
    });
    final repo = ref.read(classPostsRepositoryProvider);
    final classroomId = widget.classroom.id;
    try {
      if (widget.post case final post?) {
        await repo.update(post.id, draft);
        ref.invalidate(classPostProvider(post.id));
      } else {
        await repo.create([classroomId, ..._alsoClassrooms], draft);
      }
      ref.invalidate(classroomPostsProvider(classroomId));
      for (final id in _alsoClassrooms) {
        ref.invalidate(classroomPostsProvider(id));
      }
      if (!mounted) return;
      showMessage(context, switch (draft.state) {
        PostState.draft => 'บันทึกฉบับร่างแล้ว',
        PostState.scheduled =>
          'ตั้งเวลาเผยแพร่ ${formatThaiDateTime(_scheduledAt!)} แล้ว',
        PostState.published => _published ? 'บันทึกแล้ว' : 'เผยแพร่แล้ว',
      });
      context.pop();
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final classroom = widget.classroom;
    final courses = ref.watch(classroomCoursesProvider(classroom.id));
    final material = _kind == PostKind.material;
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    Widget section(String label) => Padding(
      padding: const EdgeInsets.only(top: 24, bottom: 8),
      child: Text(label, style: theme.textTheme.titleSmall),
    );

    return Form(
      key: _formKey,
      child: FormColumn(
        maxWidth: 640,
        children: [
          if (!_editing)
            SegmentedButton<PostKind>(
              key: const ValueKey('post_kind'),
              showSelectedIcon: false,
              segments: [
                for (final k in PostKind.values)
                  ButtonSegment(value: k, label: Text(k.label)),
              ],
              selected: {_kind},
              onSelectionChanged: (s) => setState(() => _kind = s.single),
            )
          else
            Text(_kind.label, style: theme.textTheme.titleMedium),
          const SizedBox(height: 16),
          if (material) ...[
            TextFormField(
              key: const ValueKey('post_title'),
              controller: _title,
              maxLength: 200,
              decoration: const InputDecoration(labelText: 'ชื่อเรื่อง'),
              validator: (v) =>
                  (v == null || v.trim().isEmpty) ? 'กรอกชื่อเรื่อง' : null,
            ),
            const SizedBox(height: 8),
          ],
          TextFormField(
            key: const ValueKey('post_body'),
            controller: _body,
            minLines: 4,
            maxLines: 12,
            maxLength: 10000,
            decoration: InputDecoration(
              labelText: material
                  ? 'คำอธิบาย (ไม่บังคับ)'
                  : 'ข้อความถึงนักเรียน',
              alignLabelWithHint: true,
              helperText:
                  'ข้อความธรรมดา ลิงก์ที่พิมพ์ไว้กดได้ใน Google Classroom',
            ),
          ),

          section('ไฟล์และลิงก์'),
          for (final (i, a) in _attachments.indexed) ...[
            PostAttachmentTile(
              key: ValueKey('attached_$i'),
              attachment: a,
              onRemove: _busy
                  ? null
                  : () => setState(() => _attachments.removeAt(i)),
            ),
            const SizedBox(height: 8),
          ],
          Wrap(
            spacing: 8,
            runSpacing: 8,
            crossAxisAlignment: WrapCrossAlignment.center,
            children: [
              OutlinedButton.icon(
                key: const ValueKey('post_attach_file'),
                onPressed: _busy || _uploading ? null : _pickFiles,
                icon: _uploading
                    ? const SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.attach_file),
                label: Text(_uploading ? 'กำลังอัปโหลด…' : 'แนบไฟล์'),
              ),
              OutlinedButton.icon(
                key: const ValueKey('post_attach_link'),
                onPressed: _busy ? null : _addLink,
                icon: const Icon(Icons.link),
                label: const Text('แนบลิงก์'),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            [
              'PDF รูปภาพ และไฟล์ Word, Excel, PowerPoint ไฟล์ละไม่เกิน 10 MB รวมกับลิงก์ไม่เกิน 5 รายการ',
              ?ref.watch(postStorageUsageProvider).value?.label,
            ].join(' · '),
            style: muted,
          ),

          section('รายวิชาและหัวข้อ'),
          AsyncView(
            value: courses,
            onRetry: () =>
                ref.invalidate(classroomCoursesProvider(classroom.id)),
            data: (list) => DropdownButtonFormField<int?>(
              key: const ValueKey('post_course'),
              initialValue: list.any((c) => c.id == _courseId)
                  ? _courseId
                  : null,
              isExpanded: true,
              decoration: const InputDecoration(labelText: 'รายวิชา'),
              items: [
                if (classroom.isHomeroom)
                  const DropdownMenuItem(
                    value: null,
                    child: Text('ไม่ระบุรายวิชา (ประกาศของห้อง)'),
                  ),
                for (final c in list)
                  DropdownMenuItem(
                    value: c.id,
                    child: Text(
                      '${c.code} ${c.name}',
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
              onChanged: _busy
                  ? null
                  : (v) => setState(() {
                      _courseId = v;
                      _unitId = null;
                    }),
              validator: (v) => v == null && !classroom.isHomeroom
                  ? 'เลือกรายวิชาของคุณที่สอนในห้องนี้'
                  : null,
            ),
          ),
          if (material) ...[const SizedBox(height: 16), _topicField()],
          if (!material)
            Padding(
              padding: const EdgeInsets.only(top: 8),
              child: Text(
                'ประกาศอยู่บนสตรีม ไม่มีหัวข้อ (เหมือน Google Classroom)',
                style: muted,
              ),
            ),

          section('ส่งถึง'),
          SegmentedButton<bool>(
            key: const ValueKey('post_audience'),
            showSelectedIcon: false,
            segments: const [
              ButtonSegment(value: false, label: Text('ทั้งห้อง')),
              ButtonSegment(value: true, label: Text('เลือกนักเรียน')),
            ],
            selected: {_studentIds != null},
            onSelectionChanged: _busy
                ? null
                : (s) {
                    if (s.single) {
                      _pickStudents();
                    } else {
                      setState(() => _studentIds = null);
                    }
                  },
          ),
          if (_studentIds case final ids?) ...[
            const SizedBox(height: 8),
            Align(
              alignment: AlignmentDirectional.centerStart,
              child: TextButton.icon(
                key: const ValueKey('post_students'),
                onPressed: _busy ? null : _pickStudents,
                icon: const Icon(Icons.group_outlined),
                label: Text('นักเรียน ${ids.length} คน · แก้ไข'),
              ),
            ),
          ],
          if (!_editing && _studentIds == null)
            _OtherClassrooms(
              current: classroom.id,
              selected: _alsoClassrooms,
              onChanged: _busy
                  ? null
                  : (id, on) => setState(
                      () => on
                          ? _alsoClassrooms.add(id)
                          : _alsoClassrooms.remove(id),
                    ),
            ),

          if (!_published) ...[
            section('เผยแพร่เมื่อไร'),
            SegmentedButton<PostState>(
              key: const ValueKey('post_state'),
              showSelectedIcon: false,
              segments: const [
                ButtonSegment(value: PostState.published, label: Text('ทันที')),
                ButtonSegment(value: PostState.draft, label: Text('ฉบับร่าง')),
                ButtonSegment(
                  value: PostState.scheduled,
                  label: Text('ตั้งเวลา'),
                ),
              ],
              selected: {_state},
              onSelectionChanged: _busy
                  ? null
                  : (s) {
                      setState(() => _state = s.single);
                      if (s.single == PostState.scheduled &&
                          _scheduledAt == null) {
                        _pickTime();
                      }
                    },
            ),
            if (_state == PostState.scheduled) ...[
              const SizedBox(height: 8),
              Align(
                alignment: AlignmentDirectional.centerStart,
                child: TextButton.icon(
                  key: const ValueKey('post_schedule'),
                  onPressed: _busy ? null : _pickTime,
                  icon: const Icon(Icons.schedule),
                  label: Text(
                    _scheduledAt == null
                        ? 'เลือกวันและเวลา'
                        : 'เผยแพร่ ${formatThaiDateTime(_scheduledAt!)} · แก้ไข',
                  ),
                ),
              ),
            ],
          ],

          if (_error != null) ...[
            const SizedBox(height: 16),
            Text(
              _error!,
              key: const ValueKey('post_error'),
              style: TextStyle(color: theme.colorScheme.error),
            ),
          ],
          const SizedBox(height: 24),
          FilledButton(
            key: const ValueKey('post_submit'),
            onPressed: _busy || _uploading ? null : _submit,
            child: Text(
              _published
                  ? 'บันทึก'
                  : switch (_state) {
                      PostState.published => 'เผยแพร่',
                      PostState.draft => 'บันทึกฉบับร่าง',
                      PostState.scheduled => 'ตั้งเวลาเผยแพร่',
                    },
            ),
          ),
        ],
      ),
    );
  }

  /// The unit of the chosen course, or a topic the teacher types.
  Widget _topicField() {
    final courseId = _courseId;
    final units = courseId == null
        ? null
        : ref.watch(courseDetailProvider(courseId)).value?.units;
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        DropdownButtonFormField<int>(
          key: ValueKey('post_unit_${courseId}_$_ownTopic'),
          initialValue: _ownTopic
              ? -1
              : (units?.any((u) => u.id == _unitId) ?? false)
              ? _unitId
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
          onChanged: _busy
              ? null
              : (v) => setState(() {
                  _ownTopic = v == -1;
                  _unitId = v == null || v <= 0 ? null : v;
                }),
        ),
        if (_ownTopic) ...[
          const SizedBox(height: 16),
          TextFormField(
            key: const ValueKey('post_topic'),
            controller: _topic,
            maxLength: 100,
            decoration: const InputDecoration(labelText: 'ชื่อหัวข้อ'),
            validator: (v) =>
                (v == null || v.trim().isEmpty) ? 'กรอกชื่อหัวข้อ' : null,
          ),
        ],
      ],
    );
  }
}

/// "โพสต์ไปห้องอื่นด้วย": the teacher's other open classrooms.
class _OtherClassrooms extends ConsumerWidget {
  const _OtherClassrooms({
    required this.current,
    required this.selected,
    required this.onChanged,
  });

  final int current;
  final Set<int> selected;
  final void Function(int id, bool on)? onChanged;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final others = [
      for (final c
          in ref.watch(classroomsProvider).value ?? const <Classroom>[])
        if (c.id != current && !c.isClosed) c,
    ];
    if (others.isEmpty) return const SizedBox.shrink();
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.only(top: 16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('โพสต์ไปห้องอื่นด้วย', style: theme.textTheme.bodyMedium),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            runSpacing: 8,
            children: [
              for (final c in others)
                FilterChip(
                  key: ValueKey('post_also_${c.id}'),
                  label: Text(c.name),
                  selected: selected.contains(c.id),
                  onSelected: onChanged == null
                      ? null
                      : (on) => onChanged!(c.id, on),
                ),
            ],
          ),
          const SizedBox(height: 4),
          Text(
            'แต่ละห้องได้โพสต์ของตัวเอง แก้หรือลบทีละห้องได้ รายวิชาที่เลือกต้องสอนในทุกห้อง',
            style: theme.textTheme.bodySmall?.copyWith(
              color: theme.colorScheme.onSurfaceVariant,
            ),
          ),
        ],
      ),
    );
  }
}

class _LinkDialog extends StatefulWidget {
  const _LinkDialog();

  @override
  State<_LinkDialog> createState() => _LinkDialogState();
}

class _LinkDialogState extends State<_LinkDialog> {
  final _formKey = GlobalKey<FormState>();
  final _url = TextEditingController();
  final _title = TextEditingController();

  @override
  void dispose() {
    _url.dispose();
    _title.dispose();
    super.dispose();
  }

  void _done() {
    if (!_formKey.currentState!.validate()) return;
    final url = _url.text.trim();
    final title = _title.text.trim();
    Navigator.of(
      context,
    ).pop(PostAttachment(title: title.isEmpty ? url : title, url: url));
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('แนบลิงก์'),
      content: Form(
        key: _formKey,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextFormField(
              key: const ValueKey('link_url'),
              controller: _url,
              autofocus: true,
              keyboardType: TextInputType.url,
              decoration: const InputDecoration(
                labelText: 'ลิงก์',
                hintText: 'https://',
              ),
              validator: (v) {
                final uri = Uri.tryParse((v ?? '').trim());
                final ok =
                    uri != null &&
                    (uri.scheme == 'http' || uri.scheme == 'https') &&
                    uri.host.isNotEmpty;
                return ok ? null : 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://';
              },
            ),
            const SizedBox(height: 16),
            TextFormField(
              key: const ValueKey('link_title'),
              controller: _title,
              maxLength: 200,
              decoration: const InputDecoration(
                labelText: 'ชื่อที่แสดง (ไม่บังคับ)',
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
          key: const ValueKey('link_add'),
          onPressed: _done,
          child: const Text('แนบ'),
        ),
      ],
    );
  }
}

class _StudentsDialog extends StatefulWidget {
  const _StudentsDialog({required this.roster, required this.selected});

  final List<RosterStudent> roster;
  final List<int> selected;

  @override
  State<_StudentsDialog> createState() => _StudentsDialogState();
}

class _StudentsDialogState extends State<_StudentsDialog> {
  late final _chosen = {...widget.selected};

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('เลือกนักเรียน'),
      contentPadding: const EdgeInsets.symmetric(vertical: 12),
      content: SizedBox(
        width: 420,
        child: widget.roster.isEmpty
            ? const Padding(
                padding: EdgeInsets.all(24),
                child: Text('ห้องนี้ยังไม่มีนักเรียน'),
              )
            : ListView(
                shrinkWrap: true,
                children: [
                  for (final s in widget.roster)
                    CheckboxListTile(
                      key: ValueKey('pick_student_${s.studentId}'),
                      value: _chosen.contains(s.studentId),
                      title: Text('${s.studentNumber}. ${s.name}'),
                      onChanged: (on) => setState(
                        () => on ?? false
                            ? _chosen.add(s.studentId)
                            : _chosen.remove(s.studentId),
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
          key: const ValueKey('pick_students_done'),
          onPressed: _chosen.isEmpty
              ? null
              : () => Navigator.of(context).pop(_chosen.toList()..sort()),
          child: Text('เลือก ${_chosen.length} คน'),
        ),
      ],
    );
  }
}
