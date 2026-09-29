import 'package:flutter/material.dart';

import '../../core/widgets/content_column.dart';
import 'review_models.dart';

String _matchLabel(Object? v) => switch (v) {
  'exact' => 'ตรงกับเฉลย',
  'equivalent' => 'เทียบเท่าเฉลย',
  'partial' => 'ถูกบางส่วน',
  'different' => 'ไม่ตรงกับเฉลย',
  'missing' => 'ไม่มีคำตอบ',
  _ => '-',
};

String _legibilityLabel(Object? v) => switch (v) {
  'clear' => 'ลายมือชัด',
  'readable' => 'ลายมืออ่านได้',
  'hard' => 'ลายมืออ่านยาก',
  _ => '',
};

String _levelLabel(Object? v) => switch (v) {
  'met' => 'ผ่าน',
  'partially_met' => 'ผ่านบางส่วน',
  'not_met' => 'ไม่ผ่าน',
  _ => '-',
};

/// "สิ่งที่อ่านได้": what Gemini transcribed (DESIGN §10.3), the CNN's
/// second reading of numeric boxes (§12.1), the mcq fill ratios (crop
/// path) or the options Gemini read (whole-page path, §19.4).
class ExtractionView extends StatelessWidget {
  const ExtractionView({super.key, required this.detail});

  final ResponseDetail detail;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final muted = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    final e = detail.extraction;
    final type = detail.question.type;
    final rows = <Widget>[];

    if (type == 'mcq' && detail.mcqFill != null) {
      final entries = detail.mcqFill!.entries.toList()
        ..sort((a, b) => a.key.compareTo(b.key));
      for (final MapEntry(:key, :value) in entries) {
        rows.add(
          Row(
            children: [
              SizedBox(width: 28, child: Text(key)),
              Expanded(
                child: LinearProgressIndicator(
                  value: value.clamp(0, 1).toDouble(),
                  minHeight: 8,
                ),
              ),
              const SizedBox(width: 8),
              SizedBox(width: 48, child: Text('${(value * 100).round()}%')),
            ],
          ),
        );
      }
      rows.add(Text('วงที่นับว่าฝนต้องเข้มตั้งแต่ 45% ขึ้นไป', style: muted));
    }

    if (e == null) {
      if (type != 'mcq') {
        rows.add(
          Text(
            detail.isManual
                ? 'AI ยังไม่ได้อ่านข้อนี้ ดูภาพแล้วให้คะแนนเองได้เลย'
                : 'ยังไม่มีข้อมูลที่ AI อ่านได้',
            style: muted,
          ),
        );
      }
    } else {
      final flags = <Widget>[
        if (e['blank'] == true)
          const StatusChip(label: 'ว่าง (ไม่ได้ตอบ)', color: Colors.grey),
        if (_legibilityLabel(e['legibility']) case final l when l.isNotEmpty)
          StatusChip(
            label: l,
            color: e['legibility'] == 'hard'
                ? theme.colorScheme.error
                : theme.colorScheme.secondary,
          ),
        if (e['suspicious_instruction'] == true)
          StatusChip(label: 'น่าสงสัย', color: theme.colorScheme.error),
      ];
      if (flags.isNotEmpty) {
        rows.add(Wrap(spacing: 8, runSpacing: 4, children: flags));
      }
      switch (type) {
        case 'mcq':
          // Whole-page path (DESIGN §19.4): Gemini reports the chosen options
          // and the server grades them; there are no fill ratios.
          if (e['selected_options'] case final List options) {
            rows.add(
              _labelled(
                context,
                'ตัวเลือกที่อ่านได้',
                options.isEmpty ? 'ไม่ได้เลือก' : options.join(', '),
              ),
            );
          }
        case 'short':
          rows.add(_labelled(context, 'คำตอบที่อ่านได้', e['answer_text']));
          rows.add(Text('เทียบกับเฉลย: ${_matchLabel(e['key_match'])}'));
        case 'show_work':
          final steps = e['steps'] is List ? e['steps'] as List : const [];
          if (steps.isEmpty) {
            rows.add(Text('ไม่มีขั้นตอนที่อ่านได้', style: muted));
          }
          for (final s in steps.whereType<Map>()) {
            final valid = s['valid'] == true;
            rows.add(
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Icon(
                    valid ? Icons.check_circle : Icons.cancel,
                    size: 20,
                    color: valid
                        ? Colors.green.shade700
                        : theme.colorScheme.error,
                    semanticLabel: valid ? 'ขั้นตอนถูก' : 'ขั้นตอนผิด',
                  ),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('บรรทัด ${s['line'] ?? '?'}: ${s['text'] ?? ''}'),
                        if (s['note_th'] case final String note
                            when note.isNotEmpty)
                          Text(note, style: muted),
                      ],
                    ),
                  ),
                ],
              ),
            );
          }
          rows.add(
            _labelled(
              context,
              'คำตอบสุดท้าย',
              '${e['final_answer_text'] ?? '-'} (${_matchLabel(e['final_answer_match'])})',
            ),
          );
        case 'open':
          rows.add(_labelled(context, 'ข้อความที่อ่านได้', e['transcription']));
          final criteria = e['criteria'] is List
              ? e['criteria'] as List
              : const [];
          for (final c in criteria.whereType<Map>()) {
            final ref = detail.question.criteria
                .where((q) => q.id == c['criterion_id'])
                .firstOrNull;
            rows.add(
              ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                title: Text(
                  '${ref?.isCore == true ? '(เกณฑ์หลัก) ' : ''}'
                  '${ref?.description ?? 'เกณฑ์ #${c['criterion_id']}'}',
                ),
                subtitle: c['evidence_th'] is String
                    ? Text(c['evidence_th'] as String)
                    : null,
                trailing: Text(_levelLabel(c['level'])),
              ),
            );
          }
      }
      if (e['summary_th'] case final String summary when summary.isNotEmpty) {
        rows.add(Text('หมายเหตุจาก AI: $summary', style: muted));
      }
    }

    if (detail.cnnText != null) {
      final conf = detail.cnnConfidence;
      rows.add(
        Text(
          'CNN อ่านตัวเลขได้: ${detail.cnnText}'
          '${conf == null ? '' : ' (ความมั่นใจ ${(conf * 100).round()}%)'}',
        ),
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        for (final r in rows)
          Padding(padding: const EdgeInsets.only(bottom: 6), child: r),
      ],
    );
  }

  static Widget _labelled(BuildContext context, String label, Object? value) {
    return Text.rich(
      TextSpan(
        children: [
          TextSpan(
            text: '$label: ',
            style: const TextStyle(fontWeight: FontWeight.w600),
          ),
          TextSpan(text: value is String && value.isNotEmpty ? value : '-'),
        ],
      ),
    );
  }
}
