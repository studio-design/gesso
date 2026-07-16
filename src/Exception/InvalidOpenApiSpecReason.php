<?php

declare(strict_types=1);

namespace Studio\Gesso\Exception;

/**
 * Categorical reasons an OpenAPI spec is considered broken. Carried on
 * `InvalidOpenApiSpecException` so consumers can branch on the concrete
 * failure kind instead of regex-ing the human-readable message.
 */
enum InvalidOpenApiSpecReason
{
    case LocalRefNotFound;
    case LocalRefOutsideAllowedRoot;
    case LocalRefUnreadable;
    case LocalRefRequiresSourceFile;
    case RemoteRefDisallowed;
    case RemoteRefHostDisallowed;
    case RemoteRefFetchFailed;
    case HttpClientNotConfigured;
    case FileSchemeNotSupported;
    case EmptyRef;
    case CircularRef;
    case UnresolvableRef;
    case NonStringRef;
    case BareFragmentRef;
    case RootPointerRef;
    case NonObjectRefTarget;
    case MalformedJson;
    case MalformedYaml;
    case NonMappingRoot;
    case YamlLibraryMissing;
    case UnsupportedExtension;
    case UnsupportedVersion;
    case UnsupportedJsonSchemaDialect;
    case BasePathNotConfigured;
}
