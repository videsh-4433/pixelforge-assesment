<?php

namespace App\Exceptions;

use RuntimeException;

/** The user hit a per-user usage cap (too many in flight, or the daily limit). */
class GenerationLimitException extends RuntimeException {}
