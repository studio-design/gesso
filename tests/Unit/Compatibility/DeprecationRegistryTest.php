<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;
use const T_AS;
use const T_ATTRIBUTE;
use const T_CLASS;
use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_COLON;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAMESPACE;
use const T_STRING;
use const T_USE;
use const T_WHITESPACE;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Studio\Gesso\Internal\Deprecations;

use function array_key_exists;
use function array_keys;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function json_decode;
use function ltrim;
use function sort;
use function sprintf;
use function stripslashes;
use function strtolower;
use function substr;
use function token_get_all;

/**
 * The registry fixture is the ordering artifact between the deprecation
 * release and the major that removes the surfaces (issue #499): a removal PR
 * deletes the entry it removes, so a removal with nothing to delete never
 * shipped a deprecation.
 *
 * The scan below is deliberately token-based rather than textual. A regex over
 * one call spelling would leave every other spelling — positional arguments,
 * an aliased import, a computed id — invisible to the registry check, which is
 * the one failure mode that matters here: a deprecation nobody can find is a
 * deprecation nobody deletes.
 */
final class DeprecationRegistryTest extends TestCase
{
    private const EMITTER = Deprecations::class;

    #[Test]
    public function every_notice_id_in_src_has_a_registry_entry(): void
    {
        $registered = array_keys($this->registry());
        sort($registered);

        $this->assertSame($registered, $this->emittedIds());
    }

    #[Test]
    public function every_registry_entry_names_its_replacement_and_removal(): void
    {
        foreach ($this->registry() as $id => $entry) {
            $this->assertIsArray($entry, $id);

            // `spelling` and `v3_target` are the machine-readable halves of the
            // two prose fields, so `V3RenameRegistryTest` can match an id to
            // the rename it stages by equality instead of by reading English.
            foreach (['spelling', 'surface', 'replacement', 'v3_target', 'removed_in'] as $key) {
                $this->assertArrayHasKey($key, $entry, $id);
                $this->assertIsString($entry[$key], $id . '.' . $key);
                $this->assertNotSame('', $entry[$key], $id . '.' . $key);
            }
        }
    }

    #[Test]
    public function every_entry_names_the_spelling_its_own_notice_names(): void
    {
        $subjects = [];
        $failures = [];

        foreach ($this->sourceFiles() as $file => $contents) {
            $this->scan($file, $contents, $failures, $subjects);
        }

        foreach ($this->registry() as $id => $entry) {
            $this->assertArrayHasKey($id, $subjects, $id . ' is registered but never emitted');

            // The registry is a claim about what a consumer will read on
            // STDERR. Nothing checked that claim against the call, so an entry
            // could be re-pointed at a different key while the notice it
            // describes went on naming the old one.
            $this->assertIsString($subjects[$id], sprintf(
                'The notice for "%s" builds its subject at runtime, so the registry cannot be held to it.',
                $id,
            ));

            $this->assertStringContainsString($entry['spelling'], $subjects[$id], sprintf(
                'Registry entry "%s" deprecates %s, but its notice announces "%s".',
                $id,
                $entry['spelling'],
                $subjects[$id],
            ));
        }
    }

    #[Test]
    public function the_scanner_finds_every_call_spelling(): void
    {
        // Guards the guard: if this scan silently stopped matching, the
        // registry check above would pass vacuously forever. Each snippet is
        // a spelling the scan must resolve to the same emitter.
        $spellings = [
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\nDeprecations::notice(id: 'a', subject: 's', replacement: 'r', removedIn: '3.0');",
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations as D;\nD::notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\n\\Studio\\Gesso\\Internal\\Deprecations::notice(subject: 's', id: 'a', replacement: 'r', removedIn: '3.0');",
            "<?php\nnamespace Studio\\Gesso\\Internal;\nDeprecations::notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal;\nInternal\\Deprecations::notice('a', 's', 'r', '3.0');",
        ];

        foreach ($spellings as $index => $source) {
            $this->assertSame(['a'], $this->scan('snippet-' . $index, $source), 'spelling ' . $index);
        }
    }

    #[Test]
    public function the_scanner_ignores_a_notice_call_on_another_class(): void
    {
        $source = "<?php\nnamespace X;\nuse X\\Logger;\nLogger::notice('not-a-deprecation');";

        $this->assertSame([], $this->scan('other-class', $source));
    }

