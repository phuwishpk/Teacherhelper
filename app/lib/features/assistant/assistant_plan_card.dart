import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/router/app_router.dart';
import 'assistant.dart';
import 'assistant_plan.dart';
import 'plan_cards/file_plan_card.dart';
import 'plan_cards/gradebook_plan_card.dart';
import 'plan_cards/indicator_plan_card.dart';
import 'plan_cards/plan_card_common.dart';
import 'plan_cards/practice_plan_card.dart';
import 'plan_cards/question_plan_card.dart';
import 'plan_cards/rubric_plan_card.dart';

/// The plan card under an assistant answer (DESIGN §37.6): the proposed
/// questions with ticks, where they go («เปลี่ยน»), and «สร้างเลย»; after
/// that, what was created and a way to open it. A phone shows the questions
/// folded into one line.
class AssistantPlanCard extends ConsumerWidget {
  const AssistantPlanCard({
    super.key,
    required this.message,
    required this.onNavigate,
  });

  final AssistantMessage message;

  /// Opens an app route (the desktop panel closes first).
  final ValueChanged<String> onNavigate;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final plan = message.plan!;
    return Card(
      key: ValueKey('assistant_plan_${message.id}'),
      margin: const EdgeInsets.symmetric(vertical: 4),
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 10, 12, 12),
        child: switch ((message.planStatus, plan.kind, plan.result)) {
          (AssistantMessage.planReplaced, _, _) => PlanCardReplaced(plan: plan),
          (AssistantMessage.planApplied, AssistantPlan.rubricCard, final r?) =>
            RubricPlanApplied(result: r, onNavigate: onNavigate),
          (
            AssistantMessage.planApplied,
            AssistantPlan.indicatorsCard,
            final r?,
          ) =>
            IndicatorsPlanApplied(
              plan: plan,
              result: r,
              onNavigate: onNavigate,
            ),
          (
            AssistantMessage.planApplied,
            AssistantPlan.gradebookCard,
            final r?,
          ) =>
            PlanCardSimpleApplied(
              text: 'บันทึกการตั้งค่าสมุดคะแนนของ «${r.title}» แล้ว',
              note: 'ถ้าประกาศเกรดไว้แล้ว ต้องประกาศอีกครั้งให้เป็นค่าใหม่',
              button: 'เปิดตั้งค่าหมวดคะแนน',
              route: AppRoutes.gradebookSettings(r.courseId!),
              onNavigate: onNavigate,
            ),
          (
            AssistantMessage.planApplied,
            AssistantPlan.practiceCard,
            final r?,
          ) =>
            PlanCardSimpleApplied(
              text: 'บันทึกแบบฝึก ${r.created} ข้อเป็นร่างแล้ว',
              note: [
                'ไปตรวจและกดอนุมัติใน «คลังแบบฝึก» นักเรียนจึงจะเห็น',
                for (final s in r.skipped)
                  'ข้ามข้อ ${s.index + 1}: ${s.reason}',
              ].join('\n'),
              button: 'เปิดคลังแบบฝึก',
              route: AppRoutes.practiceBank,
              onNavigate: onNavigate,
            ),
          (AssistantMessage.planApplied, AssistantPlan.fileCard, final r?) =>
            FilePlanApplied(plan: plan, result: r, onNavigate: onNavigate),
          (AssistantMessage.planApplied, _, final r?) => QuestionPlanApplied(
            plan: plan,
            result: r,
            onNavigate: onNavigate,
          ),
          (_, AssistantPlan.fileCard, _) => FilePlanOpen(message: message),
          (_, AssistantPlan.rubricCard, _) => RubricPlanOpen(message: message),
          (_, AssistantPlan.gradebookCard, _) => GradebookPlanOpen(
            message: message,
          ),
          (_, AssistantPlan.practiceCard, _) => PracticePlanOpen(
            message: message,
          ),
          (_, AssistantPlan.indicatorsCard, _) => IndicatorsPlanOpen(
            message: message,
          ),
          _ => QuestionPlanOpen(message: message),
        },
      ),
    );
  }
}
