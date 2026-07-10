<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Psr7;

use Studio\OpenApiContractTesting\OpenApiValidationResult;

use function array_map;
use function array_merge;
use function implode;

/**
 * Result of validating a PSR-7 request and response as one exchange.
 */
final readonly class OpenApiPsr7ValidationResult
{
    public function __construct(
        private OpenApiValidationResult $requestResult,
        private OpenApiValidationResult $responseResult,
    ) {}

    public function requestResult(): OpenApiValidationResult
    {
        return $this->requestResult;
    }

    public function responseResult(): OpenApiValidationResult
    {
        return $this->responseResult;
    }

    public function isValid(): bool
    {
        return $this->requestResult->isValid() && $this->responseResult->isValid();
    }

    /** @return string[] */
    public function errors(): array
    {
        return array_merge(
            array_map(static fn(string $error): string => '[request] ' . $error, $this->requestResult->errors()),
            array_map(static fn(string $error): string => '[response] ' . $error, $this->responseResult->errors()),
        );
    }

    public function errorMessage(): string
    {
        return implode("\n", $this->errors());
    }
}
