<?php

declare(strict_types=1);

namespace Studio\Gesso\Fuzz;

/**
 * Lifecycle checks applied to each (parent, follow-up) pair when whole-spec
 * exploration follows explicit OpenAPI `links`. Names match Schemathesis'
 * checks of the same purpose.
 */
enum LinkChainCheck: string
{
    /** A 2xx `GET` through a link after a successful `DELETE` — the resource outlived its deletion. */
    case UseAfterFree = 'use_after_free';

    /** A `404`/`410` `GET` through a link after a successful `POST`/`PUT`/`PATCH` — the created resource cannot be read. */
    case EnsureResourceAvailability = 'ensure_resource_availability';
}
