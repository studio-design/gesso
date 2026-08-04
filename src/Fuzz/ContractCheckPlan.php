<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Spec\OpenApiOperationResolver;
use Studio\Gesso\Spec\OpenApiPathMatcher;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Validation\Request\SecuritySchemeIntrospector;
use Studio\Gesso\Validation\Request\SecurityValidator;
use Throwable;

use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_values;
use function count;
use function crc32;
use function get_debug_type;
use function get_object_vars;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function sprintf;
use function strtolower;

/**
 * Fluent, process-local plan that dispatches named negative contract checks
 * ({@see ContractCheck}) and collects every result into one
 * {@see ContractCheckSummary} instead of failing on the first probe.
 */
final class ContractCheckPlan
{
    use SelectsExploredOperations;

    /**
     * Value written wherever an `ignored_auth` invalid-credential probe needs
     * a token. Deliberately not a well-formed JWT or key: the probe asks
     * "does the API reject a credential it cannot have issued?".
     */
    private const INVALID_CREDENTIAL = 'gesso-invalid-credential';

    /** @var list<ContractCheck> */
    private array $checks = [];

    /** @var array<string, list<int>> keyed by ContractCheck value */
    private array $expectedStatusOverrides = [];

    /** @var array<string, list<int>> keyed by ContractCheck value */
    private array $expectedStatusClassOverrides = [];

    /** @var null|callable(ExploredOperation): void */
    private $authenticate;

    /** @var null|callable(ExploredOperation): void */
    private $setUp;

    /** @var null|callable(ExploredOperation): void */
    private $tearDown;

    /** @var null|callable(ExploredCase): mixed */
    private $dispatch;

    /** @var null|array<string, list<string>> templates grouped by each method name they document, built once per plan */
    private ?array $templatesByDocumentedMethod = null;

    /** @var array<string, ?OpenApiPathMatcher> one collision matcher over every template documenting a method, null when none compile */
    private array $methodMatchers = [];

    public function __construct(
        private readonly string $specName,
        private readonly int $seed,
    ) {}

    /** @param list<ContractCheck> $checks */
    public function checks(array $checks): self
    {
        if ($checks === []) {
            throw new InvalidArgumentException('checks() requires at least one ContractCheck.');
        }

        $deduplicated = [];
        foreach ($checks as $check) {
            $deduplicated[$check->value] = $check;
        }
        $this->checks = array_values($deduplicated);

        return $this;
    }

    /**
     * Replace the check's default expectation
     * ({@see ContractCheck::defaultExpectedStatuses()} plus
     * {@see ContractCheck::defaultExpectedStatusClasses()}) with an exact
     * status list. Overriding either side drops the other side's default, so
     * `expectedStatuses($check, [400])` means exactly 400 — call
     * {@see self::expectedStatusClasses()} as well to keep a class expectation.
     *
     * @param list<int> $statuses
     */
    public function expectedStatuses(ContractCheck $check, array $statuses): self
    {
        if ($statuses === []) {
            throw new InvalidArgumentException('expectedStatuses() requires at least one HTTP status.');
        }
        foreach ($statuses as $status) {
            if ($status < 100 || $status > 599) {
                throw new InvalidArgumentException(sprintf('Expected HTTP status must be between 100 and 599, got %d.', $status));
            }
        }
        $this->expectedStatusOverrides[$check->value] = $statuses;

        return $this;
    }

