import '../../core/widgets/mascot_mood.dart';
import 'assistant.dart';

/// น้องเช็ค's mood for the chat as it stands (DESIGN §43).
MascotMood moodForChat(AssistantChatState chat) {
  if (chat.sending) return MascotMood.thinking;
  final last = chat.messages.lastOrNull;
  if (last == null) return MascotMood.wave;
  if (last.isError) return MascotMood.sorry;
  if (chat.justApplied || last.planStatus == AssistantMessage.planApplied) {
    return MascotMood.happy;
  }
  return MascotMood.idle;
}

