<?php

namespace App\Domain\Notifications\Fcm;

use RuntimeException;

/**
 * Google refused the service account or could not be reached for an access
 * token. The message holds the HTTP status and Google's error code only.
 */
final class FcmAuthFailed extends RuntimeException {}
