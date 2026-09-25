/// A published submission as listed by `GET /student/results` (DESIGN §9.7).
class StudentResult {
  const StudentResult({
    required this.submissionId,
    required this.title,
    this.subjectName,
    this.totalScore,
    this.maxScore,
    this.publishedAt,
  });

  final int submissionId;
  final String title;
  final String? subjectName;
  final double? totalScore;
  final double? maxScore;
  final DateTime? publishedAt;

  factory StudentResult.fromJson(Map<String, dynamic> json) {
    final assignment = json['assignment'] as Map<String, dynamic>?;
    final subject = assignment?['subject'] as Map<String, dynamic>?;
    final published = json['published_at'] as String?;
    return StudentResult(
      submissionId: ((json['submission_id'] ?? json['id']) as num).toInt(),
      title: (json['title'] ?? assignment?['title'] ?? 'การบ้าน') as String,
      subjectName: (json['subject_name'] ?? subject?['name']) as String?,
      totalScore: (json['total_score'] as num?)?.toDouble(),
      maxScore: (json['max_score'] as num?)?.toDouble(),
      publishedAt: published == null ? null : DateTime.tryParse(published),
    );
  }
}
