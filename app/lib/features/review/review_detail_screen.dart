import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'response_review_pane.dart';
import 'review_labels.dart';
import 'review_models.dart';
import 'review_providers.dart';

/// Phone layout of the review detail: one response per page with prev/next
/// inside the tab it was opened from.
class ReviewDetailScreen extends ConsumerStatefulWidget {
  const ReviewDetailScreen({
    super.key,
    required this.assignmentId,
    required this.responseId,
    this.band,
  });

  final int assignmentId;
  final int responseId;

  /// Tab the teacher came from; null = the whole queue.
  final PriorityBand? band;

  @override
  ConsumerState<ReviewDetailScreen> createState() => _ReviewDetailScreenState();
}

class _ReviewDetailScreenState extends ConsumerState<ReviewDetailScreen> {
  late int _current = widget.responseId;

  List<ReviewItem> _rows(ReviewQueue? q) {
    if (q == null) return const [];
    if (widget.band case final band?) return q.tab(band);
    return [...q.items]..sort((a, b) => a.compareQueueOrder(b));
  }

  @override
  Widget build(BuildContext context) {
    final q = ref.watch(reviewQueueProvider(widget.assignmentId)).value;
    final rows = _rows(q);
    final index = rows.indexWhere((r) => r.id == _current);
    void go(int i) => setState(() => _current = rows[i].id);

    return Scaffold(
      appBar: AppBar(
        title: Text(
          index < 0
              ? 'ตรวจทาน'
              : '${widget.band?.label ?? 'ตรวจทาน'} ${index + 1}/${rows.length}',
        ),
      ),
      body: ResponseReviewPane(
        key: ValueKey(_current),
        responseId: _current,
        onPrev: index > 0 ? () => go(index - 1) : null,
        onNext: index >= 0 && index < rows.length - 1
            ? () => go(index + 1)
            : null,
        onSaved: (advance) {
          ref.invalidate(reviewQueueProvider(widget.assignmentId));
          if (advance && index >= 0 && index < rows.length - 1) {
            go(index + 1);
          }
        },
      ),
    );
  }
}
