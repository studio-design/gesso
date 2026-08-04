<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Integration\Conformance;

use const E_USER_WARNING;
use const JSON_THROW_ON_ERROR;

use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\OpenApiVersion;
use Studio\Gesso\Spec\OpenApiSchemaConverter;
use Studio\Gesso\Validation\Support\ObjectConverter;
use Throwable;

use function array_keys;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function in_array;
use function is_array;
use function is_dir;
use function json_decode;
use function json_encode;
use function ksort;
use function restore_error_handler;
use function set_error_handler;
use function sort;
use function sprintf;

/**
 * Conformance signal for the OpenAPI-to-JSON-Schema conversion layer
 * (issue #283).
 *
 * Cases from the official JSON Schema Test Suite are validated twice: once
 * against the bare schema with opis alone, and once after
 * {@see OpenApiSchemaConverter::convert()} has rewritten it. Only the cases
 * where the two verdicts differ are recorded, and that recorded set is pinned
 * by a committed baseline.
 *
 * Two kinds of case are never compared: the groups in
 * {@see self::EXCLUDED_GROUPS}, and boolean (`true` / `false`) root schemas,
 * which a Schema Object cannot express, so `convert()` has no counterpart to
 * produce and there is no second verdict. Both are counted and published in the
 * baseline rather than dropped — 3,784 of the corpus's 3,824 cases are actually
 * compared.
 *
 * This deliberately does NOT re-measure how conformant opis is — that is
 * published independently at https://bowtie.report via the
 * `bowtie-json-schema/php-opis-json-schema` harness. What it measures is the
 * delta this package introduces on top of opis, which is the part no external
 * report covers. An unexplained entry in the delta set is, by construction, a
 * conversion bug; an intentional one is recorded with its reason.
 *
 * The corpus is pinned by commit SHA in `composer.json` (the lock file is not
 * committed for a library, so `composer.json` is the pin). See
 * `docs/conformance.md`.
 */
final class JsonSchemaConversionDeltaTest extends TestCase
{
    /**
     * Suite directory => the OAS version whose conversion pipeline targets
     * that JSON Schema draft, plus the draft opis assumes for the bare run.
     *
     * OAS 3.0 lowers to Draft 07; 3.1/3.2 stay on 2020-12 and share one
     * pipeline, so 3.2 would produce an identical delta set and is not run
     * twice.
     */
    private const SUITES = [
        'draft7' => ['version' => OpenApiVersion::V3_0, 'draft' => '07'],
        'draft2020-12' => ['version' => OpenApiVersion::V3_1, 'draft' => '2020-12'],
    ];

    /**
     * Groups skipped before they run, because bare opis — with no involvement
     * from this package — recurses without bound on them and exhausts the
     * stack. That is a fatal error PHP cannot catch, so it has to be excluded
     * statically rather than handled. Both are `$dynamicRef` inside an
     * `unevaluated*` applicator.
     *
     * Recorded in the baseline as well, so the exclusion is visible in the
     * published result instead of silently shrinking the corpus.
     */
    private const EXCLUDED_GROUPS = [
        'draft2020-12::unevaluatedItems.json::unevaluatedItems with $dynamicRef',
        'draft2020-12::unevaluatedProperties.json::unevaluatedProperties with $dynamicRef',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        OpenApiSchemaConverter::resetWarningStateForTesting();
    }

    protected function tearDown(): void
    {
        OpenApiSchemaConverter::resetWarningStateForTesting();

        parent::tearDown();
    }

    #[Test]
    public function conversion_changes_no_json_schema_verdict_beyond_the_recorded_baseline(): void
    {
        $baseline = $this->baseline();
        $observed = $this->measure();

        $this->assertSame(
            $baseline['corpus']['commit'],
            $this->installedCorpusCommit(),
            'The installed corpus does not match the commit the baseline was recorded against. '
            . 'Re-pin composer.json or regenerate the baseline.',
        );

        // Compare only the verdict triple. `reason` is prose maintained by
        // hand next to each entry and is asserted separately, so rewording an
        // explanation cannot turn into a false conformance regression.
        $expectedDeltas = [];
        foreach ($baseline['deltas'] as $key => $entry) {
            $expectedDeltas[$key] = [
                'expected' => $entry['expected'],
                'bare' => $entry['bare'],
                'converted' => $entry['converted'],
            ];
        }

        $this->assertSame(
            $expectedDeltas,
            $observed['deltas'],
            'The set of JSON Schema cases whose verdict changes under OpenAPI schema conversion has moved. '
            . 'A new entry is a conversion regression unless it is deliberate; a disappeared entry means a '
            . 'documented limitation was fixed. Either way, update '
            . 'tests/fixtures/compatibility/v1-json-schema-conversion-delta.json and docs/conformance.md.',
        );

        $this->assertSame(
            $baseline['suites'],
            $observed['suites'],
            'The corpus case counts moved without the pinned commit changing.',
        );
    }

