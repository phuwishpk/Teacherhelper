import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../core/api/api_client.dart';
import '../../core/api/error_codes.dart';
import '../../core/auth/auth_repository.dart';
import '../../core/auth/session.dart';
import '../assignments/assignments_providers.dart';
import '../assignments/answer_key_models.dart';
import '../assignments/answer_key_repository.dart';
import '../assignments/key_document_sources.dart';
import 'assistant_plan.dart';

/// `GET /assistant/status` (DESIGN §37): whether the teacher has a Gemini
/// key of their own (the assistant never uses the server key) and how many
/// of the day's questions are used.
class AssistantStatus {
  const AssistantStatus({
    required this.available,
    this.usedToday = 0,
    this.dailyLimit = 100,
    this.studentDataAllowed = true,
    this.studentDataAcknowledged = false,
  });

  final bool available;
  final int usedToday;
  final int dailyLimit;

  /// Students' results (DESIGN §37.8): the admin allows it for the school,
  /// and the teacher pressed "เข้าใจแล้ว" on the notice.
  final bool studentDataAllowed;
  final bool studentDataAcknowledged;

  factory AssistantStatus.fromJson(Map<String, dynamic> json) {
    final data = json['student_data'];
    return AssistantStatus(
      available: json['available'] == true,
      usedToday: (json['used_today'] as num?)?.toInt() ?? 0,
      dailyLimit: (json['daily_limit'] as num?)?.toInt() ?? 100,
      studentDataAllowed: data is! Map || data['allowed'] != false,
      studentDataAcknowledged: data is Map && data['acknowledged'] == true,
    );
  }
}

/// A student as Gemini sees them in one conversation ("S1"); the app shows
/// the name (DESIGN §37.8).
class StudentLabel {
  const StudentLabel({
    required this.label,
    required this.studentId,
    this.name,
    this.classroomId,
  });

  final String label;
  final int studentId;
  final String? name;
  final int? classroomId;

  factory StudentLabel.fromJson(Map<String, dynamic> json) => StudentLabel(
    label: json['label'] as String? ?? '',
    studentId: (json['student_id'] as num).toInt(),
    name: json['name'] as String?,
    classroomId: (json['classroom_id'] as num?)?.toInt(),
  );
}

/// The student data the teacher chose to ask about: a classroom, a student
/// or an assignment (the server's AssistantStudentData sets).
class DataScope {
  const DataScope.classroom(int this.classroomId, this.title)
    : set = 'classroom_summary',
      studentId = null,
      assignmentId = null;

  const DataScope.student(int this.studentId, int this.classroomId, this.title)
    : set = 'student_history',
      assignmentId = null;

  const DataScope.assignment(int this.assignmentId, this.title)
    : set = 'assignment_analysis',
      classroomId = null,
      studentId = null;

  final String set;
  final int? classroomId;
  final int? studentId;
  final int? assignmentId;

  /// What the chip says: the classroom, the student's name or the title.
  final String title;

  Map<String, dynamic> toJson() => {
    'set': set,
    'classroom_id': ?classroomId,
    'student_id': ?studentId,
    'assignment_id': ?assignmentId,
  };
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
    this.plan,
    this.planStatus,
    this.attachments = const [],
  });

  static const user = 'user';
  static const assistant = 'assistant';
  static const error = 'error';

  static const planProposed = 'proposed';
  static const planApplied = 'applied';
  static const planReplaced = 'replaced';

  final int? id;
  final String role;
  final String content;
  final String? errorCode;
  final int? aiCallId;

  /// The card of questions to create (DESIGN §37.6), if the answer has one.
  final AssistantPlan? plan;
  final String? planStatus;

  /// The teacher's files sent with a question (DESIGN §37.10).
  final List<({int id, String name, int pageCount})> attachments;

  bool get isUser => role == user;
  bool get isError => role == error;

  AssistantMessage withPlan(AssistantPlan? plan, String? status) =>
      AssistantMessage(
        id: id,
        role: role,
        content: content,
        errorCode: errorCode,
        aiCallId: aiCallId,
        plan: plan,
        planStatus: status,
        attachments: attachments,
      );

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
        plan: json['plan'] is Map
            ? AssistantPlan.fromJson(
                (json['plan'] as Map).cast<String, dynamic>(),
              )
            : null,
        planStatus: json['plan_status'] as String?,
        attachments: [
          for (final a in json['attachments'] as List? ?? const [])
            if (a is Map)
              (
                id: (a['id'] as num).toInt(),
                name: a['name'] as String? ?? '',
                pageCount: (a['page_count'] as num?)?.toInt() ?? 0,
              ),
        ],
      );
}

