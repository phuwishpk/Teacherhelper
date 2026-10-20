import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/error_codes.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';

/// `GET /assistant/status` (DESIGN §37): whether the teacher has a Gemini
/// key of their own (the assistant never uses the server key) and how many
/// of the day's questions are used.
class AssistantStatus {
  const AssistantStatus({
    required this.available,
    this.usedToday = 0,
    this.dailyLimit = 100,
  });

  final bool available;
  final int usedToday;
  final int dailyLimit;

  factory AssistantStatus.fromJson(Map<String, dynamic> json) =>
      AssistantStatus(
        available: json['available'] == true,
        usedToday: (json['used_today'] as num?)?.toInt() ?? 0,
        dailyLimit: (json['daily_limit'] as num?)?.toInt() ?? 100,
      );
}

/// One conversation of the list, with the tokens it has used.
class AssistantConversation {
  const AssistantConversation({
    required this.id,
    required this.title,
    this.inputTokens = 0,
    this.outputTokens = 0,
    this.updatedAt,
  });

  final int id;
  final String title;
  final int inputTokens;
  final int outputTokens;
  final DateTime? updatedAt;

  int get tokens => inputTokens + outputTokens;

  factory AssistantConversation.fromJson(Map<String, dynamic> json) {
    final updated = json['updated_at'];
    return AssistantConversation(
      id: (json['id'] as num).toInt(),
      title: json['title'] as String? ?? '',
      inputTokens: (json['input_tokens'] as num?)?.toInt() ?? 0,
      outputTokens: (json['output_tokens'] as num?)?.toInt() ?? 0,
      updatedAt: updated is String ? DateTime.tryParse(updated) : null,
    );
  }
}

/// A question, an answer or a failure. A failure the server never saw (no
/// connection, the day's limit, no key) lives in the app only: [id] null.
class AssistantMessage {
  const AssistantMessage({
    this.id,
    required this.role,
    required this.content,
    this.errorCode,
    this.aiCallId,
  });

  static const user = 'user';
  static const assistant = 'assistant';
  static const error = 'error';

  final int? id;
  final String role;
  final String content;
  final String? errorCode;
  final int? aiCallId;

  bool get isUser => role == user;
  bool get isError => role == error;

  /// "AI-QUOTA · #48213" for the error bubble.
  ErrorCode? get code => switch (errorCode) {
    final c? => ErrorCode(c, callId: aiCallId),
    null => null,
  };

  factory AssistantMessage.fromJson(Map<String, dynamic> json) =>
      AssistantMessage(
        id: (json['id'] as num?)?.toInt(),
        role: json['role'] as String? ?? assistant,
        content: json['content'] as String? ?? '',
        errorCode: json['error_code'] as String?,
        aiCallId: (json['ai_call_id'] as num?)?.toInt(),
      );
}

/// The conversation after a question: the question and its answer, or, in
/// the body of a failed AI call, the question and the stored failure.
class AssistantReply {
  const AssistantReply(this.conversation, this.messages);

  final AssistantConversation conversation;
  final List<AssistantMessage> messages;

  static AssistantReply? tryParse(Map<String, dynamic>? json) {
    final conversation = json?['conversation'];
    final messages = json?['messages'];
    if (conversation is! Map || messages is! List) return null;
    return AssistantReply(
      AssistantConversation.fromJson(conversation.cast<String, dynamic>()),
      [
        for (final m in messages)
          if (m is Map) AssistantMessage.fromJson(m.cast<String, dynamic>()),
      ],
    );
  }
}

abstract class AssistantRepository {
  Future<AssistantStatus> status();
  Future<List<AssistantConversation>> conversations();
  Future<AssistantReply> conversation(int id);
  Future<void> delete(int id);

  /// A failed AI call is a DioException whose body also carries the stored
  /// `conversation` and `messages` ([AssistantReply.tryParse]).
  Future<AssistantReply> send(String text, {int? conversationId});
  Future<AssistantReply> retry(int conversationId);
}

class ApiAssistantRepository implements AssistantRepository {
  ApiAssistantRepository(this._dio);

  final Dio _dio;

  /// The server waits up to a minute for Gemini; more than the default 20 s.
  static final _slow = Options(receiveTimeout: const Duration(seconds: 90));

  @override
  Future<AssistantStatus> status() async {
    final res = await _dio.get<Object?>('/assistant/status');
    return AssistantStatus.fromJson(unwrapJson(res.data));
  }

  @override
  Future<List<AssistantConversation>> conversations() async {
    final res = await _dio.get<Object?>('/assistant/conversations');
    final data = res.data is Map ? (res.data as Map)['data'] : res.data;
    return [
      for (final c in data is List ? data : const [])
        if (c is Map) AssistantConversation.fromJson(c.cast<String, dynamic>()),
    ];
  }

  @override
  Future<AssistantReply> conversation(int id) async {
    final res = await _dio.get<Object?>('/assistant/conversations/$id');
    return AssistantReply.tryParse(unwrapJson(res.data)) ??
        (throw const FormatException('assistant conversation'));
  }

  @override
  Future<void> delete(int id) async {
    await _dio.delete<Object?>('/assistant/conversations/$id');
  }

  @override
  Future<AssistantReply> send(String text, {int? conversationId}) async {
    final res = await _dio.post<Object?>(
      '/assistant/messages',
      data: {'text': text, 'conversation_id': ?conversationId},
      options: _slow,
    );
    return AssistantReply.tryParse(unwrapJson(res.data)) ??
        (throw const FormatException('assistant reply'));
  }

