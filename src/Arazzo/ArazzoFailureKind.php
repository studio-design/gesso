<?php

declare(strict_types=1);

namespace Studio\Gesso\Arazzo;

/**
 * Which boundary an Arazzo workflow run stopped at.
 */
enum ArazzoFailureKind: string
{
    /** The authored request violates the source's OpenAPI contract; it was not dispatched. */
    case RequestContract = 'request_contract';

    /** The dispatch callback threw or returned something other than a response. */
    case Dispatch = 'dispatch';

    /** The response violates the source's OpenAPI contract. */
    case ResponseContract = 'response_contract';

    /** A `successCriteria` condition was false or could not be evaluated. */
    case Criterion = 'criterion';

    /** Workflow inputs, parameters, payloads or outputs could not be evaluated. */
    case Workflow = 'workflow';
}
