<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Helpers;

use Studio\Gesso\Coverage\SdkExerciseCoverageReportBuilder;

/**
 * @internal Test fixture shared by coverage renderer suites.
 *
 * @phpstan-import-type SdkExerciseCoverageResult from SdkExerciseCoverageReportBuilder
 */
final class SdkExerciseCoverageResultFixture
{
    /** @return SdkExerciseCoverageResult */
    public static function result(): array
    {
        return [
            'responses' => [
                [
                    'endpoint' => 'GET /v1/sdk-pets',
                    'method' => 'GET',
                    'path' => '/v1/sdk-pets',
                    'operationId' => 'listSdkPets',
                    'statusKey' => '200',
                    'contentTypeKey' => 'application/json',
                    'exercised' => true,
                    'hits' => 2,
                ],
                [
                    'endpoint' => 'GET /v1/sdk-pets',
                    'method' => 'GET',
                    'path' => '/v1/sdk-pets',
                    'operationId' => 'listSdkPets',
                    'statusKey' => '422',
                    'contentTypeKey' => 'application/problem+json',
                    'exercised' => false,
                    'hits' => 0,
                ],
            ],
            'responseTotal' => 2,
            'responseExercised' => 1,
            'responseUnexercised' => 1,
            'unexpectedObservations' => [[
                'endpoint' => 'GET /v1/orphan',
                'statusKey' => '418',
                'contentTypeKey' => 'application/json',
                'hits' => 3,
            ]],
        ];
    }
}
