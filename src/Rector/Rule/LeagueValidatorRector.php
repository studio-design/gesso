<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PHPStan\Type\ObjectType;
use Studio\Gesso\Rector\GessoSetList;

use function count;

/**
 * Rewrites league/openapi-psr7-validator's validation statements for Gesso's
 * PSR-7 trait: `ServerRequestValidator` / `RequestValidator::validate($request)`
 * and `RoutedServerRequestValidator::validate($address, $request)` become
 * `$this->assertOpenApiRequest($request)`, which finds the operation from the
 * request itself; `ResponseValidator::validate(new OperationAddress($path,
 * $method), $response)` becomes `$this->assertOpenApiResponse($response,
 * method: $method, path: $path)`; `expectException(ValidationFailed::class)`
 * expects a PHPUnit failure.
 *
 * @internal Applied through {@see GessoSetList::LEAGUE_TO_GESSO}; the class name is not a contract.
 */
final class LeagueValidatorRector extends AssertionMigrationRector
{
    private const string PREFIX = 'League\OpenAPIValidation\PSR7\\';
    private const string TRAIT = 'Studio\Gesso\Psr7\OpenApiAssertions';

    protected function rewrite(Expr $expr): ?array
    {
        if (!$expr instanceof MethodCall || !$this->isName($expr->name, 'validate') || $expr->isFirstClassCallable()) {
            return null;
        }
        $args = $expr->getArgs();
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                return null;
            }
        }
        if (count($args) === 1 && ($this->isValidator($expr, 'ServerRequestValidator') || $this->isValidator($expr, 'RequestValidator'))) {
            return [self::TRAIT, $this->assertion('assertOpenApiRequest', [new Arg($args[0]->value)])];
        }
        if (count($args) !== 2) {
            return null;
        }
        if ($this->isValidator($expr, 'RoutedServerRequestValidator')) {
            return [self::TRAIT, $this->assertion('assertOpenApiRequest', [new Arg($args[1]->value)])];
        }
        $address = $args[0]->value;
        if (!$this->isValidator($expr, 'ResponseValidator') || !$address instanceof New_ || !$this->isName($address->class, self::PREFIX . 'OperationAddress')) {
            return null;
        }
        $operation = $address->getArgs();
        if (count($operation) !== 2 || $operation[0]->name !== null || $operation[1]->name !== null) {
            return null;
        }

        return [self::TRAIT, $this->assertion('assertOpenApiResponse', [
            new Arg($args[1]->value),
            new Arg($operation[1]->value, name: new Identifier('method')),
            new Arg($operation[0]->value, name: new Identifier('path')),
        ])];
    }

    protected function failureException(): string
    {
        return self::PREFIX . 'Exception\ValidationFailed';
    }

    private function isValidator(MethodCall $call, string $class): bool
    {
        return $this->isObjectType($call->var, new ObjectType(self::PREFIX . $class));
    }

    /** @param list<Arg> $args */
    private function assertion(string $name, array $args): MethodCall
    {
        return new MethodCall(new Variable('this'), $name, $args);
    }
}
