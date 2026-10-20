import 'dart:typed_data';

import 'package:eduvision/core/theme/app_theme.dart';
import 'package:eduvision/features/assignments/answer_key_models.dart';
import 'package:eduvision/features/assignments/answer_key_repository.dart';
import 'package:eduvision/features/assignments/key_document_sources.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:eduvision/features/assistant/assistant_panel.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

/// Files in "ผู้ช่วย Krucheck" (DESIGN §37.10).
class _Fake implements AssistantRepository {
  final sent = <List<int>>[];
  final applied = <int>[];

  @override
  Future<AssistantStatus> status() async =>
      const AssistantStatus(available: true);
  @override
  Future<AssistantStatus> acknowledgeStudentData() => status();
  @override
  Future<List<AssistantConversation>> conversations() async => const [];
  @override
  Future<AssistantReply> conversation(int id) => throw UnimplementedError();
  @override
  Future<void> delete(int id) async {}
  @override
  Future<AssistantReply> retry(int id) => throw UnimplementedError();

  static Map<String, dynamic> _card({Map<String, dynamic>? result}) => {
    'kind': 'file',
    'action': 'answer_key',
    'documents': [
      {'id': 31, 'name': 'เฉลย.pdf', 'page_count': 2},
    ],
    'target': {
      'assignment_id': 88,
      'title': 'การบ้านเศษส่วน',
      'kind': 'freeform',
    },
    'result': ?result,
  };

  @override
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  }) async {
    applied.add(messageId);
    return AssistantMessage.fromJson({
      'id': messageId,
      'role': 'assistant',
      'content': 'เสนอส่งไฟล์ไปอ่าน',
      'plan': _card(
        result: {
          'assignment_id': 88,
          'title': 'การบ้านเศษส่วน',
          'cached': false,
        },
      ),
      'plan_status': 'applied',
    });
  }

  @override
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
    List<int> documentIds = const [],
  }) async {
    sent.add(documentIds);
    return AssistantReply(const AssistantConversation(id: 7, title: 'x'), [
      AssistantMessage.fromJson({
        'id': 1,
        'role': 'user',
        'content': text,
        'attachments': [
          for (final id in documentIds)
            {
              'id': id,
              'name': 'เฉลย.pdf',
              'mime_type': 'application/pdf',
              'page_count': 2,
            },
        ],
      }),
      AssistantMessage.fromJson({
        'id': 2,
        'role': 'assistant',
        'content': 'เสนอส่งไฟล์ไปอ่าน',
        'plan': _card(),
        'plan_status': 'proposed',
      }),
    ]);
  }
}

class _Picker implements DocumentFilePicker {
  @override
  Future<List<PickedDocument>> pick({
    bool imagesOnly = false,
    String? dialogTitle,
  }) async => [
    PickedDocument(
      name: 'เฉลย.pdf',
      bytes: Uint8List.fromList([37, 80, 68, 70]),
    ),
  ];
}

class _Uploads extends Fake implements AnswerKeyRepository {
  @override
  Future<List<SourceDocument>> upload(List<PickedDocument> files) async =>
      const [
        SourceDocument(
          id: 31,
          originalName: 'เฉลย.pdf',
          mimeType: 'application/pdf',
          pageCount: 2,
        ),
      ];
}

void main() {
  testWidgets(
    'a file goes with the question and a file card sends it to be read',
    (tester) async {
      final repo = _Fake();
      final routes = <String>[];
      tester.view.physicalSize = const Size(820, 1200);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.pumpWidget(
        ProviderScope(
          overrides: [
            assistantRepositoryProvider.overrideWithValue(repo),
            documentFilePickerProvider.overrideWithValue(_Picker()),
            answerKeyRepositoryProvider.overrideWithValue(_Uploads()),
          ],
          child: MaterialApp(
            theme: AppTheme.light(),
            home: Scaffold(
              body: AssistantPanel(onClose: () {}, onNavigate: routes.add),
            ),
          ),
        ),
      );
      await tester.pumpAndSettle();

      await tester.tap(find.byKey(const ValueKey('assistant_attach')));
      await tester.pumpAndSettle();
      expect(find.byKey(const ValueKey('assistant_file_31')), findsOneWidget);
      expect(
        find.text(
          'แนบเฉพาะเอกสารของครู อย่าแนบไฟล์ที่มีชื่อหรือคำตอบของนักเรียน',
        ),
        findsOneWidget,
      );

      await tester.enterText(
        find.byKey(const ValueKey('assistant_input')),
        'อ่านเฉลยจากไฟล์นี้',
      );
      await tester.tap(find.byKey(const ValueKey('assistant_send')));
      await tester.pumpAndSettle();
      expect(repo.sent.single, [31]);
      // Sent: the chip is gone, the question shows its file.
      expect(find.byKey(const ValueKey('assistant_file_31')), findsNothing);
      expect(find.text('เฉลย.pdf (2 หน้า)'), findsNWidgets(2));
      expect(find.text('อ่านเฉลยจากไฟล์'), findsOneWidget);
      expect(find.text('ไปที่: «การบ้านเศษส่วน»'), findsOneWidget);

      await tester.tap(find.byKey(const ValueKey('assistant_plan_apply_2')));
      await tester.pumpAndSettle();
      expect(repo.applied, [2]);
      expect(find.text('ส่งไฟล์ไปอ่านแล้ว'), findsOneWidget);
      await tester.tap(find.byKey(const ValueKey('assistant_plan_open')));
      expect(routes, ['/assignments/88/answer-key']);
    },
  );
}