    #[Test]
    public function every_recorded_delta_and_exclusion_carries_a_reason(): void
    {
        $baseline = $this->baseline();

        // Reasons are shared: 115 of the recorded deltas are one defect, and
        // repeating its explanation per entry would make the file unreadable
        // without making it more precise. Keys stay per case, so a genuinely
        // new case still fails the comparison above.
        foreach ($baseline['reasons'] as $key => $reason) {
            $this->assertNotSame('', $reason['summary'] ?? '', sprintf('Reason "%s" has no summary.', $key));
        }

        $referenced = [];
        foreach ($baseline['deltas'] as $key => $entry) {
            $reason = $entry['reason'] ?? '';
            $this->assertArrayHasKey(
                $reason,
                $baseline['reasons'],
                sprintf('Delta "%s" cites unknown reason "%s".', $key, $reason),
            );
            $referenced[$reason] = true;
        }

        foreach ($baseline['excluded_groups'] as $entry) {
            $this->assertNotSame('', $entry['reason'] ?? '', sprintf('Exclusion "%s" has no reason.', $entry['group']));
        }

        $published = array_keys($baseline['reasons']);
        $cited = array_keys($referenced);
        sort($published);
        sort($cited);

        $this->assertSame(
            $published,
            $cited,
            'A published reason is no longer cited by any delta. Remove reasons that no longer apply '
            . 'instead of leaving them to rot.',
        );

        $this->assertSame(
            self::EXCLUDED_GROUPS,
            array_keys($baseline['excluded_groups']),
            'The statically excluded groups and the published exclusion list have drifted apart.',
        );
    }

    /**
     * @return array{
     *     deltas: array<string, array{expected: string, bare: string, converted: string}>,
     *     suites: array<string, array{cases: int, boolean_root_schemas: int, excluded_cases: int, deltas: int}>
     * }
     */
    private function measure(): array
    {
        $deltas = [];
        $suites = [];

        foreach (self::SUITES as $suiteName => $suite) {
            $validator = $this->validatorFor($suite['draft']);

            $cases = 0;
            $booleanRootSchemas = 0;
            $excludedCases = 0;
            $suiteDeltas = 0;

            foreach ($this->suiteFiles($suiteName) as $file) {
                $contents = file_get_contents($file);
                $this->assertIsString($contents, sprintf('Unreadable corpus file: %s.', $file));

                // Decoded twice on purpose. The object form is the faithful
                // JSON: `{}` stays an object and `[]` stays a list, a
                // distinction PHP associative arrays cannot carry. It is what
                // the bare reference run and both data instances are built
                // from, so the reference side is never distorted by the way
                // this test reads the corpus.
                //
                // The array form exists only because `convert()` takes a
                // Schema Object as an array — the same shape
                // `OpenApiSpecLoader` produces with `json_decode(..., true)`.
                // Any object/list information the OAS pipeline loses is
                // therefore lost here too, and shows up as a recorded delta
                // instead of being hidden behind an identical exception on
                // both sides.
                /** @var list<object{description: string, schema: mixed, tests: list<object{description: string, data: mixed, valid: bool}>}> $groups */
                $groups = json_decode($contents, false, flags: JSON_THROW_ON_ERROR);
                /** @var list<array{description: string, schema: mixed, tests: list<array{description: string, data: mixed, valid: bool}>}> $arrayGroups */
                $arrayGroups = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

                foreach ($groups as $groupIndex => $group) {
                    $groupKey = $suiteName . '::' . basename($file) . '::' . $group->description;

                    if (in_array($groupKey, self::EXCLUDED_GROUPS, true)) {
                        $excludedCases += count($group->tests);
                        continue;
                    }

                    foreach ($group->tests as $case) {
                        $cases++;

                        // `convert()` takes a Schema Object; a bare `true` /
                        // `false` root schema has nothing for it to rewrite,
                        // so there is no delta to measure. Counted rather than
                        // dropped.
                        if (!is_array($arrayGroups[$groupIndex]['schema'])) {
                            $booleanRootSchemas++;
                            continue;
                        }

                        // A separate instance per run. opis writes `default`
                        // values into the instance it validates, so sharing
                        // one would let the bare run's mutation reach the
                        // converted run and register as a delta conversion
                        // never caused.
                        $bare = $this->verdict($validator, $group->schema, $this->instance($case->data));
                        $converted = $this->convertedVerdict(
                            $validator,
                            $arrayGroups[$groupIndex]['schema'],
                            $suite['version'],
                            $this->instance($case->data),
                        );

                        if ($bare === $converted) {
                            continue;
                        }

                        $suiteDeltas++;
                        $deltas[$groupKey . '::' . $case->description] = [
                            'expected' => $case->valid ? 'valid' : 'invalid',
                            'bare' => $bare,
                            'converted' => $converted,
                        ];
                    }
                }
            }

            $suites[$suiteName] = [
                'cases' => $cases,
                'boolean_root_schemas' => $booleanRootSchemas,
                'excluded_cases' => $excludedCases,
                'deltas' => $suiteDeltas,
            ];
        }

        ksort($deltas);

        return ['deltas' => $deltas, 'suites' => $suites];
    }

