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
use function array_unique;
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
use function str_replace;
use function str_starts_with;
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
        $survivors = $this->adrSurvivingSpellings();

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
            //
            // `unchanged-spelling` is the one channel that stages nothing and
            // counts toward nothing, so a mislabelled entry leaves the gate.
            // Claiming it therefore has to be true twice over: locally, the
            // entry replaces itself; and in the ADR, the row's v3 column names
            // the same spelling back.
            if ($entry['channel'] === 'unchanged-spelling') {
                $this->assertNull($entry['removed_in'], $spelling . '.removed_in');

                $this->assertSame($spelling, $entry['replacement'], sprintf(
                    '%s is recorded as surviving v3 unchanged, but it is replaced by %s.',
                    $spelling,
                    $entry['replacement'],
                ));

                $this->assertContains($spelling, $survivors, sprintf(
                    'ADR 0005 replaces %s, so it cannot be recorded as surviving v3 unchanged.',
                    $spelling,
                ));

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
        $claimed = [];

        foreach ($this->renames() as $spelling => $entry) {
            $id = $entry['deprecation_id'] ?? null;
            if ($id === null) {
                continue;
            }

            $this->assertIsString($id, $spelling . '.deprecation_id');

            // Two spellings sharing one id means one of them is staged on
            // paper only: deleting that id at 3.0 leaves the other with no
            // deprecation and nothing to notice its absence.
            $this->assertArrayNotHasKey($id, $claimed, sprintf(
                '%s and %s both claim deprecation id "%s". One id stages one spelling.',
                $claimed[$id] ?? '',
                $spelling,
                $id,
            ));
            $claimed[$id] = $spelling;

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

            // Existence is not correspondence. Without these two, any id that
            // happens to share a removal version satisfies the link, so a
            // spelling can be marked staged by pointing at another spelling's
            // deprecation. Both registry fields are prose written for humans,
            // so the check is containment with the backticks taken out.
            $this->assertIsString($registry[$id]['surface'], $id . '.surface');
            $this->assertStringContainsString(
                $spelling,
                $this->unquoted($registry[$id]['surface']),
                sprintf('Registry entry "%s" does not name the spelling %s deprecates.', $id, $spelling),
            );

            $this->assertIsString($entry['replacement'], $spelling . '.replacement');
            $this->assertIsString($registry[$id]['replacement'], $id . '.replacement');
            $this->assertStringContainsString(
                $this->unquoted($entry['replacement']),
                $this->unquoted($registry[$id]['replacement']),
                sprintf(
                    'Registry entry "%s" points somewhere other than the v3 name ADR 0005 gives %s.',
                    $id,
                    $spelling,
                ),
            );
        }
    }

    #[Test]
    public function accepted_spellings_and_legacy_identity_are_the_same_set(): void
    {
        $accepted = array_merge(LegacyIdentity::ENV_NAMES, LegacyIdentity::COMMAND_NAMES);

        $listed = array_keys(array_filter(
            $this->renames(),
            static fn(array $entry): bool => $entry['channel'] === 'accepted-spelling',
        ));
        sort($listed);

        $mapped = array_keys($accepted);
        sort($mapped);

        // Checked as a set, not one way. Fixture → LegacyIdentity alone lets an
        // entry be deleted from the fixture while the spelling keeps working
        // and keeps needing a removal plan, which is the same disappearance
        // this file exists to prevent — just on the other channel.
        $this->assertSame($mapped, $listed, sprintf(
            "LegacyIdentity and v3-renames.json disagree about which spellings are still accepted.\n"
            . "  only in LegacyIdentity: %s\n  only in the fixture: %s",
            implode(', ', array_diff($mapped, $listed)) ?: '(none)',
            implode(', ', array_diff($listed, $mapped)) ?: '(none)',
        ));

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

        // The survivor derivation decides who may claim `unchanged-spelling`,
        // so it needs its own guard: if it returned everything the channel
        // would stop being checked, and if it returned nothing the row that
        // legitimately uses it could never be recorded.
        //
        // Four rows keep their name. Only `--output-file` keeps its meaning
        // too — the other three swap a bare value for the collapsed grammar,
        // so the fixture puts them on `deprecation` instead. Surviving the
        // rename is permission to claim the channel, not an instruction to.
        $survivors = $this->adrSurvivingSpellings();

        $this->assertSame(
            ['--output-file', '--strict-additional-properties', '--strict-required', 'baseline_stale'],
            $survivors,
        );
        $this->assertContains('--json-output', $spellings, 'a replaced flag is still scanned');
        $this->assertNotContains('--json-output', $survivors, 'but it is not a survivor');
    }

    /**
     * Every old spelling named in ADR 0005's two "Replaces" columns.
     *
     * @return list<string>
     */
    private function adrSpellings(): array
    {
        $spellings = array_keys($this->adrRows());
        sort($spellings);

        return $spellings;
    }

    /**
     * The old spellings whose own row names them again in its v3 column, i.e.
     * the ones ADR 0005 carries into v3 under the same name. Only these may be
     * recorded as `unchanged-spelling`; a row may still choose the stricter
     * `deprecation` channel, as `baseline_stale` does because its accepted
     * value changes while its name does not.
     *
     * @return list<string>
     */
    private function adrSurvivingSpellings(): array
    {
        $survivors = [];

        foreach ($this->adrRows() as $spelling => $v3Names) {
            if (in_array($spelling, $v3Names, true)) {
                $survivors[] = $spelling;
            }
        }

        sort($survivors);

        return $survivors;
    }

    /**
     * Each old spelling in ADR 0005's two "Replaces" columns, mapped to the v3
     * names its own row proposes.
     *
     * Parenthetical asides are dropped before the backticked tokens are read:
     * the ADR uses them for commentary, and one of them quotes a config *value*
     * rather than a name.
     *
     * @return array<string, list<string>>
     */
    private function adrRows(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . self::ADR);
        $this->assertIsString($contents);

        $rows = [];
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

            $v3Names = $this->names($columns[1]);

            foreach ($this->names($replaces) as $spelling) {
                $rows[$spelling] = array_values(array_unique(
                    array_merge($rows[$spelling] ?? [], $v3Names),
                ));
            }
        }

        $this->assertSame([], $unreadable, sprintf(
            "This ADR table row did not split into two columns, so its spellings were never read:\n  %s",
            implode("\n  ", $unreadable),
        ));

        return $rows;
    }

    /**
     * The backticked names in one ADR table cell, with parenthetical asides
     * dropped first.
     *
     * @return list<string>
     */
    private function names(string $cell): array
    {
        $prose = preg_replace('/\([^)]*\)/', '', $cell);
        $this->assertIsString($prose);

        preg_match_all('/`([^`]+)`/', $prose, $matches);

        $names = [];

        foreach ($matches[1] as $name) {
            // `--specs=<a,b>` names the flag and its value shape in one token;
            // the flag is the part a consumer would have to rename. `explode()`
            // rather than `strtok()`: a token that is nothing but the delimiter
            // names no flag, and only `explode()` reports that as a value the
            // guard below can see.
            $head = trim(explode('=', $name)[0]);
            if ($head === '' || in_array($head, $names, true)) {
                continue;
            }

            $names[] = $head;
        }

        return $names;
    }

    /**
     * The registry writes its `surface` and `replacement` as prose for a human
     * reading a deprecation notice, so it quotes names in backticks. Comparing
     * against the fixture's bare spellings means taking those out first.
     */
    private function unquoted(string $prose): string
    {
        return str_replace('`', '', $prose);
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
