<?php

declare(strict_types=1);

namespace Studio\Gesso\Internal;

use Studio\Gesso\ValidationOutput;

use function getenv;
use function is_string;
use function trim;

/**
 * Whether the process runs under an AI coding agent, so the `auto`
 * validation output format can pick the compact `agent` shape.
 *
 * Detection is the environment-variable table of laravel/agent-detector
 * (the detector laravel/pao uses), vendored at commit
 * f566699e48da4c6af80763dc1692846c9e7b2618: `AI_AGENT` first, then the
 * per-agent variables. Its compound, TTY, and filesystem heuristics are left
 * out, as are variables that are not agent-specific (`REPL_ID`,
 * `COPILOT_GITHUB_TOKEN`). An empty value counts as unset.
 *
 * ponytail: static table, so a new agent needs a new entry; prefer
 * laravel/agent-detector when it is installed if the table starts to lag.
 *
 * @internal Implementation detail of {@see ValidationOutput}.
 */
final class AgentEnvironment
{
    public const ENV_VARS = [
        'AI_AGENT',
        'CLAUDECODE',
        'CLAUDE_CODE',
        'CURSOR_AGENT',
        'CODEX_SANDBOX',
        'CODEX_CI',
        'CODEX_THREAD_ID',
        'CODEX_SANDBOX_NETWORK_DISABLED',
        'GEMINI_CLI',
        'QWEN_CODE',
        'COPILOT_CLI',
        'COPILOT_MODEL',
        'COPILOT_ALLOW_ALL',
        'OPENCODE',
        'OPENCODE_CLIENT',
        'AMP_CURRENT_THREAD_ID',
        'AUGMENT_AGENT',
        'JUNIE_DATA',
        'MATTERHORN_SESSION_ID',
        'KIRO_AGENT_PATH',
        'CLINE_ACTIVE',
        'WINDSURF_CASCADE_TERMINAL',
        'ANTIGRAVITY_AGENT',
        'PI_CODING_AGENT',
        'GROK_PLUGIN_ROOT',
        'KIMI_PLUGIN_ROOT',
        'GOOSE_TERMINAL',
    ];
    private static ?bool $assumed = null;

    private function __construct() {}

    public static function detected(): bool
    {
        if (self::$assumed !== null) {
            return self::$assumed;
        }

        foreach (self::ENV_VARS as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Test seam: pin the answer (`null` restores environment detection).
     * The test bootstrap pins `false` so the suite renders the same output
     * whether or not it runs inside an agent.
     */
    public static function assume(?bool $detected): void
    {
        self::$assumed = $detected;
    }
}
