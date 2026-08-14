<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const E_USER_DEPRECATED;
use const JSON_THROW_ON_ERROR;
use const T_AS;
use const T_ATTRIBUTE;
use const T_CLASS;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_COLON;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_FUNCTION;
use const T_LNUMBER;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NAMESPACE;
use const T_NS_SEPARATOR;
use const T_OBJECT_OPERATOR;
use const T_START_HEREDOC;
use const T_STRING;
use const T_USE;
use const T_VARIABLE;
use const T_WHITESPACE;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Studio\Gesso\Internal\Deprecations;

use function array_diff_key;
use function array_key_exists;
use function array_keys;
use function array_slice;
use function chr;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function hexdec;
use function implode;
use function in_array;
use function intval;
use function is_array;
use function is_string;
use function json_decode;
use function ksort;
use function ltrim;
use function octdec;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function preg_replace_callback;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function token_get_all;
use function trim;

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

    /** The shipped directories a deprecation can be emitted from. */
    private const SHIPPED = ['src', 'bin'];

    /**
     * The functions that raise a user error. `user_error` is PHP's own alias
     * for `trigger_error` and reaches the same channel.
     */
    private const RAISERS = ['trigger_error', 'user_error'];

    /**
     * The severities `trigger_error()` accepts, and their values.
     *
     * A closed set, because the question this answers is "is this the
     * deprecation channel", and only an argument that is demonstrably one of
     * the other three can answer no. A user-defined constant holding
     * `E_USER_DEPRECATED` is not a fourth name for something else — it is the
     * channel wearing a name this scan cannot follow.
     */
    private const SEVERITIES = [
        'E_USER_ERROR' => 256,
        'E_USER_WARNING' => 512,
        'E_USER_NOTICE' => 1024,
        'E_USER_DEPRECATED' => 16384,
    ];

    /**
     * The files allowed to hand a notice to `E_USER_DEPRECATED` directly.
     *
     * `Deprecations` is the channel's one emitter, which is the whole point of
     * routing deprecations through it: the registry can be checked against the
     * call sites. `ValidatesOpenApiSchema` is the deliberate exception — its
     * contradictory-intent warning rides the channel so PHPUnit counts it, and
     * it announces no removal, so it has nothing to register.
     *
     * Counted, not just named: a file already on the list would otherwise be
     * free to grow a second direct emission, and the file that owns the one
     * exception is also the file with the most reason to add one. Any change
     * here adds a deprecation the registry will never see, so it should cost a
     * deliberate edit and a sentence in the PR.
     */
    private const DEPRECATION_EMITTERS = [
        'src/Internal/Deprecations.php' => 1,
        'src/Laravel/ValidatesOpenApiSchema.php' => 1,
    ];

    /**
     * The `notice()` arguments the registry mirrors, as registry key =>
     * parameter name, in the order {@see Deprecations::notice()} declares them
     * after `$id`. Positions matter: a call may pass them positionally.
     */
    private const ARGUMENTS = ['subject' => 'subject', 'replacement' => 'replacement', 'removed_in' => 'removedIn'];

    /**
     * Every token a class reference can be, including `namespace\Foo`. PHP
     * resolves that one against the file's own namespace, so it reaches the
     * emitter from inside `Studio\Gesso\Internal` — and a scan that did not
     * know the token dropped the call before it ever looked at the class name.
     */
    private const CLASS_REFERENCES = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

    /** The escapes a double-quoted PHP literal gives a meaning of its own. */
    private const ESCAPES = [
        'n' => "\n",
        'r' => "\r",
        't' => "\t",
        'v' => "\v",
        'e' => "\e",
        'f' => "\f",
        '\\' => '\\',
        '$' => '$',
        '"' => '"',
    ];

    #[Test]
    public function every_notice_id_in_src_has_a_registry_entry(): void
    {
        $registered = array_keys($this->registry());
        sort($registered);

        $emitted = array_keys($this->emittedCalls());
        sort($emitted);

        $this->assertSame($registered, $emitted);
    }

    #[Test]
    public function every_registry_entry_names_its_spelling_target_and_notice(): void
    {
        // The shape check itself lives in `registry()`, so that no test can
        // read a malformed entry and pass. This one names the invariant and
        // fails if the fixture is ever emptied out from under the others.
        $this->assertNotSame([], $this->registry());
    }

    #[Test]
    public function every_entry_records_the_notice_its_call_emits(): void
    {
        $calls = $this->emittedCalls();

        foreach ($this->registry() as $id => $entry) {
            $this->assertArrayHasKey($id, $calls, $id . ' is registered but never emitted');

            // The registry is a claim about what a consumer reads on STDERR.
            // Only the id was ever checked against the call, so a notice could
            // be re-pointed at another target, re-dated to a release v3 does
            // not own, or moved to a different surface, and the ledger a
            // reviewer reads would go on describing the notice that used to be
            // there. Each argument is compared to the call verbatim: changing
            // one in `src/` is now a change to this fixture too.
            foreach (self::ARGUMENTS as $key => $parameter) {
                $emitted = $calls[$id][$key];

                $this->assertIsString($emitted, sprintf(
                    'The notice for "%s" builds its $%s at runtime, so the registry cannot be held to it.',
                    $id,
                    $parameter,
                ));

                $this->assertSame($entry['notice'][$key], $emitted, sprintf(
                    'Registry entry "%s" records a $%s its own notice does not pass.',
                    $id,
                    $parameter,
                ));
            }

            $this->assertStringContainsString($entry['spelling'], $entry['notice']['subject'], sprintf(
                'Registry entry "%s" deprecates %s, but its notice announces "%s".',
                $id,
                $entry['spelling'],
                $entry['notice']['subject'],
            ));

            // The notice may add a parenthetical aside — when the replacement
            // starts being accepted, say — but everything before it is the v3
            // name itself, so that the target the consumer is sent to is the
            // one `V3RenameRegistryTest` pins against ADR 0005.
            $this->assertMatchesRegularExpression(
                '/^' . preg_quote($entry['v3_target'], '/') . '(?: \(.+\))?$/',
                $entry['notice']['replacement'],
                sprintf(
                    'Registry entry "%s" sends the reader to "%s" while its v3_target is "%s".',
                    $id,
                    $entry['notice']['replacement'],
                    $entry['v3_target'],
                ),
            );
        }
    }

    #[Test]
    public function the_deprecation_channel_has_one_emitter(): void
    {
        // The registry can only be checked against `Deprecations::notice()`
        // call sites, so a `trigger_error(..., E_USER_DEPRECATED)` written
        // anywhere else announces a removal that nothing here can see. The
        // class docblock says routing everything through one method is the
        // point; nothing enforced it.
        $emitters = [];

        foreach ($this->sourceFiles() as $file => $contents) {
            $tokens = $this->significantTokens($contents);

            $emissions = count($this->channelEmissions($tokens));
            if ($emissions > 0) {
                $emitters[$file] = $emissions;
            }
        }

        ksort($emitters);
        $expected = self::DEPRECATION_EMITTERS;
        ksort($expected);

        $this->assertSame($expected, $emitters, sprintf(
            "The E_USER_DEPRECATED channel is emitted on somewhere this gate does not know about.\n"
            . "  unlisted: %s\n  listed but no longer emitting: %s",
            implode(', ', array_diff_key($emitters, $expected)) !== ''
                ? implode(', ', array_keys(array_diff_key($emitters, $expected)))
                : '(none)',
            implode(', ', array_keys(array_diff_key($expected, $emitters))) ?: '(none)',
        ));
    }

    #[Test]
    public function no_attribute_deprecates_behind_the_emitter(): void
    {
        // PHP 8.4's `#[\Deprecated]` raises `E_USER_DEPRECATED` by itself when
        // the symbol is used. That is the same channel with none of the
        // ledger: no id, no removal version, nothing for 3.0 to delete.
        $tagged = [];

        foreach ($this->sourceFiles() as $file => $contents) {
            foreach ($this->deprecatedAttributes($this->significantTokens($contents)) as $_) {
                $tagged[] = $file;
            }
        }

        $this->assertSame([], $tagged, sprintf(
            'These files deprecate a symbol with #[\Deprecated], which emits on the same channel as '
            . "Deprecations::notice() and records nothing the removing major can delete:\n  %s",
            implode("\n  ", $tagged),
        ));
    }

    #[Test]
    public function the_emitter_scan_resolves_aliased_functions_and_constants(): void
    {
        // Guards the guard. Both halves of the call can be renamed by an
        // import, and comparing the written spelling saw neither — nor the
        // reverse, where an alias makes a call that looks right go elsewhere.
        $cases = [
            "<?php\nnamespace X;\nuse function trigger_error as deprecate;\n"
                . "deprecate('m', E_USER_DEPRECATED);" => 1,
            "<?php\nnamespace X;\nuse const E_USER_DEPRECATED as GONE;\n"
                . "trigger_error('m', GONE);" => 1,
            "<?php\nnamespace X;\ntrigger_error('m', \\E_USER_DEPRECATED);" => 1,
            // The value PHP stores, written out; and the parameter reached by
            // name rather than by position.
            "<?php\nnamespace X;\ntrigger_error('m', 16384);" => 1,
            "<?php\nnamespace X;\ntrigger_error(error_level: 16384, message: 'm');" => 1,
            "<?php\nnamespace X;\ntrigger_error(message: 'm', error_level: E_USER_DEPRECATED);" => 1,
            // A callable in waiting: nothing else spells the function's name
            // as a string.
            "<?php\nnamespace X;\ncall_user_func('trigger_error', 'm', E_USER_DEPRECATED);" => 1,
            // A severity this cannot evaluate might be the channel, so it
            // counts rather than passing.
            "<?php\nnamespace X;\n\$level = E_USER_DEPRECATED;\ntrigger_error('m', \$level);" => 1,
            // Every base PHP writes an integer in, and the separator it
            // allows. `(int)` read the hexadecimal spelling as zero.
            "<?php\nnamespace X;\ntrigger_error('m', 0x4000);" => 1,
            "<?php\nnamespace X;\ntrigger_error('m', 0b100000000000000);" => 1,
            "<?php\nnamespace X;\ntrigger_error('m', 040000);" => 1,
            "<?php\nnamespace X;\ntrigger_error('m', 0o40000);" => 1,
            "<?php\nnamespace X;\ntrigger_error('m', 16_384);" => 1,
            // A name whose value this cannot see is not a fourth severity.
            "<?php\nnamespace X;\nconst LEVEL = E_USER_DEPRECATED;\ntrigger_error('m', LEVEL);" => 1,
            // PHP's own alias for the same function, and a name relative to a
            // file that has no namespace — which is `bin/gesso`.
            "<?php\nnamespace X;\nuser_error('m', E_USER_DEPRECATED);" => 1,
            "<?php\nnamespace\\trigger_error('m', \\E_USER_DEPRECATED);" => 1,
            // A nowdoc body is a string like any other, and a heredoc body is
            // that string after PHP applies its escapes.
            "<?php\nnamespace X;\ncall_user_func(<<<'F'\ntrigger_error\nF, 'm', E_USER_DEPRECATED);" => 1,
            "<?php\nnamespace X;\ncall_user_func(<<<F\n\\x74rigger_error\nF, 'm', E_USER_DEPRECATED);" => 1,
            // The same body under nowdoc delimiters is fifteen literal
            // characters and no function, which is why the two are told apart
            // rather than both being decoded.
            "<?php\nnamespace X;\ncall_user_func(<<<'F'\n\\x74rigger_error\nF, 'm', E_USER_DEPRECATED);" => 0,
            // A relative name under a namespace is a different function.
            "<?php\nnamespace X;\nnamespace\\trigger_error('m', \\E_USER_DEPRECATED);" => 0,
            "<?php\nnamespace X;\ntrigger_error('m', E_USER_WARNING);" => 0,
            "<?php\nnamespace X;\ntrigger_error('m', 512);" => 0,
            "<?php\nnamespace X;\ntrigger_error('m');" => 0,
            // An alias pointing somewhere else is not this channel.
            "<?php\nnamespace X;\nuse function X\\log as trigger_error;\n"
                . "trigger_error('m', E_USER_DEPRECATED);" => 0,
            // A namespaced constant that merely shares the short name is a
            // constant this cannot evaluate, not a different severity — it
            // could hold 16384. Unreadable counts.
            "<?php\nnamespace X;\nuse const X\\E_USER_DEPRECATED as GONE;\n"
                . "trigger_error('m', GONE);" => 1,
        ];

        foreach ($cases as $source => $expected) {
            $this->assertCount($expected, $this->channelEmissions($this->significantTokens($source)), $source);
        }
    }

    #[Test]
    public function the_attribute_scan_reads_a_whole_group_and_resolves_aliases(): void
    {
        // Guards the guard. An attribute group holds several attributes and an
        // attribute name is a class name, so reading one token and comparing
        // its spelling missed both `#[Other, Deprecated]` and `use Deprecated
        // as D; #[D]` — and, the other way, took `X\Deprecated` for the real
        // one when PHP would not have.
        $cases = [
            "<?php\nnamespace X;\n#[\\Deprecated]\nfunction f(): void {}" => 1,
            "<?php\nnamespace X;\n#[\\Other, \\Deprecated]\nfunction f(): void {}" => 1,
            "<?php\nnamespace X;\n#[\\Other(1, 2), \\Deprecated(since: '2.6')]\nfunction f(): void {}" => 1,
            "<?php\nnamespace X;\nuse Deprecated as D;\n#[D]\nfunction f(): void {}" => 1,
            // Class names are case-insensitive, so these are the same
            // attribute raising the same notice.
            "<?php\nnamespace X;\n#[\\deprecated]\nfunction f(): void {}" => 1,
            "<?php\nnamespace X;\nuse Deprecated as gone;\n#[gone]\nfunction f(): void {}" => 1,
            "<?php\nnamespace X;\n#[\\Other]\nfunction f(): void {}" => 0,
            // Unimported and namespaced: PHP resolves this to X\Deprecated,
            // which is not the attribute that emits.
            "<?php\nnamespace X;\n#[Deprecated]\nfunction f(): void {}" => 0,
        ];

        foreach ($cases as $source => $expected) {
            $this->assertCount($expected, $this->deprecatedAttributes($this->significantTokens($source)), $source);
        }
    }

    #[Test]
    public function the_scan_covers_the_shipped_cli(): void
    {
        // Guards the guard. `bin/gesso` has no `.php` extension, so an
        // extension filter silently excluded the surface ADR 0005 renames most.
        $this->assertArrayHasKey('bin/gesso', $this->sourceFiles());
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
            // Grouped imports, alone and among siblings, plain and aliased.
            // An unread group resolves the call against the namespace instead,
            // misses the emitter, and drops the call out of the scan silently.
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\{Deprecations};\nDeprecations::notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\{LegacyIdentity, Deprecations};\n"
                . "Deprecations::notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\{Deprecations as D};\nD::notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\nuse Studio\\Gesso\\{Internal\\Deprecations};\nDeprecations::notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\{function helper, Deprecations};\n"
                . "Deprecations::notice('a', 's', 'r', '3.0');",
            // `namespace\Foo` from inside the emitter's own namespace. Its
            // token is neither T_STRING nor T_NAME_QUALIFIED, so a scan that
            // did not list it dropped the call before reading the class name.
            "<?php\nnamespace Studio\\Gesso\\Internal;\nnamespace\\Deprecations::notice('a', 's', 'r', '3.0');",
        ];

        foreach ($spellings as $index => $source) {
            $this->assertSame(['a'], $this->scan('snippet-' . $index, $source), 'spelling ' . $index);
        }
    }

    #[Test]
    public function the_emitters_own_static_properties_are_not_an_escape(): void
    {
        // `self::$counts[$id]` is a property read, not a method selected at
        // runtime. Treating every `::$` as an escape reported the emitter for
        // doing its own bookkeeping.
        $source = "<?php\nnamespace Studio\\Gesso\\Internal;\nclass Deprecations {\n"
            . "public static array \$counts = [];\n"
            . "public static function f(): void { self::\$counts['a'] = 1; }\n}";

        $failures = [];
        $this->scan('property', $source, $failures);

        $this->assertSame([], $failures);
    }

    #[Test]
    public function the_scanner_ignores_a_notice_call_on_another_class(): void
    {
        $sources = [
            "<?php\nnamespace X;\nuse X\\Logger;\nLogger::notice('not-a-deprecation');",
            // A grouped import of something else must not make every short
            // name in the file resolve to the emitter.
            "<?php\nnamespace X;\nuse X\\{Logger, Other};\nLogger::notice('not-a-deprecation');",
            // `namespace\` is relative to the file, not to the emitter, so the
            // same spelling under another namespace is a different class.
            "<?php\nnamespace X;\nnamespace\\Deprecations::notice('not-a-deprecation');",
        ];

        foreach ($sources as $index => $source) {
            $this->assertSame([], $this->scan('other-class-' . $index, $source), 'source ' . $index);
        }
    }

    #[Test]
    public function a_second_call_under_one_id_fails_rather_than_shadowing_the_first(): void
    {
        $source = "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
            . "Deprecations::notice('a', 'first', 'r', '3.0');\n"
            . "Deprecations::notice('a', 'second', 'r', '3.0');";

        $failures = [];
        $calls = [];

        $this->assertSame(['a'], $this->scan('duplicate', $source, $failures, $calls));

        // The first call is what a process actually emits, so it is the one
        // kept — and the duplicate is reported rather than silently replacing
        // it, which would hold the registry to a notice nobody ever reads.
        $this->assertSame('first', $calls['a']['subject']);
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('reuses the id "a"', $failures[0]);
    }

    #[Test]
    public function the_scanner_decodes_a_literal_the_way_php_does(): void
    {
        // Left column: the literal as it is written in the source. Right
        // column: what PHP makes of it, and therefore what the registry has to
        // record. `stripslashes()` agreed with PHP on neither quote style —
        // it ate the backslash out of a Windows path and left `\x41` as text.
        $literals = [
            "'C:\\new'" => 'C:\\new',
            "'it\\'s'" => "it's",
            "'a\\\\b'" => 'a\\b',
            "'A\\Gesso'" => 'A\\Gesso',
            '"a\\nb"' => "a\nb",
            '"C:\\\\new"' => 'C:\\new',
            '"A\\Gesso"' => 'A\\Gesso',
            '"say \\"hi\\""' => 'say "hi"',
            '"\\$x"' => '$x',
            '"\\x41"' => 'A',
            '"\\101"' => 'A',
            '"\\e[0m"' => "\e[0m",
            '"\\u{2019}"' => "\u{2019}",
            '"\\u{1F600}"' => "\u{1F600}",
        ];

        foreach ($literals as $source => $expected) {
            $calls = [];
            $failures = [];

            $this->scan(
                'literal',
                "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
                    . "Deprecations::notice('id', " . $source . ", 'r', '3.0');",
                $failures,
                $calls,
            );

            $this->assertSame([], $failures, $source);
            $this->assertSame($expected, $calls['id']['subject'] ?? null, $source);
        }
    }

    #[Test]
    public function an_undecodable_literal_fails_instead_of_decoding_to_something_else(): void
    {
        // Past the last code point. Reporting it as unreadable is the only
        // honest answer; producing some other string would put a value in the
        // registry that no notice can ever print.
        $source = "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
            . "Deprecations::notice(\"a\\u{110000}\", 's', 'r', '3.0');";

        $failures = [];
        $this->scan('undecodable', $source, $failures);

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('the id must be a literal string', $failures[0]);
    }

    #[Test]
    public function a_dynamically_dispatched_notice_fails_instead_of_going_unnoticed(): void
    {
        // Each of these reaches the same emitter at runtime and leaves no
        // class name for the scan to resolve, so silence would mean a notice
        // that never needs a registry entry.
        $spellings = [
            "<?php\nnamespace X;\n\$emitter = 'Studio\\Gesso\\Internal\\Deprecations';\n"
                . "\$emitter::notice('a', 's', 'r', '3.0');",
            "<?php\nnamespace X;\nclass C { const E = \\Studio\\Gesso\\Internal\\Deprecations::class;\n"
                . "public function f(): void { self::E::notice('a', 's', 'r', '3.0'); } }",
        ];

        foreach ($spellings as $index => $source) {
            $failures = [];
            $this->scan('dynamic-' . $index, $source, $failures);

            $this->assertStringContainsString(
                'names its class dynamically',
                implode("\n", $failures),
                'spelling ' . $index,
            );
        }
    }

    #[Test]
    public function selecting_notice_as_a_value_fails_instead_of_going_unnoticed(): void
    {
        // Both reach the emitter with the method name in a value, so no `id`
        // is readable and no registry entry is ever demanded.
        $spellings = [
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
                . "Deprecations::{'notice'}('a', 's', 'r', '3.0');" => 'selects its method as a value',
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
                . "Deprecations::\${'notice'}('a', 's', 'r', '3.0');" => 'selects its method as a value',
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
                . "call_user_func([Deprecations::class, 'notice'], 'a', 's', 'r', '3.0');"
                => 'selects its method as a value',
            // The method name in a variable. `::$name(` is a call; `::$name`
            // without one is a static property, which the emitter has three of.
            "<?php\nnamespace X;\nuse Studio\\Gesso\\Internal\\Deprecations;\n"
                . "\$method = 'notice';\nDeprecations::\$method('a', 's', 'r', '3.0');"
                => 'selects its method as a value',
            // No class token at all: the emitter is named by a string, which
            // is a callable one call away.
            "<?php\nnamespace X;\ncall_user_func("
                . "'Studio\\\\Gesso\\\\Internal\\\\Deprecations::notice', 'a', 's', 'r', '3.0');"
                => 'can only build a callable',
            "<?php\nnamespace X;\n\$e = 'Studio\\\\Gesso\\\\Internal\\\\Deprecations';\n"
                . "\$e::notice('a', 's', 'r', '3.0');" => 'can only build a callable',
            // A nowdoc spells the callable out in full and is not a quoted
            // literal, so a scan reading only quoted literals never saw it.
            "<?php\nnamespace X;\ncall_user_func(<<<'CALLABLE'\n"
                . "Studio\\Gesso\\Internal\\Deprecations::notice\nCALLABLE, 'a', 's', 'r', '3.0');"
                => 'can only build a callable',
            // And a heredoc spells it out only once PHP has applied the
            // escapes, which is the string the callable is built from.
            "<?php\nnamespace X;\ncall_user_func(<<<CALLABLE\n"
                . "\\x53tudio\\Gesso\\Internal\\Deprecations::notice\nCALLABLE, 'a', 's', 'r', '3.0');"
                => 'can only build a callable',
        ];

        foreach ($spellings as $source => $expected) {
            $failures = [];
            $this->scan('escape', $source, $failures);

            $this->assertStringContainsString($expected, implode("\n", $failures), $source);
        }
    }

    #[Test]
    public function a_second_namespace_fails_instead_of_resolving_against_the_first(): void
    {
        // Every unqualified reference in the second block resolves under a
        // prefix the scan never applies, so its calls would land on some other
        // class and vanish.
        $source = "<?php\nnamespace X {\n}\nnamespace Studio\\Gesso\\Internal {\n"
            . "Deprecations::notice('a', 's', 'r', '3.0');\n}";

        $failures = [];
        $this->assertSame([], $this->scan('two-namespaces', $source, $failures));

        $this->assertCount(1, $failures);
        $this->assertStringContainsString('declares 2 namespaces', $failures[0]);
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
     * Every {@see Deprecations::notice()} call anywhere in `src/`, keyed by id.
     *
     * A call whose id cannot be read statically fails the test rather than
     * being skipped — an id the registry cannot list is an id the removing
     * major cannot delete. So does a second call under an id already seen: the
     * whole of `src/` is scanned in one pass precisely so that two files
     * cannot each look unambiguous on their own.
     *
     * @return array<string, array<string, null|string>>
     */
    private function emittedCalls(): array
    {
        $calls = [];
        $failures = [];

        foreach ($this->sourceFiles() as $file => $contents) {
            $this->scan($file, $contents, $failures, $calls);
        }

        $this->assertSame([], $failures, 'every Deprecations::notice() call must be readable and uniquely identified');

        return $calls;
    }

    /**
     * Resolve every `Deprecations::notice()` call in one PHP source and return
     * its ids. Unreadable ids are appended to `$failures` rather than thrown,
     * so one scan reports every offending call site at once.
     *
     * `$calls` receives each id's remaining arguments, keyed by
     * {@see self::ARGUMENTS}, with `null` for any that is not a literal.
     *
     * @param list<string> $failures
     * @param array<string, array<string, null|string>> $calls
     *
     * @return list<string>
     */
    private function scan(
        string $file,
        string $contents,
        array &$failures = [],
        array &$calls = [],
    ): array {
        $tokens = $this->significantTokens($contents);
        $namespaces = $this->namespacesOf($tokens);
        $aliases = $this->importsOf($tokens);
        $namespace = $namespaces[0] ?? '';
        $selfClass = $this->classOf($tokens, $namespace);
        $ids = [];

        // One namespace per file is what this scan can resolve, and what PSR-4
        // gives it. A second block would put every unqualified reference under
        // a prefix the scan does not apply — the call would resolve to some
        // other class and disappear — so it is reported rather than guessed at.
        if (count($namespaces) > 1) {
            $failures[] = sprintf(
                '%s: this file declares %d namespaces, and the deprecation scan resolves one per file.',
                $file,
                count($namespaces),
            );

            return [];
        }

        foreach ($tokens as $index => $token) {
            // `call_user_func('Studio\\Gesso\\Internal\\Deprecations::notice', …)`
            // is a valid callable and leaves no class token to resolve. The
            // emitter's name has no other use as a string in shipped code, so
            // the string is reported as the call it is about to become.
            $text = $this->staticText($tokens, $index);
            if ($text !== null && $this->namesEmitter($text)) {
                $failures[] = sprintf(
                    '%s: this string names %s, which can only build a callable the deprecation scan '
                    . 'cannot read. Call notice() by name.',
                    $file,
                    self::EMITTER,
                );

                continue;
            }

            if (!is_array($token) || !$this->isStaticCallTo($tokens, $index, 'notice')) {
                // A `::notice(` whose class is computed reaches the emitter
                // just as well and leaves no name to resolve. Nothing can
                // check it, so it fails instead of passing unseen.
                if ($this->isDynamicCallTo($tokens, $index, 'notice')) {
                    $failures[] = sprintf(
                        '%s: this ::notice() call names its class dynamically, so the scan cannot tell '
                        . 'whether it reaches Deprecations. Call the emitter by name.',
                        $file,
                    );
                }

                // `Deprecations::{'notice'}()` and `[Deprecations::class,
                // 'notice']` both reach the emitter with the method name in a
                // value. Neither can be read here, so both are reported: the
                // emitter's name appearing next to anything but a literal
                // method call is the signal.
                if ($this->isEmitterEscape($tokens, $index, $namespace, $aliases, $selfClass)) {
                    $failures[] = sprintf(
                        '%s: this reference to %s selects its method as a value, so the deprecation '
                        . 'registry cannot read the id. Call notice() by name.',
                        $file,
                        self::EMITTER,
                    );
                }

                continue;
            }

            // Class names are case-insensitive to PHP too, so the comparison
            // must be as well or a mis-cased reference reaches the emitter
            // while the registry never sees it.
            if (strtolower($this->resolve($token, $namespace, $aliases, $selfClass)) !== strtolower(self::EMITTER)) {
                continue;
            }

            $id = $this->argument($tokens, $index + 3, 'id', 0, $reason);
            if ($id === null) {
                $failures[] = sprintf(
                    '%s: this Deprecations::notice() call cannot be checked against the deprecation '
                    . 'registry because %s.',
                    $file,
                    $reason ?? 'the id could not be read',
                );

                continue;
            }

            // Second call under an id already seen. The channel dedups per id,
            // so at runtime the first call to fire is the notice a consumer
            // reads and the rest are swallowed — while a check that kept the
            // last one it parsed would be describing a notice nobody sees. One
            // id names one surface; two calls is a mistake either way.
            if (array_key_exists($id, $calls)) {
                $failures[] = sprintf(
                    '%s: this Deprecations::notice() call reuses the id "%s", which another call already '
                    . 'emits. The channel dedups per id, so only the first to fire is ever read.',
                    $file,
                    $id,
                );

                continue;
            }

            $ids[] = $id;

            $position = 0;
            foreach (self::ARGUMENTS as $key => $parameter) {
                $calls[$id][$key] = $this->argument($tokens, $index + 3, $parameter, ++$position);
            }
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
     * The name of the function a plain call at `$tokens[$index]` invokes,
     * lowercased and fully resolved, or `null` when this is not one — a method
     * of the same name, or a declaration of it.
     *
     * `$aliases` is the file's `use function` map, so a call written through
     * an alias resolves to what it really invokes, and one whose alias points
     * elsewhere resolves to that instead. `namespace\f()` and `A\f()` are
     * relative to the file, and an unqualified name that no import claims
     * falls back to the global function the way PHP does.
     *
     * @param list<array{int, string}|string> $tokens
     * @param array<string, string> $aliases
     */
    private function calledFunction(array $tokens, int $index, string $namespace, array $aliases): ?string
    {
        $token = $tokens[$index] ?? null;
        if (!is_array($token) || !in_array($token[0], self::CLASS_REFERENCES, true)) {
            return null;
        }

        if (($tokens[$index + 1] ?? null) !== '(') {
            return null;
        }

        $previous = $tokens[$index - 1] ?? null;
        if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR, T_FUNCTION], true)) {
            return null;
        }

        $name = $token[1];

        if ($token[0] === T_NAME_FULLY_QUALIFIED) {
            return strtolower(ltrim($name, '\\'));
        }

        if ($token[0] === T_NAME_RELATIVE) {
            $relative = substr($name, strlen('namespace\\'));

            return strtolower($namespace === '' ? $relative : $namespace . '\\' . $relative);
        }

        if ($token[0] === T_NAME_QUALIFIED) {
            return strtolower($namespace === '' ? $name : $namespace . '\\' . $name);
        }

        // Unqualified. An import decides it; otherwise PHP looks in the
        // current namespace and falls back to the global function, and no
        // shipped file defines one of these.
        $short = strtolower($name);

        return strtolower($aliases[$short] ?? $short);
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
        if (!is_array($class) || !in_array($class[0], self::CLASS_REFERENCES, true)) {
            return false;
        }

        // `Foo::BAR::notice()` puts a name in the class slot that is a class
        // constant, not a class: the name resolves to nothing, and treating it
        // as a class would quietly decide the call is on some other class.
        $preceding = $tokens[$index - 1] ?? null;
        if (is_array($preceding) && $preceding[0] === T_DOUBLE_COLON) {
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
     * The index of every `trigger_error(..., E_USER_DEPRECATED)` call in one
     * file's tokens.
     *
     * Both halves can be renamed by an import — `use function trigger_error as
     * deprecate` and `use const E_USER_DEPRECATED as GONE` are both legal — so
     * both are resolved rather than compared as written.
     *
     * @param list<array{int, string}|string> $tokens
     *
     * @return list<int>
     */
    private function channelEmissions(array $tokens): array
    {
        $functions = $this->symbolAliasesOf($tokens, T_FUNCTION);
        $constants = $this->symbolAliasesOf($tokens, T_CONST);
        $namespace = $this->namespacesOf($tokens)[0] ?? '';
        $found = [];

        foreach (array_keys($tokens) as $index) {
            // A string naming one of the functions is a callable waiting to be
            // invoked: `call_user_func('trigger_error', …)` raises exactly the
            // same notice. The names have no other use in shipped code, so
            // their presence is counted as the call they are about to become.
            $literal = $this->staticText($tokens, $index);
            if ($literal !== null) {
                if (in_array(strtolower(trim(ltrim($literal, '\\'))), self::RAISERS, true)) {
                    $found[] = $index;
                }

                continue;
            }

            if (!in_array($this->calledFunction($tokens, $index, $namespace, $functions), self::RAISERS, true)) {
                continue;
            }

            // The severity is one argument, read as one argument. Looking for
            // the constant among all of them meant a numeric `16384` — the
            // same value, spelled the way PHP stores it — matched nothing.
            if ($this->severityOf($tokens, $index + 1, $constants) !== 'other') {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * Which channel one `trigger_error()` call raises on: `channel` for
     * `E_USER_DEPRECATED` however it is spelled, `other` for a severity that
     * is demonstrably something else, `unreadable` when the argument cannot be
     * evaluated here.
     *
     * `unreadable` counts as an emission, deliberately. A severity this cannot
     * read might be the deprecation channel, and the conservative answer is
     * the one that fails loudly rather than the one that lets a notice ship
     * with no registry entry.
     *
     * @param list<array{int, string}|string> $tokens
     * @param array<string, string> $constants
     */
    private function severityOf(array $tokens, int $open, array $constants): string
    {
        $segments = $this->argumentSegments($tokens, $open);
        if ($segments === null) {
            return 'unreadable';
        }

        $severity = null;
        foreach ($segments as $segment) {
            $head = $segment[0] ?? null;
            if (is_array($head) && $head[0] === T_STRING && $head[1] === 'error_level' && ($segment[1] ?? null) === ':') {
                $severity = array_slice($segment, 2);

                break;
            }
        }

        if ($severity === null) {
            $positional = $segments[1] ?? null;
            $head = $positional[0] ?? null;

            // No second argument at all: PHP defaults to E_USER_NOTICE.
            if ($positional === null || (is_array($head) && $head[0] === T_STRING && ($positional[1] ?? null) === ':')) {
                return 'other';
            }

            $severity = $positional;
        }

        $token = count($severity) === 1 ? $severity[0] : null;
        if (!is_array($token)) {
            return 'unreadable';
        }

        if ($token[0] === T_LNUMBER) {
            $value = $this->integer($token[1]);

            return match (true) {
                $value === self::SEVERITIES['E_USER_DEPRECATED'] => 'channel',
                in_array($value, self::SEVERITIES, true) => 'other',
                default => 'unreadable',
            };
        }

        if (!in_array($token[0], self::CLASS_REFERENCES, true)) {
            return 'unreadable';
        }

        $name = ltrim($token[1], '\\');
        $name = $constants[strtolower($name)] ?? $name;

        // Not "anything but E_USER_DEPRECATED is fine". A name this does not
        // know is a name whose value it cannot see, and
        // `const REVIEW_LEVEL = E_USER_DEPRECATED` is exactly that.
        return match (true) {
            $name === 'E_USER_DEPRECATED' => 'channel',
            array_key_exists($name, self::SEVERITIES) => 'other',
            default => 'unreadable',
        };
    }

    /**
     * The value of one PHP integer literal, in every base PHP writes them in.
     *
     * `(int)` was here, and it reads `0x4000` as zero — the hexadecimal
     * spelling of the deprecation channel arriving as "not the channel".
     * `intval(..., 0)` detects `0x`, `0b` and a leading-zero octal; `0o` is
     * newer than the function and `_` is a separator PHP strips itself.
     */
    private function integer(string $literal): int
    {
        $literal = str_replace('_', '', $literal);

        if (preg_match('/^0[oO]/', $literal) === 1) {
            $literal = '0' . substr($literal, 2);
        }

        return intval($literal, 0);
    }

    /**
     * The index of every `#[\Deprecated]` in one file's tokens, wherever it
     * sits in an attribute group and whatever it is imported as.
     *
     * @param list<array{int, string}|string> $tokens
     *
     * @return list<int>
     */
    private function deprecatedAttributes(array $tokens): array
    {
        $namespace = $this->namespacesOf($tokens)[0] ?? '';
        $imports = $this->importsOf($tokens);
        $selfClass = $this->classOf($tokens, $namespace);
        $found = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_ATTRIBUTE) {
                continue;
            }

            foreach ($this->attributeNames($tokens, $index) as $name) {
                if (strtolower($this->resolve($name, $namespace, $imports, $selfClass)) === 'deprecated') {
                    $found[] = $index;
                }
            }
        }

        return $found;
    }

    /**
     * The name token of every attribute in the group opening at `$index`.
     *
     * @param list<array{int, string}|string> $tokens
     *
     * @return list<array{int, string}>
     */
    private function attributeNames(array $tokens, int $index): array
    {
        $names = [];
        $depth = 0;
        $expectName = true;

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                if ($token[0] === T_ATTRIBUTE) {
                    $depth++;
                } elseif ($expectName && $depth === 0 && in_array($token[0], self::CLASS_REFERENCES, true)) {
                    $names[] = $token;
                    $expectName = false;
                }

                continue;
            }

            if (in_array($token, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token, [')', ']', '}'], true)) {
                if ($token === ']' && $depth === 0) {
                    break;
                }

                $depth--;
            } elseif ($token === ',' && $depth === 0) {
                $expectName = true;
            }
        }

        return $names;
    }

    /**
     * The text of one token that carries a static string, or `null` when the
     * token is not one — or is one this cannot decode faithfully.
     *
     * Quoted literals are one token; a heredoc or nowdoc body is a
     * `T_ENCAPSED_AND_WHITESPACE` between its delimiters. Reading only the
     * first meant a nowdoc could spell out a callable in full and be invisible.
     * Reading the body as written was the same miss one step along: a heredoc
     * applies escapes, so `\x53tudio\Gesso\Internal\Deprecations::notice` is a
     * working callable at runtime and matches nothing as it is spelled. Only
     * the `T_START_HEREDOC` that opened the body says which of the two it is.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function staticText(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];
        if (!is_array($token)) {
            return null;
        }

        if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
            return $this->decode($token[1]);
        }

        if ($token[0] !== T_ENCAPSED_AND_WHITESPACE) {
            return null;
        }

        $enclosure = $this->enclosureOf($tokens, $index);

        return $enclosure === 'nowdoc' ? $token[1] : $this->unescape($token[1], $enclosure === 'quoted');
    }

    /**
     * Which enclosure the string body at `$tokens[$index]` sits in: `nowdoc`
     * for `<<<'ID'`, `heredoc` for `<<<ID` and `<<<"ID"`, `quoted` for a
     * double quote or a backtick.
     *
     * An enclosure this cannot find is answered as a heredoc, the reading that
     * applies escapes. Of the two ways to be wrong, naming a call site that
     * turns out to be innocent costs someone a second reading; missing one
     * costs a released deprecation with no registry entry.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function enclosureOf(array $tokens, int $index): string
    {
        for ($cursor = $index - 1; $cursor >= 0; $cursor--) {
            $token = $tokens[$cursor];
            if (is_array($token) && $token[0] === T_START_HEREDOC) {
                return str_contains($token[1], "'") ? 'nowdoc' : 'heredoc';
            }

            // Interpolation returns the lexer to normal PHP, so a quote inside
            // `{$a["k"]}` arrives as one `T_CONSTANT_ENCAPSED_STRING` and never
            // as the bare character that closes a body.
            if ($token === '"' || $token === '`') {
                return 'quoted';
            }
        }

        return 'heredoc';
    }

    /**
     * True when one static string names the emitter class — on its own, or
     * with a member after it.
     */
    private function namesEmitter(string $literal): bool
    {
        $value = strtolower(trim(ltrim($literal, '\\')));
        $emitter = strtolower(self::EMITTER);

        return $value === $emitter || str_starts_with($value, $emitter . '::');
    }

    /**
     * True when `$tokens[$index]` is a reference to the emitter that reaches
     * it by something other than a literal `::notice(` — `::{'notice'}()`, or
     * `::class` handed to a callable.
     *
     * @param list<array{int, string}|string> $tokens
     * @param array<string, string> $imports
     */
    private function isEmitterEscape(
        array $tokens,
        int $index,
        string $namespace,
        array $imports,
        ?string $selfClass,
    ): bool {
        $token = $tokens[$index] ?? null;
        if (!is_array($token) || !in_array($token[0], self::CLASS_REFERENCES, true)) {
            return false;
        }

        $operator = $tokens[$index + 1] ?? null;
        if (!is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
            return false;
        }

        if (strtolower($this->resolve($token, $namespace, $imports, $selfClass)) !== strtolower(self::EMITTER)) {
            return false;
        }

        $member = $tokens[$index + 2] ?? null;

        // A literal member name is the only one this can read. `::class` in
        // `src/` has no use but building a callable, since the emitter is
        // never injected, and `::{`, `::${` and `::$method(` all pick the
        // member at runtime — `notice` being one of the names they can pick.
        // Listing the escapes let each new syntax through in turn; this lists
        // the one readable form instead. The trailing `(` is what tells
        // `::$method(` from `::$property`, which the emitter reads three of.
        if (is_array($member) && $member[0] === T_VARIABLE) {
            return ($tokens[$index + 3] ?? null) === '(';
        }

        return !is_array($member) || $member[0] !== T_STRING;
    }

    /**
     * True when `$tokens[$index]` starts a `::$method(` static call whose
     * class is not a name the scan can resolve — `$class::notice()`,
     * `(expr)::notice()`, `self::CLASS_NAME::notice()`.
     *
     * Anchored on the `::` so that the token before it can be anything at all;
     * that is the point.
     *
     * @param list<array{int, string}|string> $tokens
     */
    private function isDynamicCallTo(array $tokens, int $index, string $method): bool
    {
        $operator = $tokens[$index] ?? null;
        $name = $tokens[$index + 1] ?? null;

        if (!is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
            return false;
        }

        if (!is_array($name) || $name[0] !== T_STRING || strtolower($name[1]) !== $method) {
            return false;
        }

        if (($tokens[$index + 2] ?? null) !== '(') {
            return false;
        }

        $class = $tokens[$index - 1] ?? null;
        if (!is_array($class) || !in_array($class[0], self::CLASS_REFERENCES, true)) {
            return true;
        }

        // A name in the class slot that is itself reached through `::` is a
        // class constant holding a class name — resolvable at runtime, not here.
        $preceding = $tokens[$index - 2] ?? null;

        return is_array($preceding) && $preceding[0] === T_DOUBLE_COLON;
    }

    /**
     * The literal value of one argument of the call whose `(` sits at `$open`,
     * found by name first and by position second, the way PHP resolves it, or
     * `null` when it cannot be read.
     *
     * `$reason` receives the explanation, so a scan that cannot read an
     * argument says which kind of unreadable it hit rather than guessing. Only
     * the id passes it: an unreadable id is a failure because the registry
     * could not list it, while a subject that interpolates a runtime value is
     * legitimate and left for the caller to judge.
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

        return $this->decode($value[1]);
    }

    /**
     * One PHP string literal, decoded the way PHP decodes it.
     *
     * `stripslashes()` was here, and it agrees with neither quote style: it
     * eats the backslash in `'C:\new'`, which PHP keeps, and it leaves `\x41`
     * as `x41`, which PHP makes an `A`. Either way the registry records a
     * string no consumer ever sees, and the comparison against `src/` compares
     * two things that are both wrong.
     *
     * `null` for anything this cannot decode faithfully — an unreadable
     * literal fails the scan rather than being guessed at.
     */
    private function decode(string $literal): ?string
    {
        // `b'...'` / `B"..."` are the same literals with a binary prefix.
        $literal = ltrim($literal, 'bB');
        $quote = $literal[0] ?? '';
        $inner = substr($literal, 1, -1);

        if ($quote === "'") {
            // Single quotes escape the quote and the backslash, nothing else:
            // `'\n'` is a backslash followed by an n.
            $decoded = preg_replace('/\\\\([\'\\\\])/', '$1', $inner);

            return is_string($decoded) ? $decoded : null;
        }

        return $quote === '"' ? $this->unescape($inner, true) : null;
    }

    /**
     * One double-quoted or heredoc body with its escapes applied, or `null`
     * when it holds one that has no faithful value.
     *
     * `$quoted` is whether `\"` is one of those escapes. Inside double quotes
     * it is; inside a heredoc PHP keeps both characters — the manual says a
     * heredoc "behaves just like a double-quoted string", and on 8.4 and 8.5
     * `<<<X\n\"\nX` is two bytes, so the exception is taken from the engine
     * rather than from the sentence.
     *
     * @see https://www.php.net/manual/en/language.types.string.php
     */
    private function unescape(string $inner, bool $quoted): ?string
    {
        $simple = 'nrtvef\\\\$' . ($quoted ? '"' : '');
        $invalid = false;
        $decoded = preg_replace_callback(
            '/\\\\(?:([' . $simple . '])|([0-7]{1,3})|x([0-9A-Fa-f]{1,2})|u\{([0-9A-Fa-f]+)\})/',
            function (array $match) use (&$invalid): string {
                if ($match[1] !== '') {
                    return self::ESCAPES[$match[1]];
                }

                if ($match[2] !== '') {
                    return chr(octdec($match[2]) % 256);
                }

                if ($match[3] !== '') {
                    return chr(hexdec($match[3]));
                }

                $utf8 = $this->utf8((int) hexdec($match[4]));
                $invalid = $invalid || $utf8 === null;

                return $utf8 ?? '';
            },
            $inner,
        );

        // A backslash before anything else stays a backslash, which is why the
        // pattern leaves it alone rather than stripping it.
        return $invalid || !is_string($decoded) ? null : $decoded;
    }

    /**
     * One code point as UTF-8, or `null` when it is not a code point.
     *
     * Written out rather than taken from `mb_chr()`: mbstring is not in this
     * package's `require`, and a compatibility gate that decodes differently
     * depending on the extensions a machine happens to have is not a gate.
     */
    private function utf8(int $codepoint): ?string
    {
        if ($codepoint < 0 || $codepoint > 0x10FFFF) {
            return null;
        }

        if ($codepoint < 0x80) {
            return chr($codepoint);
        }

        if ($codepoint < 0x800) {
            return chr(0xC0 | $codepoint >> 6) . chr(0x80 | $codepoint & 0x3F);
        }

        if ($codepoint < 0x10000) {
            return chr(0xE0 | $codepoint >> 12)
                . chr(0x80 | $codepoint >> 6 & 0x3F)
                . chr(0x80 | $codepoint & 0x3F);
        }

        return chr(0xF0 | $codepoint >> 18)
            . chr(0x80 | $codepoint >> 12 & 0x3F)
            . chr(0x80 | $codepoint >> 6 & 0x3F)
            . chr(0x80 | $codepoint & 0x3F);
    }

    /**
     * Every namespace the file declares, in source order.
     *
     * @param list<array{int, string}|string> $tokens
     *
     * @return list<string>
     */
    private function namespacesOf(array $tokens): array
    {
        $namespaces = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_NAMESPACE) {
                continue;
            }

            // `namespace\Foo` is a class reference, not a declaration: its
            // whole name is one token, so there is no separator after it.
            $name = $tokens[$index + 1] ?? null;
            if (is_array($name) && $name[0] === T_NS_SEPARATOR) {
                continue;
            }

            $namespaces[] = is_array($name) ? $name[1] : '';
        }

        return $namespaces;
    }

    /**
     * Short name (lowercased) → imported FQCN, for every form a `use`
     * statement takes: `use A\B;`, `use A\B as C;`, the comma-separated
     * `use A\B, A\C;`, and the grouped `use A\{B, C as D};`.
     *
     * Every clause has to be read, not just the first. An unrecognised import
     * does not fail — nothing about the file says a deprecation was meant to
     * be in it — so a clause left unread silently resolves
     * `Deprecations::notice()` against the current namespace, misses the
     * emitter, and takes the call out of the scan entirely. A missing registry
     * entry is exactly what this file exists to make impossible.
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

            // `use function f;` / `use const C;` import no class at all.
            $next = $tokens[$index + 1] ?? null;
            if (is_array($next) && in_array($next[0], [T_FUNCTION, T_CONST], true)) {
                continue;
            }

            $this->addStatement($tokens, $index + 1, $imports);
        }

        return $imports;
    }

    /**
     * Alias (lowercased) → the function or constant name it imports, for the
     * `use function` / `use const` statements of one file.
     *
     * The value is the fully qualified name, not the short one, so that both
     * directions resolve: `use const A\B\E_USER_DEPRECATED as X` names a
     * different constant that merely shares a short name, and `use function
     * X\log as trigger_error` makes a call that looks right go elsewhere.
     *
     * @param list<array{int, string}|string> $tokens
     *
     * @return array<string, string>
     */
    private function symbolAliasesOf(array $tokens, int $kind): array
    {
        $symbols = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token) || $token[0] !== T_USE) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            if (!is_array($next) || $next[0] !== $kind) {
                continue;
            }

            $this->addStatement($tokens, $index + 2, $symbols);
        }

        return $symbols;
    }

    /**
     * Read one `use` statement from `$start` up to its `;`, registering every
     * clause it names.
     *
     * @param list<array{int, string}|string> $tokens
     * @param array<string, string> $imports
     */
    private function addStatement(array $tokens, int $start, array &$imports): void
    {
        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            // `;` ends the statement; a bare `{` here opens a trait-conflict
            // block, which renames methods rather than importing classes.
            if ($token === ';' || $token === '{') {
                return;
            }

            if (!is_array($token) || !in_array($token[0], self::CLASS_REFERENCES, true)) {
                continue;
            }

            $fqcn = ltrim($token[1], '\\');
            $next = $tokens[$i + 1] ?? null;

            // `use A\B\{...}` — the prefix keeps its own token and the brace
            // is reached through a separator of its own.
            if (is_array($next) && $next[0] === T_NS_SEPARATOR && ($tokens[$i + 2] ?? null) === '{') {
                $i = $this->addGroup($tokens, $i + 3, $fqcn, $imports);

                continue;
            }

            $alias = $tokens[$i + 2] ?? null;
            if (is_array($next) && $next[0] === T_AS && is_array($alias)) {
                $this->addImport($fqcn, $alias[1], $imports);
                $i += 2;

                continue;
            }

            $this->addImport($fqcn, null, $imports);
        }
    }

    /**
     * Read the members of one `use A\{...}` group, starting just inside the
     * brace, into `$imports`.
     *
     * Returns the index of the closing `}`, so the statement scan resumes
     * after the group rather than re-reading its members as clauses.
     *
     * @param list<array{int, string}|string> $tokens
     * @param array<string, string> $imports
     */
    private function addGroup(array $tokens, int $open, string $prefix, array &$imports): int
    {
        $count = count($tokens);
        $i = $open;

        for (; $i < $count && $tokens[$i] !== '}'; $i++) {
            $member = $tokens[$i];

            // `use A\{function f, const C}` imports no class, and registering
            // the name anyway would let a function shadow a real class of the
            // same short name.
            if (is_array($member) && in_array($member[0], [T_FUNCTION, T_CONST], true)) {
                $i++;

                continue;
            }

            if (!is_array($member) || !in_array($member[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $as = $tokens[$i + 1] ?? null;
            $alias = $tokens[$i + 2] ?? null;
            if (is_array($as) && $as[0] === T_AS && is_array($alias)) {
                $this->addImport($prefix . '\\' . $member[1], $alias[1], $imports);
                $i += 2;

                continue;
            }

            $this->addImport($prefix . '\\' . $member[1], null, $imports);
        }

        return $i;
    }

    /**
     * Register one import under its alias, or under its own last segment.
     *
     * @param array<string, string> $imports
     */
    private function addImport(string $fqcn, ?string $alias, array &$imports): void
    {
        $segments = explode('\\', $fqcn);

        $imports[strtolower($alias ?? $segments[count($segments) - 1])] = $fqcn;
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

        // `namespace\Foo` is relative to the file's own namespace and never
        // consults an import, so it must be resolved before the alias lookup.
        if ($token[0] === T_NAME_RELATIVE) {
            $relative = substr($name, strlen('namespace\\'));

            return $namespace === '' ? $relative : $namespace . '\\' . $relative;
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

    /**
     * The registry, with its shape checked rather than assumed.
     *
     * The check lives here rather than in a test of its own so that a test
     * which only wants one field still cannot read a half-written entry: the
     * comparisons against `src/` are only as strong as the fixture side being
     * a string where a string is expected.
     *
     * @return array<string, array{
     *     spelling: string,
     *     v3_target: string,
     *     notice: array{subject: string, replacement: string, removed_in: string},
     * }>
     */
    private function registry(): array
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v2-deprecations.json',
        );
        $this->assertIsString($contents);

        /** @var array{deprecations: array<string, mixed>} $fixture */
        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        $registry = [];

        foreach ($fixture['deprecations'] as $id => $entry) {
            $this->assertIsArray($entry, $id);

            // `spelling` and `v3_target` are the machine-readable halves of the
            // notice's prose, so `V3RenameRegistryTest` can match an id to the
            // rename it stages by equality instead of by reading English.
            foreach (['spelling', 'v3_target'] as $key) {
                $this->assertArrayHasKey($key, $entry, $id);
                $this->assertIsString($entry[$key], $id . '.' . $key);
                $this->assertNotSame('', $entry[$key], $id . '.' . $key);
            }

            $this->assertArrayHasKey('notice', $entry, $id);
            $this->assertIsArray($entry['notice'], $id . '.notice');

            $notice = [];
            foreach (array_keys(self::ARGUMENTS) as $key) {
                $this->assertArrayHasKey($key, $entry['notice'], $id . '.notice');
                $this->assertIsString($entry['notice'][$key], $id . '.notice.' . $key);
                $this->assertNotSame('', $entry['notice'][$key], $id . '.notice.' . $key);

                $notice[$key] = $entry['notice'][$key];
            }

            $registry[$id] = [
                'spelling' => $entry['spelling'],
                'v3_target' => $entry['v3_target'],
                'notice' => [
                    'subject' => $notice['subject'],
                    'replacement' => $notice['replacement'],
                    'removed_in' => $notice['removed_in'],
                ],
            ];
        }

        return $registry;
    }

    /**
     * Every shipped PHP source, keyed by its repository-relative path.
     *
     * `bin/` is scanned as well as `src/`, and its one file has no extension:
     * `bin/gesso` is a `#!/usr/bin/env php` script, listed in composer's
     * `bin`, and it owns the CLI flags ADR 0005 renames. Scanning `src/*.php`
     * alone left the surface with the most renames outside the gate — a
     * deprecation emitted from the CLI would have needed no registry entry.
     *
     * @return array<string, string>
     */
    private function sourceFiles(): array
    {
        $projectRoot = dirname(__DIR__, 3);
        $sources = [];

        foreach (self::SHIPPED as $directory) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($projectRoot . '/' . $directory),
            );

            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());
                $this->assertIsString($contents);

                $isPhp = $file->getExtension() === 'php' ||
                    (str_starts_with($contents, '#!') && str_contains($contents, '<?php'));
                if (!$isPhp) {
                    continue;
                }

                $sources[$directory . '/' . $iterator->getSubPathname()] = $contents;
            }
        }

        return $sources;
    }
}
