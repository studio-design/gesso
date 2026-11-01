<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Internal;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Internal\AgentEnvironment;
use Studio\Gesso\Internal\LegacyIdentity;
use Studio\Gesso\ValidationOutput;
use Studio\Gesso\ValidationOutputFormat;

use function getenv;
use function putenv;

final class AgentEnvironmentTest extends TestCase
{
    /** @var array<string, false|string> host values, restored after each test */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (AgentEnvironment::ENV_VARS as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
        AgentEnvironment::assume(null);
        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }
        // Back to the suite-wide pin from tests/bootstrap.php.
        AgentEnvironment::assume(false);
        LegacyIdentity::resetEnvForTesting('GESSO_VALIDATION_FORMAT');
        ValidationOutput::reset();

        parent::tearDown();
    }

    #[Test]
    public function no_agent_variable_means_no_agent(): void
    {
        $this->assertFalse(AgentEnvironment::detected());
    }

    #[Test]
    public function claude_code_is_detected(): void
    {
        putenv('CLAUDECODE=1');

        $this->assertTrue(AgentEnvironment::detected());
    }

    #[Test]
    public function the_generic_ai_agent_variable_is_detected(): void
    {
        putenv('AI_AGENT=some-agent');

        $this->assertTrue(AgentEnvironment::detected());
    }

    #[Test]
    public function an_empty_value_counts_as_unset(): void
    {
        putenv('CURSOR_AGENT=');

        $this->assertFalse(AgentEnvironment::detected());
    }

    #[Test]
    public function auto_resolves_to_agent_under_an_agent_and_text_otherwise(): void
    {
        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());

        putenv('GEMINI_CLI=1');

        $this->assertSame(ValidationOutputFormat::Agent, ValidationOutput::format());
    }

    #[Test]
    public function an_explicit_text_or_json_selection_wins_over_detection(): void
    {
        putenv('CODEX_THREAD_ID=abc');

        ValidationOutput::use(ValidationOutputFormat::Text);
        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());

        ValidationOutput::use(ValidationOutputFormat::Json);
        $this->assertSame(ValidationOutputFormat::Json, ValidationOutput::format());

        ValidationOutput::reset();
        putenv('GESSO_VALIDATION_FORMAT=text');
        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());
    }

    #[Test]
    public function an_explicit_auto_still_resolves_and_is_never_returned(): void
    {
        ValidationOutput::use(ValidationOutputFormat::Auto);
        $this->assertSame(ValidationOutputFormat::Text, ValidationOutput::format());

        putenv('GESSO_VALIDATION_FORMAT=auto');
        putenv('OPENCODE=1');
        $this->assertSame(ValidationOutputFormat::Agent, ValidationOutput::format());
    }
}
