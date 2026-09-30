<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

use RuntimeException;

/** The API answered differently than the scenario expects, or could not be reached (exit code 1). */
final class UnexpectedOutcome extends RuntimeException {}
