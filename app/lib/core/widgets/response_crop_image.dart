import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../api/api_client.dart';
import '../api/response_crops.dart';

/// The answer crop of one response, loaded through the authenticated API.
/// Tapping opens it full screen with pinch-zoom.
class ResponseCropImage extends ConsumerWidget {
  const ResponseCropImage({
    super.key,
    required this.responseId,
    this.finalPart = false,
    this.height = 180,
  });

  final int responseId;

  /// The final-answer box of a show_work question (`?part=final`).
  final bool finalPart;
  final double height;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final key = (responseId: responseId, finalPart: finalPart);
    final crop = ref.watch(responseCropProvider(key));
    final theme = Theme.of(context);
    Widget box(Widget child) => Container(
      height: height,
      width: double.infinity,
      decoration: BoxDecoration(
        color: theme.colorScheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      alignment: Alignment.center,
      child: child,
    );

    return crop.when(
      loading: () => box(const CircularProgressIndicator()),
      error: (e, _) {
        final message = switch (apiStatusCode(e)) {
          410 => 'ภาพนี้ถูกลบตามนโยบายการเก็บข้อมูลแล้ว',
          404 => 'ไม่มีภาพของข้อนี้',
          403 => 'ไม่มีสิทธิ์ดูภาพนี้',
          _ => 'โหลดภาพไม่ได้',
        };
        final retryable = apiStatusCode(e) == null || apiStatusCode(e)! >= 500;
        return box(
          Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Icon(
                Icons.image_not_supported_outlined,
                color: theme.colorScheme.onSurfaceVariant,
              ),
              const SizedBox(height: 4),
              Text(message, textAlign: TextAlign.center),
              if (retryable)
                TextButton(
                  onPressed: () => ref.invalidate(responseCropProvider(key)),
                  child: const Text('ลองใหม่'),
                ),
            ],
          ),
        );
      },
      data: (bytes) => Semantics(
        label: finalPart ? 'ภาพกรอบคำตอบสุดท้าย' : 'ภาพคำตอบ',
        image: true,
        child: InkWell(
          onTap: () => Navigator.of(context).push(
            MaterialPageRoute<void>(
              fullscreenDialog: true,
              builder: (_) => Scaffold(
                appBar: AppBar(title: const Text('ภาพคำตอบ')),
                body: InteractiveViewer(
                  maxScale: 6,
                  child: Center(child: Image.memory(bytes)),
                ),
              ),
            ),
          ),
          child: box(
            Image.memory(
              bytes,
              fit: BoxFit.contain,
              gaplessPlayback: true,
              errorBuilder: (_, _, _) => const Text('เปิดไฟล์ภาพไม่ได้'),
            ),
          ),
        ),
      ),
    );
  }
}
