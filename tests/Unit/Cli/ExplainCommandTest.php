<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Cli;

use const JSON_THROW_ON_ERROR;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Cli\ExplainCommand;
use Studio\Gesso\Internal\FailureCatalogue;

use function array_column;
use function array_keys;
use function json_decode;

final class ExplainCommandTest extends TestCase
{
    private string $stdout = '';
    private string $stderr = '';

    #[Test]
    public function explains_one_code_as_text(): void
    {
        $this->assertSame(ExplainCommand::EXIT_OK, $this->explain(['gso212']));

        $this->assertStringStartsWith('GSO212: ', $this->stdout);
        $this->assertStringContainsString('`response.body` with keyword `type`', $this->stdout);
        $this->assertStringContainsString('Docs: https://studio-design.github.io/gesso/failure-codes#gso212', $this->stdout);
        $this->assertSame('', $this->stderr);
    }

    #[Test]
    public function explains_one_code_as_json(): void
    {
        $this->assertSame(ExplainCommand::EXIT_OK, $this->explain(['GSO401', '--format=json']));

        $document = json_decode($this->stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(ExplainCommand::JSON_SCHEMA_VERSION, $document['schemaVersion']);
        $this->assertSame('GSO401', $document['code']);
        $this->assertSame(
            ['schemaVersion', 'tool', 'code', 'title', 'summary', 'source', 'causes', 'fix', 'do_not', 'docs_url'],
            array_keys($document),
        );
        $this->assertSame(FailureCatalogue::docsUrl('GSO401'), $document['docs_url']);
    }

    #[Test]
    public function lists_every_code_without_an_argument(): void
    {
        $this->assertSame(ExplainCommand::EXIT_OK, $this->explain(['--format=json']));

        $document = json_decode($this->stdout, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(FailureCatalogue::codes(), array_column($document['codes'], 'code'));

        $this->stdout = '';
        $this->assertSame(ExplainCommand::EXIT_OK, $this->explain([]));
        $this->assertStringContainsString('GSO101  Request path is not in the spec', $this->stdout);
    }

    #[Test]
    public function an_unknown_code_exits_two_and_suggests_close_codes(): void
    {
        $this->assertSame(ExplainCommand::EXIT_USAGE, $this->explain(['GSO2122']));

        $this->assertSame('', $this->stdout);
        $this->assertStringContainsString('Unknown failure code: GSO2122.', $this->stderr);
        $this->assertStringContainsString('Did you mean GSO212', $this->stderr);
    }

    #[Test]
    public function rejects_extra_arguments_and_unknown_formats(): void
    {
        $this->assertSame(ExplainCommand::EXIT_USAGE, $this->explain(['GSO212', 'GSO211']));
        $this->assertStringContainsString('Unknown argument(s): GSO211', $this->stderr);

        $this->stderr = '';
        $this->assertSame(ExplainCommand::EXIT_USAGE, $this->explain(['GSO212', '--format=xml']));
        $this->assertStringContainsString('Unsupported --format=xml.', $this->stderr);
    }

    /** @param list<string> $argv */
    private function explain(array $argv): int
    {
        $command = new ExplainCommand(
            stdoutWriter: function (string $message): void {
                $this->stdout .= $message;
            },
            stderrWriter: function (string $message): void {
                $this->stderr .= $message;
            },
        );

        return $command->run(ExplainCommand::parseArgv($argv));
    }
}
