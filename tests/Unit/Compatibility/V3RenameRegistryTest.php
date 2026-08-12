<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\LegacyIdentity;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_merge;
use function array_values;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function implode;
use function in_array;
use function json_decode;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strtok;
use function trim;

/**
 * The reverse direction of {@see DeprecationRegistryTest}.
 *
 * That test scans `src/` for `Deprecations::notice()` calls and requires each
 * one to be registered, which catches a deprecation nobody registered. It
 * cannot catch the opposite and more likely failure: a rename that ships with
 * no notice at all. Nothing is emitted, so nothing is scanned, and the registry
 * stays as correct — and as empty — as it was.
 *
 * This test starts from `docs/adr/0005-v3-configuration-and-cli-naming.md`
 * instead. The ADR fixes every v3 spelling, so every old spelling it names is a
 * removal that `docs/versioning.md` requires a v2 minor to deprecate first. The
 * fixture transcribes those spellings and records which channel each one uses;
 * `unstaged_count` is the ratchet that turns the review rule in
 * [#499](https://github.com/studio-design/gesso/issues/499) into an arithmetic
 * one.
 *
 * The ordering it protects is unrecoverable, which is why it exists:
 * `docs/versioning.md` notes that the first breaking commit on `main` turns the
 * pending release into `3.0.0`, after which no further v2 release can be cut.
 * A rename whose deprecation was forgotten before that point cannot be given
 * one afterwards without spending another major.
 */
final class V3RenameRegistryTest extends TestCase
{
    private const ADR = '/docs/adr/0005-v3-configuration-and-cli-naming.md';
    private const CHANNELS = ['deprecation', 'accepted-spelling', 'unchanged-spelling'];

    #[Test]
    public function every_old_spelling_the_adr_names_is_listed(): void
    {
        $missing = array_values(array_diff($this->adrSpellings(), array_keys($this->renames())));

        $this->assertSame([], $missing, sprintf(
            'ADR 0005 renames %d spelling(s) that tests/fixtures/compatibility/v3-renames.json does not list, '
            . "so nothing checks that they ship a deprecation:\n  %s",
            count($missing),
            implode("\n  ", $missing),
        ));
    }

    #[Test]
    public function every_entry_declares_a_channel_and_a_removal(): void
    {
        foreach ($this->renames() as $spelling => $entry) {
            foreach (['surface', 'replacement', 'channel', 'owner'] as $key) {
                $this->assertArrayHasKey($key, $entry, $spelling);
                $this->assertIsString($entry[$key], $spelling . '.' . $key);
                $this->assertNotSame('', $entry[$key], $spelling . '.' . $key);
            }

            $this->assertContains($entry['channel'], self::CHANNELS, $spelling . '.channel');
            $this->assertArrayHasKey('removed_in', $entry, $spelling);
            $this->assertArrayHasKey('deprecation_id', $entry, $spelling);

            // A spelling that survives has nothing to remove; every other
            // spelling has to name the version that removes it, or the notice
            // it eventually carries cannot be written — `Deprecations::notice()`
            // rejects an empty `$removedIn`.
            if ($entry['channel'] === 'unchanged-spelling') {
                $this->assertNull($entry['removed_in'], $spelling . '.removed_in');

                continue;
            }

            $this->assertIsString($entry['removed_in'], $spelling . '.removed_in');
            $this->assertNotSame('', $entry['removed_in'], $spelling . '.removed_in');
        }
    }

    #[Test]
    public function every_staged_deprecation_agrees_with_the_registry(): void
    {
        $registry = $this->registry();

        foreach ($this->renames() as $spelling => $entry) {
            $id = $entry['deprecation_id'] ?? null;
            if ($id === null) {
                continue;
            }

            $this->assertSame('deprecation', $entry['channel'], sprintf(
                '%s names a deprecation id but is routed through the %s channel.',
                $spelling,
                $entry['channel'],
            ));

            $this->assertArrayHasKey($id, $registry, sprintf(
                '%s names deprecation id "%s", which v2-deprecations.json does not list.',
                $spelling,
                $id,
            ));

            $this->assertSame($registry[$id]['removed_in'], $entry['removed_in'], sprintf(
                '%s and its registry entry "%s" disagree about the removal version.',
                $spelling,
                $id,
            ));
        }
    }

    #[Test]
    public function every_accepted_spelling_is_wired_into_legacy_identity(): void
    {
        $accepted = array_merge(LegacyIdentity::ENV_NAMES, LegacyIdentity::COMMAND_NAMES);

        foreach ($this->renames() as $spelling => $entry) {
            if ($entry['channel'] !== 'accepted-spelling') {
                continue;
            }

            // Routing to the `[Gesso]` channel is a claim about code, not a
            // label: an entry that names it while LegacyIdentity has never
            // heard of the spelling warns nobody.
            $this->assertArrayHasKey($spelling, $accepted, sprintf(
                '%s is recorded as an accepted spelling, but LegacyIdentity maps no such name, '
                . 'so using it emits no warning.',
                $spelling,
            ));

            $this->assertSame($accepted[$spelling], $entry['replacement'], $spelling . '.replacement');
            $this->assertSame(LegacyIdentity::REMOVED_IN, $entry['removed_in'] . '.0', $spelling . '.removed_in');
        }
    }

