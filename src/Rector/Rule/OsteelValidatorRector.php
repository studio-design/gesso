<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Studio\Gesso\Rector\GessoSetList;

use function array_unique;
use function array_unshift;
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
 * A class is left alone when its messages need both traits, or when it
 * already has an assertOpenApiResponse() from elsewhere, such as the Laravel
 * trait.
 *
 * @internal Applied through {@see GessoSetList::OSTEEL_TO_GESSO}; the class name is not a contract.
 */
final class OsteelValidatorRector extends AbstractRector
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

    public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        /** @var list<array{Expression, Expr}> $rewrites */
        $rewrites = [];
        $traits = [];
        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$rewrites, &$traits): ?int {
            if ($inner instanceof Class_) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
            if (!$inner instanceof Expression) {
                return null;
            }
            $expectation = $this->expectedFailure($inner->expr);
            if ($expectation !== null) {
                $rewrites[] = [$inner, $expectation];

                return null;
            }
            $rewrite = $this->rewrite($this->unwrapAssertTrue($inner->expr));
            if ($rewrite !== null) {
                $rewrites[] = [$inner, $rewrite[1]];
                $traits[] = $rewrite[0];
            }

            return null;
        });

        $traits = array_unique($traits);
        if ($rewrites === [] || count($traits) > 1 || $traits !== [] && !$this->canUse($node, $traits[0])) {
            return null;
        }
        foreach ($rewrites as [$statement, $expr]) {
            $statement->expr = $expr;
        }
        if ($traits !== [] && !$this->hasTrait($node, $traits[0])) {
            array_unshift($node->stmts, new TraitUse([new FullyQualified($traits[0])]));
        }

        return $node;
    }

    /**
     * The trait and the Gesso assertion for an osteel validation call, or null.
     *
     * @return null|array{string, MethodCall}
     */
    private function rewrite(Expr $expr): ?array
    {
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

    private function unwrapAssertTrue(Expr $expr): Expr
    {
        if (($expr instanceof MethodCall && $expr->var instanceof Variable && $expr->var->name === 'this' ||
                $expr instanceof StaticCall && $expr->class instanceof Name && in_array($expr->class->toLowerString(), ['self', 'static'], true)) &&
            $this->isName($expr->name, 'assertTrue') && !$expr->isFirstClassCallable() && $expr->getArgs() !== []) {
            return $expr->getArgs()[0]->value;
        }

        return $expr;
    }

    /** `expectException(ValidationException::class)` expecting a PHPUnit failure instead, or null. */
    private function expectedFailure(Expr $expr): ?Expr
    {
        if (!$expr instanceof MethodCall || !$this->isName($expr->name, 'expectException') || $expr->isFirstClassCallable()) {
            return null;
        }
        $class = $expr->getArgs()[0]->value ?? null;
        if (!$class instanceof ClassConstFetch || !$this->isName($class->class, self::VALIDATION_EXCEPTION) || !$this->isName($class->name, 'class')) {
            return null;
        }

        return new MethodCall($expr->var, $expr->name, [new Arg(new ClassConstFetch(new FullyQualified('PHPUnit\Framework\AssertionFailedError'), 'class'))]);
    }

    /** False when the class already has assertOpenApiResponse() from something other than $trait. */
    private function canUse(Class_ $node, string $trait): bool
    {
        $name = $node->namespacedName?->toString();
        if ($name === null || !$this->reflectionProvider->hasClass($name)) {
            return true;
        }
        $class = $this->reflectionProvider->getClass($name);

        return !$class->hasMethod('assertOpenApiResponse') || $class->hasTraitUse($trait);
    }

    private function hasTrait(Class_ $node, string $trait): bool
    {
        $name = $node->namespacedName?->toString();
        if ($name !== null && $this->reflectionProvider->hasClass($name) && $this->reflectionProvider->getClass($name)->hasTraitUse($trait)) {
            return true;
        }
        foreach ($node->getTraitUses() as $use) {
            foreach ($use->traits as $used) {
                if ($this->isName($used, $trait)) {
                    return true;
                }
            }
        }

        return false;
    }
}
