<?php

declare(strict_types=1);

// The parts of osteel/openapi-httpfoundation-testing the fixtures need,
// required once per test by the Rector test case.

namespace Osteel\OpenApi\Testing;

use LogicException;

use function interface_exists;

if (!interface_exists(ValidatorInterface::class, false)) {
    interface ValidatorInterface
    {
        public function validate(object $message, string $path, string $method): bool;

        public function get(object $message, string $path): bool;
    }

    final class ValidatorBuilder
    {
        public static function fromYamlFile(string $definition): self
        {
            return new self();
        }

        public function getValidator(): ValidatorInterface
        {
            throw new LogicException('stub');
        }
    }
}
