<?php

namespace App\Enums;

/**
 * What storing a fetched USD/LBP observation did. Observations are
 * immutable, so a repeat is a no-op and a conflicting value is only logged.
 */
enum ObservationOutcome: string
{
    case Stored = 'stored';
    case AlreadyStored = 'already_stored';
    case Conflicting = 'conflicting';
}
