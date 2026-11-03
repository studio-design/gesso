<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Studio\Gesso\Rector\GessoSetList;

use function array_key_exists;
use function count;
use function in_array;

/**
 * Renames the v2 assertion methods of Gesso's Laravel, Symfony, and PSR-7
 * traits to assertOpenApiResponse(), assertOpenApiRequest(), and
 * assertOpenApiExchange(), and puts the response first where v2 took the
 * request first.
 *
 * Laravel and Symfony share the name assertResponseMatchesOpenApiSchema().
 * The Laravel form takes one argument, a null or HttpMethod second, or the
 * named `method:` / `path:`; anything else is the Symfony form.
 *
 * @internal Applied through {@see GessoSetList::V3}; the class name is not a contract.
 */
final class RenamedAssertionRector extends AbstractRector
{
    /** v2 name => [v3 name, v2 parameters, v3 parameters] */
    private const array RENAMES = [
        'assertRequestMatchesOpenApiSchema' => ['assertOpenApiRequest', null, null],
        'assertClientMatchesOpenApiSchema' => ['assertOpenApiExchange', null, null],
        'assertPsr7RequestMatchesOpenApiSchema' => ['assertOpenApiRequest', null, null],
        'assertPsr7ExchangeMatchesOpenApiSchema' => ['assertOpenApiExchange', null, null],
        'assertPsr7ResponseMatchesOpenApiSchema' => [
            'assertOpenApiResponse',
            ['request', 'response'],
            ['response', 'request', 'method', 'path'],
        ],
        'assertPsr7ResponseForOperationMatchesOpenApiSchema' => [
            'assertOpenApiResponse',
            ['method', 'path', 'response'],
            ['response', 'request', 'method', 'path'],
        ],
    ];

    private const array SYMFONY_RESPONSE = [
        'assertOpenApiResponse',
        ['request', 'response', 'extraSkipResponseCodes'],
        ['response', 'request', 'method', 'path', 'extraSkipResponseCodes'],
    ];

    public function getNodeTypes(): array
    {
        return [MethodCall::class, StaticCall::class];
    }

    /**
     * @param MethodCall|StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$node->name instanceof Identifier || !$this->isOwnCall($node) || $node->isFirstClassCallable()) {
            return null;
        }
        $name = $node->name->toString();
        if ($name === 'assertResponseMatchesOpenApiSchema') {
            if ($this->isLaravelForm($node->getArgs())) {
                $node->name = new Identifier('assertOpenApiResponse');

                return $node;
            }
            [$newName, $from, $to] = self::SYMFONY_RESPONSE;
        } elseif (isset(self::RENAMES[$name])) {
            [$newName, $from, $to] = self::RENAMES[$name];
        } else {
            return null;
        }

        if ($from !== null && $to !== null) {
            $args = $this->reorder($node->getArgs(), $from, $to);
            if ($args === null) {
                return null;
            }
            $node->args = $args;
        }
        $node->name = new Identifier($newName);

        return $node;
    }

    private function isOwnCall(MethodCall|StaticCall $node): bool
    {
        if ($node instanceof MethodCall) {
            return $node->var instanceof Variable && $node->var->name === 'this';
        }

        return $node->class instanceof Name && in_array($node->class->toLowerString(), ['self', 'static'], true);
    }

    /** @param list<Arg> $args */
    private function isLaravelForm(array $args): bool
    {
        foreach ($args as $arg) {
            if ($arg->name !== null && $arg->name->toString() !== 'response') {
                return in_array($arg->name->toString(), ['method', 'path'], true);
            }
        }
        if (count($args) < 2) {
            return true;
        }
        $second = $args[1]->value;

        return $second instanceof ConstFetch && $this->isName($second, 'null') ||
            $second instanceof ClassConstFetch && $this->isName($second->class, 'Studio\Gesso\HttpMethod') ||
            $this->isObjectType($second, new ObjectType('Studio\Gesso\HttpMethod'));
    }

    /**
     * The arguments in v3 order: positional while no parameter is skipped,
     * named after that. Null when an argument is unpacked or unknown.
     *
     * @param list<Arg> $args
     * @param list<string> $from
     * @param list<string> $to
     *
     * @return null|list<Arg>
     */
    private function reorder(array $args, array $from, array $to): ?array
    {
        $byName = [];
        foreach ($args as $index => $arg) {
            if ($arg->unpack) {
                return null;
            }
            $name = $arg->name?->toString() ?? $from[$index] ?? null;
            if ($name === 'requestPath') {
                $name = 'path';
            }
            if ($name === null || !in_array($name, $to, true)) {
                return null;
            }
            $byName[$name] = $arg;
        }

        $reordered = [];
        $positional = true;
        foreach ($to as $name) {
            if (!array_key_exists($name, $byName)) {
                $positional = false;

                continue;
            }
            $arg = $byName[$name];
            $arg->name = $positional ? null : new Identifier($name);
            $reordered[] = $arg;
        }

        return $reordered;
    }
}