    #[Test]
    public function a_computed_id_fails_instead_of_going_unnoticed(): void
    {
        $source = "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
            . "Deprecations::notice(id: \$key, subject: 's', replacement: 'r', removedIn: '3.0');";

        $failures = [];
        $this->scan('computed', $source, $failures);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('the id must be a literal string', $failures[0]);
    }

    #[Test]
    public function a_mis_cased_call_is_still_resolved(): void
    {
        // PHP resolves class and method names case-insensitively, so these
        // reach the real emitter. A scanner that matched the source spelling
        // would let them ship without a registry entry.
        $spellings = [
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\nDeprecations::Notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\nDeprecations::NOTICE('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\n\\Studio\\Gesso\\INTERNAL\\Deprecations::notice('a', 's', 'r', '3.0');",
        ];

        foreach ($spellings as $index => $source) {
            $this->assertSame(['a'], $this->scan('cased-' . $index, $source), 'spelling ' . $index);
        }
    }

    #[Test]
    public function an_interpolated_argument_does_not_hide_a_literal_id(): void
    {
        // `"{$x}"` opens its brace with an array token and closes it with a
        // plain `}`. Counting only the plain openers ran the argument split
        // off the end of the file, which surfaced as "the id is not a literal"
        // on a call whose id is a literal — sending the author after the wrong
        // problem, and hiding a real registry gap behind a wrong explanation.
        $source = "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
            . "Deprecations::notice(subject: \"the {\$name} option\", id: 'laravel.config.legacy',"
            . " replacement: 'r', removedIn: '3.0');\n"
            . "Deprecations::notice(id: 'second.call', subject: 's', replacement: 'r', removedIn: '3.0');";

        $failures = [];

        $this->assertSame(['laravel.config.legacy', 'second.call'], $this->scan('interpolated', $source, $failures));
        $this->assertSame([], $failures);
    }

    #[Test]
    public function an_unterminated_call_says_so_rather_than_blaming_the_id(): void
    {
        $source = "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
            . "Deprecations::notice(id: 'a', subject: 's'";

        $failures = [];
        $this->scan('unterminated', $source, $failures);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('did not find the closing parenthesis', $failures[0]);
    }

    /**
     * Every id emitted through {@see Deprecations::notice()} anywhere in
     * `src/`, sorted. A call whose id cannot be read statically fails the test
     * rather than being skipped — an id the registry cannot list is an id the
     * removing major cannot delete.
     *
     * @return list<string>
     */
    private function emittedIds(): array
    {
        $ids = [];
        $failures = [];

        foreach ($this->sourceFiles() as $file => $contents) {
            foreach ($this->scan($file, $contents, $failures) as $id) {
                $ids[$id] = true;
            }
        }

        $this->assertSame([], $failures, 'every Deprecations::notice() id must be statically readable');

        $ids = array_keys($ids);
        sort($ids);

        return $ids;
    }

    /**
     * Resolve every `Deprecations::notice()` call in one PHP source and return
     * its ids. Unreadable ids are appended to `$failures` rather than thrown,
     * so one scan reports every offending call site at once.
     *
     * @param list<string> $failures
     * @param array<string, null|string> $subjects receives each id's `subject`
     *                                             argument, `null` when it is
     *                                             not a literal
     *
     * @return list<string>
     */
    private function scan(
        string $file,
        string $contents,
        array &$failures = [],
        array &$subjects = [],
    ): array {
        $tokens = $this->significantTokens($contents);
        $namespace = $this->namespaceOf($tokens);
        $aliases = $this->importsOf($tokens);
        $selfClass = $this->classOf($tokens, $namespace);
        $ids = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || !$this->isStaticCallTo($tokens, $index, 'notice')) {
                continue;
            }

            // Class names are case-insensitive to PHP too, so the comparison
            // must be as well or a mis-cased reference reaches the emitter
            // while the registry never sees it.
            if (strtolower($this->resolve($token, $namespace, $aliases, $selfClass)) !== strtolower(self::EMITTER)) {
                continue;
            }

            $id = $this->idArgument($tokens, $index + 3, $reason);
            if ($id === null) {
                $failures[] = sprintf(
                    '%s: this Deprecations::notice() call cannot be checked against the deprecation '
                    . 'registry because %s.',
                    $file,
                    $reason ?? 'the id could not be read',
                );

                continue;
            }

            $ids[] = $id;
            $subjects[$id] = $this->subjectArgument($tokens, $index + 3);
        }

