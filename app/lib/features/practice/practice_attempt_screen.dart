import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';
import '../../core/widgets/async_view.dart';
import '../../core/widgets/content_column.dart';
import '../mastery/mastery_models.dart';
import '../mastery/mastery_repository.dart';
import '../mastery/mastery_widgets.dart';
import 'practice_models.dart';
import 'practice_page.dart';
import 'practice_repository.dart';

/// One practice item: type the answer, see at once whether it is right and
/// the item's explanation (DESIGN §9.7, §14.1). Checked on the server with
/// the deterministic AnswerMatcher; there is no retry of the same item.
class PracticeAttemptScreen extends ConsumerWidget {
  const PracticeAttemptScreen({super.key, required this.itemId, this.args});

  final int itemId;

  /// From the practice tab; missing when the route is restored, then the
  /// item is looked up in the recommendations.
  final PracticeAttemptArgs? args;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (args case final a? when a.item.id == itemId) {
      return _AttemptView(item: a.item, next: a.next);
    }
    final recs = ref.watch(practiceRecommendationsProvider);
    return Scaffold(
      appBar: AppBar(title: const Text('แบบฝึก')),
      body: AsyncView(
        value: recs,
        onRetry: () => ref.invalidate(practiceRecommendationsProvider),
        data: (list) {
          for (final r in list) {
            final i = r.items.indexWhere((it) => it.id == itemId);
            if (i >= 0) {
              return _AttemptBody(
                item: r.items[i],
                next: r.items.sublist(i + 1),
              );
            }
          }
          return const EmptyView(
            icon: Icons.fitness_center_outlined,
            title: 'ไม่พบข้อนี้',
            message:
                'ข้อนี้อาจทำไปแล้วหรือครูเลิกใช้ กลับไปเลือกข้ออื่นในหน้าแบบฝึก',
          );
        },
      ),
    );
  }
}

class _AttemptView extends StatelessWidget {
  const _AttemptView({required this.item, required this.next});

  final PracticeItem item;
  final List<PracticeItem> next;

  @override
  Widget build(BuildContext context) => Scaffold(
    appBar: AppBar(
      title: Text(item.skill.code.isEmpty ? 'แบบฝึก' : item.skill.code),
    ),
    body: _AttemptBody(item: item, next: next),
  );
}

class _AttemptBody extends ConsumerStatefulWidget {
  const _AttemptBody({required this.item, required this.next});

  final PracticeItem item;
  final List<PracticeItem> next;

  @override
  ConsumerState<_AttemptBody> createState() => _AttemptBodyState();
}

class _AttemptBodyState extends ConsumerState<_AttemptBody> {
  final _answer = TextEditingController();
  String? _choice;
  bool _sending = false;
  String? _error;
  PracticeAttemptResult? _result;

  PracticeItem get item => widget.item;

  @override
  void dispose() {
    _answer.dispose();
    super.dispose();
  }

  String get _currentAnswer => item.answerType == PracticeAnswerType.mcq
      ? (_choice ?? '')
      : _answer.text.trim();

  Future<void> _submit() async {
    final answer = _currentAnswer;
    if (answer.isEmpty) {
      setState(
        () => _error = item.answerType == PracticeAnswerType.mcq
            ? 'เลือกคำตอบก่อน'
            : 'พิมพ์คำตอบก่อน',
      );
      return;
    }
    setState(() {
      _sending = true;
      _error = null;
    });
    try {
      final result = await ref
          .read(studentPracticeRepositoryProvider)
          .attempt(item.id, answer);
      if (!mounted) return;
      setState(() => _result = result);
      // The attempt moved mastery and takes the item out of the list for
      // 7 days (§14.1): both refresh in the background.
      ref
        ..invalidate(practiceRecommendationsProvider)
        ..invalidate(myMasteryProvider);
    } catch (e) {
      if (mounted) setState(() => _error = apiErrorMessage(e));
    } finally {
      if (mounted) setState(() => _sending = false);
    }
  }

