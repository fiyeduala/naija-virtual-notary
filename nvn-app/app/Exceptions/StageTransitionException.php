<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A stage change the server refused.
 *
 * Thrown rather than returned false, because every caller — a Filament action,
 * a bulk action, a command — must stop rather than carry on believing the
 * application moved. The message is written for the person who pressed the
 * button, so it is safe to show.
 */
class StageTransitionException extends RuntimeException
{
}
