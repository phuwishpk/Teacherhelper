import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../core/api/api_client.dart';
import '../../core/router/app_router.dart';

/// The Gemini key is missing or refused (DESIGN §10.1): the snack bar
/// offers the key entry on the settings screen.
bool isAiKeyProblem(Object error) {
  final code = apiErrorCode(error);
  return code == 'ai_key_missing' || code == 'ai_key_invalid';
}

/// Thai text of an error of an AI action; [messages] replaces the text of
/// specific `code`s (e.g. `regrade_in_progress`).
String aiErrorMessage(Object error, {Map<String, String> messages = const {}}) {
  final code = apiErrorCode(error);
  if (code != null && messages[code] != null) return messages[code]!;
  return switch (code) {
    'ai_key_missing' =>
      'ยังไม่ได้ใส่ Gemini API key ใส่ได้ที่หน้าตั้งค่า แล้วลองอีกครั้ง',
    'ai_key_invalid' =>
      'Gemini API key ใช้ไม่ได้ ตรวจ key ที่หน้าตั้งค่าอีกครั้ง',
    'ai_unavailable' => 'AI ไม่ว่างชั่วคราว ลองใหม่อีกครั้งในอีกสักครู่',
    _ when apiStatusCode(error) == 429 => 'กดบ่อยเกินไป รอสักครู่แล้วลองใหม่',
    _ => apiErrorMessage(error),
  };
}

/// Shows [aiErrorMessage] in a snack bar, with "ไปใส่ key" (the settings
/// screen's Gemini key entry) when the key is the problem.
void showAiError(
  BuildContext context,
  Object error, {
  Map<String, String> messages = const {},
}) {
  final router = GoRouter.maybeOf(context);
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(
      SnackBar(
        content: Text(aiErrorMessage(error, messages: messages)),
        action: isAiKeyProblem(error) && router != null
            ? SnackBarAction(
                key: const ValueKey('open_ai_key_settings'),
                label: 'ไปใส่ key',
                onPressed: () => router.push(AppRoutes.settings),
              )
            : null,
      ),
    );
}
