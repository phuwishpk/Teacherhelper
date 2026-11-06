<?php

namespace App\Domain\Gemini;

use RuntimeException;

/** The answer crop of a response is not on the private disk: it cannot be sent to Gemini. */
class CropMissing extends RuntimeException {}
