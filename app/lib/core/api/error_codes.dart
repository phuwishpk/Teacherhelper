import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'api_client.dart';

/// The traceable code of a failed request (DESIGN §36): the server's
/// `ai_error_code` with its `ai_call_id` (the admin finds the call by it),
/// or a code of the connection when the server was not reached.
class ErrorCode {
  const ErrorCode(this.code, {this.callId});

  /// No connection to the server (the device is offline or it is down).
  static const offline = 'NET-OFFLINE';

  /// The server took too long to answer.
  static const timeout = 'NET-TIMEOUT';

  final String code;
  final int? callId;

  /// "AI-QUOTA · #48213" or "NET-OFFLINE".
  String get label => callId == null ? code : '$code · #$callId';
}

/// The code of [error], or null when there is none worth showing.
ErrorCode? errorCodeOf(Object error) {
  final body = apiErrorBody(error);
  final ai = body?['ai_error_code'];
  if (ai is String && ai.isNotEmpty) {
    final id = body?['ai_call_id'];
    return ErrorCode(ai, callId: id is num ? id.toInt() : null);
  }
  if (error is DioException) {
    return switch (error.type) {
      DioExceptionType.connectionTimeout ||
      DioExceptionType.sendTimeout ||
      DioExceptionType.receiveTimeout => const ErrorCode(ErrorCode.timeout),
      DioExceptionType.connectionError => const ErrorCode(ErrorCode.offline),
      // No dart:io on the web: recognise a socket failure by its text.
      DioExceptionType.unknown
          when '${error.error}'.contains('SocketException') ||
              '${error.error}'.contains('Failed host lookup') =>
        const ErrorCode(ErrorCode.offline),
      _ => null,
    };
  }
  return null;
}

/// "รหัส AI-QUOTA · #48213" under an error, with a copy button, so the
/// teacher can send it to the admin.
class ErrorCodeLine extends StatelessWidget {
  const ErrorCodeLine(this.code, {super.key});

  final ErrorCode code;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final style = theme.textTheme.bodySmall?.copyWith(
      color: theme.colorScheme.onSurfaceVariant,
    );
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(
          child: SelectableText(
            'รหัส ${code.label}',
            key: const ValueKey('error_code_line'),
            style: style,
          ),
        ),
        IconButton(
          tooltip: 'คัดลอกรหัส',
          visualDensity: VisualDensity.compact,
          iconSize: 16,
          icon: const Icon(Icons.copy),
          onPressed: () => Clipboard.setData(ClipboardData(text: code.label)),
        ),
      ],
    );
  }
}
