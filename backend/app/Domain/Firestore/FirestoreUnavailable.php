<?php

namespace App\Domain\Firestore;

use RuntimeException;

/** Firestore could not be reached or refused the write (DESIGN §38.4). */
final class FirestoreUnavailable extends RuntimeException {}
