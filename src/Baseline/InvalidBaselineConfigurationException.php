<?php

declare(strict_types=1);

namespace Studio\Gesso\Baseline;

use RuntimeException;
use Studio\Gesso\PHPUnit\OpenApiCoverageExtension;

/**
 * Thrown by {@see OpenApiCoverageExtension} when `OPENAPI_BASELINE_GENERATE`
 * is set but no `baseline_file` parameter names the output path. A
 * generation run that silently produced nothing would look like "zero
 * violations", so the misconfiguration must abort bootstrap; bootstrap()
 * catches this and translates it to `exit(1)` like the other configuration
 * exceptions.
 *
 * @internal PHPUnit extension configuration boundary. Do not catch from user code.
 */
final class InvalidBaselineConfigurationException extends RuntimeException {}
