<?php

declare(strict_types=1);

namespace FleetFuel\PosSimulator;

use RuntimeException;

/** Missing or malformed environment configuration (exit code 2). */
final class ConfigurationError extends RuntimeException {}
