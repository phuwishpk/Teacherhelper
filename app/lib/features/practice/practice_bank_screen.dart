import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../assignments/question.dart';
import '../assignments/skills_picker.dart';
import '../review/review_labels.dart';
import 'practice_item_editor.dart';
import 'practice_models.dart';
import 'practice_repository.dart';

/// The school's practice bank for teachers (DESIGN §9.6, §14.1): generate
/// drafts with AI per skill, review and edit them, approve or retire.
class PracticeBankScreen extends ConsumerStatefulWidget {
  const PracticeBankScreen({super.key, this.initialSkill});

  final Skill? initialSkill;

  @override
  ConsumerState<PracticeBankScreen> createState() => _PracticeBankScreenState();
}

class _PracticeBankScreenState extends ConsumerState<PracticeBankScreen> {
  late Skill? _skill = widget.initialSkill;
  PracticeItemStatus _status = PracticeItemStatus.draft;

  PracticeBankFilter get _filter => (skillId: _skill?.id, status: _status);

  Future<Skill?> _pickSkill() async {
    final picked = await showSkillsPicker(
      context,
      subjectId: null,
      selected: [?_skill],
    );
    if (picked == null || picked.isEmpty) return null;
    return picked.last;
  }

  Future<void> _chooseSkill() async {
    final skill = await _pickSkill();
    if (skill != null && mounted) setState(() => _skill = skill);
  }

  Future<void> _generate() async {
    final skill = _skill ?? await _pickSkill();
    if (skill == null || !mounted) return;
    final count = await showDialog<int>(
      context: context,
      builder: (context) => SimpleDialog(
        title: Text('สร้างข้อฝึกใหม่ของ ${skill.code}'),
        children: [
          const Padding(
            padding: EdgeInsets.fromLTRB(24, 0, 24, 8),
            child: Text(
              'AI (Gemini) จะร่างข้อใหม่ให้ ข้อที่ได้เป็น "ร่าง" '
              'ต้องตรวจและอนุมัติก่อนนักเรียนเห็น',
            ),
          ),
          for (final n in const [3, 5, 10])
            SimpleDialogOption(
              onPressed: () => Navigator.of(context).pop(n),
              child: Text('สร้าง $n ข้อ'),
            ),
        ],
      ),
    );
    if (count == null || !mounted) return;
    try {
      await ref
          .read(practiceBankRepositoryProvider)
          .generate(skill.id, count: count);
      if (!mounted) return;
      setState(() {
        _skill = skill;
        _status = PracticeItemStatus.draft;
      });
      showMessage(
        context,
        'ส่งคำขอแล้ว ข้อใหม่จะขึ้นในแท็บ "ร่าง" ภายในไม่กี่นาที ดึงลงเพื่อรีเฟรช',
      );
    } catch (e) {
      if (!mounted) return;
      final code = apiErrorCode(e);
      showMessage(
        context,
        code == 'ai_key_missing'
            ? 'ยังไม่ได้ใส่ Gemini API key ใส่ได้ที่หน้าตั้งค่า'
            : apiErrorMessage(e),
      );
    }
  }

  Future<void> _setStatus(PracticeItem item, PracticeItemStatus status) async {
    try {
      await ref
          .read(practiceBankRepositoryProvider)
          .update(item.id, PracticeItemPatch(status: status));
      if (!mounted) return;
      ref.invalidate(practiceBankProvider);
      showMessage(context, switch (status) {
        PracticeItemStatus.approved =>
          'อนุมัติแล้ว นักเรียนจะได้ข้อนี้เป็นแบบฝึก',
        PracticeItemStatus.retired => 'เลิกใช้ข้อนี้แล้ว',
        PracticeItemStatus.draft => 'ย้ายกลับเป็นร่างแล้ว',
      });
    } catch (e) {
      if (mounted) showMessage(context, apiErrorMessage(e));
    }
  }

  Future<void> _edit(PracticeItem item) async {
    final saved = await showPracticeItemEditor(context, item);
    if (saved != null && mounted) {
      ref.invalidate(practiceBankProvider);
      showMessage(context, 'บันทึกแล้ว');
    }
  }

