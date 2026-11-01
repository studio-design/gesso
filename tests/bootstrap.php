<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests;

use Studio\Gesso\Internal\AgentEnvironment;

require __DIR__ . '/../vendor/autoload.php';

// The default `auto` validation format renders the compact agent shape when a
// coding agent is detected. Pin "no agent" so failure messages are the same
// whether the suite runs in CI or inside Claude Code, Cursor, Codex, ...
AgentEnvironment::assume(false);
