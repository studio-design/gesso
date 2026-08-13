<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;
use const PREG_SET_ORDER;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\LegacyIdentity;

use function array_diff;
use function array_fill_keys;
use function array_filter;
use function array_key_exists;
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
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sort;
use function sprintf;
use function str_contains;
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

    /**
     * The release that removes everything on the `deprecation` channel. ADR
     * 0004's sequencing amendment makes v3.0 the deletion release, so a v3
     * rename dated anywhere else has quietly left the milestone.
     */
    private const REMOVED_IN = '3.0';

    /**
     * The only spelling entitled to the `unchanged-spelling` channel.
     *
     * Deriving this from the ADR does not work, and the failed attempt is
     * worth recording. Four rows name the same spelling in both columns, but
     * three of them — `baseline_stale` and the two `--strict-*` flags — keep
     * the name while replacing the value it accepts, which is a removal to
     * everyone who wrote the old value down. The distinction lives in the
     * value grammar the ADR spells out (`--strict-required="run=…,per_call=…"`
     * against a bare `--strict-required`), and telling that apart from a mere
     * placeholder like `--output-file=<path>` is a guess a parser should not
     * be making. One name, listed by hand, is the honest form: this channel
     * stages nothing and counts toward nothing, so entry into it should cost a
     * deliberate edit here.
     */
    private const UNCHANGED_SPELLINGS = ['--output-file'];

    /**
     * What a deprecation notice has to call each surface. The surface itself
     * is derived — from the ADR table a spelling sits under, or from which
     * LegacyIdentity map holds it — so this is the one place the derived value
     * meets the sentence a consumer reads on STDERR.
     */
    private const SURFACE_WORDS = [
        'config-key' => 'config key',
        'cli-flag' => 'flag',
        'env-var' => 'environment variable',
        'artisan-command' => 'command',
    ];

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
    public function every_replacement_points_where_the_adr_points(): void
    {
        foreach ($this->adrRows() as $spelling => $row) {
            $entry = $this->renames()[$spelling] ?? null;
            if ($entry === null) {
                continue; // Reported by every_old_spelling_the_adr_names_is_listed.
            }

            $this->assertIsString($entry['replacement'], $spelling . '.replacement');

            // `— removed —` rows name no successor, so the fixture has to say
            // so rather than invent one.
            if ($row['target'] === null) {
                $this->assertStringStartsWith('none', $entry['replacement'], sprintf(
                    'ADR 0005 removes %s outright, so its replacement cannot name a v3 target.',
                    $spelling,
                ));

                continue;
            }

            // Exact, not containment. Containment let a spelling name the
            // right v3 key and the wrong member of it — `min_endpoint_coverage`
            // pointing at `coverage.min_coverage['response']` — which is the
            // failure a grouped row invites, and the reason ADR 0005 now
            // spells its pairings out with `→`.
            $this->assertSame($row['target'], $entry['replacement'], sprintf(
                'ADR 0005 replaces %s with "%s"; the fixture says "%s".',
                $spelling,
                $row['target'],
                $entry['replacement'],
            ));
        }
    }

    #[Test]
    public function unchanged_spellings_are_exactly_the_listed_ones(): void
    {
        $listed = array_keys(array_filter(
            $this->renames(),
            static fn(array $entry): bool => $entry['channel'] === 'unchanged-spelling',
        ));
        sort($listed);

        $expected = self::UNCHANGED_SPELLINGS;
        sort($expected);

        // Both directions. Checking only that a claimant is on the list leaves
        // the other way open: moving `--output-file` onto `deprecation` and
        // raising the count reads as progress on the ratchet while nothing was
        // staged, because the spelling it claims to deprecate is not going
        // anywhere.
        $this->assertSame($expected, $listed, sprintf(
            "The unchanged-spelling channel and V3RenameRegistryTest::UNCHANGED_SPELLINGS disagree.\n"
            . "  listed in the constant but not in the fixture: %s\n"
            . '  claiming the channel but not in the constant: %s',
            implode(', ', array_diff($expected, $listed)) ?: '(none)',
            implode(', ', array_diff($listed, $expected)) ?: '(none)',
        ));
    }

    #[Test]
    public function no_entry_sits_outside_both_gates(): void
    {
        $adr = $this->adrSpellings();
        $accepted = array_keys(array_merge(LegacyIdentity::ENV_NAMES, LegacyIdentity::COMMAND_NAMES));

        $ungated = array_values(array_diff(array_keys($this->renames()), $adr, $accepted));

        // Two checks hold this fixture to its sources — the ADR tables and
        // LegacyIdentity's maps — and both compare against a set they read
        // elsewhere. An entry belonging to neither source answers to nothing:
        // deleting it passes, which is how a spelling stops being tracked
        // without anyone deciding to stop tracking it.
        $this->assertSame([], $ungated, sprintf(
            'These entries are checked by neither the ADR scan nor LegacyIdentity, so deleting them '
            . "would go unnoticed. Make the ADR name the spelling instead of describing it:\n  %s",
            implode("\n  ", $ungated),
        ));
    }

    #[Test]
    public function every_entry_declares_a_channel_and_a_removal(): void
    {
        $surfaces = $this->expectedSurfaces();

        foreach ($this->renames() as $spelling => $entry) {
            foreach (['surface', 'replacement', 'channel', 'owner'] as $key) {
                $this->assertArrayHasKey($key, $entry, $spelling);
                $this->assertIsString($entry[$key], $spelling . '.' . $key);
                $this->assertNotSame('', $entry[$key], $spelling . '.' . $key);
            }

            $this->assertContains($entry['channel'], self::CHANNELS, $spelling . '.channel');

            // Derived, not merely enumerated. A closed list still accepts
            // `cli-flag` on a configuration key, because the wrong value is a
            // legal one; only the source can say which surface a spelling is
            // written on.
            $this->assertSame($surfaces[$spelling] ?? null, $entry['surface'], sprintf(
                '%s is written on the %s surface, not %s.',
                $spelling,
                $surfaces[$spelling] ?? '(unknown)',
                $entry['surface'],
            ));

            // `owner` is how a reader gets from an unstaged spelling to the
            // issue that owes it a notice, so it has to be a reachable issue
            // reference rather than any non-empty string.
            $this->assertMatchesRegularExpression('/^#\d+$/', $entry['owner'], $spelling . '.owner');

            $this->assertArrayHasKey('removed_in', $entry, $spelling);
            $this->assertArrayHasKey('deprecation_id', $entry, $spelling);

            // A spelling that survives has nothing to remove; every other
            // spelling has to name the version that removes it, or the notice
            // it eventually carries cannot be written — `Deprecations::notice()`
            // rejects an empty `$removedIn`.
            //
            // `unchanged-spelling` is the one channel that stages nothing and
            // counts toward nothing, so a mislabelled entry leaves the gate
            // entirely. Membership is the hand-written list above, not a
            // property of the entry, precisely so that relabelling an entry
            // cannot let it in.
            if ($entry['channel'] === 'unchanged-spelling') {
                $this->assertNull($entry['removed_in'], $spelling . '.removed_in');

                $this->assertSame($spelling, $entry['replacement'], sprintf(
                    '%s is recorded as surviving v3 unchanged, but it is replaced by %s.',
                    $spelling,
                    $entry['replacement'],
                ));

                continue;
            }

            // Each channel removes at exactly one version, so "non-empty" is
            // not the check: a deprecation quietly re-dated to 4.0 keeps its
            // notice, keeps its registry entry, and stops being something v3
            // has to finish, which is the whole subject of this fixture.
            if ($entry['channel'] === 'accepted-spelling') {
                $this->assertSame(
                    LegacyIdentity::REMOVED_IN,
                    $entry['removed_in'] . '.0',
                    $spelling . ' is removed when LegacyIdentity says it is',
                );

                continue;
            }

            $this->assertSame(self::REMOVED_IN, $entry['removed_in'], sprintf(
                'A deprecation is removed in Gesso %s; %s says %s.',
                self::REMOVED_IN,
                $spelling,
                $entry['removed_in'],
            ));
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

            $notice = $registry[$id]['notice'] ?? null;
            $this->assertIsArray($notice, $id . '.notice');

            // `notice` is what `DeprecationRegistryTest` holds to the call in
            // `src/` argument by argument, so comparing against it compares
            // against the emitted notice, not against a second copy of the
            // fixture's own opinion.
            $this->assertSame($notice['removed_in'] ?? null, $entry['removed_in'], sprintf(
                '%s and the notice "%s" emits disagree about the removal version.',
                $spelling,
                $id,
            ));

            // Existence is not correspondence, and neither is prose. The
            // registry's `surface` and `replacement` are written for a human
            // reading a notice, so one sentence can mention two spellings at
            // once — enough for a containment check to accept an id that
            // actually stages the sibling key. The comparison is against the
            // machine-readable pair the registry carries for this purpose.
            $this->assertSame($spelling, $registry[$id]['spelling'], sprintf(
                'Registry entry "%s" deprecates %s, not %s.',
                $id,
                $registry[$id]['spelling'],
                $spelling,
            ));

            $this->assertSame($entry['replacement'], $registry[$id]['v3_target'], sprintf(
                'Registry entry "%s" replaces %s with "%s"; ADR 0005 replaces it with "%s".',
                $id,
                $spelling,
                $registry[$id]['v3_target'],
                $entry['replacement'],
            ));

            // Which surface the notice announces is a fact the ADR already
            // fixes, by which of its two tables the spelling sits under. The
            // notice is free prose and can call a config key a flag; the
            // vocabulary below is the least it has to get right about the
            // thing a reader then goes looking for.
            $subject = $notice['subject'] ?? null;
            $this->assertIsString($subject, $id . '.notice.subject');

            $this->assertArrayHasKey($entry['surface'], self::SURFACE_WORDS, $spelling . '.surface');
            $this->assertStringContainsStringIgnoringCase(
                self::SURFACE_WORDS[$entry['surface']],
                $subject,
                sprintf(
                    '%s is written on the %s surface, but the notice "%s" emits announces it as "%s".',
                    $spelling,
                    $entry['surface'],
                    $id,
                    $subject,
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

        // The v3 column is read too, and `every_replacement_points_where_the_adr_points`
        // is only as strong as what it finds there.
        $rows = $this->adrRows();

        $this->assertSame('spec.base_path', $rows['spec_base_path']['target'], 'a one-to-one row');
        $this->assertSame(
            "coverage.min_coverage['endpoint']",
            $rows['min_endpoint_coverage']['target'],
            'a grouped row resolves per spelling, not per key',
        );
        $this->assertSame('--report=json:<path>', $rows['--json-output']['target'], 'a grouped flag row');
        $this->assertNull($rows['enum_spec_base_path']['target'], 'the "— removed —" row names no successor');

        // The surface comes from which table the row sits under, so the two
        // headers have to be told apart or every spelling reads as a config
        // key and the check stops being able to fail.
        $this->assertSame('config-key', $rows['spec_base_path']['surface']);
        $this->assertSame('cli-flag', $rows['--json-output']['surface']);

        // Splitting a target into its key and its members is what makes the
        // comparison against the row's v3 key exact. A split that stopped
        // parsing one of ADR 0005's shapes would return the whole target as a
        // key that matches nothing — loud — but one that quietly parsed a
        // prefix would compare the wrong half, so the shapes are pinned.
        $this->assertSame(
            ['key' => 'spec.base_path', 'members' => []],
            $this->targetParts('spec.base_path'),
            'a bare key',
        );
        $this->assertSame(
            ['key' => 'coverage.min_coverage', 'members' => ['endpoint']],
            $this->targetParts("coverage.min_coverage['endpoint']"),
            'a subscripted key',
        );
        $this->assertSame(
            ['key' => '--strict-required', 'members' => ['run', 'per_call']],
            $this->targetParts('--strict-required="run=…,per_call=…"'),
            'a flag carrying the collapsed grammar',
        );
        $this->assertSame(
            ['key' => '--report', 'members' => []],
            $this->targetParts('--report=json:<path>'),
            'a flag carrying a value placeholder',
        );
        $this->assertSame(
            ['key' => 'laravel.auto_inject_dummy_credentials', 'members' => []],
            $this->targetParts("laravel.auto_inject_dummy_credentials = 'bearer'"),
            'a key pinned to one value',
        );
        $this->assertNull($this->targetParts("coverage.min_coverage['endpoint'] and then some"), 'trailing prose');
    }

    /**
     * The surface each spelling is written on, taken from where the spelling
     * is defined rather than from what the fixture says about it: the ADR's
     * two tables carry one surface each, and LegacyIdentity keeps its
     * environment variables and its Artisan commands in separate maps.
     *
     * @return array<string, string>
     */
    private function expectedSurfaces(): array
    {
        $surfaces = [];

        foreach ($this->adrRows() as $spelling => $row) {
            $surfaces[$spelling] = $row['surface'];
        }

        foreach (array_keys(LegacyIdentity::ENV_NAMES) as $spelling) {
            $surfaces[$spelling] = 'env-var';
        }

        foreach (array_keys(LegacyIdentity::COMMAND_NAMES) as $spelling) {
            $surfaces[$spelling] = 'artisan-command';
        }

        return $surfaces;
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
     * Each old spelling in ADR 0005's two "Replaces" columns, mapped to the one
     * v3 spelling that replaces it and to the surface its table describes.
     *
     * The target comes from the row's own `old` → `new` pairing where the row
     * has one, and from its single v3 name where it does not. A row that
     * replaces several spellings without pairing them is unreadable rather
     * than guessed at, and a `— removed —` row has no target at all.
     *
     * Parenthetical asides are dropped before the backticked tokens are read:
     * the ADR uses them for commentary, and one of them quotes a config *value*
     * rather than a name.
     *
     * The checks on the ADR itself live here rather than in a test of their
     * own, so that a test which only wants the spelling list still cannot read
     * a malformed table. The cost is that their failures are reported under
     * whichever test called this first; the message says which row and why.
     *
     * @return array<string, array{target: null|string, surface: string}>
     */
    private function adrRows(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . self::ADR);
        $this->assertIsString($contents);

        $rows = [];
        $unreadable = [];
        $claimed = [];
        $surface = null;

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
                // The header says which surface the rows beneath it describe,
                // which is how `surface` becomes derived rather than declared.
                $surface = trim($columns[1]) === 'v3 flag' ? 'cli-flag' : 'config-key';

                continue;
            }

            $this->assertIsString($surface, 'a table row appeared before any header');

            $v3Cell = $this->prose($columns[1]);
            $v3Names = $this->names($v3Cell);
            $members = $this->members($v3Cell);
            $replacesCell = $this->prose($replaces);
            $paired = $this->pairs($replacesCell);

            // A row whose v3 cell enumerates members but whose enumeration
            // stopped parsing would silently drop the member check below, so
            // the two grammars the ADR actually uses are pinned here.
            if (str_contains($v3Cell, '= [') || str_contains($v3Cell, '="')) {
                $this->assertNotSame([], $members, 'members unreadable in: ' . trim($v3Cell));
            }

            if ($paired === []) {
                $spellings = $this->names($replacesCell);
                if ($spellings === []) {
                    continue; // A row that adds a flag or renames nothing.
                }

                // No `→`, so the row can only speak for itself if it names one
                // target. More than one and the pairing is the reader's guess,
                // which is exactly what the arrows exist to remove.
                if (count($v3Names) > 1) {
                    $unreadable[] = $line;

                    continue;
                }

                $paired = array_fill_keys($spellings, $v3Names[0] ?? null);
            } elseif ($this->tokens($replacesCell) !== count($paired) * 2) {
                // Some spelling in an arrowed row has no arrow of its own.
                $unreadable[] = $line;

                continue;
            }

            foreach ($paired as $spelling => $target) {
                $this->assertSame(
                    $rows[$spelling]['surface'] ?? $surface,
                    $surface,
                    $spelling . ' appears under two different ADR tables',
                );

                if (array_key_exists($spelling, $rows)) {
                    $unreadable[] = $spelling . ' is replaced twice: ' . $line;

                    continue;
                }

                if ($target !== null) {
                    // The target has to be a member of this row's key, not of
                    // whichever key happens to appear in the same sentence.
                    $this->assertNotSame([], $v3Names, $spelling . ' names a target under no v3 key');

                    $parts = $this->targetParts($target);
                    if ($parts === null) {
                        $unreadable[] = $spelling . ' is replaced by an unparseable target: ' . $line;

                        continue;
                    }

                    // Split, then compared exactly, in both halves.
                    // `assertStringStartsWith` accepted
                    // `coverage.min_coverage_typo['response']` under the key
                    // `coverage.min_coverage`, because the wrong key had the
                    // right one as a prefix — the same substring failure the
                    // member check had already been fixed for.
                    $this->assertSame($v3Names[0], $parts['key'], sprintf(
                        '%s is replaced by "%s", which is under the key %s, not this row\'s %s.',
                        $spelling,
                        $target,
                        $parts['key'],
                        $v3Names[0],
                    ));

                    if ($members !== []) {
                        $this->assertNotSame([], $parts['members'], sprintf(
                            '%s is replaced by "%s", which names no member of the key its row collapses into.',
                            $spelling,
                            $target,
                        ));

                        $this->assertSame([], array_values(array_diff($parts['members'], $members)), sprintf(
                            "%s is replaced by \"%s\", whose member is not one this row lists:\n  %s",
                            $spelling,
                            $target,
                            implode("\n  ", $members),
                        ));
                    }

                    $this->assertArrayNotHasKey($target, $claimed, sprintf(
                        '%s and %s are both replaced by "%s". One target replaces one spelling.',
                        $claimed[$target] ?? '',
                        $spelling,
                        $target,
                    ));
                    $claimed[$target] = $spelling;
                }

                $rows[$spelling] = ['target' => $target, 'surface' => $surface];
            }
        }

        $this->assertSame([], $unreadable, sprintf(
            "ADR 0005 has a row this scan cannot read, so its spellings were never checked:\n  %s",
            implode("\n  ", $unreadable),
        ));

        return $rows;
    }

    /** One ADR table cell with its parenthetical asides dropped. */
    private function prose(string $cell): string
    {
        $prose = preg_replace('/\([^)]*\)/', '', $cell);
        $this->assertIsString($prose);

        return $prose;
    }

    /**
     * The `old` → `new` pairs in a Replaces cell, keyed by the old spelling.
     *
     * @return array<string, string>
     */
    private function pairs(string $prose): array
    {
        preg_match_all('/`([^`]+)`\s*→\s*`([^`]+)`/', $prose, $matches, PREG_SET_ORDER);

        $pairs = [];

        foreach ($matches as $match) {
            // Only the left side is split at `=`: it names a flag whose value
            // shape rides along, while the right side is the exact string the
            // fixture has to carry.
            $pairs[trim(explode('=', $match[1])[0])] = $match[2];
        }

        return $pairs;
    }

    /** How many backticked names a cell holds, arrows included. */
    private function tokens(string $prose): int
    {
        preg_match_all('/`[^`]+`/', $prose, $matches);

        return count($matches[0]);
    }

    /**
     * One v3 target split into the key it names and the members it selects out
     * of that key: `coverage.min_coverage['endpoint']` is the key
     * `coverage.min_coverage` selecting `endpoint`, and
     * `--min-coverage="strict"` is the flag `--min-coverage` selecting
     * `strict`. A target naming a whole key or a plain flag value selects no
     * member.
     *
     * `null` when the target is written in none of the shapes ADR 0005 uses.
     * Matching the whole string is what makes the split a split: a pattern
     * that read a prefix and ignored the rest would let anything trail a
     * well-formed target and still be compared as if it were one.
     *
     * @return null|array{key: string, members: list<string>}
     */
    private function targetParts(string $target): ?array
    {
        $matched = preg_match(
            '/^(?<key>[^\s=\[]+)(?<subscripts>(?:\[\'\w+\'\])*)(?:\s*=\s*(?:"[^"]*"|\'[^\']*\'|[\w:<>.,-]+))?$/',
            $target,
            $parts,
        );

        if ($matched !== 1) {
            return null;
        }

        preg_match_all("/\\['(\\w+)'\\]/", $parts['subscripts'], $subscripts);

        return [
            'key' => $parts['key'],
            'members' => $subscripts[1] !== [] ? $subscripts[1] : $this->grammarMembers($target),
        ];
    }

    /**
     * The sub-keys a v3 cell enumerates, in the two forms ADR 0005 uses: a PHP
     * array literal for `gesso.php`, and the collapsed string grammar for a
     * CLI flag. A cell using neither enumerates nothing.
     *
     * @return list<string>
     */
    private function members(string $prose): array
    {
        preg_match_all("/'(\\w+)' =>/", $prose, $arrayLiteral);
        if ($arrayLiteral[1] !== []) {
            return $arrayLiteral[1];
        }

        return $this->grammarMembers($prose);
    }

    /**
     * The sub-keys named inside a collapsed `name=value,name=value` string,
     * the one grammar ADR 0005 gives a CLI flag that carries several settings.
     *
     * @return list<string>
     */
    private function grammarMembers(string $subject): array
    {
        if (preg_match('/="([^"]+)"/', $subject, $grammar) !== 1) {
            return [];
        }

        $members = [];

        foreach (explode(',', $grammar[1]) as $pair) {
            $member = trim(explode('=', $pair)[0]);
            if ($member !== '' && !in_array($member, $members, true)) {
                $members[] = $member;
            }
        }

        return $members;
    }

    /**
     * The backticked names in one already-prosed ADR table cell.
     *
     * @return list<string>
     */
    private function names(string $prose): array
    {
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
