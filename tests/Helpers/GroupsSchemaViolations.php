<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use Studio\Gesso\Validation\Support\SchemaValidatorRunner;

trait GroupsSchemaViolations
{
    /**
     * @return array<string, list<string>> displayPath => messages, the map the
     *                                     removed SchemaValidatorRunner::validate() returned
     */
    private static function groupedErrors(SchemaValidatorRunner $runner, mixed $schema, mixed $data): array
    {
        $grouped = [];
        foreach ($runner->validateStructured($schema, $data) as $violation) {
            $grouped[$violation->displayPath()][] = $violation->message;
        }

        return $grouped;
    }
}
