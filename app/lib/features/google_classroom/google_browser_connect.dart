import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';

import 'google_models.dart';
import 'google_providers.dart';
import 'google_repository.dart';

/// Opens [url] outside the app; false when nothing could open it.
typedef ExternalUrlOpener = Future<bool> Function(Uri url);

/// The system browser on Android (Google does not allow its consent page
/// inside a WebView), a new tab on the web.
Future<bool> openExternalUrl(Uri url) async {
  try {
    return await launchUrl(
      url,
      mode: kIsWeb
          ? LaunchMode.platformDefault
          : LaunchMode.externalApplication,
      webOnlyWindowName: '_blank',
    );
  } catch (e) {
    debugPrint('launchUrl failed: $e');
    return false;
  }
}

/// A seam so widget tests do not open a browser.
final externalUrlOpenerProvider = Provider<ExternalUrlOpener>(
  (ref) => openExternalUrl,
);

/// The browser connect flow (no native Google Sign-In: the web, or a build
/// without GOOGLE_SERVER_CLIENT_ID): `POST /google/oauth/url`, open Google's
/// consent page outside the app, then wait in a dialog while the server
/// finishes the connection. Returns the new status, or null when the
/// teacher closed the dialog. Throws the DioException of the url request.
Future<GoogleStatus?> connectGoogleInBrowser(
  BuildContext context,
  WidgetRef ref,
) async {
  final url = await ref.read(googleStatusProvider.notifier).browserConnectUrl();
  final opened = await ref.read(externalUrlOpenerProvider)(url);
  if (!context.mounted) return null;
  return showDialog<GoogleStatus>(
    context: context,
    barrierDismissible: false,
    builder: (_) => GoogleBrowserConnectDialog(opened: opened),
  );
}

/// "ทำขั้นตอนในหน้าต่าง Google ให้เสร็จ แล้วกดตรวจสอบ": polls
/// `GET /google/status` every [pollInterval] for up to [timeout] and closes
/// with the status as soon as the teacher is connected. The teacher can
/// check at once, open a fresh Google page, or cancel.
class GoogleBrowserConnectDialog extends ConsumerStatefulWidget {
  const GoogleBrowserConnectDialog({
    super.key,
    this.opened = true,
    this.pollInterval = const Duration(seconds: 3),
    this.timeout = const Duration(minutes: 3),
  });

  /// Whether the Google page could be opened (else the dialog says so and
  /// offers to open it again).
  final bool opened;
  final Duration pollInterval;
  final Duration timeout;

  @override
  ConsumerState<GoogleBrowserConnectDialog> createState() =>
      _GoogleBrowserConnectDialogState();
}

class _GoogleBrowserConnectDialogState
    extends ConsumerState<GoogleBrowserConnectDialog> {
  Timer? _timer;
  int _polls = 0;
  bool _polling = false;
  bool _checking = false;
  bool _opening = false;
  bool _done = false;
  String? _note;

  @override
  void initState() {
    super.initState();
    if (!widget.opened) {
      _note =
          'เปิดหน้าต่าง Google ไม่ได้ กด "เปิดหน้า Google อีกครั้ง" '
          '(ถ้าเบราว์เซอร์บล็อกป๊อปอัป ให้อนุญาตก่อน)';
    }
    _startPolling();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  void _startPolling() {
    _timer?.cancel();
    _polls = 0;
    _polling = true;
    _timer = Timer.periodic(widget.pollInterval, (_) => _poll());
  }

  Future<void> _poll() async {
    _polls++;
    final last = widget.pollInterval * _polls >= widget.timeout;
    if (last) {
      _timer?.cancel();
      _polling = false;
    }
    await _check(manual: false);
    if (last && mounted && !_done) {
      setState(
        () => _note =
            'ยังไม่พบการเชื่อมบัญชี ถ้าทำในหน้าต่าง Google เสร็จแล้ว '
            'กด "ตรวจสอบการเชื่อม" หรือเปิดหน้า Google อีกครั้ง',
      );
    }
  }

  Future<void> _check({required bool manual}) async {
    if (_checking || _done) return;
    _checking = true;
    if (manual) setState(() {});
    try {
      final status = await ref
          .read(googleStatusProvider.notifier)
          .checkConnection();
      if (!mounted) return;
      if (status.ready) {
        _done = true;
        _timer?.cancel();
        Navigator.of(context).pop(status);
        return;
      }
      if (manual) {
        setState(
          () => _note =
              'ยังไม่พบการเชื่อม ทำขั้นตอนในหน้าต่าง Google ให้เสร็จก่อน '
              'แล้วกดตรวจสอบอีกครั้ง',
        );
        if (!_polling) _startPolling();
      }
    } catch (e) {
      if (manual && mounted) setState(() => _note = googleErrorMessage(e));
    } finally {
      _checking = false;
      if (manual && mounted && !_done) setState(() {});
    }
  }

  Future<void> _reopen() async {
    setState(() => _opening = true);
    try {
      // A fresh page: the previous state may be spent or past 10 minutes.
      final url = await ref
          .read(googleStatusProvider.notifier)
          .browserConnectUrl();
      final opened = await ref.read(externalUrlOpenerProvider)(url);
      if (!mounted) return;
      setState(
        () => _note = opened
            ? null
            : 'เปิดหน้าต่าง Google ไม่ได้ (ถ้าเบราว์เซอร์บล็อกป๊อปอัป ให้อนุญาตก่อน)',
      );
      _startPolling();
    } catch (e) {
      if (mounted) setState(() => _note = googleErrorMessage(e));
    } finally {
      if (mounted) setState(() => _opening = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AlertDialog(
      key: const ValueKey('google_browser_connect_dialog'),
      title: const Text('เชื่อม Google Classroom'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('ทำขั้นตอนในหน้าต่าง Google ให้เสร็จ แล้วกดตรวจสอบ'),
            const SizedBox(height: 8),
            Text(
              'เลือกบัญชี Google ของครูและติ๊กอนุญาตทุกสิทธิ์ '
              'แอปตรวจสอบการเชื่อมให้เองทุก ${widget.pollInterval.inSeconds} วินาที',
              style: theme.textTheme.bodySmall?.copyWith(
                color: theme.colorScheme.onSurfaceVariant,
              ),
            ),
            if (_polling || _checking) ...[
              const SizedBox(height: 12),
              const LinearProgressIndicator(),
            ],
            if (_note case final note?) ...[
              const SizedBox(height: 12),
              Text(
                note,
                key: const ValueKey('google_browser_note'),
                style: TextStyle(color: theme.colorScheme.error),
              ),
            ],
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: () {
            _timer?.cancel();
            Navigator.of(context).pop();
          },
          child: const Text('ยกเลิก'),
        ),
        TextButton(
          key: const ValueKey('google_browser_reopen'),
          onPressed: _opening ? null : _reopen,
          child: const Text('เปิดหน้า Google อีกครั้ง'),
        ),
        FilledButton(
          key: const ValueKey('google_browser_check'),
          onPressed: _checking ? null : () => _check(manual: true),
          child: const Text('ตรวจสอบการเชื่อม'),
        ),
      ],
    );
  }
}