/// The conversation after a question: the question and its answer, or, in
/// the body of a failed AI call, the question and the stored failure.
class AssistantReply {
  const AssistantReply(
    this.conversation,
    this.messages, {
    this.applyMessageId,
    this.labels = const [],
  });

  final AssistantConversation conversation;
  final List<AssistantMessage> messages;

  /// The teacher typed a confirmation ("โอเค สร้างเลย"): the open card to
  /// create, with the ticks it has in the app.
  final int? applyMessageId;

  /// Every student label of the conversation so far.
  final List<StudentLabel> labels;

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
      applyMessageId: (json?['apply_message_id'] as num?)?.toInt(),
      labels: [
        for (final l in json?['labels'] as List? ?? const [])
          if (l is Map) StudentLabel.fromJson(l.cast<String, dynamic>()),
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
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
    List<int> documentIds = const [],
  });

  /// "เข้าใจแล้ว" on the student-data notice (DESIGN §37.8).
  Future<AssistantStatus> acknowledgeStudentData();
  Future<AssistantReply> retry(int conversationId);

  /// «สร้างเลย» (DESIGN §37.6): the card with its `result`.
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  });
}

class ApiAssistantRepository implements AssistantRepository {
  ApiAssistantRepository(this._dio);

  final Dio _dio;

  /// The server waits up to 90 s for Gemini (a card of 20 questions is a
  /// long answer); more than the default 20 s.
  static final _slow = Options(receiveTimeout: const Duration(seconds: 120));

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
  Future<AssistantReply> send(
    String text, {
    int? conversationId,
    DataScope? scope,
    List<int> documentIds = const [],
  }) async {
    final res = await _dio.post<Object?>(
      '/assistant/messages',
      data: {
        'text': text,
        'conversation_id': ?conversationId,
        'scope': ?scope?.toJson(),
        if (documentIds.isNotEmpty) 'document_ids': documentIds,
      },
      options: _slow,
    );
    return AssistantReply.tryParse(unwrapJson(res.data)) ??
        (throw const FormatException('assistant reply'));
  }

  @override
  Future<AssistantMessage> apply(
    int messageId,
    List<int> selected, {
    Map<String, dynamic>? target,
  }) async {
    final res = await _dio.post<Object?>(
      '/assistant/messages/$messageId/apply',
      data: {'selected': selected, 'target': ?target},
      options: _slow,
    );
    final message = unwrapJson(res.data)['message'];
    if (message is! Map) throw const FormatException('assistant apply');
    return AssistantMessage.fromJson(message.cast<String, dynamic>());
  }

