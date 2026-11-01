<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitor;
use PHPStan\Type\ObjectType;
use Rector\Rector\AbstractRector;
use Studio\Gesso\Rector\GessoSetList;

use function array_unshift;
use function count;
use function in_array;

/**
 * Rewrites hotmeteor/spectator's assertions for Gesso's Laravel trait, whose
 * `laravel.auto_assert` and `laravel.auto_validate_request` validate every
 * exchange: assertValidRequest() and assertValidResponse() leave the call
 * chain, assertValidResponse($status) becomes assertStatus($status),
 * `Spectator::using()` statements are deleted, and a class that changed
 * gets the trait.
 *
 * The assertions are TestResponse macros the type resolver cannot see, so
 * they match by name.
 *
 * @internal Applied through {@see GessoSetList::SPECTATOR_TO_GESSO}; the class name is not a contract.
 */
final class SpectatorAssertionRector extends AbstractRector
{
    private const string TRAIT = 'Studio\Gesso\Laravel\ValidatesOpenApiSchema';
    private const string TEST_RESPONSE = 'Illuminate\Testing\TestResponse';

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $changed = false;
        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use (&$changed): null|int|Node {
            if ($inner instanceof Class_) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
            if ($inner instanceof Expression) {
                if ($inner->expr instanceof StaticCall && $this->isName($inner->expr->class, 'Spectator\Spectator') && $this->isName($inner->expr->name, 'using')) {
                    $changed = true;

                    return NodeVisitor::REMOVE_NODE;
                }
                $expr = $inner->expr;
                while (($unwrapped = $this->unwrap($expr)) !== null) {
                    $expr = $unwrapped;
                    $changed = true;
                }
                if ($expr === $inner->expr) {
                    return null;
                }
                if ($expr instanceof Variable) {
                    return NodeVisitor::REMOVE_NODE;
                }
                $inner->expr = $expr;

                return $inner;
            }
            if (!$inner instanceof MethodCall) {
                return null;
            }
            $expr = $this->unwrap($inner);
            if ($expr === null) {
                return null;
            }
            $changed = true;

            return $expr;
        });
        if (!$changed) {
            return null;
        }

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    if ($this->isName($trait, self::TRAIT)) {
                        return $node;
                    }
                }
            }
        }
        array_unshift($node->stmts, new TraitUse([new FullyQualified(self::TRAIT)]));

        return $node;
    }

    /**
     * The call without its outermost Spectator assertion, or null when it has
     * none. A receiver whose type declares the method is not a TestResponse
     * macro and is left alone.
     */
    private function unwrap(Expr $expr): ?Expr
    {
        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return null;
        }
        $name = $expr->name->toString();
        if (!in_array($name, ['assertValidRequest', 'assertValidResponse'], true)) {
            return null;
        }
        $type = $this->getType($expr->var);
        if ($type->isObject()->yes() && !$this->isObjectType($expr->var, new ObjectType(self::TEST_RESPONSE)) && $type->hasMethod($name)->yes()) {
            return null;
        }
        if ($name === 'assertValidResponse' && count($expr->args) === 1) {
            $expr->name = new Identifier('assertStatus');

            return $expr;
        }

        return $expr->args === [] ? $expr->var : null;
    }
}