  @override
  Future<AssistantReply> retry(int conversationId) async {
    final res = await _dio.post<Object?>(
      '/assistant/conversations/$conversationId/retry',
      options: _slow,
    );
    return AssistantReply.tryParse(unwrapJson(res.data)) ??
        (throw const FormatException('assistant reply'));
  }
}

final assistantRepositoryProvider = Provider<AssistantRepository>(
  (ref) => ApiAssistantRepository(ref.watch(dioProvider)),
);

/// Asked again each time the panel opens.
final assistantStatusProvider = FutureProvider.autoDispose<AssistantStatus>((
  ref,
) {
  watchSignedInUser(ref, keepAlive: false);
  return ref.watch(assistantRepositoryProvider).status();
});

final assistantConversationsProvider =
    FutureProvider.autoDispose<List<AssistantConversation>>((ref) {
      watchSignedInUser(ref, keepAlive: false);
      return ref.watch(assistantRepositoryProvider).conversations();
    });

/// The open conversation. [sending] while the AI answers: the panel shows
/// the thinking bubble and locks the input.
class AssistantChatState {
  const AssistantChatState({
    this.conversation,
    this.messages = const [],
    this.sending = false,
    this.loading = false,
  });

  final AssistantConversation? conversation;
  final List<AssistantMessage> messages;
  final bool sending;
  final bool loading;

  /// The last message failed and nothing is running: "ลองใหม่" can go.
  bool get canRetry => !sending && (messages.lastOrNull?.isError ?? false);
}

/// Kept while the teacher is signed in, so closing the desktop panel or
/// leaving the phone's chat page does not lose the conversation or a
/// running question; a different user starts empty.
class AssistantChatController extends Notifier<AssistantChatState> {
  @override
  AssistantChatState build() {
    ref.watch(currentUserProvider.select((u) => u?.id));
    return const AssistantChatState();
  }

  AssistantRepository get _repo => ref.read(assistantRepositoryProvider);

  void startNew() {
    if (state.sending) return;
    state = const AssistantChatState();
  }

  Future<void> open(int id) async {
    if (state.sending) return;
    state = const AssistantChatState(loading: true);
    try {
      final c = await _repo.conversation(id);
      if (!ref.mounted) return;
      state = AssistantChatState(
        conversation: c.conversation,
        messages: c.messages,
      );
    } catch (e) {
      if (!ref.mounted) return;
      state = AssistantChatState(messages: [_localFailure(e)]);
    }
  }

  /// Throws the DioException so the list can say why.
  Future<void> delete(int id) async {
    await _repo.delete(id);
    if (!ref.mounted) return;
    if (state.conversation?.id == id && !state.sending) {
      state = const AssistantChatState();
    }
    ref.invalidate(assistantConversationsProvider);
  }

  Future<void> send(String text) async {
    final question = text.trim();
    if (question.isEmpty || state.sending || state.loading) return;
    final before = state.messages;
    state = AssistantChatState(
      conversation: state.conversation,
      messages: [
        ...before,
        AssistantMessage(role: AssistantMessage.user, content: question),
      ],
      sending: true,
    );
    await _ask(
      before,
      () => _repo.send(question, conversationId: state.conversation?.id),
    );
  }

  /// "ลองใหม่" on the last failure: the server asks its question again; a
  /// failure the server never saw sends the question again.
  Future<void> retry() async {
    if (!state.canRetry) return;
    final messages = state.messages;
    final failure = messages.last;
    final conversation = state.conversation;
    if (failure.id != null && conversation != null) {
      final before = messages.sublist(0, messages.length - 2);
      state = AssistantChatState(
        conversation: conversation,
        messages: messages.sublist(0, messages.length - 1),
        sending: true,
      );
      await _ask(before, () => _repo.retry(conversation.id));
      return;
    }
    final question = messages.length >= 2
        ? messages[messages.length - 2]
        : null;
    state = AssistantChatState(
      conversation: conversation,
      messages: messages.sublist(
        0,
        messages.length - (question == null ? 1 : 2),
      ),
    );
    if (question != null && question.isUser) await send(question.content);
  }

  /// Runs [call]; the question and its answer or failure follow [before].
  Future<void> _ask(
    List<AssistantMessage> before,
    Future<AssistantReply> Function() call,
  ) async {
    try {
      final reply = await call();
      if (!ref.mounted) return;
      state = AssistantChatState(
        conversation: reply.conversation,
        messages: [...before, ...reply.messages],
      );
    } catch (e) {
      if (!ref.mounted) return;
      final stored = AssistantReply.tryParse(apiErrorBody(e));
      state = stored != null
          ? AssistantChatState(
              conversation: stored.conversation,
              messages: [...before, ...stored.messages],
            )
          : AssistantChatState(
              conversation: state.conversation,
              messages: [...state.messages, _localFailure(e)],
            );
    } finally {
      if (ref.mounted) {
        ref.invalidate(assistantStatusProvider);
        ref.invalidate(assistantConversationsProvider);
      }
    }
  }

  static AssistantMessage _localFailure(Object error) {
    final code = errorCodeOf(error);
    return AssistantMessage(
      role: AssistantMessage.error,
      content: switch (code?.code) {
        ErrorCode.offline =>
          'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้ ตรวจอินเทอร์เน็ตแล้วลองใหม่',
        ErrorCode.timeout => 'เซิร์ฟเวอร์ตอบช้าเกินไป ลองใหม่อีกครั้ง',
        _ => apiErrorMessage(error),
      },
      errorCode: code?.code,
      aiCallId: code?.callId,
    );
  }
}

final assistantChatProvider =
    NotifierProvider<AssistantChatController, AssistantChatState>(
      AssistantChatController.new,
    );
