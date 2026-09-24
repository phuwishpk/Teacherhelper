import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:mobile_scanner/mobile_scanner.dart';

import '../../core/api/api_client.dart';
import '../../core/auth/session.dart';

/// Camera view that looks for a login-card QR (`EVL1.{token}`) and signs the
/// student in with `POST /auth/student/qr`.
class StudentQrScanScreen extends ConsumerStatefulWidget {
  const StudentQrScanScreen({super.key});

  @override
  ConsumerState<StudentQrScanScreen> createState() =>
      _StudentQrScanScreenState();
}

class _StudentQrScanScreenState extends ConsumerState<StudentQrScanScreen> {
  final _controller = MobileScannerController(
    formats: const [BarcodeFormat.qrCode],
    detectionSpeed: DetectionSpeed.noDuplicates,
  );
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  Future<void> _onDetect(BarcodeCapture capture) async {
    if (_busy) return;
    String? payload;
    for (final b in capture.barcodes) {
      final v = b.rawValue;
      if (v != null && v.startsWith(studentCardQrPrefix)) {
        payload = v;
        break;
      }
    }
    if (payload == null) {
      setState(() => _error = 'QR นี้ไม่ใช่บัตรเข้าสู่ระบบของ EduVision');
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
    });
    await _controller.stop();
    try {
      await ref.read(sessionProvider.notifier).signInStudentQr(payload);
      // The router redirects to /student once the session is SignedIn.
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = apiStatusCode(e) == 401 || apiStatusCode(e) == 422
            ? 'บัตรนี้ใช้ไม่ได้แล้ว (อาจถูกออกบัตรใหม่) ขอบัตรใหม่จากครู'
            : apiErrorMessage(e);
        _busy = false;
      });
      await _controller.start();
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      appBar: AppBar(
        title: const Text('สแกนบัตร QR'),
        actions: [
          IconButton(
            tooltip: 'ไฟฉาย',
            icon: const Icon(Icons.flashlight_on_outlined),
            onPressed: () => _controller.toggleTorch(),
          ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: Stack(
              fit: StackFit.expand,
              children: [
                MobileScanner(
                  controller: _controller,
                  onDetect: _onDetect,
                  errorBuilder: (context, error) => Center(
                    child: Padding(
                      padding: const EdgeInsets.all(24),
                      child: Text(
                        'เปิดกล้องไม่ได้: ${error.errorDetails?.message ?? error.errorCode.name}',
                        textAlign: TextAlign.center,
                      ),
                    ),
                  ),
                ),
                Center(
                  child: Container(
                    width: 240,
                    height: 240,
                    decoration: BoxDecoration(
                      border: Border.all(color: Colors.white, width: 3),
                      borderRadius: BorderRadius.circular(16),
                    ),
                  ),
                ),
                if (_busy)
                  const ColoredBox(
                    color: Colors.black54,
                    child: Center(child: CircularProgressIndicator()),
                  ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              children: [
                Text(
                  _error ?? 'ถือบัตรให้ QR อยู่ในกรอบ',
                  textAlign: TextAlign.center,
                  style: _error == null
                      ? theme.textTheme.bodyLarge
                      : TextStyle(color: theme.colorScheme.error),
                ),
                const SizedBox(height: 8),
                TextButton(
                  onPressed: () => context.pop(),
                  child: const Text('ใช้รหัสห้องและ PIN แทน'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
