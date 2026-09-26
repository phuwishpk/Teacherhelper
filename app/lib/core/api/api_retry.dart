import 'package:flutter_riverpod/flutter_riverpod.dart';

import 'api_client.dart';

/// Riverpod retry policy for providers backed by the API: a 4xx answer
/// (403, 404, 422…) does not change by asking again, so only network
/// errors and 5xx are retried, with the default backoff, at most 3 times.
Duration? apiRetry(int retryCount, Object error) {
  final status = apiStatusCode(error);
  if (status != null && status < 500) return null;
  return ProviderContainer.defaultRetry(retryCount, error, maxRetries: 3);
}
