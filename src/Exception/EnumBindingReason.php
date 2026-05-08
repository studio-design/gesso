<?php

declare(strict_types=1);

namespace Studio\OpenApiContractTesting\Exception;

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
    case BasePathNotConfigured;
    case EnumBasePathNotFound;
    case EnumSpecBasePathOrphaned;
    case SpecFileNotFound;
    case MalformedJson;
    case NonMappingRoot;
    case EnumKeyMissing;
    case EnumKeyNotArray;
    case EnumValueUnsupported;
    case ScanNamespaceUnresolvable;
    case ScanComposerLoaderUnavailable;
    case NoNamespacesConfigured;
}
