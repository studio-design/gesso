<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Type\ObjectType;
use Studio\Gesso\Rector\GessoSetList;

use function count;
use function in_array;
use function strtoupper;

/**
 * Rewrites osteel/openapi-httpfoundation-testing's `$validator->validate($message,
 * $path, $method)` and its `get()`, `post()`, ... shortcuts by the message's
 * static type: a response becomes `$this->assertOpenApiResponse($response,
 * method: ..., path: ...)`, a request `$this->assertOpenApiRequest($request)`,
 * which finds the operation from the request itself. HttpFoundation messages
 * take Gesso's Symfony trait, PSR-7 messages the PSR-7 one, and the class gets
 * that trait. A wrapping `assertTrue()` is dropped, and
 * `expectException(ValidationException::class)` expects a PHPUnit failure.
 *
 * @internal Applied through {@see GessoSetList::OSTEEL_TO_GESSO}; the class name is not a contract.
 */
final class OsteelValidatorRector extends AssertionMigrationRector
{
    private const array VALIDATORS = ['Osteel\OpenApi\Testing\ValidatorInterface', 'Osteel\OpenApi\Testing\Validator'];
    private const array SHORTCUTS = ['delete', 'get', 'head', 'options', 'patch', 'post', 'put', 'trace'];
    private const string VALIDATION_EXCEPTION = 'Osteel\OpenApi\Testing\Exceptions\ValidationException';

    /** message type => [trait, is a response] */
    private const array MESSAGES = [
        'Symfony\Component\HttpFoundation\Response' => ['Studio\Gesso\Symfony\OpenApiAssertions', true],
        'Symfony\Component\HttpFoundation\Request' => ['Studio\Gesso\Symfony\OpenApiAssertions', false],
        'Psr\Http\Message\ResponseInterface' => ['Studio\Gesso\Psr7\OpenApiAssertions', true],
        'Psr\Http\Message\RequestInterface' => ['Studio\Gesso\Psr7\OpenApiAssertions', false],
    ];

    /**
     * The trait and the Gesso assertion for an osteel validation call, or null.
     *
     * @return null|array{string, MethodCall}
     */
    protected function rewrite(Expr $expr): ?array
    {
        $expr = $this->unwrapAssertTrue($expr);
        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier || $expr->isFirstClassCallable()) {
            return null;
        }
        $name = $expr->name->toLowerString();
        $args = $expr->getArgs();
        if ($name === 'validate' && count($args) === 3) {
            $method = $args[2]->value;
        } elseif (in_array($name, self::SHORTCUTS, true) && count($args) === 2) {
            $method = new String_(strtoupper($name));
        } else {
            return null;
        }
        foreach ($args as $arg) {
            if ($arg->unpack || $arg->name !== null) {
                return null;
            }
        }
        if (!$this->isObjectType($expr->var, new ObjectType(self::VALIDATORS[0])) && !$this->isObjectType($expr->var, new ObjectType(self::VALIDATORS[1]))) {
            return null;
        }
        foreach (self::MESSAGES as $type => [$trait, $isResponse]) {
            if (!$this->isObjectType($args[0]->value, new ObjectType($type))) {
                continue;
            }
            $call = $isResponse
                ? new MethodCall(new Variable('this'), 'assertOpenApiResponse', [
                    new Arg($args[0]->value),
                    new Arg($method, name: new Identifier('method')),
                    new Arg($args[1]->value, name: new Identifier('path')),
                ])
                : new MethodCall(new Variable('this'), 'assertOpenApiRequest', [new Arg($args[0]->value)]);

            return [$trait, $call];
        }

        return null;
    }

    protected function failureException(): string
    {
        return self::VALIDATION_EXCEPTION;
    }

    private function unwrapAssertTrue(Expr $expr): Expr
    {
        if (($expr instanceof MethodCall && $expr->var instanceof Variable && $expr->var->name === 'this' ||
                $expr instanceof StaticCall && $expr->class instanceof Name && in_array($expr->class->toLowerString(), ['self', 'static'], true)) &&
            $this->isName($expr->name, 'assertTrue') && !$expr->isFirstClassCallable() && $expr->getArgs() !== []) {
            return $expr->getArgs()[0]->value;
        }

        return $expr;
    }
}
