<?php

declare(strict_types=1);

namespace Studio\Gesso\Config;

use RuntimeException;

/**
 * Thrown by {@see GessoConfig} when `gesso.php` cannot be read as
 * configuration: it does not return an array, it declares a section or key
 * outside the documented set, or a declared key carries a value of the wrong
 * shape.
 *
 * Every one of those is a typo in the one file that now decides how the whole
 * suite is configured, so all of them are FATAL rather than warn-and-default.
 * Silently ignoring `validaton.max_errors` would leave a suite reporting 20
 * errors while its author believed they had raised the limit — the same class
 * of silent divergence issue #501 exists to remove.
 *
 * The message always names the offending key path so the fix does not require
 * bisecting the file.
 *
 * @internal Configuration boundary. Do not catch from user code.
 */
final class InvalidGessoConfigurationException extends RuntimeException {}