  @override
  Future<AssistantStatus> acknowledgeStudentData() async {
    final res = await _dio.post<Object?>('/assistant/student-data/ack');
    return AssistantStatus.fromJson(unwrapJson(res.data));
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
    this.labels = const {},
  });

  final AssistantConversation? conversation;
  final List<AssistantMessage> messages;
  final bool sending;
  final bool loading;

  /// The conversation's students by label (DESIGN §37.8).
  final Map<String, StudentLabel> labels;

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
        labels: _labels(c.labels),
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
    final scope = ref.read(assistantScopeProvider);
    final files = ref.read(assistantAttachmentsProvider).files;
    state = AssistantChatState(
      conversation: state.conversation,
      messages: [
        ...before,
        AssistantMessage(
          role: AssistantMessage.user,
          content: question,
          attachments: [
            for (final f in files)
              (id: f.id, name: f.originalName, pageCount: f.pageCount),
          ],
        ),
      ],
      sending: true,
      labels: state.labels,
    );
    final reached = await _ask(
      before,
      () => _repo.send(
        question,
        conversationId: state.conversation?.id,
        scope: scope,
        documentIds: [for (final f in files) f.id],
      ),
    );
    // The files went with the question; a question that never reached the
    // server keeps them for sending again.
    if (reached && ref.mounted) {
      ref.read(assistantAttachmentsProvider.notifier).clear();
    }
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
        labels: state.labels,
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
      labels: state.labels,
    );
    if (question != null && question.isUser) await send(question.content);
  }

  /// Runs [call]; the question and its answer or failure follow [before].
  /// True when the server got the question (answered or stored a failure).
  Future<bool> _ask(
    List<AssistantMessage> before,
    Future<AssistantReply> Function() call,
  ) async {
    try {
      final reply = await call();
      if (!ref.mounted) return true;
      final messages = [...before, ...reply.messages];
      state = AssistantChatState(
        conversation: reply.conversation,
        messages: reply.messages.any((m) => m.plan != null)
            ? _olderCardsReplaced(messages)
            : messages,
        labels: _labels(reply.labels),
      );
      // "โอเค สร้างเลย": create the open card with the ticks it has here.
      if (reply.applyMessageId case final id?) {
        unawaited(ref.read(assistantPlansProvider.notifier).apply(id));
      }
      return true;
    } catch (e) {
      if (!ref.mounted) return false;
      final stored = AssistantReply.tryParse(apiErrorBody(e));
      state = stored != null
          ? AssistantChatState(
              conversation: stored.conversation,
              messages: [...before, ...stored.messages],
              labels: stored.labels.isEmpty
                  ? state.labels
                  : _labels(stored.labels),
            )
          : AssistantChatState(
              conversation: state.conversation,
              messages: [...state.messages, _localFailure(e)],
              labels: state.labels,
            );
      return stored != null;
    } finally {
      if (ref.mounted) {
        ref.invalidate(assistantStatusProvider);
        ref.invalidate(assistantConversationsProvider);
      }
    }
  }

  /// The card after «สร้างเลย».
  void replaceMessage(AssistantMessage message) {
    state = AssistantChatState(
      conversation: state.conversation,
      messages: [
        for (final m in state.messages) m.id == message.id ? message : m,
      ],
      sending: state.sending,
      loading: state.loading,
      labels: state.labels,
    );
  }

  static Map<String, StudentLabel> _labels(List<StudentLabel> labels) => {
    for (final l in labels) l.label: l,
  };

  /// A new card takes the place of the open one (the server did the same).
  static List<AssistantMessage> _olderCardsReplaced(
    List<AssistantMessage> messages,
  ) {
    final last = messages.lastIndexWhere((m) => m.plan != null);
    return [
      for (final (i, m) in messages.indexed)
        i != last && m.planStatus == AssistantMessage.planProposed
            ? m.withPlan(m.plan, AssistantMessage.planReplaced)
            : m,
    ];
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

/// What the teacher did on a card: the ticks, a changed target, and the
/// state of «สร้างเลย».
class PlanUi {
  const PlanUi({
    required this.selected,
    this.target,
    this.applying = false,
    this.error,
  });

  final Set<int> selected;

  /// Chosen with «เปลี่ยน»; null = the card's own target.
  final PlanTarget? target;
  final bool applying;
  final String? error;
}

/// The cards' [PlanUi] by message id, kept like the chat while the teacher
/// is signed in.
class AssistantPlansController extends Notifier<Map<int, PlanUi>> {
  @override
  Map<int, PlanUi> build() {
    ref.watch(currentUserProvider.select((u) => u?.id));
    return const {};
  }

  /// Ticked at first: every question with a key that fits the target, or
  /// every indicator item that can be done and is not "mine" already. A
  /// rubric card has nothing to tick.
  static Set<int> defaultSelection(AssistantPlan plan, PlanTarget target) =>
      switch (plan.kind) {
        AssistantPlan.indicatorsCard => {
          for (final (i, item) in plan.items.indexed)
            if (!item.blocked &&
                !item.flags.contains(IndicatorItem.flagAlreadyMine))
              i,
        },
        AssistantPlan.practiceCard => {
          for (final (i, item) in plan.practiceItems.indexed)
            if (item.error == null) i,
        },
        AssistantPlan.rubricCard ||
        AssistantPlan.gradebookCard ||
        AssistantPlan.fileCard => const {},
        _ => {
          for (final (i, q) in plan.questions.indexed)
            if (q.usableFor(target.kind)) i,
        },
      };

  PlanUi of(AssistantMessage message) {
    final plan = message.plan!;
    return state[message.id] ??
        PlanUi(selected: defaultSelection(plan, plan.target));
  }

  PlanTarget targetOf(AssistantMessage message) =>
      state[message.id]?.target ?? message.plan!.target;

  void toggle(AssistantMessage message, int index) {
    final ui = of(message);
    final selected = {...ui.selected};
    if (!selected.remove(index)) selected.add(index);
    state = {
      ...state,
      message.id!: PlanUi(selected: selected, target: ui.target),
    };
  }

  /// «เปลี่ยน»: the ticks start again for the new target's kind.
  void setTarget(AssistantMessage message, PlanTarget target) {
    state = {
      ...state,
      message.id!: PlanUi(
        selected: defaultSelection(message.plan!, target),
        target: target,
      ),
    };
  }

  Future<void> apply(int messageId) async {
    final message = ref
        .read(assistantChatProvider)
        .messages
        .where(
          (m) =>
              m.id == messageId &&
              m.planStatus == AssistantMessage.planProposed,
        )
        .firstOrNull;
    if (message == null) return;
    final ui = of(message);
    final rubric = const [
      AssistantPlan.rubricCard,
      AssistantPlan.gradebookCard,
      AssistantPlan.fileCard,
    ].contains(message.plan!.kind);
    if (ui.applying || (ui.selected.isEmpty && !rubric)) return;
    state = {
      ...state,
      messageId: PlanUi(
        selected: ui.selected,
        target: ui.target,
        applying: true,
      ),
    };
    try {
      final updated = await ref
          .read(assistantRepositoryProvider)
          .apply(
            messageId,
            ui.selected.toList()..sort(),
            target: ui.target?.toApply(),
          );
      if (!ref.mounted) return;
      ref.read(assistantChatProvider.notifier).replaceMessage(updated);
      ref.invalidate(assignmentsProvider);
      state = {
        ...state,
        messageId: PlanUi(selected: ui.selected, target: ui.target),
      };
    } catch (e) {
      if (!ref.mounted) return;
      state = {
        ...state,
        messageId: PlanUi(
          selected: ui.selected,
          target: ui.target,
          error: apiErrorMessage(e),
        ),
      };
    }
  }
}

final assistantPlansProvider =
    NotifierProvider<AssistantPlansController, Map<int, PlanUi>>(
      AssistantPlansController.new,
    );

/// The student data the next questions are about (DESIGN §37.8), chosen
/// with «ข้อมูลนักเรียน»; null = none (the assistant may ask for some).
class AssistantScopeController extends Notifier<DataScope?> {
  @override
  DataScope? build() {
    ref.watch(currentUserProvider.select((u) => u?.id));
    return null;
  }

  void choose(DataScope? scope) => state = scope;
}

final assistantScopeProvider =
    NotifierProvider<AssistantScopeController, DataScope?>(
      AssistantScopeController.new,
    );

/// Files the teacher picked for the next question (DESIGN §37.10),
/// uploaded at once with POST /documents.
class AssistantAttachments {
  const AssistantAttachments({
    this.files = const [],
    this.uploading = false,
    this.error,
  });

  final List<SourceDocument> files;
  final bool uploading;
  final String? error;
}

class AssistantAttachmentsController extends Notifier<AssistantAttachments> {
  /// The same limit as the server's (AssistantChat::MAX_ATTACHMENTS).
  static const maxFiles = 5;

  @override
  AssistantAttachments build() {
    ref.watch(currentUserProvider.select((u) => u?.id));
    return const AssistantAttachments();
  }

  Future<void> pick() async {
    if (state.uploading || state.files.length >= maxFiles) return;
    final picked = await ref
        .read(documentFilePickerProvider)
        .pick(dialogTitle: 'แนบเอกสารของครู');
    if (picked.isEmpty || !ref.mounted) return;
    state = AssistantAttachments(files: state.files, uploading: true);
    try {
      final uploaded = await ref
          .read(answerKeyRepositoryProvider)
          .upload(picked.take(maxFiles - state.files.length).toList());
      if (!ref.mounted) return;
      state = AssistantAttachments(files: [...state.files, ...uploaded]);
    } catch (e) {
      if (!ref.mounted) return;
      state = AssistantAttachments(
        files: state.files,
        error: apiErrorMessage(e),
      );
    }
  }

  void remove(int id) => state = AssistantAttachments(
    files: [
      for (final f in state.files)
        if (f.id != id) f,
    ],
  );

  void clear() => state = const AssistantAttachments();
}

final assistantAttachmentsProvider =
    NotifierProvider<AssistantAttachmentsController, AssistantAttachments>(
      AssistantAttachmentsController.new,
    );
