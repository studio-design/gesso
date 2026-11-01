<?php

declare(strict_types=1);

namespace Studio\Gesso\Rector\Rule;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use Rector\Rector\AbstractRector;
use Studio\Gesso\Rector\GessoSetList;

use function array_splice;

/**
 * Drops the `enumBasePath` argument v3 removed from `OpenApiSpecLoader::configure()`,
 * named or positional (index 5 in the v2 signature). Rector's built-in
 * ArgumentRemoverRector matches by position only, so it misses the named form.
 *
 * Index 5 is `array $allowedRemoteRefHosts` in v3, so a positional argument is
 * removed only when its type cannot be an array (v2's `?string`). That keeps a
 * second run from deleting a v3 argument; an untyped variable is left alone.
 *
 * @internal Applied through {@see GessoSetList::V3}; the class name is not a contract.
 */
final class RemoveEnumBasePathArgumentRector extends AbstractRector
{
    private const int V2_POSITION = 5;

    public function getNodeTypes(): array
    {
        return [StaticCall::class];
    }

    /**
     * @param StaticCall $node
     */
    public function refactor(Node $node): ?Node
    {
        if (!$this->isName($node->class, 'Studio\Gesso\Spec\OpenApiSpecLoader') ||
            !$this->isName($node->name, 'configure') ||
            $node->isFirstClassCallable()) {
            return null;
        }

        foreach ($node->args as $index => $arg) {
            // A spread hides the positions behind it.
            if (!$arg instanceof Arg || $arg->unpack) {
                return null;
            }
            // PHP forbids a positional argument after a named one, so a nameless
            // argument at index 5 means indexes 0-5 are all positional.
            $isEnumBasePath = $arg->name !== null
                ? $arg->name->toString() === 'enumBasePath'
                : $index === self::V2_POSITION && $this->getType($arg->value)->isArray()->no();
            if ($isEnumBasePath) {
                array_splice($node->args, $index, 1);

                return $node;
            }
        }

        return null;
    }
}