    /**
     * Replace the check's default expectation with an exact list of status
     * *classes* (`4` matches every 4xx). Same replace-both semantics as
     * {@see self::expectedStatuses()}.
     *
     * @param list<int> $statusClasses
     */
    public function expectedStatusClasses(ContractCheck $check, array $statusClasses): self
    {
        if ($statusClasses === []) {
            throw new InvalidArgumentException('expectedStatusClasses() requires at least one HTTP status class.');
        }
        foreach ($statusClasses as $statusClass) {
            if ($statusClass < 1 || $statusClass > 5) {
                throw new InvalidArgumentException(sprintf('Expected HTTP status class must be between 1 and 5, got %d.', $statusClass));
            }
        }
        $this->expectedStatusClassOverrides[$check->value] = $statusClasses;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function authenticateUsing(callable $callback): self
    {
        $this->authenticate = $callback;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function setUpUsing(callable $callback): self
    {
        $this->setUp = $callback;

        return $this;
    }

    /** @param callable(ExploredOperation): void $callback */
    public function tearDownUsing(callable $callback): self
    {
        $this->tearDown = $callback;

        return $this;
    }

    /** @param callable(ExploredCase): mixed $callback */
    public function dispatchUsing(callable $callback): self
    {
        $this->dispatch = $callback;

        return $this;
    }

    public function report(): ContractCheckSummary
    {
        if ($this->dispatch === null) {
            throw new InvalidArgumentException('The contract check plan requires dispatchUsing() before report().');
        }
        if ($this->checks === []) {
            throw new InvalidArgumentException('The contract check plan requires checks() with at least one check before report().');
        }

        $spec = OpenApiSpecLoader::load($this->specName);
        $paths = SpecPathsPreflight::validatedPaths($this->specName, $spec);

        $failures = [];
        $skips = [];
        /** @var array<string, true> */
        $probedPathSet = [];
        $dispatchedProbes = 0;
        $selected = 0;

        foreach ($paths as $path => $pathItem) {
            foreach ($this->checks as $check) {
                $derivedSeed = $this->derivedSeed($check, $path);
                $matching = [];
                foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                    $operation = ExploredOperation::fromDeclaration(
                        $this->specName,
                        $path,
                        $declared['method'],
                        $declared['operation'],
                        $derivedSeed,
                    );
                    if ($this->matchesFilters($operation)) {
                        $matching[] = [$operation, is_array($declared['operation']) ? $declared['operation'] : []];
                    }
                }
                if ($matching === []) {
                    continue;
                }
                $selected += count($matching);

                $probes = match ($check) {
                    ContractCheck::IgnoredAuth => $this->buildIgnoredAuthProbes($check, $path, $spec, $matching, $derivedSeed, $skips),
                    ContractCheck::MissingRequiredHeader => $this->buildMissingRequiredHeaderProbes($check, $path, $matching, $derivedSeed, $skips, $probedPathSet, $dispatchedProbes),
                    ContractCheck::UnsupportedMethod => $this->buildUnsupportedMethodProbes($check, $path, $pathItem, $matching, $derivedSeed, $skips, $paths),
                };

                [$expected, $expectedClasses] = $this->expectationFor($check);
                foreach ($probes as $probe) {
                    $probedPathSet[$path] = true;

                    $actualStatus = $this->dispatchProbe($check, $probe, $derivedSeed);
                    $dispatchedProbes++;

                    if (in_array($actualStatus, $expected, true) || in_array(intdiv($actualStatus, 100), $expectedClasses, true)) {
                        continue;
                    }
                    $failures[] = new ContractCheckFailure(
                        $check,
                        $probe->case->method->value,
                        $path,
                        $probe->operationId,
                        $expected,
                        $actualStatus,
                        $probe->case,
                        $expectedClasses,
                        $probe->mutation,
                    );
                }
            }
        }

        if ($selected === 0) {
            throw new InvalidArgumentException(sprintf(
                "Contract checks matched no operations in spec '%s'.",
                $this->specName,
            ));
        }

        return new ContractCheckSummary(count($probedPathSet), $dispatchedProbes, $failures, $skips);
    }

    /**
     * Whether the operation's effective `security` actually demands
     * credentials. Resolution precedence lives in
     * {@see SecurityValidator::effectiveSecurity()} so the probe and the
     * runtime validator cannot disagree about which declaration wins.
     *
     * A malformed declaration is a hard error here for the same reason it is
     * one in {@see SecurityValidator::validate()}: reading `security:
     * "not-a-list"` as "no authentication required" would turn a broken spec
     * into a green run with zero probes — the silent pass this check exists to
     * eliminate. Only the two well-formed spellings of "no credentials needed"
     * return false.
     *
     * @param array<string, mixed> $spec
     * @param array<string, mixed> $rawOperation
     *
     * @throws InvalidArgumentException when the effective `security` node is malformed
     */
    private static function requiresAuthentication(array $spec, array $rawOperation, ExploredOperation $operation): bool
    {
        $security = SecurityValidator::effectiveSecurity($spec, $rawOperation);
        if ($security === null) {
            return false;
        }
        if (!is_array($security) || !array_is_list($security)) {
            throw new InvalidArgumentException(self::malformedSecurityMessage(
                $operation,
                sprintf('operation/root-level `security` must be a list of requirement objects, got %s.', get_debug_type($security)),
            ));
        }
        // An explicit `security: []` opts the operation out of authentication.
        if ($security === []) {
            return false;
        }

        foreach ($security as $entryIndex => $entry) {
            // An empty requirement object is how OAS spells "credentials are
            // optional for this operation". Empty objects survive decoding as
            // stdClass (see SpecDocumentDecoder), so an empty *array* entry is
            // a JSON `[]` where an object belongs — malformed, exactly as the
            // runtime validator reads it.
            if ($entry instanceof stdClass) {
                if (get_object_vars($entry) === []) {
                    return false;
                }

                continue;
            }
            if (!is_array($entry) || $entry === []) {
                throw new InvalidArgumentException(self::malformedSecurityMessage($operation, sprintf(
                    'security requirement at index %s must be an object mapping scheme names to scope arrays, got %s.',
                    (string) $entryIndex,
                    $entry === [] ? 'an empty array' : get_debug_type($entry),
                )));
            }
        }

        return true;
    }

    private static function malformedSecurityMessage(ExploredOperation $operation, string $reason): string
    {
        return sprintf(
            "ignored_auth cannot decide whether %s '%s' requires authentication: %s Fix the spec — reading a malformed declaration as \"no authentication required\" would silently probe nothing.",
            $operation->method,
            $operation->path,
            $reason,
        );
    }

    /**
     * Strip credentials the generated case might carry. `Authorization` never
     * reaches a generated case (ParameterCollector drops it per OAS
     * §4.7.12.1), but an `apiKey` scheme can share its name with a declared
     * header or query parameter, and a probe that quietly ships one would not
     * be testing unauthenticated access at all.
     *
     * @param list<array{kind: 'apiKey', in: 'cookie'|'header'|'query', name: string}|array{kind: 'bearer'}> $credentials
     */
    private static function withoutCredentials(ExploredCase $case, array $credentials): ExploredCase
    {
        $headers = $case->headers;
        $query = $case->query;
        $cookies = $case->cookies;

        foreach ($credentials as $credential) {
            if ($credential['kind'] !== 'apiKey') {
                continue;
            }
            if ($credential['in'] === 'header') {
                foreach (array_keys($headers) as $name) {
                    if (strtolower($name) === strtolower($credential['name'])) {
                        unset($headers[$name]);
                    }
                }
            } elseif ($credential['in'] === 'query') {
                unset($query[$credential['name']]);
            } else {
                unset($cookies[$credential['name']]);
            }
        }

        return $case->withHeaders($headers)->withQuery($query)->withCookies($cookies);
    }

    /**
     * Write {@see self::INVALID_CREDENTIAL} into every credential location the
     * operation declares. All of them, not one: an AND-style requirement entry
     * is only exercised when each of its schemes is present-but-wrong.
     *
     * @param list<array{kind: 'apiKey', in: 'cookie'|'header'|'query', name: string}|array{kind: 'bearer'}> $credentials
     */
    private static function withInvalidCredentials(ExploredCase $case, array $credentials): ExploredCase
    {
        $headers = $case->headers;
        $query = $case->query;
        $cookies = $case->cookies;

        foreach ($credentials as $credential) {
            if ($credential['kind'] === 'bearer') {
                $headers['Authorization'] = 'Bearer ' . self::INVALID_CREDENTIAL;

                continue;
            }
            if ($credential['in'] === 'header') {
                $headers[$credential['name']] = self::INVALID_CREDENTIAL;
            } elseif ($credential['in'] === 'query') {
                $query[$credential['name']] = self::INVALID_CREDENTIAL;
            } else {
                // A `Cookie:` request header is the wire form, but test clients
                // build cookies from a separate argument, so writing the header
                // alone would leave `$request->cookie(...)` empty and the probe
                // indistinguishable from the no-credential one.
                $cookies[$credential['name']] = self::INVALID_CREDENTIAL;
            }
        }

        return $case->withHeaders($headers)->withQuery($query)->withCookies($cookies);
    }

    private function derivedSeed(ContractCheck $check, string $path): int
    {
        return crc32(implode("\0", [$this->specName, $check->value, $path, (string) $this->seed])) & 0x7fffffff;
    }

    /**
     * Pass statuses and status classes for one check. An override on either
     * side replaces the whole default expectation, so an exact
     * `expectedStatuses()` list is not silently widened by the check's default
     * class (and vice versa).
     *
     * @return array{list<int>, list<int>}
     */
    private function expectationFor(ContractCheck $check): array
    {
        $statuses = $this->expectedStatusOverrides[$check->value] ?? null;
        $classes = $this->expectedStatusClassOverrides[$check->value] ?? null;

        if ($statuses === null && $classes === null) {
            return [$check->defaultExpectedStatuses(), $check->defaultExpectedStatusClasses()];
        }

        return [$statuses ?? [], $classes ?? []];
    }

    /**
     * Probe every selected operation that actually requires authentication
     * twice: once with no credentials at all, once with credentials the API
     * cannot have issued. Both probes bypass the plan's `authenticateUsing()`
     * hook — running it would hand the request the very credentials the check
     * exists to withhold.
     *
     * Operations with no effective `security`, or whose requirement list
     * contains an empty entry (the OAS spelling of "credentials optional
     * here"), are skipped: there is no enforcement to ignore.
     *
     * The invalid-credential probe needs to know *where* a credential goes,
     * which {@see SecuritySchemeIntrospector} answers only for the schemes
     * this library can locate (`http` + `bearer`, `apiKey`). Operations
     * secured solely by oauth2 / openIdConnect / mutualTLS / http-basic-style
     * schemes get the no-credential probe plus a skip for the other one,
     * rather than a fabricated credential in a guessed location.
     *
     * @param array<string, mixed> $spec
     * @param non-empty-list<array{ExploredOperation, array<string, mixed>}> $matching
     * @param list<ContractCheckSkip> $skips
     *
     * @return list<ContractCheckProbe>
     */
    private function buildIgnoredAuthProbes(
        ContractCheck $check,
        string $path,
        array $spec,
        array $matching,
        int $derivedSeed,
        array &$skips,
    ): array {
        $probes = [];

        foreach ($matching as [$operation, $rawOperation]) {
            if (!self::requiresAuthentication($spec, $rawOperation, $operation)) {
                $skips[] = new ContractCheckSkip(
                    $check,
                    $path,
                    $operation->method,
                    'Operation has no effective security requirement (or declares an entry permitting unauthenticated access); there is no authentication to ignore.',
                );

                continue;
            }
            if (!$this->supportsExplorerMethod($check, $path, $operation, $skips)) {
                continue;
            }

            $case = $this->validCaseFor($check, $path, $operation, $derivedSeed, $skips);
            if ($case === null) {
                continue;
            }

            $credentials = (new SecuritySchemeIntrospector())->injectableCredentialsFor($spec, $rawOperation);
            $unauthenticated = self::withoutCredentials($case, $credentials);
            $probes[] = new ContractCheckProbe(
                $unauthenticated,
                $operation,
                $operation->operationId,
                'no credentials',
                authenticate: false,
            );

            if ($credentials === []) {
                $skips[] = new ContractCheckSkip(
                    $check,
                    $path,
                    $operation->method,
                    'No credential location this library can synthesize (the operation is secured only by oauth2 / openIdConnect / mutualTLS / non-bearer http schemes), so the invalid-credential probe was not dispatched; the no-credential probe still ran.',
                );

                continue;
            }

            $probes[] = new ContractCheckProbe(
                self::withInvalidCredentials($unauthenticated, $credentials),
                $operation,
                $operation->operationId,
                'invalid credentials',
                authenticate: false,
            );
        }

        return $probes;
    }

    /**
     * One probe per `required: true` header parameter, each omitting exactly
     * that header from an otherwise-valid case so the failing status names the
     * header that was not enforced.
     *
     * Every omission probe is gated behind a control request: the *unmutated*
     * valid case, dispatched first. Accepting any 4xx is only meaningful once
     * the un-omitted request is known to reach the handler — otherwise an
     * operation that never enforces the header still "passes" because the
     * request was rejected for an unrelated reason (no credentials configured,
     * a missing fixture, a 404). When the control does not succeed, the
     * operation is skipped with the control's status in the reason rather than
     * scored green.
     *
     * @param non-empty-list<array{ExploredOperation, array<string, mixed>}> $matching
     * @param list<ContractCheckSkip> $skips
     * @param array<string, true> $probedPathSet
     *
     * @return list<ContractCheckProbe>
     */
    private function buildMissingRequiredHeaderProbes(
        ContractCheck $check,
        string $path,
        array $matching,
        int $derivedSeed,
        array &$skips,
        array &$probedPathSet,
        int &$dispatchedProbes,
    ): array {
        $probes = [];

        foreach ($matching as [$operation, $_rawOperation]) {
            if (!$this->supportsExplorerMethod($check, $path, $operation, $skips)) {
                continue;
            }

            try {
                $requiredHeaders = OpenApiEndpointExplorer::requiredHeaderNames($this->specName, $operation->method, $operation->path);
            } catch (InvalidArgumentException $e) {
                $skips[] = new ContractCheckSkip($check, $path, $operation->method, $e->getMessage());

                continue;
            }
            if ($requiredHeaders === []) {
                $skips[] = new ContractCheckSkip(
                    $check,
                    $path,
                    $operation->method,
                    'Operation declares no required in:header parameter; there is nothing to omit.',
                );

                continue;
            }

            // Deferred until a header is known to exist: generating a full
            // valid case can skip an operation whose body is not synthesizable,
            // and that skip would be noise for an operation this check has no
            // work for anyway.
            $case = $this->validCaseFor($check, $path, $operation, $derivedSeed, $skips);
            if ($case === null) {
                continue;
            }

            $probedPathSet[$path] = true;
            $controlStatus = $this->dispatchProbe($check, new ContractCheckProbe(
                $case,
                $operation,
                $operation->operationId,
                'control: unmutated valid request',
            ), $derivedSeed);
            $dispatchedProbes++;

            if ($controlStatus >= 400) {
                $skips[] = new ContractCheckSkip($check, $path, $operation->method, sprintf(
                    'The unmutated valid request answered %d, so it never reached the handler whose header enforcement this check tests; every omission probe would have been scored as a pass for an unrelated reason. Configure setUpUsing()/authenticateUsing() so the valid request succeeds.',
                    $controlStatus,
                ));

                continue;
            }

            foreach ($requiredHeaders as $name) {
                if (!array_key_exists($name, $case->headers)) {
                    $skips[] = new ContractCheckSkip($check, $path, $operation->method, sprintf(
                        "Required header parameter '%s' has no generated value, so there is nothing to omit.",
                        $name,
                    ));

                    continue;
                }

                $headers = $case->headers;
                unset($headers[$name]);
                $probes[] = new ContractCheckProbe(
                    $case->withHeaders($headers),
                    $operation,
                    $operation->operationId,
                    sprintf("omitted required header '%s'", $name),
                );
            }
        }

        return $probes;
    }

    /** @param list<ContractCheckSkip> $skips */
    private function supportsExplorerMethod(ContractCheck $check, string $path, ExploredOperation $operation, array &$skips): bool
    {
        if (HttpMethod::tryFrom($operation->method) !== null) {
            return true;
        }

        $skips[] = new ContractCheckSkip($check, $path, $operation->method, sprintf(
            'HTTP method is not supported by the explorer. Supported: %s.',
            HttpMethod::listOfValues(),
        ));

        return false;
    }

    /**
     * First valid case for a documented operation, or null with a recorded
     * skip when its inputs cannot be synthesized. Unlike the
     * `unsupported_method` probe, the per-operation checks dispatch the
     * documented request itself, so body and parameter generatability really
     * does gate them.
     *
     * @param list<ContractCheckSkip> $skips
     */
    private function validCaseFor(
        ContractCheck $check,
        string $path,
        ExploredOperation $operation,
        int $derivedSeed,
        array &$skips,
    ): ?ExploredCase {
        try {
            $cases = OpenApiEndpointExplorer::explore($this->specName, $operation->method, $operation->path, 1, $derivedSeed);
        } catch (InvalidArgumentException $e) {
            $skips[] = new ContractCheckSkip($check, $path, $operation->method, $e->getMessage());

            return null;
        } catch (Throwable $e) {
            throw new RuntimeException($this->generationFailureMessage($check, $operation, $derivedSeed, $e), 0, $e);
        }

        return $cases->cases[0];
    }

    /**
     * Choose one undocumented explorable method for the path and build the
     * probe case from a documented operation's concrete path parameters. The
     * probe sends no body, query, or headers, so only path-parameter
     * generatability gates it — a documented operation whose request body or
     * other inputs cannot be synthesized must not cause a skip (issue #439).
     * `additionalOperations` names are never probed themselves (case-sensitive
     * custom methods), but every declared name counts as documented and is
     * excluded from the candidates.
     *
     * The concrete probe URI can also be an instance of a different documented
     * template (`/members/me` alongside `/members/{member_id}`), and the
     * application would route the probe to that template's operation. Methods
     * documented by any colliding template are therefore excluded too; when
     * none survive, the path is skipped instead of reporting a failure the
     * spec does not contain.
     *
     * @param array<string, mixed> $pathItem
     * @param non-empty-list<array{ExploredOperation, array<string, mixed>}> $matching
     * @param list<ContractCheckSkip> $skips
     * @param array<string, array<string, mixed>> $paths every documented path item, unfiltered
     *
     * @return list<ContractCheckProbe>
     */
    private function buildUnsupportedMethodProbes(
        ContractCheck $check,
        string $path,
        array $pathItem,
        array $matching,
        int $derivedSeed,
        array &$skips,
        array $paths,
    ): array {
        // Every declared method name counts as documented: fixed fields under
        // their canonical uppercase key, additionalOperations entries verbatim.
        // OAS 3.2 forbids additionalOperations entries that spell a fixed
        // method ("PUT"), but the runtime resolver honors them case-sensitively,
        // so the probe must too — otherwise a documented method would be
        // reported as an unsupported-method contract failure.
        $documented = [];
        foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
            $documented[] = $declared['method'];
        }

        $candidates = [];
        foreach (HttpMethod::cases() as $method) {
            if (!in_array($method->value, $documented, true)) {
                $candidates[] = $method;
            }
        }
        if ($candidates === []) {
            $skips[] = new ContractCheckSkip(
                $check,
                $path,
                null,
                'Every explorable HTTP method is documented for this path; no undocumented method to probe.',
            );

            return [];
        }

        $lastReason = null;
        foreach ($matching as [$operation, $_rawOperation]) {
            if (HttpMethod::tryFrom($operation->method) === null) {
                $lastReason ??= sprintf(
                    'No documented operation with an explorer-supported method (%s) is available to generate concrete path parameters.',
                    HttpMethod::listOfValues(),
                );

                continue;
            }

            try {
                $cases = OpenApiEndpointExplorer::exploreUriOnly($this->specName, $operation->method, $operation->path, 1, $derivedSeed);
            } catch (InvalidArgumentException $e) {
                $lastReason = $e->getMessage();

                continue;
            } catch (Throwable $e) {
                throw new RuntimeException($this->generationFailureMessage($check, $operation, $derivedSeed, $e), 0, $e);
            }

            foreach ($cases as $sourceCase) {
                $concretePath = $sourceCase->uri();
                $collisions = $this->collidingDocumentedMethods($concretePath, $paths, $candidates);

                $safeCandidates = [];
                foreach ($candidates as $candidate) {
                    if (!isset($collisions[$candidate->value])) {
                        $safeCandidates[] = $candidate;
                    }
                }
                if ($safeCandidates === []) {
                    $collisionDetails = [];
                    foreach ($candidates as $candidate) {
                        $collisionDetails[] = sprintf("%s is documented by '%s'", $candidate->value, $collisions[$candidate->value]);
                    }
                    $skips[] = new ContractCheckSkip($check, $path, null, sprintf(
                        "The concrete probe URI '%s' is also an instance of other documented path templates that declare every undocumented method for this path (%s); a probe would be routed to a documented operation and proves nothing.",
                        $concretePath,
                        implode(', ', $collisionDetails),
                    ));

                    return [];
                }

                return [new ContractCheckProbe(
                    new ExploredCase(
                        null,
                        [],
                        [],
                        $sourceCase->pathParams,
                        $safeCandidates[$derivedSeed % count($safeCandidates)],
                        $path,
                        seed: $derivedSeed,
                        caseIndex: 0,
                    ),
                    $operation,
                    // Path-level check: the probe's method has no documented
                    // operation by construction, so there is no operationId
                    // to name on a failure.
                    operationId: null,
                )];
            }
        }

        $skips[] = new ContractCheckSkip($check, $path, null, $lastReason ?? 'No documented operation could generate concrete path parameters.');

        return [];
    }

