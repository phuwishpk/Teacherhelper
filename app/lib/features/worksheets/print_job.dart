/// A queued PDF render (DESIGN §8.3 worksheet_prints, and the login-card
/// print of §9.2). Status goes queued -> rendering -> ready | failed.
class PrintJob {
  const PrintJob({
    required this.id,
    required this.status,
    this.downloadUrl,
    this.pollUrl,
    this.error,
    this.kind,
    this.versionNo,
    this.layoutVersion,
  });

  final int id;
  final String status;

  /// Where to fetch the PDF once [isReady]. Absolute or `/api/v1/...`.
  final String? downloadUrl;

  /// Optional server-provided status URL; overrides the default poll path.
  final String? pollUrl;
  final String? error;

  /// `worksheet`, `exam_booklet`, `answer_sheet` or `key_sheet` (DESIGN
  /// §22.6); null on a login-card print.
  final String? kind;

  /// The version of an exam booklet (1 = ชุด ก); null for the others.
  final int? versionNo;

  /// The layout of the sheets; null for a booklet (never scanned).
  final int? layoutVersion;

  bool get isReady => status == 'ready' && downloadUrl != null;
  bool get isFailed => status == 'failed';
  bool get isPending => !isReady && !isFailed;

  factory PrintJob.fromJson(Map<String, dynamic> json) => PrintJob(
    id: (json['id'] as num).toInt(),
    status: json['status'] as String? ?? 'queued',
    downloadUrl:
        (json['download_url'] ?? json['file_url'] ?? json['url']) as String?,
    pollUrl: (json['status_url'] ?? json['poll_url']) as String?,
    error: json['error'] as String?,
    kind: json['kind'] as String?,
    versionNo: (json['version_no'] as num?)?.toInt(),
    layoutVersion: (json['layout_version'] as num?)?.toInt(),
  );
}

String printStatusLabel(String status) => switch (status) {
  'queued' => 'อยู่ในคิว',
  'rendering' => 'กำลังสร้าง PDF',
  'ready' => 'พร้อมดาวน์โหลด',
  'failed' => 'สร้างไม่สำเร็จ',
  _ => status,
};
