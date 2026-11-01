<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeVisitor;
use Rector\Rector\AbstractRector;
use Studio\Gesso\Rector\GessoSetList;

use function array_intersect;

/**
 * Rewrites `$this->` calls to the private helpers v3 removed from Gesso's
 * assertion traits: failOpenApi()/failPsr7()/failExplore() → fail(),
 * assertOpenApi()/assertPsr7() → assertTrue(), and shouldSkipOpenApi() →
 * `findSkipOpenApiAttribute() !== null`.
 *
 * Being private, the helpers were callable only from a class that uses the
 * trait directly, so the rule fires only there, and not when the class
 * declares a method of the same name itself.
 *
 * @internal Applied through {@see GessoSetList::V3}; the class name is not a contract.
 */
final class RemovedTraitHelperCallRector extends AbstractRector
{
    private const string LARAVEL = 'Studio\Gesso\Laravel\ValidatesOpenApiSchema';
    private const string SYMFONY = 'Studio\Gesso\Symfony\OpenApiAssertions';
    private const string PSR7 = 'Studio\Gesso\Psr7\OpenApiAssertions';
    private const string EXPLORE = 'Studio\Gesso\Laravel\ExploresOpenApiEndpoint';

    /** v2 helper => [v3 replacement, the traits that composed the helper] */
    private const array HELPERS = [
        'failOpenApi' => ['fail', [self::LARAVEL, self::SYMFONY, self::PSR7]],
        'assertOpenApi' => ['assertTrue', [self::LARAVEL, self::SYMFONY, self::PSR7]],
        'failPsr7' => ['fail', [self::PSR7]],
        'assertPsr7' => ['assertTrue', [self::PSR7]],
        'failExplore' => ['fail', [self::EXPLORE]],
        'shouldSkipOpenApi' => ['findSkipOpenApiAttribute', [self::LARAVEL]],
    ];

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        $traits = [];
        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof TraitUse) {
                foreach ($stmt->traits as $trait) {
                    $traits[] = (string) $this->getName($trait);
                }
            }
        }
        if ($traits === []) {
            return null;
        }

        $changed = false;
        $this->traverseNodesWithCallable($node->stmts, function (Node $inner) use ($node, $traits, &$changed): null|int|Node {
            // `$this` inside a nested class is that class.
            if ($inner instanceof Class_) {
                return NodeVisitor::DONT_TRAVERSE_CHILDREN;
            }
            if (!$inner instanceof MethodCall ||
                !$inner->var instanceof Variable ||
                $inner->var->name !== 'this' ||
                !$inner->name instanceof Identifier) {
                return null;
            }

            $helper = $inner->name->toString();
            if (!isset(self::HELPERS[$helper]) || $node->getMethod($helper) !== null) {
                return null;
            }
            [$replacement, $owners] = self::HELPERS[$helper];
            if (array_intersect($owners, $traits) === []) {
                return null;
            }

            $changed = true;
            $inner->name = new Identifier($replacement);

            return $helper === 'shouldSkipOpenApi'
                ? new NotIdentical($inner, new ConstFetch(new Name('null')))
                : $inner;
        });

        return $changed ? $node : null;
    }
}
