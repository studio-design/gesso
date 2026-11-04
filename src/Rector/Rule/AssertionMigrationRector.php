<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeVisitor;
use PHPStan\Reflection\ReflectionProvider;
use Rector\Rector\AbstractRector;

use function array_diff_key;
use function array_filter;
use function array_push;
use function array_unique;
use function array_unshift;
use function count;
use function in_array;
use function is_array;
use function property_exists;

/**
 * Turns a competitor's validation statements into calls to a Gesso trait's
 * assertions, and adds the trait to the class. A class is left alone when its
 * statements need two traits, or when it already has assertOpenApiResponse()
 * from elsewhere, such as the Laravel trait.
 *
 * @internal Base of the competitor migration rules; not a contract.
 */
abstract class AssertionMigrationRector extends AbstractRector
{
    final public function __construct(private readonly ReflectionProvider $reflectionProvider) {}

    final public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    final public function refactor(Node $node): ?Node
    {
        /** @var list<array{Expression, Expr}> $rewrites */
        $rewrites = [];
        $traits = [];
        /** @var list<TryCatch> $tries */
        $tries = [];
        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$rewrites, &$traits, &$tries): ?int {
            if ($inner instanceof Class_) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
            if ($inner instanceof TryCatch && $this->onlyFailsOnValidation($inner)) {
                $tries[] = $inner;
            }
            if (!$inner instanceof Expression) {
                return null;
            }
            $expectation = $this->expectedFailure($inner->expr);
            if ($expectation !== null) {
                $rewrites[] = [$inner, $expectation];

                return null;
            }
            $rewrite = $this->rewrite($inner->expr);
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
        $rewritten = [];
        foreach ($rewrites as [$statement, $expr]) {
            $statement->expr = $expr;
            $rewritten[] = $statement;
        }
        $tries = array_filter($tries, static fn(TryCatch $try): bool => array_diff_key($try->stmts, array_filter($try->stmts, static fn(Node $stmt): bool => in_array($stmt, $rewritten, true))) === []);
        if ($tries !== []) {
            $this->traverseNodesWithCallable($node->stmts, static function (Node $inner) use ($tries): null {
                if (property_exists($inner, 'stmts') && is_array($inner->stmts)) {
                    $stmts = [];
                    foreach ($inner->stmts as $stmt) {
                        array_push($stmts, ...(in_array($stmt, $tries, true) ? $stmt->stmts : [$stmt]));
                    }
                    $inner->stmts = $stmts;
                }

                return null;
            });
        }
        if ($traits !== [] && !$this->hasTrait($node, $traits[0])) {
            array_unshift($node->stmts, new TraitUse([new FullyQualified($traits[0])]));
        }

        return $node;
    }

    /**
     * The trait and the Gesso assertion replacing a statement's expression, or null.
     *
     * @return null|array{string, Expr}
     */
    abstract protected function rewrite(Expr $expr): ?array;

    /** The competitor's validation exception, which `expectException()` names. */
    abstract protected function failureException(): string;

    /**
     * Whether the try's only job is turning the competitor's exception into
     * a PHPUnit failure: one catch of that exception, whose body is a single
     * fail() call, and no finally.
     */
    private function onlyFailsOnValidation(TryCatch $try): bool
    {
        if (count($try->catches) !== 1 || $try->finally !== null || $try->stmts === []) {
            return false;
        }
        $catch = $try->catches[0];
        foreach ($catch->types as $type) {
            if (!$this->isName($type, $this->failureException())) {
                return false;
            }
        }
        $fail = count($catch->stmts) === 1 && $catch->stmts[0] instanceof Expression ? $catch->stmts[0]->expr : null;

        return ($fail instanceof MethodCall || $fail instanceof StaticCall) && $this->isName($fail->name, 'fail');
    }

    /** `expectException(<competitor exception>::class)` expecting a PHPUnit failure instead, or null. */
    private function expectedFailure(Expr $expr): ?Expr
    {
        if (!$expr instanceof MethodCall || !$this->isName($expr->name, 'expectException') || $expr->isFirstClassCallable()) {
            return null;
        }
        $class = $expr->getArgs()[0]->value ?? null;
        if (!$class instanceof ClassConstFetch || !$this->isName($class->class, $this->failureException()) || !$this->isName($class->name, 'class')) {
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