    /**
     * Candidate methods that a probe against `$concretePath` cannot disprove:
     * a candidate collides when a documented template also matches the
     * concrete URI and declares it. Candidates are undocumented on the probed
     * template by construction, so that template never appears in its
     * candidates' matchers and any match is a genuine collision. One matcher
     * per method keeps the scan at a handful of match calls per probe instead
     * of one per documented template.
     *
     * @param array<string, array<string, mixed>> $paths
     * @param non-empty-list<HttpMethod> $candidates
     *
     * @return array<string, string> candidate method name => colliding template declaring it
     */
    private function collidingDocumentedMethods(string $concretePath, array $paths, array $candidates): array
    {
        $collisions = [];
        foreach ($candidates as $candidate) {
            $template = $this->methodMatcher($candidate->value, $paths)?->match($concretePath);
            if ($template !== null) {
                $collisions[$candidate->value] = $template;
            }
        }

        return $collisions;
    }

    /** @param array<string, array<string, mixed>> $paths */
    private function methodMatcher(string $method, array $paths): ?OpenApiPathMatcher
    {
        if ($this->templatesByDocumentedMethod === null) {
            // Keyed by template to deduplicate a (spec-invalid but
            // runtime-honored) fixed field repeated under additionalOperations.
            $grouped = [];
            foreach ($paths as $template => $pathItem) {
                foreach (OpenApiOperationResolver::declaredOperations($pathItem) as $declared) {
                    $grouped[$declared['method']][$template] = true;
                }
            }

            $this->templatesByDocumentedMethod = [];
            foreach ($grouped as $documentedMethod => $templates) {
                $this->templatesByDocumentedMethod[$documentedMethod] = array_keys($templates);
            }
        }

        if (!array_key_exists($method, $this->methodMatchers)) {
            $this->methodMatchers[$method] = $this->compileCollisionMatcher(
                $this->templatesByDocumentedMethod[$method] ?? [],
            );
        }

        return $this->methodMatchers[$method];
    }

