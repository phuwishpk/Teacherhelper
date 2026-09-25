<?php

namespace App\Domain\Notifications\Fcm;

use RuntimeException;

/**
 * FIREBASE_CREDENTIALS does not point at a usable service-account JSON file.
 * The message names the problem, never the file's contents.
 */
final class FirebaseCredentialsInvalid extends RuntimeException {}
