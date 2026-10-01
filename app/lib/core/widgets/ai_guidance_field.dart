import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../api/teacher_guidance.dart';

export '../api/teacher_guidance.dart';

/// Guidance is sent to Gemini and stored (DESIGN §20.9, §21.12).
const kGuidancePrivacyNote = 'อย่าใส่ชื่อหรือข้อมูลของนักเรียน';

/// Examples of the hint text per place the field is used.
const kGuidanceHintKeyRead =
    'เช่น "เฉลยอยู่หน้าสุดท้าย วงกลมสีแดงคือคำตอบ" หรือ "ข้อ 3 รับคำตอบเป็นเศษส่วนด้วย"';
const kGuidanceHintKeyDraft =
    'เช่น "ข้อ 3 รับคำตอบเป็นเศษส่วนด้วย" หรือ "ใช้หน่วยเป็นเซนติเมตร"';
const kGuidanceHintExamRead =
    'เช่น "เฉลยอยู่ท้ายไฟล์" หรือ "ข้อ 1–20 เป็นปรนัย 4 ตัวเลือก ข้าม ตอนที่ 3"';
const kGuidanceHintCourse =
    'เช่น "แผนอยู่หน้า 3 ถึง 5" หรือ "ตัวชี้วัดอยู่ในตารางท้ายเอกสาร"';
const kGuidanceHintIndicators =
    'เช่น "ข้อ 1–5 เป็นเรื่องการบวก" หรือ "ข้อเขียนตอบวัดการให้เหตุผล"';
const kGuidanceHintExplanation =
    'เช่น "อธิบายด้วยการนับทีละสิบ" หรือ "ใช้ภาษาง่ายสำหรับเด็ก ป.2"';
const kGuidanceHintAnalysis =
    'เช่น "เน้นเรื่องเศษส่วน" หรือ "เขียนให้สั้น เป็นข้อๆ"';

/// Cuts input at [kGuidanceMaxLength] code points.
class _CodePointLimit extends TextInputFormatter {
  const _CodePointLimit(this.max);

  final int max;

  @override
  TextEditingValue formatEditUpdate(
    TextEditingValue oldValue,
    TextEditingValue newValue,
  ) {
    final runes = newValue.text.runes;
    if (runes.length <= max) return newValue;
    // Keeps the old text when a keystroke would go over; a paste is cut.
    if (guidanceLength(oldValue.text) == max) return oldValue;
    final text = String.fromCharCodes(runes.take(max));
    return TextEditingValue(
      text: text,
      selection: TextSelection.collapsed(offset: text.length),
    );
  }
}

/// "คำแนะนำถึง AI" (DESIGN §21.12): an optional multi-line note the teacher
/// adds when Gemini reads a document or drafts something for them, with a
/// 500-character counter, examples in the hint and the privacy line
/// "อย่าใส่ชื่อหรือข้อมูลของนักเรียน".
class AiGuidanceField extends StatelessWidget {
  const AiGuidanceField({
    super.key,
    required this.controller,
    required this.hintText,
    this.onChanged,
    this.enabled = true,
    this.errorText,
    this.autofocus = false,
  });

  final TextEditingController controller;

  /// Examples that fit the place, e.g. [kGuidanceHintKeyRead].
  final String hintText;
  final ValueChanged<String>? onChanged;
  final bool enabled;

  /// The server's message for the field (`errors.guidance`).
  final String? errorText;
  final bool autofocus;

  @override
  Widget build(BuildContext context) {
    return TextField(
      key: const ValueKey('ai_guidance'),
      controller: controller,
      enabled: enabled,
      autofocus: autofocus,
      minLines: 2,
      maxLines: 5,
      keyboardType: TextInputType.multiline,
      textInputAction: TextInputAction.newline,
      inputFormatters: const [_CodePointLimit(kGuidanceMaxLength)],
      onChanged: onChanged,
      buildCounter:
          (
            context, {
            required currentLength,
            required isFocused,
            required maxLength,
          }) => Text(
            '${guidanceLength(controller.text)}/$kGuidanceMaxLength',
            key: const ValueKey('ai_guidance_counter'),
            style: Theme.of(context).textTheme.bodySmall,
          ),
      decoration: InputDecoration(
        labelText: 'คำแนะนำถึง AI (ไม่บังคับ)',
        alignLabelWithHint: true,
        hintText: hintText,
        hintMaxLines: 3,
        helperText: kGuidancePrivacyNote,
        helperMaxLines: 2,
        errorText: errorText,
        errorMaxLines: 3,
        border: const OutlineInputBorder(),
      ),
    );
  }
}

/// "คำแนะนำที่ใช้: …" under a result the server echoed `guidance` for.
class GuidanceUsedNote extends StatelessWidget {
  const GuidanceUsedNote({super.key, required this.guidance, this.color});

  final String guidance;

  /// Text color on a colored card.
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final c = color ?? theme.colorScheme.onSurfaceVariant;
    return Row(
      key: const ValueKey('guidance_used'),
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Padding(
          padding: const EdgeInsets.only(top: 2),
          child: Icon(Icons.tips_and_updates_outlined, size: 16, color: c),
        ),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            'คำแนะนำที่ใช้: $guidance',
            style: theme.textTheme.bodySmall?.copyWith(color: c),
          ),
        ),
      ],
    );
  }
}

/// The answer of [showGuidanceDialog]: the teacher pressed send ([guidance]
/// may be null = none); a cancelled dialog resolves to null instead.
typedef GuidanceChoice = ({String? guidance});

/// A small dialog with [AiGuidanceField] before a one-tap AI action ("ให้ AI
/// เขียนใหม่", "วิเคราะห์ตอนนี้", "ให้ AI เสนอตัวชี้วัด"), prefilled with
/// [initial] (the guidance the server echoed last time).
Future<GuidanceChoice?> showGuidanceDialog(
  BuildContext context, {
  required String title,
  required String hintText,
  String confirmLabel = 'ส่งให้ AI',
  String? message,
  String? initial,
}) => showDialog<GuidanceChoice>(
  context: context,
  builder: (_) => _GuidanceDialog(
    title: title,
    hintText: hintText,
    confirmLabel: confirmLabel,
    message: message,
    initial: initial,
  ),
);

class _GuidanceDialog extends StatefulWidget {
  const _GuidanceDialog({
    required this.title,
    required this.hintText,
    required this.confirmLabel,
    this.message,
    this.initial,
  });

  final String title;
  final String hintText;
  final String confirmLabel;
  final String? message;
  final String? initial;

  @override
  State<_GuidanceDialog> createState() => _GuidanceDialogState();
}

class _GuidanceDialogState extends State<_GuidanceDialog> {
  late final _text = TextEditingController(text: widget.initial ?? '');

  @override
  void dispose() {
    _text.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.title),
      scrollable: true,
      content: SizedBox(
        width: 480,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (widget.message case final m?) ...[
              Text(m),
              const SizedBox(height: 12),
            ],
            AiGuidanceField(controller: _text, hintText: widget.hintText),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('ยกเลิก'),
        ),
        FilledButton.icon(
          key: const ValueKey('guidance_send'),
          onPressed: () => Navigator.of(
            context,
          ).pop<GuidanceChoice>((guidance: normalizeGuidance(_text.text))),
          icon: const Icon(Icons.auto_awesome),
          label: Text(widget.confirmLabel),
        ),
      ],
    );
  }
}