    /** @param list<string> $templates */
    private function compileCollisionMatcher(array $templates): ?OpenApiPathMatcher
    {
        if ($templates === []) {
            return null;
        }

        try {
            return new OpenApiPathMatcher($templates);
        } catch (InvalidArgumentException) {
            // At least one template cannot compile (e.g. duplicate placeholder
            // names). Such a template cannot route a request, so it cannot
            // collide either; retry with the ones the matcher accepts.
        }

        $compilable = [];
        foreach ($templates as $template) {
            try {
                new OpenApiPathMatcher([$template]);
                $compilable[] = $template;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return $compilable === [] ? null : new OpenApiPathMatcher($compilable);
    }

    private function generationFailureMessage(
        ContractCheck $check,
        ExploredOperation $operation,
        int $derivedSeed,
        Throwable $failure,
    ): string {
        return sprintf(
            "Contract check input generation failed.\nCheck: %s\nSpec: %s\nOperation: %s\nMethod/path: %s %s\nGlobal seed: %d\nDerived seed: %d\nMessage: %s",
            $check->value,
            $this->specName,
            $operation->operationId ?? '(none)',
            $operation->method,
            $operation->path,
            $this->seed,
            $derivedSeed,
            $failure->getMessage(),
        );
    }

    private function dispatchProbe(ContractCheck $check, ContractCheckProbe $probe, int $derivedSeed): int
    {
        $case = $probe->case;
        $operation = $probe->operation;

        try {
            if ($this->setUp !== null) {
                ($this->setUp)($operation);
            }
            // Probes that exist to prove authentication is enforced must not
            // be handed credentials by the plan's own hook.
            if ($probe->authenticate && $this->authenticate !== null) {
                ($this->authenticate)($operation);
            }

            try {
                $response = ($this->dispatch)($case);

                return ResponseStatusExtractor::extract($response);
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf(
                    "Contract check dispatch failed.\nCheck: %s\nSpec: %s\nMethod/path: %s %s\nGlobal seed: %d\nDerived seed: %d\nCurl: %s",
                    $check->value,
                    $this->specName,
                    $case->method->value,
                    $case->matchedPath,
                    $this->seed,
                    $derivedSeed,
                    $case->curlSnippet(),
                ), 0, $e);
            }
        } finally {
            if ($this->tearDown !== null) {
                ($this->tearDown)($operation);
            }
        }
    }
}
