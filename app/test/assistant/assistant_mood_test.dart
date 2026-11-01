import 'package:eduvision/core/widgets/mascot_mood.dart';
import 'package:eduvision/features/assistant/assistant.dart';
import 'package:eduvision/features/assistant/assistant_mood.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  const question = AssistantMessage(
    id: 1,
    role: AssistantMessage.user,
    content: 'ถาม',
  );
  const answer = AssistantMessage(
    id: 2,
    role: AssistantMessage.assistant,
    content: 'ตอบ',
  );
  const failure = AssistantMessage(
    id: 3,
    role: AssistantMessage.error,
    content: 'ล้ม',
  );
  const applied = AssistantMessage(
    id: 4,
    role: AssistantMessage.assistant,
    content: 'เสนอโจทย์',
    planStatus: AssistantMessage.planApplied,
  );

  test('an empty chat waves', () {
    expect(moodForChat(const AssistantChatState()), MascotMood.wave);
  });

  test('sending thinks, even after a failure', () {
    expect(
      moodForChat(
        const AssistantChatState(messages: [question, failure], sending: true),
      ),
      MascotMood.thinking,
    );
  });

  test('a failure is sorry', () {
    expect(
      moodForChat(const AssistantChatState(messages: [question, failure])),
      MascotMood.sorry,
    );
  });

  test('an applied card is happy, an answer is idle', () {
    expect(
      moodForChat(const AssistantChatState(messages: [question, applied])),
      MascotMood.happy,
    );
    expect(
      moodForChat(const AssistantChatState(messages: [question, answer])),
      MascotMood.idle,
    );
  });

  test('a typed confirmation is happy though the card is not the last', () {
    expect(
      moodForChat(
        const AssistantChatState(
          messages: [applied, question, answer],
          justApplied: true,
        ),
      ),
      MascotMood.happy,
    );
  });
}