        return $ids;
    }

    /**
     * Drop whitespace and comments so callers can index neighbours directly.
     *
     * @return list<array{int, string}|string>
     */
    private function significantTokens(string $contents): array
    {
        $tokens = [];
        foreach (token_get_all($contents) as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $tokens[] = is_array($token) ? [$token[0], $token[1]] : $token;
        }

        return $tokens;
    }

    /**
     * True when `$tokens[$index]` is the class part of a `Class::$method(`
     * static call.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function isStaticCallTo(array $tokens, int $index, string $method): bool
    {
        $class = $tokens[$index] ?? null;
        if (!is_array($class) || !in_array($class[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $operator = $tokens[$index + 1] ?? null;
        $name = $tokens[$index + 2] ?? null;

        return is_array($operator) &&
            $operator[0] === T_DOUBLE_COLON &&
            is_array($name) &&
            $name[0] === T_STRING &&
            // PHP resolves method names case-insensitively, so `::Notice(`
            // reaches the same emitter. Matching the source spelling would let
            // that call site skip the registry entirely.
            strtolower($name[1]) === $method &&
            ($tokens[$index + 3] ?? null) === '(';
    }

    /**
     * The `id` argument of the call whose `(` sits at `$open`, or `null` when
     * it cannot be read. Prefers the `id:` named argument and falls back to the
     * first positional one, matching PHP's own resolution order. `$reason`
     * receives the explanation, so a scan that cannot read an id says which
     * kind of unreadable it hit rather than guessing.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function idArgument(array $tokens, int $open, ?string &$reason = null): ?string
    {
        return $this->argument($tokens, $open, 'id', 0, $reason);
    }

    /**
     * The `subject` argument of the same call, or `null` when it is not a
     * literal. Unlike the id, an unreadable subject is not a failure here: the
     * caller decides, because a subject that interpolates a runtime value is
     * legitimate while an id that does is not.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function subjectArgument(array $tokens, int $open): ?string
    {
        return $this->argument($tokens, $open, 'subject', 1);
    }

    /**
     * The literal value of one argument, found by name first and by position
     * second, the way PHP resolves it.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function argument(
        array $tokens,
        int $open,
        string $name,
        int $position,
        ?string &$reason = null,
    ): ?string {
        $reason = null;
        $arguments = $this->argumentSegments($tokens, $open);
        if ($arguments === null) {
            $reason = 'its argument list could not be split into arguments — the scanner did not '
                . 'find the closing parenthesis, so it cannot see the ' . $name;

            return null;
        }

        $notALiteral = sprintf(
            'the %s must be a literal string so the deprecation registry can list it',
            $name,
        );

        foreach ($arguments as $segment) {
            $head = $segment[0] ?? null;
            if (is_array($head) && $head[0] === T_STRING && $head[1] === $name && ($segment[1] ?? null) === ':') {
                $value = $this->literal($segment, 2);
                $reason = $value === null ? $notALiteral : null;

                return $value;
            }
        }

        $positional = $arguments[$position] ?? null;
        $head = $positional[0] ?? null;
        // A named argument in this position means the call passes no
        // positional argument here at all.
        if ($positional === null || (is_array($head) && $head[0] === T_STRING && ($positional[1] ?? null) === ':')) {
            $reason = 'the call passes no ' . $name . ' argument';

            return null;
        }

        $value = $this->literal($positional, 0);
        $reason = $value === null ? $notALiteral : null;

        return $value;
    }

    /**
     * Split the argument list opened at `$open` into per-argument token runs,
     * ignoring commas nested inside brackets. `null` when the matching `)` was
     * never reached — a scan that ran off the end has not read the arguments,
     * and reporting its garbage as "the id is not a literal" would send the
     * author after the wrong problem.
     *
     * @param list<array{int, string}|string> $tokens
     *
     * @return null|list<list<array{int, string}|string>>
     */
    private function argumentSegments(array $tokens, int $open): ?array
    {
        $segments = [];
        $current = [];
        $depth = 0;
        $closed = false;

        for ($i = $open + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            // Interpolation and attributes open a bracket with an array token
            // (`T_CURLY_OPEN` for `"{$x}"`, `T_ATTRIBUTE` for `#[`) but close
            // it with a plain `}` / `]`. Counting only the plain openers drives
            // the depth negative and swallows the rest of the file.
            if (is_array($token)) {
                if (in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE], true)) {
                    $depth++;
                }

                $current[] = $token;

                continue;
            }

            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                if ($token === ')' && $depth === 0) {
                    $closed = true;

                    break;
                }

                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                $segments[] = $current;
                $current = [];

                continue;
            }

            $current[] = $token;
        }

        if (!$closed || $depth !== 0) {
            return null;
        }

        if ($current !== []) {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * The value of `$segment` from `$offset` on, when it is exactly one string
     * literal. Anything else — a constant, a variable, a concatenation — is
     * `null`, because the registry has to be able to name the id.
     *
     * @param list<array{int, string}|string> $segment
     */
    private function literal(array $segment, int $offset): ?string
    {
        $value = $segment[$offset] ?? null;
        if (count($segment) !== $offset + 1 || !is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        // A double-quoted literal that interpolated anything would not have
        // survived as one T_CONSTANT_ENCAPSED_STRING, so unescaping is safe.
        return stripslashes(substr($value[1], 1, -1));
    }

    /** @param list<array{int, string}|string> $tokens */
    private function namespaceOf(array $tokens): string
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;

            return is_array($name) ? $name[1] : '';
        }

        return '';
    }

    /**
     * Short name (lowercased) → imported FQCN, for both `use A\B;` and
     * `use A\B as C;`.
     *
     * @param list<array{int, string}|string> $tokens
     *
     * @return array<string, string>
     */
    private function importsOf(array $tokens): array
    {
        $imports = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;
            if (!is_array($name) || !in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $fqcn = ltrim($name[1], '\\');
            $next = $tokens[$index + 2] ?? null;
            if (is_array($next) && $next[0] === T_AS) {
                $alias = $tokens[$index + 3] ?? null;
                if (is_array($alias)) {
                    $imports[strtolower($alias[1])] = $fqcn;
                }

                continue;
            }

            $segments = explode('\\', $fqcn);
            $imports[strtolower($segments[count($segments) - 1])] = $fqcn;
        }

        return $imports;
    }

    /**
     * The FQCN of the first class declared in the file, so `self::` resolves.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function classOf(array $tokens, string $namespace): ?string
    {
        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_CLASS) {
                continue;
            }

            $name = $tokens[$index + 1] ?? null;
            if (is_array($name) && $name[0] === T_STRING) {
                return $namespace === '' ? $name[1] : $namespace . '\\' . $name[1];
            }
        }

        return null;
    }

    /**
     * Resolve a class reference the way PHP does: leading `\` is absolute,
     * `self`/`static` is the enclosing class, a first segment matching an
     * import is replaced by it, and anything else is namespace-relative.
     *
     * @param array{int, string} $token
     * @param array<string, string> $imports
     */
    private function resolve(array $token, string $namespace, array $imports, ?string $selfClass): string
    {
        $name = $token[1];

        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($name, '\\');
        }

        if (in_array(strtolower($name), ['self', 'static'], true)) {
            return $selfClass ?? '';
        }

        $segments = explode('\\', $name);
        $head = strtolower($segments[0]);
        if (array_key_exists($head, $imports)) {
            $segments[0] = $imports[$head];

            return implode('\\', $segments);
        }

        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /** @return array<string, mixed> */
    private function registry(): array
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v2-deprecations.json',
        );
        $this->assertIsString($contents);

        /** @var array{deprecations: array<string, mixed>} $fixture */
        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $fixture['deprecations'];
    }

    /** @return array<string, string> */
    private function sourceFiles(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot . '/src'),
        );
        $sources = [];

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $this->assertIsString($contents);
            $sources['src/' . $iterator->getSubPathname()] = $contents;
        }

        return $sources;
    }
}
