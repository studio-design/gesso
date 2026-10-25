<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

use const PATHINFO_EXTENSION;

use stdClass;
use Studio\Gesso\Exception\InvalidOpenApiSpecException;
use Studio\Gesso\Internal\SpecDocumentDecoder;
use Studio\Gesso\Spec\OpenApiRefResolver;

use function array_filter;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function count;
use function dirname;
use function file_get_contents;
use function get_debug_type;
use function get_object_vars;
use function implode;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function pathinfo;
use function preg_match;
use function rawurldecode;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strtolower;

/**
 * A parsed and structurally validated Arazzo 1.1 description. Parsing is
 * pure: it never loads a source description, never touches
 * OpenApiSpecLoader and never reaches the network.
 *
 * `baseUri` is the document location relative source URLs resolve against
 * (their directory is the join point): the file's own path when `$self` is
 * absent, the file-relative `$self` path when `$self` is relative, or the
 * absolute `$self` URL. Local locations are filesystem paths, not `file:`
 * URIs.
 *
 * @internal Arazzo runner implementation detail; not public API.
 */
final readonly class ArazzoDescription
{
    private const NAME_PATTERN = '/^[A-Za-z0-9_-]+$/D';
    private const SOURCE_TYPES = ['openapi', 'asyncapi', 'arazzo'];

    /**
     * @param array<string, ArazzoSourceDescription> $sources keyed by name, declaration order
     * @param array<string, array<string, mixed>> $workflows raw workflow objects keyed by workflowId
     * @param array<string, mixed> $components raw `components` object
     */
    private function __construct(
        public string $path,
        public string $baseUri,
        public string $arazzoVersion,
        public array $sources,
        public array $workflows,
        public array $components,
    ) {}

    /**
     * @throws InvalidArazzoDescriptionException
     */
    public static function fromFile(string $path): self
    {
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw InvalidArazzoDescriptionException::single(ArazzoDescriptionReason::FileNotFound, sprintf('Arazzo description not found: %s', $path), $path);
        }

        $extension = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        if (!in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw InvalidArazzoDescriptionException::single(ArazzoDescriptionReason::UnsupportedExtension, sprintf('Unsupported Arazzo description extension .%s; use .json, .yaml or .yml.', $extension), $real);
        }

        $content = (string) file_get_contents($real);

        try {
            $document = $extension === 'json'
                ? SpecDocumentDecoder::decodeJson($content, $real, ['payload'])
                : SpecDocumentDecoder::decodeYaml($content, $real, ['payload']);
        } catch (InvalidOpenApiSpecException $e) {
            throw InvalidArazzoDescriptionException::single(
                ArazzoDescriptionReason::MalformedDocument,
                sprintf('Arazzo description does not decode: %s', $e->getPrevious()?->getMessage() ?? $e->getMessage()),
                $real,
                $e,
            );
        }

        if (self::isNonEmptyList($document)) {
            throw InvalidArazzoDescriptionException::single(ArazzoDescriptionReason::MalformedDocument, 'Arazzo description root must be an object, got a list.', $real);
        }

        return self::fromDocument($document, $real);
    }

    public static function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1;
    }

    /**
     * A JSON object as decoded by SpecDocumentDecoder: a non-list array, an
     * empty array, or a retained empty `stdClass`.
     */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && ($value === [] || !array_is_list($value)));
    }

    public static function isNonEmptyList(mixed $value): bool
    {
        return is_array($value) && $value !== [] && array_is_list($value);
    }

    /**
     * @return array<array-key, mixed>
     */
    public static function members(mixed $value): array
    {
        return $value instanceof stdClass ? get_object_vars($value) : (is_array($value) ? $value : []);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidArazzoDescriptionException UnknownWorkflow
     */
    public function workflow(string $workflowId): array
    {
        return $this->workflows[$workflowId] ?? throw InvalidArazzoDescriptionException::single(
            ArazzoDescriptionReason::UnknownWorkflow,
            sprintf("Unknown workflowId '%s'; the description declares: %s.", $workflowId, implode(', ', array_keys($this->workflows))),
            $this->path,
        );
    }

    public function nonArazzoSourceCount(): int
    {
        return count(array_filter($this->sources, static fn(ArazzoSourceDescription $s): bool => $s->type !== 'arazzo'));
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function fromDocument(array $document, string $path): self
    {
        /** @var list<array{0: ArazzoDescriptionReason, 1: string}> $problems */
        $problems = [];

        $version = $document['arazzo'] ?? null;
        if (!is_string($version)) {
            $problems[] = [ArazzoDescriptionReason::MissingRequiredField, 'arazzo: a version string is required.'];
        } elseif (preg_match('/^1\.1(\.\d+)?$/D', $version) !== 1) {
            $problems[] = [ArazzoDescriptionReason::UnsupportedVersion, sprintf("arazzo: version '%s' is not supported; only 1.1.x is.", $version)];
        }

        $info = $document['info'] ?? null;
        if (!self::isObject($info)) {
            $problems[] = [ArazzoDescriptionReason::MissingRequiredField, 'info: an object is required.'];
        } else {
            foreach (['title', 'version'] as $field) {
                if (!is_string(self::members($info)[$field] ?? null)) {
                    $problems[] = [ArazzoDescriptionReason::MissingRequiredField, sprintf('info.%s: a string is required.', $field)];
                }
            }
        }

        $baseUri = $path;
        if (array_key_exists('$self', $document)) {
            $self = $document['$self'];
            if (!is_string($self) || $self === '' || str_contains($self, '#')) {
                $problems[] = [ArazzoDescriptionReason::InvalidField, '$self: must be a URI without a fragment.'];
            } elseif (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $self) === 1) {
                $baseUri = $self;
            } else {
                $decoded = rawurldecode($self);
                $baseUri = OpenApiRefResolver::normaliseDotSegments(str_starts_with($decoded, '/') ? $decoded : dirname($path) . '/' . $decoded);
                // A directory-valued reference (`./`, `..`) keeps its trailing slash so it is not dirname()'d again.
                if (preg_match('#(^|/)\.{0,2}$#', $self) === 1) {
                    $baseUri = rtrim($baseUri, '/') . '/';
                }
            }
        }

        $sources = self::sources($document['sourceDescriptions'] ?? null, $baseUri, $problems);
        $workflows = self::workflows($document['workflows'] ?? null, $problems);

        $components = $document['components'] ?? [];
        if (!self::isObject($components)) {
            $problems[] = [ArazzoDescriptionReason::InvalidField, sprintf('components: expected object, got %s.', get_debug_type($components))];
        }

        if ($problems !== []) {
            throw InvalidArazzoDescriptionException::fromProblems($problems, $path);
        }

        /** @var array<string, mixed> $componentMembers */
        $componentMembers = self::members($components);

        return new self($path, $baseUri, (string) $version, $sources, $workflows, $componentMembers);
    }

    /**
     * @param list<array{0: ArazzoDescriptionReason, 1: string}> $problems
     *
     * @return array<string, ArazzoSourceDescription>
     */
    private static function sources(mixed $node, string $baseUri, array &$problems): array
    {
        if (!self::isNonEmptyList($node)) {
            $problems[] = [ArazzoDescriptionReason::MissingRequiredField, 'sourceDescriptions: a non-empty list is required.'];

            return [];
        }

        $sources = [];
        foreach ($node as $index => $source) {
            $at = sprintf('sourceDescriptions[%d]', $index);
            if (!self::isObject($source)) {
                $problems[] = [ArazzoDescriptionReason::InvalidField, sprintf('%s: expected object, got %s.', $at, get_debug_type($source))];

                continue;
            }

            $source = self::members($source);
            $name = $source['name'] ?? null;
            $url = $source['url'] ?? null;
            $type = $source['type'] ?? 'openapi';
            if (!is_string($name) || preg_match(self::NAME_PATTERN, $name) !== 1) {
                $problems[] = [ArazzoDescriptionReason::MissingRequiredField, sprintf('%s.name: a name matching [A-Za-z0-9_-]+ is required.', $at)];

                continue;
            }

            if (isset($sources[$name])) {
                $problems[] = [ArazzoDescriptionReason::DuplicateIdentifier, sprintf("%s.name: '%s' is declared more than once.", $at, $name)];

                continue;
            }

            if (!is_string($url) || $url === '') {
                $problems[] = [ArazzoDescriptionReason::MissingRequiredField, sprintf('%s.url: a URI reference is required.', $at)];

                continue;
            }

            if (!is_string($type) || !in_array($type, self::SOURCE_TYPES, true)) {
                $problems[] = [ArazzoDescriptionReason::InvalidField, sprintf('%s.type: must be one of openapi, asyncapi, arazzo.', $at)];

                continue;
            }

            $resolved = self::resolveSourceUrl($url, $baseUri);
            if ($resolved === null) {
                $problems[] = [ArazzoDescriptionReason::UnsupportedSourceUrl, sprintf("%s.url: '%s' must be a relative reference or an absolute http(s) URL.", $at, $url)];

                continue;
            }

            $sources[$name] = new ArazzoSourceDescription($name, $url, $type, $resolved);
        }

        return $sources;
    }

    /**
     * @param list<array{0: ArazzoDescriptionReason, 1: string}> $problems
     *
     * @return array<string, array<string, mixed>>
     */
    private static function workflows(mixed $node, array &$problems): array
    {
        if (!self::isNonEmptyList($node)) {
            $problems[] = [ArazzoDescriptionReason::MissingRequiredField, 'workflows: a non-empty list is required.'];

            return [];
        }

        $workflows = [];
        foreach ($node as $index => $workflow) {
            $at = sprintf('workflows[%d]', $index);
            if (!self::isObject($workflow)) {
                $problems[] = [ArazzoDescriptionReason::InvalidField, sprintf('%s: expected object, got %s.', $at, get_debug_type($workflow))];

                continue;
            }

            /** @var array<string, mixed> $workflow */
            $workflow = self::members($workflow);
            $id = $workflow['workflowId'] ?? null;
            if (!is_string($id) || $id === '') {
                $problems[] = [ArazzoDescriptionReason::MissingRequiredField, sprintf('%s.workflowId: a string is required.', $at)];

                continue;
            }

            $at = sprintf('workflows[%s]', $id);
            if (isset($workflows[$id])) {
                $problems[] = [ArazzoDescriptionReason::DuplicateIdentifier, sprintf("%s: workflowId '%s' is declared more than once.", $at, $id)];

                continue;
            }

            if (array_key_exists('inputs', $workflow) && !self::isObject($workflow['inputs'])) {
                $problems[] = [ArazzoDescriptionReason::InvalidField, sprintf('%s.inputs: expected a JSON Schema object.', $at)];
            }

            $steps = $workflow['steps'] ?? null;
            if (!self::isNonEmptyList($steps)) {
                $problems[] = [ArazzoDescriptionReason::MissingRequiredField, sprintf('%s.steps: a non-empty list is required.', $at)];
            } else {
                $stepIds = [];
                foreach ($steps as $stepIndex => $step) {
                    $stepId = self::isObject($step) ? (self::members($step)['stepId'] ?? null) : null;
                    if (!is_string($stepId) || $stepId === '') {
                        $problems[] = [ArazzoDescriptionReason::MissingRequiredField, sprintf('%s.steps[%d].stepId: a string is required.', $at, $stepIndex)];
                    } elseif (isset($stepIds[$stepId])) {
                        $problems[] = [ArazzoDescriptionReason::DuplicateIdentifier, sprintf("%s.steps[%d]: stepId '%s' is declared more than once.", $at, $stepIndex, $stepId)];
                    } else {
                        $stepIds[$stepId] = true;
                    }
                }
            }

            $workflows[$id] = $workflow;
        }

        return $workflows;
    }

    private static function resolveSourceUrl(string $url, string $baseUri): ?string
    {
        if (self::isHttpUrl($url)) {
            return $url;
        }

        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $url) === 1) {
            return null;
        }

        if (self::isHttpUrl($baseUri)) {
            return OpenApiRefResolver::resolveRelativeUrl($baseUri, $url);
        }

        // A network-path reference (`//host/x`) names an authority, never a local file.
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*:#', $baseUri) === 1 || str_starts_with($url, '//')) {
            return null;
        }

        $directory = str_ends_with($baseUri, '/') ? $baseUri : dirname($baseUri) . '/';

        // The reference is percent-encoded; the base is already a real file path.
        $path = rawurldecode($url);

        return OpenApiRefResolver::normaliseDotSegments(str_starts_with($path, '/') ? $path : $directory . $path);
    }
}