    #[Test]
    public function the_unstaged_count_only_goes_down(): void
    {
        $unstaged = array_keys(array_filter(
            $this->renames(),
            static fn(array $entry): bool => $entry['channel'] === 'deprecation' &&
                ($entry['deprecation_id'] ?? null) === null,
        ));
        sort($unstaged);

        $recorded = $this->fixture()['unstaged_count'];

        $this->assertLessThanOrEqual($recorded, count($unstaged), sprintf(
            'This branch adds a v3 rename without staging its deprecation. Stage it, or raise '
            . "unstaged_count deliberately and say why in the PR.\n  %s",
            implode("\n  ", $unstaged),
        ));

        // Downward moves are the point, but a stale number stops the ratchet
        // from ratcheting: it leaves room for the next omission to slip in
        // under the old ceiling.
        $this->assertCount($recorded, $unstaged, sprintf(
            'unstaged_count says %d and %d entries are unstaged. Lower it in the same change that stages one.',
            $recorded,
            count($unstaged),
        ));
    }

    #[Test]
    public function the_adr_scan_reads_both_tables(): void
    {
        // Guards the guard. If the ADR's table markup drifted and this scan
        // silently matched nothing, the coverage test above would pass
        // vacuously and the fixture could rot untouched.
        $spellings = $this->adrSpellings();

        $this->assertGreaterThan(50, count($spellings));
        $this->assertContains('spec_base_path', $spellings, 'the configuration table');
        $this->assertContains('--console-output', $spellings, 'the CLI table');
        $this->assertContains('enum_spec_base_path', $spellings, 'the "— removed —" row');
        $this->assertContains('--specs', $spellings, 'a flag written with a value placeholder');

        // `bearer` is backticked inside "(the legacy key's behaviour becomes
        // the value `bearer`)". It is a value, not a spelling anyone configured,
        // and listing it would demand a deprecation for something that was never
        // a name.
        $this->assertNotContains('bearer', $spellings);
    }

    /**
     * Every old spelling named in ADR 0005's two "Replaces" columns.
     *
     * Parenthetical asides are dropped before the backticked tokens are read:
     * the ADR uses them for commentary, and one of them quotes a config *value*
     * rather than a name.
     *
     * @return list<string>
     */
    private function adrSpellings(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . self::ADR);
        $this->assertIsString($contents);

        $spellings = [];
        $unreadable = [];

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if (!str_starts_with($line, '|') || str_contains($line, '| --- |')) {
                continue;
            }

            $columns = explode('|', $line);
            if (count($columns) !== 4) {
                // Skipping the row silently would drop its spellings from a
                // gate whose entire job is to notice a dropped spelling. A cell
                // containing a literal `|` is the way this happens.
                $unreadable[] = $line;

                continue;
            }

            $replaces = trim($columns[2]);
            if ($replaces === 'Replaces') {
                continue;
            }

            $prose = preg_replace('/\([^)]*\)/', '', $replaces);
            $this->assertIsString($prose);

            preg_match_all('/`([^`]+)`/', $prose, $matches);

            foreach ($matches[1] as $spelling) {
                // `--specs=<a,b>` names the flag and its v2 value shape in one
                // token; the flag is the part a consumer would have to rename.
                $name = (string) strtok($spelling, '=');
                if (!in_array($name, $spellings, true)) {
                    $spellings[] = $name;
                }
            }
        }

        $this->assertSame([], $unreadable, sprintf(
            "This ADR table row did not split into two columns, so its spellings were never read:\n  %s",
            implode("\n  ", $unreadable),
        ));

        sort($spellings);

        return $spellings;
    }

    /** @return array<string, array<string, mixed>> */
    private function renames(): array
    {
        return $this->fixture()['renames'];
    }

    /** @return array<string, array<string, mixed>> */
    private function registry(): array
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v2-deprecations.json',
        );
        $this->assertIsString($contents);

        /** @var array{deprecations: array<string, array<string, mixed>>} $fixture */
        $fixture = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $fixture['deprecations'];
    }

    /** @return array{renames: array<string, array<string, mixed>>, unstaged_count: int} */
    private function fixture(): array
    {
        $contents = file_get_contents(
            dirname(__DIR__, 2) . '/fixtures/compatibility/v3-renames.json',
        );
        $this->assertIsString($contents);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        // Checked rather than asserted by docblock: the ratchet is arithmetic,
        // so a `unstaged_count` that decoded as a string would compare as zero
        // and quietly disarm it.
        $this->assertArrayHasKey('renames', $decoded);
        $this->assertIsArray($decoded['renames']);
        $this->assertArrayHasKey('unstaged_count', $decoded);
        $this->assertIsInt($decoded['unstaged_count']);

        /** @var array<string, array<string, mixed>> $renames */
        $renames = $decoded['renames'];

        return ['renames' => $renames, 'unstaged_count' => $decoded['unstaged_count']];
    }
}