  @override
  Widget build(BuildContext context) {
    final items = ref.watch(practiceBankProvider(_filter));
    return Scaffold(
      appBar: AppBar(title: const Text('คลังแบบฝึก')),
      floatingActionButtonLocation: const ContentFabLocation(),
      floatingActionButton: FloatingActionButton.extended(
        heroTag: 'practice_generate',
        onPressed: _generate,
        icon: const Icon(Icons.auto_awesome),
        label: const Text('สร้างข้อใหม่ด้วย AI'),
      ),
      body: RefreshIndicator(
        onRefresh: () => ref.refresh(practiceBankProvider(_filter).future),
        child: ContentColumn(
          padding: const EdgeInsets.fromLTRB(16, 8, 16, 0),
          child: CustomScrollView(
            slivers: [
              SliverToBoxAdapter(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Wrap(
                      spacing: 8,
                      runSpacing: 8,
                      crossAxisAlignment: WrapCrossAlignment.center,
                      children: [
                        InputChip(
                          key: const ValueKey('bank_skill_filter'),
                          avatar: const Icon(Icons.filter_list, size: 18),
                          label: Text(
                            _skill == null ? 'ทุกทักษะ' : _skill!.code,
                          ),
                          onPressed: _chooseSkill,
                          onDeleted: _skill == null
                              ? null
                              : () => setState(() => _skill = null),
                        ),
                        if (_skill case final skill?)
                          ActionChip(
                            avatar: const Icon(
                              Icons.menu_book_outlined,
                              size: 18,
                            ),
                            label: const Text('ลิงก์ทบทวน'),
                            onPressed: () => context.push(
                              AppRoutes.skillResources(skill.id),
                              extra: skill,
                            ),
                          ),
                      ],
                    ),
                    if (_skill case final skill? when skill.name.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(
                          skill.name,
                          style: Theme.of(context).textTheme.bodySmall,
                        ),
                      ),
                    const SizedBox(height: 8),
                    SegmentedButton<PracticeItemStatus>(
                      segments: const [
                        ButtonSegment(
                          value: PracticeItemStatus.draft,
                          label: Text('ร่าง'),
                          icon: Icon(Icons.edit_note),
                        ),
                        ButtonSegment(
                          value: PracticeItemStatus.approved,
                          label: Text('อนุมัติแล้ว'),
                          icon: Icon(Icons.verified_outlined),
                        ),
                        ButtonSegment(
                          value: PracticeItemStatus.retired,
                          label: Text('เลิกใช้'),
                          icon: Icon(Icons.archive_outlined),
                        ),
                      ],
                      selected: {_status},
                      onSelectionChanged: (s) =>
                          setState(() => _status = s.first),
                    ),
                    const SizedBox(height: 12),
                  ],
                ),
              ),
              ...items.when(
                skipLoadingOnRefresh: true,
                loading: () => [
                  const SliverFillRemaining(
                    hasScrollBody: false,
                    child: Center(child: CircularProgressIndicator()),
                  ),
                ],
                error: (e, _) => [
                  SliverFillRemaining(
                    hasScrollBody: false,
                    child: ErrorView(
                      message: apiErrorMessage(e),
                      onRetry: () =>
                          ref.invalidate(practiceBankProvider(_filter)),
                    ),
                  ),
                ],
                data: (list) => list.isEmpty
                    ? [
                        SliverFillRemaining(
                          hasScrollBody: false,
                          child: EmptyView(
                            icon: Icons.inventory_2_outlined,
                            title: switch (_status) {
                              PracticeItemStatus.draft =>
                                'ไม่มีข้อที่รออนุมัติ',
                              PracticeItemStatus.approved =>
                                'ยังไม่มีข้อที่อนุมัติ',
                              PracticeItemStatus.retired =>
                                'ไม่มีข้อที่เลิกใช้',
                            },
                            message:
                                'กด "สร้างข้อใหม่ด้วย AI" แล้วเลือกทักษะ ข้อที่อนุมัติแล้ว'
                                'ใช้ร่วมกันทั้งโรงเรียน',
                          ),
                        ),
                      ]
                    : [
                        SliverList.builder(
                          itemCount: list.length,
                          itemBuilder: (context, i) => PracticeItemCard(
                            item: list[i],
                            onEdit: () => _edit(list[i]),
                            onStatus: (s) => _setStatus(list[i], s),
                          ),
                        ),
                        const SliverToBoxAdapter(child: SizedBox(height: 96)),
                      ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// A bank item with its answer and explanation, so a draft can be checked
/// and approved from the list.
class PracticeItemCard extends StatelessWidget {
  const PracticeItemCard({
    super.key,
    required this.item,
    required this.onEdit,
    required this.onStatus,
  });

  final PracticeItem item;
  final VoidCallback onEdit;
  final ValueChanged<PracticeItemStatus> onStatus;

  String get _answer => switch (item.answerType) {
    PracticeAnswerType.mcq => item.correctOption ?? '-',
    PracticeAnswerType.numeric when item.numericValue != null => {
      '${formatScore(item.numericValue)}'
          '${(item.numericTolerance ?? 0) > 0 ? ' ± ${formatScore(item.numericTolerance)}' : ''}',
      ...item.acceptedAnswers,
    }.join(' / '),
    _ => item.acceptedAnswers.join(' / '),
  };

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(16, 12, 8, 4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              [
                if (item.skill.code.isNotEmpty) item.skill.code,
                item.answerType.label,
                item.source == 'teacher' ? 'ครูเขียน' : 'AI ร่าง',
              ].join(' · '),
              style: muted,
            ),
            const SizedBox(height: 4),
            Text(item.promptText, style: theme.textTheme.bodyLarge),
            if (item.answerType == PracticeAnswerType.mcq)
              for (final o in item.options)
                Text(
                  '${o.key}. ${o.text}',
                  style: o.key == item.correctOption
                      ? theme.textTheme.bodyMedium?.copyWith(
                          fontWeight: FontWeight.w600,
                        )
                      : theme.textTheme.bodyMedium,
                ),
            const SizedBox(height: 6),
            Text('คำตอบ: $_answer', style: theme.textTheme.bodyMedium),
            if (item.explanation.isNotEmpty)
              Text(
                'คำอธิบาย: ${item.explanation}',
                style: muted,
                maxLines: 3,
                overflow: TextOverflow.ellipsis,
              ),
            Align(
              alignment: Alignment.centerRight,
              child: Wrap(
                spacing: 4,
                children: [
                  TextButton.icon(
                    onPressed: onEdit,
                    icon: const Icon(Icons.edit_outlined),
                    label: const Text('แก้ไข'),
                  ),
                  if (item.status == PracticeItemStatus.draft)
                    FilledButton.tonalIcon(
                      onPressed: () => onStatus(PracticeItemStatus.approved),
                      icon: const Icon(Icons.verified_outlined),
                      label: const Text('อนุมัติ'),
                    ),
                  if (item.status != PracticeItemStatus.retired)
                    TextButton.icon(
                      onPressed: () => onStatus(PracticeItemStatus.retired),
                      icon: const Icon(Icons.archive_outlined),
                      label: const Text('เลิกใช้'),
                    )
                  else
                    TextButton.icon(
                      onPressed: () => onStatus(PracticeItemStatus.approved),
                      icon: const Icon(Icons.unarchive_outlined),
                      label: const Text('นำกลับมาใช้'),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}