  void _goNext() {
    final next = widget.next;
    if (next.isEmpty) {
      context.pop();
      return;
    }
    context.pushReplacement(
      AppRoutes.studentPractice(next.first.id),
      extra: PracticeAttemptArgs(item: next.first, next: next.sublist(1)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final done = _result != null;
    return SingleChildScrollView(
      child: ContentColumn(
        maxWidth: 640,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (item.skill.name.isNotEmpty)
              Text(
                'ทักษะ: ${item.skill.name}',
                style: theme.textTheme.bodySmall?.copyWith(
                  color: theme.colorScheme.onSurfaceVariant,
                ),
              ),
            const SizedBox(height: 8),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Text(
                  item.promptText,
                  style: theme.textTheme.titleMedium,
                ),
              ),
            ),
            const SizedBox(height: 16),
            _answerInput(enabled: !done && !_sending),
            if (_error != null) ...[
              const SizedBox(height: 8),
              Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
            ],
            const SizedBox(height: 16),
            if (!done)
              FilledButton.icon(
                key: const ValueKey('practice_submit'),
                onPressed: _sending ? null : _submit,
                icon: _sending
                    ? const SizedBox.square(
                        dimension: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.send),
                label: const Text('ส่งคำตอบ'),
              )
            else ...[
              _ResultCard(
                result: _result!,
                fallbackExplanation: item.explanation,
              ),
              const SizedBox(height: 16),
              FilledButton.icon(
                onPressed: _goNext,
                icon: Icon(
                  widget.next.isEmpty ? Icons.check : Icons.arrow_forward,
                ),
                label: Text(
                  widget.next.isEmpty ? 'กลับไปหน้าแบบฝึก' : 'ข้อต่อไป',
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _answerInput({required bool enabled}) {
    switch (item.answerType) {
      case PracticeAnswerType.mcq:
        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            for (final o in item.options)
              _OptionTile(
                option: o,
                selected: _choice == o.key,
                onTap: enabled ? () => setState(() => _choice = o.key) : null,
              ),
          ],
        );
      case PracticeAnswerType.numeric:
        return TextField(
          key: const ValueKey('practice_answer'),
          controller: _answer,
          enabled: enabled,
          keyboardType: const TextInputType.numberWithOptions(
            signed: true,
            decimal: true,
          ),
          inputFormatters: [
            FilteringTextInputFormatter.allow(RegExp(r'[0-9.,\-/ ]')),
          ],
          decoration: const InputDecoration(
            labelText: 'คำตอบ (ตัวเลข)',
            hintText: 'เช่น 12.5, -3 หรือ 3/4',
          ),
          onSubmitted: (_) => _submit(),
        );
      case PracticeAnswerType.short:
        return TextField(
          key: const ValueKey('practice_answer'),
          controller: _answer,
          enabled: enabled,
          maxLength: 200,
          decoration: const InputDecoration(labelText: 'คำตอบ'),
          onSubmitted: (_) => _submit(),
        );
    }
  }
}

class _OptionTile extends StatelessWidget {
  const _OptionTile({
    required this.option,
    required this.selected,
    required this.onTap,
  });

  final PracticeOption option;
  final bool selected;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Card(
      color: selected ? scheme.primaryContainer : null,
      child: ListTile(
        key: ValueKey('practice_option_${option.key}'),
        selected: selected,
        leading: CircleAvatar(
          radius: 16,
          backgroundColor: selected ? scheme.primary : null,
          foregroundColor: selected ? scheme.onPrimary : null,
          child: Text(option.key),
        ),
        title: Text(option.text),
        trailing: selected ? const Icon(Icons.check_circle) : null,
        onTap: onTap,
      ),
    );
  }
}

class _ResultCard extends StatelessWidget {
  const _ResultCard({required this.result, required this.fallbackExplanation});

  final PracticeAttemptResult result;
  final String fallbackExplanation;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final (icon, title, color) = result.correct
        ? (Icons.check_circle, 'ถูกต้อง เก่งมาก', Colors.green.shade700)
        : result.partlyCorrect
        ? (Icons.adjust, 'ถูกบางส่วน', Colors.orange.shade800)
        : (Icons.cancel, 'ยังไม่ถูก', theme.colorScheme.error);
    final explanation = (result.explanation?.trim().isNotEmpty ?? false)
        ? result.explanation!.trim()
        : fallbackExplanation.trim();
    return Card(
      key: const ValueKey('practice_result'),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Icon(icon, color: color, size: 32),
                const SizedBox(width: 12),
                Expanded(
                  child: Text(
                    title,
                    style: theme.textTheme.titleLarge?.copyWith(color: color),
                  ),
                ),
              ],
            ),
            if (explanation.isNotEmpty) ...[
              const SizedBox(height: 12),
              Text(
                result.correct ? 'คำอธิบายเพิ่มเติม' : 'คำอธิบาย',
                style: theme.textTheme.labelLarge,
              ),
              const SizedBox(height: 4),
              Text(explanation, style: theme.textTheme.bodyLarge),
            ],
            if (result.mastery case final m?) ...[
              const SizedBox(height: 12),
              Row(
                children: [
                  Expanded(
                    child: Text(
                      'ทักษะนี้ตอนนี้ ${percent(m.value)}',
                      style: theme.textTheme.bodyMedium,
                    ),
                  ),
                  MasteryLevelChip(level: m.level),
                ],
              ),
              const SizedBox(height: 6),
              MasteryBar(mastery: m),
            ],
          ],
        ),
      ),
    );
  }
}
