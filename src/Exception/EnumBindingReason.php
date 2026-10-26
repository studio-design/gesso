<?php

declare(strict_types=1);

namespace Studio\Gesso\Exception;

/**
 * Categorical reasons a `#[BoundToOpenApiEnum]` binding cannot be resolved
 * into a comparable spec/enum pair. Carried on `EnumBindingException` so
 * callers can branch on the concrete misconfiguration kind rather than
 * regex-ing the human-readable message.
 */
enum EnumBindingReason
{
    case TargetIsNotEnum;
    case TargetIsNotBackedEnum;
    case ReflectionFailed;
    case AttributeMissing;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case BasePathNotConfigured;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case EnumBasePathNotFound;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case EnumSpecBasePathOrphaned;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case SpecFileNotFound;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case MalformedJson;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case MalformedYaml;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case YamlLibraryMissing;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case NonMappingRoot;
    /**
     * @deprecated Raised only by file-path bindings
     *             (`#[BoundToOpenApiEnum('path/to/file.json')]`), which Gesso 3.0
     *             removes together with this case. Bind by component name instead.
     */
    case EnumKeyMissing;
    /**
     * Also raised for a bound `components.schemas` entry whose `enum` is not a
     * list, so it is not deprecated with the other file-path reasons.
     */
    case EnumKeyNotArray;
    case EnumValueUnsupported;
    case ScanNamespaceUnresolvable;
    case ScanComposerLoaderUnavailable;
    case NoNamespacesConfigured;
    case ComponentNotFound;
    case ComponentConflict;
    case AttributeInvalid;
}
