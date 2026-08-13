<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Compatibility;

use const JSON_THROW_ON_ERROR;
use const PREG_SET_ORDER;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\LegacyIdentity;

use function array_diff;
use function array_diff_key;
use function array_fill;
use function array_fill_keys;
use function array_filter;
use function array_key_exists;
use function array_keys;
use function array_merge;
use function array_unique;
use function array_unshift;
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
use function preg_quote;
use function preg_replace;
use function sort;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr_count;
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

    /**
     * The one shape a deprecation notice's subject may take, as a format
     * string taking the surface phrase and the spelling.
     *
     * A fixed shape rather than a vocabulary check, because prose that merely
     * contains the right words can still say the wrong thing: "The CLI flag
     * 'x' (not a config key)" passed a containment test while describing the
     * wrong surface entirely. The optional capitalised word is the framework
     * or tool a surface belongs to — `Laravel config key`, `Artisan command`,
     * `CLI flag` — and is the only freedom the format leaves.
     */
    private const SUBJECT = '/^The (?:[A-Z]\\w+ )?%s \'%s\'$/';

    /**
     * How many old spellings ADR 0005's two tables name, and the highest
     * `unstaged_count` the fixture may record.
     *
     * Both numbers live here rather than being read out of the artifact they
     * bound, which is the only way either can bound anything: the ratchet's
     * two assertions both took `unstaged_count` from the file being edited, so
     * the fixture certified itself and the "may go down, never up" half was
     * unenforceable. Lowering either is progress and costs one line; raising
     * one is a decision, and now it shows up in the diff of a test.
     */
    private const SPELLINGS = 55;

    /**
     * What a Replaces cell reads as when the row renames nothing, once its
     * parenthetical commentary is stripped. Anything else with no backticked
     * name in it is prose the scan would silently take for one of these.
     */
    private const RENAMES_NOTHING = ['', 'unchanged'];

    /**
     * What a `— removed —` row's entry records as its replacement. A sentinel
     * with no room in it, so that a successor cannot be smuggled into a field
     * whose point is that there is none.
     */
    private const NO_SUCCESSOR = 'none';

    private const UNSTAGED_CEILING = 53;

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

            // `— removed —` rows name no successor, so the fixture says so
            // with a sentinel and nothing else. A prefix test let the rest of
            // the field carry one anyway — "none — use spec.base_path
            // instead" both starts with `none` and points somewhere, which is
            // the whole thing the row denies. The reason a row has no
            // successor goes in its `$comment`, where nothing reads it as one.
            if ($row['target'] === null) {
                $this->assertSame(self::NO_SUCCESSOR, $entry['replacement'], sprintf(
                    'ADR 0005 removes %s outright, so its replacement is the bare sentinel "%s".',
                    $spelling,
                    self::NO_SUCCESSOR,
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
        $owners = $this->expectedOwners();

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
            // issue that owes it a notice, so it has to be *that* issue. Any
            // issue in the ADR header was not enough: rule 4 says one issue
            // owns each name, and swapping two of them left every entry
            // pointing at a real issue and half of them at the wrong one.
            $this->assertSame($owners[$spelling] ?? null, $entry['owner'], sprintf(
                '%s is owned by %s; ADR 0005 gives it to %s.',
                $spelling,
                $entry['owner'],
                $owners[$spelling] ?? '(no issue)',
            ));

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
            // fixes, by which of its two tables the spelling sits under — so
            // the subject is derived from it rather than merely searched for
            // inside it. Containment was not enough: "The CLI flag 'x' (not a
            // config key)" contains "config key" and says the opposite.
            $subject = $notice['subject'] ?? null;
            $this->assertIsString($subject, $id . '.notice.subject');

            $this->assertArrayHasKey($entry['surface'], self::SURFACE_WORDS, $spelling . '.surface');
            $this->assertMatchesRegularExpression(
                sprintf(self::SUBJECT, preg_quote(self::SURFACE_WORDS[$entry['surface']], '/'), preg_quote($spelling, '/')),
                $subject,
                sprintf(
                    '%s is a %s, so the notice "%s" must announce it as "The [Qualifier] %s \'%s\'". It says "%s".',
                    $spelling,
                    $entry['surface'],
                    $id,
                    self::SURFACE_WORDS[$entry['surface']],
                    $spelling,
                    $subject,
                ),
            );
        }
    }

    #[Test]
    public function every_shipped_notice_is_claimed_by_the_rename_it_stages(): void
    {
        $renames = $this->renames();

        foreach ($this->registry() as $id => $entry) {
            $spelling = $entry['spelling'] ?? null;
            $this->assertIsString($spelling, $id . '.spelling');

            // A deprecation of something ADR 0005 does not rename — a method,
            // a return shape — has no rename to be claimed by.
            if (!array_key_exists($spelling, $renames)) {
                continue;
            }

            // The other direction, and the reason it is needed: the forward
            // check starts from `deprecation_id` and skips a null one, so
            // clearing that field unlinks a notice that actually shipped. The
            // spelling would go back to counting as unstaged, and the next
            // reader would stage it a second time or, worse, read the count as
            // work still to do at 3.0 and remove it without one.
            $this->assertSame($id, $renames[$spelling]['deprecation_id'] ?? null, sprintf(
                'The notice "%s" deprecates %s, which v3-renames.json does not name it as staging.',
                $id,
                $spelling,
            ));
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

        $this->assertLessThanOrEqual(self::UNSTAGED_CEILING, $recorded, sprintf(
            'unstaged_count is %d, above the %d this gate was set at. Staging lowers it; raising it '
            . 'means a rename shipped with no notice, so raise the ceiling here too and say why.',
            $recorded,
            self::UNSTAGED_CEILING,
        ));

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

        // Pinned, not floored. A row deleted from the ADR is not missing from
        // anything — the ADR is the source of truth — so the count is the only
        // thing that notices a spelling leaving the gate.
        $this->assertCount(self::SPELLINGS, $spellings);
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

        // Rule 4 applied mechanically. Each of these is a different branch,
        // and a derivation that collapsed to one answer would agree with the
        // fixture everywhere and check nothing.
        $owners = $this->expectedOwners();

        $this->assertSame('#501', $owners['spec_base_path'], 'a key that collapses nothing');
        $this->assertSame('#502', $owners['min_endpoint_coverage'], 'a key that collapses several settings');
        $this->assertSame('#502', $owners['console_output'], 'section C, marked inline in the table');
        $this->assertSame('#507', $owners['--json-output'], 'the CLI, including the flags it gained from #502');
        $this->assertSame('#508', $owners['enum_spec_base_path'], 'a removal, marked inline in the table');
        $this->assertSame('#504', $owners['OPENAPI_VALIDATION_OUTPUT'], 'an environment variable');
        $this->assertSame('#504', $owners['openapi:routes'], 'an Artisan command');

        // Every derived owner is one the ADR actually routes work to, or the
        // rule has invented an issue number nobody can follow.
        $this->assertSame([], array_values(array_diff($owners, $this->adrIssues())));

        // Splitting a target into its key and its members is what makes the
        // comparison against the row's v3 key exact. A split that stopped
        // parsing one of ADR 0005's shapes would return the whole target as a
        // key that matches nothing — loud — but one that quietly parsed a
        // prefix would compare the wrong half, so the shapes are pinned.
        $this->assertSame(
            ['key' => 'spec.base_path', 'shape' => 'none', 'members' => []],
            $this->targetParts('spec.base_path'),
            'a bare key',
        );
        $this->assertSame(
            ['key' => 'coverage.min_coverage', 'shape' => 'array', 'members' => ['endpoint' => true]],
            $this->targetParts("coverage.min_coverage['endpoint']"),
            'a subscripted key',
        );
        $this->assertSame(
            ['key' => '--strict-required', 'shape' => 'grammar', 'members' => ['run' => true, 'per_call' => true]],
            $this->targetParts('--strict-required="run=…,per_call=…"'),
            'a flag carrying the collapsed grammar',
        );
        $this->assertSame(
            ['key' => '--min-coverage', 'shape' => 'grammar', 'members' => ['strict' => false]],
            $this->targetParts('--min-coverage="strict"'),
            'a grammar member written without a value',
        );
        $this->assertSame(
            ['key' => '--report', 'shape' => 'none', 'members' => []],
            $this->targetParts('--report=json:<path>'),
            'a flag carrying a value placeholder',
        );
        $this->assertSame(
            ['key' => 'laravel.auto_inject_dummy_credentials', 'shape' => 'none', 'members' => []],
            $this->targetParts("laravel.auto_inject_dummy_credentials = 'bearer'"),
            'a key pinned to one value',
        );
        $this->assertNull($this->targetParts("coverage.min_coverage['endpoint'] and then some"), 'trailing prose');

        // Structure, not just names. Each of these parses into members the
        // enclosing row would accept, and each is a different target from the
        // one the row declares.
        $this->assertSame(
            ['endpoint' => true, 'response' => true],
            $this->targetParts("coverage.min_coverage['endpoint']['response']")['members'] ?? null,
            'a nested chain keeps both levels, so the depth check can see it',
        );
        $this->assertNull($this->targetParts("coverage.min_coverage['endpoint']['endpoint']"), 'a repeated subscript');
        $this->assertNull($this->targetParts('--min-coverage="run=…,run=…"'), 'a repeated grammar member');
        $this->assertSame(
            'grammar',
            $this->targetParts('coverage.min_coverage="endpoint=…"')['shape'] ?? null,
            'the notation a target is written in is not the notation of the key it names',
        );

        // The declaration side has to carry the same two facts, or comparing
        // against it compares against half of one.
        $this->assertSame(
            ['shape' => 'array', 'members' => ['endpoint' => true, 'response' => true]],
            $this->declaredMembers("`coverage.min_coverage` = `['endpoint' => …, 'response' => …]`"),
            'a flat map declaration',
        );
        $this->assertSame(
            ['shape' => 'grammar', 'members' => ['endpoint' => true, 'strict' => false]],
            $this->declaredMembers('`--min-coverage="endpoint=…,strict"`'),
            'a grammar declaration, with and without values',
        );
        $this->assertSame(
            ['shape' => 'none', 'members' => []],
            $this->declaredMembers('`--console-report=<mode>`'),
            'a row that collapses nothing',
        );
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

        $legacy = [
            'env-var' => array_keys(LegacyIdentity::ENV_NAMES),
            'artisan-command' => array_keys(LegacyIdentity::COMMAND_NAMES),
        ];

        foreach ($legacy as $surface => $spellings) {
            foreach ($spellings as $spelling) {
                // Overwriting silently would let the second source decide, and
                // the two disagree about more than the label: an ADR spelling
                // that also appeared in LegacyIdentity would take the surface
                // of a name that keeps working, and with it the channel that
                // stages no deprecation.
                $this->assertArrayNotHasKey($spelling, $surfaces, sprintf(
                    '%s is named by both ADR 0005 and LegacyIdentity, which disagree about whether it '
                    . 'is removed at 3.0 or accepted through v3.',
                    $spelling,
                ));

                $surfaces[$spelling] = $surface;
            }
        }

        return $surfaces;
    }

    /**
     * The issue that owns each spelling, applying ADR 0005's rule 4 — "one
     * issue owns each name" — to its own division of labour.
     *
     * A row that names an issue inline settles itself; the `(#508)` markers on
     * the removed rows and the `(#502)` on `console_output`, which the ADR's
     * section C decides in prose, are all read that way. The rest follow
     * "What each issue changes": #504 owns the environment variables and the
     * Artisan commands, #507 owns the CLI (including the merge flags it gained
     * from #502), #502 owns the keys that collapse several v2 settings into
     * one — that collapse *is* its subject — and #501 owns the rest of the
     * key set.
     *
     * Derived rather than transcribed, so that an owner cannot be edited into
     * something plausible. A future spelling the rule gets wrong is marked
     * inline in the ADR, the same way the two exceptions above are.
     *
     * @return array<string, string>
     */
    private function expectedOwners(): array
    {
        $owners = [];

        foreach ($this->adrRows() as $spelling => $row) {
            $owners[$spelling] = $row['owner']
                ?? ($row['surface'] === 'cli-flag' ? '#507' : ($row['collapses'] ? '#502' : '#501'));
        }

        foreach (array_keys(array_merge(LegacyIdentity::ENV_NAMES, LegacyIdentity::COMMAND_NAMES)) as $spelling) {
            $owners[$spelling] = '#504';
        }

        return $owners;
    }

    /**
     * The issues ADR 0005 routes its work to, from its own header.
     *
     * Derived rather than listed here for the same reason `surface` is: the
     * ADR already decides who owns which part of v3, and a second copy of that
     * decision is a second thing to keep true.
     *
     * @return list<string>
     */
    private function adrIssues(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . self::ADR);
        $this->assertIsString($contents);

        $header = explode('## Context', $contents)[0];
        preg_match_all('/\\[(#\\d+)\\]/', $header, $matches);

        $issues = array_values(array_unique($matches[1]));
        $this->assertNotSame([], $issues, 'ADR 0005 names no issue in its header');

        return $issues;
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
     * @return array<string, array{
     *     target: null|string,
     *     surface: string,
     *     collapses: bool,
     *     owner: null|string,
     * }>
     */
    private function adrRows(): array
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . self::ADR);
        $this->assertIsString($contents);

        $rows = [];
        $unreadable = [];
        $claimed = [];
        $surface = null;

        $inTable = false;

        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);

            // A table ends at the first blank line, and GitHub-flavoured
            // Markdown lets a row omit its outer pipes — so membership is a
            // property of the block, not of the first character. Keying on a
            // leading `|` let a row render in the published table and never
            // enter the scan, which is a rename nobody has to record.
            if ($line === '') {
                $inTable = false;

                continue;
            }

            // A delimiter is dashes, colons and pipes and nothing else. The
            // old substring test for `| --- |` also swallowed any data row
            // that happened to contain one, which is how an ASCII-dashed
            // "removed" row would have left the gate.
            if (preg_match('/^\|?[\s:|-]+\|?$/', $line) === 1 && str_contains($line, '-')) {
                $inTable = str_contains($line, '|');

                continue;
            }

            if (!$inTable && !str_starts_with($line, '|')) {
                continue;
            }

            $columns = explode('|', trim($line, '|'));
            if (count($columns) !== 2) {
                // Skipping the row silently would drop its spellings from a
                // gate whose entire job is to notice a dropped spelling. A cell
                // containing a literal `|` is the way this happens.
                $unreadable[] = $line;

                continue;
            }

            array_unshift($columns, '');

            $replaces = trim($columns[2]);
            if ($replaces === 'Replaces') {
                $inTable = true;

                // The header says which surface the rows beneath it describe,
                // which is how `surface` becomes derived rather than declared.
                $surface = trim($columns[1]) === 'v3 flag' ? 'cli-flag' : 'config-key';

                continue;
            }

            $this->assertIsString($surface, 'a table row appeared before any header');

            $v3Cell = $this->prose($columns[1]);
            $v3Names = $this->names($v3Cell);
            $declared = $this->declaredMembers($v3Cell);
            $replacesCell = $this->prose($replaces);
            $paired = $this->pairs($replacesCell);
            $owner = preg_match('/\(#(\d+)\)/', $replaces, $inline) === 1 ? '#' . $inline[1] : null;

            // `prose()` drops parentheticals before anything counts tokens, so
            // an arrow written inside one is renaming a spelling that no check
            // downstream can see. The ADR uses asides for commentary; a
            // rename is not commentary.
            if (substr_count($replaces, '→') !== substr_count($replacesCell, '→')) {
                $unreadable[] = 'a parenthetical hides a rename: ' . $line;

                continue;
            }

            // A Replaces cell says one of three things: nothing is replaced
            // (`unchanged`, or a parenthetical noting a new flag), or it names
            // spellings in backticks. Prose that names something without
            // backticks reads as the first while meaning the second.
            if ($this->tokens($replacesCell) === 0 && !in_array(trim($replacesCell), self::RENAMES_NOTHING, true)) {
                $unreadable[] = 'a Replaces cell names something without backticks: ' . $line;

                continue;
            }

            // A row whose v3 cell enumerates members but whose enumeration
            // stopped parsing would silently drop the member check below, so
            // the two grammars the ADR actually uses are pinned here.
            if (str_contains($v3Cell, '= [') || str_contains($v3Cell, '="')) {
                $this->assertNotSame('none', $declared['shape'], 'members unreadable in: ' . trim($v3Cell));
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

                    // The notation is part of the declaration. A row collapsing
                    // a key into a flat `gesso.php` map is not answered by a
                    // target written in the CLI's collapsed string grammar,
                    // even when both name the same member.
                    $this->assertSame($declared['shape'], $parts['shape'], sprintf(
                        '%s is replaced by "%s", written as %s, but its row declares %s.',
                        $spelling,
                        $target,
                        $parts['shape'],
                        $declared['shape'],
                    ));

                    // Checked whether or not the row enumerates anything. A row
                    // that collapses no key lists no members, and `[]` is the
                    // set a target under it may select from — so guarding this
                    // on a non-empty enumeration let exactly those rows accept
                    // any subscript at all.
                    $this->assertSame([], array_keys(array_diff_key($parts['members'], $declared['members'])), sprintf(
                        "%s is replaced by \"%s\", which selects a member this row does not list:\n  %s",
                        $spelling,
                        $target,
                        $declared['members'] === []
                            ? '(this row collapses no key, so it lists none)'
                            : implode("\n  ", array_keys($declared['members'])),
                    ));

                    // ADR 0005's array literals are flat, so a subscripted
                    // target selects exactly one key of exactly one map.
                    // Flattening the chain to a set of names lost that:
                    // `['endpoint']['response']` read as two ordinary members.
                    if ($parts['shape'] === 'array') {
                        $this->assertCount(1, $parts['members'], sprintf(
                            '%s is replaced by "%s", but its row declares %s as a flat map, so a target '
                            . 'selects one of its keys and no deeper.',
                            $spelling,
                            $target,
                            $v3Names[0],
                        ));
                    }

                    // Whether a member carries a value is declared too:
                    // `--min-coverage` takes `endpoint=…` but a bare `strict`.
                    foreach ($parts['members'] as $member => $carriesValue) {
                        $this->assertSame($declared['members'][$member], $carriesValue, sprintf(
                            '%s is replaced by "%s", but this row declares %s %s a value.',
                            $spelling,
                            $target,
                            $member,
                            $declared['members'][$member] ? 'with' : 'without',
                        ));
                    }

                    if ($declared['members'] !== []) {
                        $this->assertNotSame([], $parts['members'], sprintf(
                            '%s is replaced by "%s", which names no member of the key its row collapses into.',
                            $spelling,
                            $target,
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

                $rows[$spelling] = [
                    'target' => $target,
                    'surface' => $surface,
                    'collapses' => $declared['shape'] !== 'none',
                    'owner' => $owner,
                ];
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
     * of that key, each mapped to whether the target writes it with a value.
     *
     * `coverage.min_coverage['endpoint']` is the key `coverage.min_coverage`
     * selecting `endpoint`; `--min-coverage="strict"` is the flag
     * `--min-coverage` selecting `strict` and giving it no value; a plain key
     * or flag value selects nothing. `shape` is which of the two notations the
     * target is written in, so that a target cannot answer a row in a notation
     * that row does not use.
     *
     * `null` when the target is written in none of the shapes ADR 0005 uses,
     * or names one member twice. Matching the whole string is what makes the
     * split a split: a pattern that read a prefix and ignored the rest would
     * let anything trail a well-formed target and still be compared as one.
     *
     * @return null|array{key: string, shape: string, members: array<string, bool>}
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
        if ($subscripts[1] !== []) {
            // A key an array subscript selects always carries a value, so the
            // map is uniform; what the depth of the chain says is how many
            // levels down the target reaches, and that has to survive.
            $members = $this->distinct($subscripts[1], array_fill(0, count($subscripts[1]), true));

            return $members === null ? null : ['key' => $parts['key'], 'shape' => 'array', 'members' => $members];
        }

        $grammar = $this->grammarMembers($target);

        return match (true) {
            $grammar === null && str_contains($target, '="') => null,
            $grammar === null => ['key' => $parts['key'], 'shape' => 'none', 'members' => []],
            default => ['key' => $parts['key'], 'shape' => 'grammar', 'members' => $grammar],
        };
    }

    /**
     * What a v3 cell declares its key collapses into: the notation, and each
     * member mapped to whether the ADR writes it with a value.
     *
     * The value-ness is half the declaration. `--min-coverage` takes
     * `endpoint=…` but a bare `strict`, so a target writing `strict=…` or a
     * bare `endpoint` is naming a setting the ADR does not describe, even
     * though both name a member that exists.
     *
     * @return array{shape: string, members: array<string, bool>}
     */
    private function declaredMembers(string $prose): array
    {
        preg_match_all("/'(\\w+)' =>/", $prose, $arrayLiteral);
        if ($arrayLiteral[1] !== []) {
            $members = $this->distinct($arrayLiteral[1], array_fill(0, count($arrayLiteral[1]), true));
            $this->assertNotNull($members, 'a key is declared twice in: ' . trim($prose));

            return ['shape' => 'array', 'members' => $members];
        }

        $grammar = $this->grammarMembers($prose);

        return $grammar === null
            ? ['shape' => 'none', 'members' => []]
            : ['shape' => 'grammar', 'members' => $grammar];
    }

    /**
     * The members named inside a collapsed `name=value,name` string — the one
     * grammar ADR 0005 gives a CLI flag that carries several settings — each
     * mapped to whether it is written with a value.
     *
     * `null` when the subject is not that grammar, or names a member twice.
     *
     * @return null|array<string, bool>
     */
    private function grammarMembers(string $subject): ?array
    {
        if (preg_match('/="([^"]*)"/', $subject, $grammar) !== 1) {
            return null;
        }

        $names = [];
        $values = [];

        foreach (explode(',', $grammar[1]) as $element) {
            $parts = explode('=', $element, 2);
            $name = trim($parts[0]);
            if ($name === '') {
                return null;
            }

            $names[] = $name;
            $values[] = count($parts) === 2;
        }

        return $this->distinct($names, $values);
    }

    /**
     * Zip names to their value-ness, or `null` when a name repeats.
     *
     * A member selected twice is a typo whichever way it is read, and folding
     * it into a map would hide it — `['endpoint']['endpoint']` would arrive
     * here as one perfectly ordinary member.
     *
     * @param list<string> $names
     * @param list<bool> $values
     *
     * @return null|array<string, bool>
     */
    private function distinct(array $names, array $values): ?array
    {
        $members = [];

        foreach ($names as $index => $name) {
            if (array_key_exists($name, $members)) {
                return null;
            }

            $members[$name] = $values[$index];
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