    /**
     * Run the schema through the OpenAPI conversion pipeline, then validate.
     *
     * The converter uses `E_USER_WARNING` as its loud-signal channel (unknown
     * `format` values, 3.0-only keywords). Under `failOnWarning="true"` those
     * would abort the run, and they are not what this test measures — the
     * verdict is. They are swallowed here and the dedup state is reset per
     * schema so one early warning cannot mask a later case.
     *
     * @param array<string, mixed> $schema
     */
    private function convertedVerdict(Validator $validator, array $schema, OpenApiVersion $version, mixed $data): string
    {
        set_error_handler(static fn(): bool => true, E_USER_WARNING);

        try {
            $converted = OpenApiSchemaConverter::convert($schema, $version);
        } catch (Throwable $exception) {
            return 'convert-error:' . $exception::class;
        } finally {
            restore_error_handler();
            OpenApiSchemaConverter::resetWarningStateForTesting();
        }

        return $this->verdict($validator, ObjectConverter::convert($converted), $data);
    }

    private function verdict(Validator $validator, mixed $schema, mixed $data): string
    {
        try {
            // opis registers every `$id`-bearing schema in a process-wide
            // loader cache. Without clearing it, validating the converted form
            // of a case whose bare form was just registered collides on the
            // same `$id` (DuplicateSchemaIdException) and would be reported as
            // a conversion delta that does not exist. opis's own suite runner
            // clears between schema groups for the same reason.
            $validator->loader()->clearCache();

            return $validator->validate($data, $schema)->isValid() ? 'valid' : 'invalid';
        } catch (Throwable $exception) {
            return 'error:' . $exception::class;
        }
    }

    /**
     * The suite expects `remotes/` to be served at `http://localhost:1234/`;
     * without that mapping every remote `$ref` raises the same unresolved-
     * reference error on both sides and 54 cases across `refRemote.json`
     * assert nothing at all.
     */
    private function validatorFor(string $draft): Validator
    {
        $validator = new Validator();
        $validator->parser()->setDefaultDraftVersion($draft);
        $validator->resolver()?->registerPrefix('http://localhost:1234/', $this->corpusPath() . '/remotes');

        return $validator;
    }

    /**
     * A private copy of the case data, decoded from the faithful object form
     * so `{}` and `[]` stay distinct. opis mutates the instance it validates
     * (it writes `default` values into it), so each verdict has to own its
     * own.
     */
    private function instance(mixed $data): mixed
    {
        return json_decode(json_encode($data, flags: JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Required cases plus the suite's `optional/` and `optional/format/`
     * directories. `optional/` is where `format`, big numbers, and unknown
     * keywords live — all areas the converter actively rewrites — so leaving
     * them out would hide exactly the behavior this test exists to pin.
     *
     * @return list<string>
     */
    private function suiteFiles(string $suite): array
    {
        $root = $this->corpusPath() . '/tests/' . $suite;
        $this->assertDirectoryExists($root, sprintf('Corpus suite "%s" is missing.', $suite));

        $files = [];
        foreach (['', '/optional', '/optional/format'] as $subdirectory) {
            $directory = $root . $subdirectory;
            if (!is_dir($directory)) {
                continue;
            }

            $matches = glob($directory . '/*.json');
            $this->assertIsArray($matches);
            foreach ($matches as $match) {
                $files[] = $match;
            }
        }

        $this->assertNotEmpty($files, sprintf('Corpus suite "%s" yielded no files.', $suite));

        return $files;
    }

    private function installedCorpusCommit(): string
    {
        $installed = file_get_contents($this->corpusPath() . '/../../composer/installed.json');
        $this->assertIsString($installed, 'Cannot read vendor/composer/installed.json.');

        /** @var array{packages: list<array{name: string, dist?: array{reference?: string}}>} $decoded */
        $decoded = json_decode($installed, true, flags: JSON_THROW_ON_ERROR);

        foreach ($decoded['packages'] as $package) {
            if ($package['name'] === 'json-schema-org/json-schema-test-suite') {
                return $package['dist']['reference'] ?? '';
            }
        }

        $this->fail('The JSON Schema Test Suite corpus is not installed.');
    }

    private function corpusPath(): string
    {
        $path = __DIR__ . '/../../../vendor/json-schema-org/json-schema-test-suite';
        $this->assertDirectoryExists($path, 'Run `composer install` to fetch the pinned JSON Schema Test Suite.');

        return $path;
    }

    /**
     * @return array{
     *     corpus: array{commit: string},
     *     reasons: array<string, array{summary?: string}>,
     *     deltas: array<string, array{expected: string, bare: string, converted: string, reason?: string}>,
     *     excluded_groups: array<string, array{group: string, reason?: string}>,
     *     suites: array<string, array{cases: int, boolean_root_schemas: int, excluded_cases: int, deltas: int}>
     * }
     */
    private function baseline(): array
    {
        $path = __DIR__ . '/../../fixtures/compatibility/v1-json-schema-conversion-delta.json';
        $contents = file_get_contents($path);
        $this->assertIsString($contents, 'Missing conformance baseline.');

        return json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
