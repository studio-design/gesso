<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

/**
 * Why an Arazzo description, one of its sources, or a selected workflow was
 * rejected before anything was dispatched.
 */
enum ArazzoDescriptionReason
{
    case FileNotFound;
    case UnsupportedExtension;
    case MalformedDocument;
    case UnsupportedVersion;
    case MissingRequiredField;
    case InvalidField;
    case DuplicateIdentifier;
    case UnknownWorkflow;
    case UnknownStep;
    case UnknownSource;
    case UnknownOutput;
    case UnsupportedSourceType;
    case UnsupportedSourceUrl;
    case SourceNotConfigured;
    case SourceNotFound;
    case SourceOutsideBasePath;
    case AmbiguousSourceFile;
    case SourceUnloadable;
    case MissingTarget;
    case AmbiguousTarget;
    case UnqualifiedOperationId;
    case OperationNotFound;
    case DuplicateParameter;
    case ForwardStepReference;
    case InvalidExpression;
    case UnsupportedFeature;
    case UnknownComponent;
}
