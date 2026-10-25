<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Arazzo;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Arazzo\ArazzoDescription;
use Studio\Gesso\Arazzo\ArazzoDescriptionReason;
use Studio\Gesso\Arazzo\InvalidArazzoDescriptionException;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_keys;
use function array_values;
use function bin2hex;
use function dirname;
use function file_get_contents;
use function file_put_contents;
use function mkdir;
use function random_bytes;
use function realpath;
use function rmdir;
use function str_replace;
use function sys_get_temp_dir;
use function touch;
use function unlink;

final class ArazzoDescriptionTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/arazzo';

    protected function setUp(): void
    {
        // Parsing must never need loader configuration.
        OpenApiSpecLoader::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
    }

    /**
     * @return iterable<string, array{string, ArazzoDescriptionReason}>
     */
    public static function provideMalformed_documents_fail_with_their_reasonCases(): iterable
    {
        yield 'no sources' => ['malformed/no-sources.arazzo.json', ArazzoDescriptionReason::MissingRequiredField];
        yield 'duplicate step id' => ['malformed/duplicate-step-id.arazzo.json', ArazzoDescriptionReason::DuplicateIdentifier];
        yield 'self with fragment' => ['malformed/self-with-fragment.arazzo.json', ArazzoDescriptionReason::InvalidField];
        yield 'version 2' => ['malformed/version-2.arazzo.json', ArazzoDescriptionReason::UnsupportedVersion];
        yield 'not a mapping' => ['malformed/not-a-mapping.arazzo.json', ArazzoDescriptionReason::MalformedDocument];
        yield 'missing file' => ['missing.arazzo.json', ArazzoDescriptionReason::FileNotFound];
    }

    #[Test]
    public function a_root_relative_self_replaces_the_path_and_numeric_names_stay_strings(): void
    {
        $description = ArazzoDescription::fromFile(self::DIR . '/self-root.arazzo.json');

        $this->assertSame('/workflows/root.arazzo.json', $description->baseUri);
        $source = array_values($description->sources)[0];
        $this->assertSame('/workflows/specs/pets.json', $source->resolvedUrl);
        $this->assertSame('123', $source->name);
    }

    #[Test]
    public function json_and_yaml_parse_identically(): void
    {
        $json = ArazzoDescription::fromFile(self::DIR . '/pet-purchase.arazzo.json');
        $yaml = ArazzoDescription::fromFile(self::DIR . '/pet-purchase.arazzo.yaml');

        $this->assertSame('1.1.0', $json->arazzoVersion);
        $this->assertSame(['petstore'], array_keys($json->sources));
        $this->assertSame(array_keys($json->sources), array_keys($yaml->sources));
        $this->assertSame(['buyPet'], array_keys($json->workflows));
        $this->assertSame(array_keys($json->workflows), array_keys($yaml->workflows));
        $this->assertSame($json->sources['petstore']->resolvedUrl, $yaml->sources['petstore']->resolvedUrl);
        $this->assertSame(realpath(self::DIR . '/../specs/arazzo-petstore.json'), $json->sources['petstore']->resolvedUrl);
        $this->assertSame('openapi', $json->sources['petstore']->type);
        $this->assertSame(1, $json->nonArazzoSourceCount());
    }

    #[Test]
    public function the_base_uri_follows_self(): void
    {
        $absent = ArazzoDescription::fromFile(self::DIR . '/pet-purchase.arazzo.json');
        $relative = ArazzoDescription::fromFile(self::DIR . '/self-relative.arazzo.json');
        $absolute = ArazzoDescription::fromFile(self::DIR . '/self-absolute.arazzo.json');

        $this->assertSame(realpath(self::DIR . '/pet-purchase.arazzo.json'), $absent->baseUri);
        $this->assertSame(dirname((string) realpath(self::DIR . '/self-relative.arazzo.json')) . '/nested/self.arazzo.json', $relative->baseUri);
        $this->assertSame(realpath(self::DIR . '/../specs/arazzo-petstore.json'), $relative->sources['petstore']->resolvedUrl);
        $this->assertSame('https://workflows.example.test/wf.json', $absolute->baseUri);
        $this->assertSame('https://workflows.example.test/petstore.json', $absolute->sources['petstore']->resolvedUrl);
        $this->assertTrue($absolute->sources['petstore']->isRemote());

        $directory = ArazzoDescription::fromFile(self::DIR . '/self-directory.arazzo.json');
        $this->assertSame(realpath(self::DIR . '/../specs/arazzo-petstore.json'), $directory->sources['petstore']->resolvedUrl);
    }

    #[Test]
    public function source_urls_resolve_as_uri_references(): void
    {
        $network = ArazzoDescription::fromFile(self::DIR . '/self-network-path.arazzo.json');
        $encoded = ArazzoDescription::fromFile(self::DIR . '/percent-encoded-source.arazzo.json');

        $this->assertSame('https://api.example.test/pets.json', $network->sources['api']->resolvedUrl);
        $this->assertSame('https://workflows.example.test/wf.json?format=openapi', $network->sources['query']->resolvedUrl);
        $this->assertSame(realpath(self::DIR . '/../specs/arazzo-petstore.json'), $encoded->sources['petstore']->resolvedUrl);
    }

    #[Test]
    public function an_encoded_looking_directory_name_is_not_decoded(): void
    {
        $dir = sys_get_temp_dir() . '/gesso-arazzo-' . bin2hex(random_bytes(4)) . '/suite%20name';
        mkdir($dir, 0o777, true);
        $workflow = str_replace('../specs/arazzo-petstore.json', 'pets.json', (string) file_get_contents(self::DIR . '/pet-purchase.arazzo.json'));
        file_put_contents($dir . '/workflow.arazzo.json', $workflow);
        touch($dir . '/pets.json');

        try {
            $description = ArazzoDescription::fromFile($dir . '/workflow.arazzo.json');
            $this->assertSame(realpath($dir . '/pets.json'), $description->sources['petstore']->resolvedUrl);
        } finally {
            unlink($dir . '/workflow.arazzo.json');
            unlink($dir . '/pets.json');
            rmdir($dir);
            rmdir(dirname($dir));
        }
    }

    #[Test]
    #[DataProvider('provideMalformed_documents_fail_with_their_reasonCases')]
    public function malformed_documents_fail_with_their_reason(string $file, ArazzoDescriptionReason $reason): void
    {
        try {
            ArazzoDescription::fromFile(self::DIR . '/' . $file);
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    #[Test]
    public function every_structural_problem_is_reported_at_once(): void
    {
        try {
            ArazzoDescription::fromFile(self::DIR . '/malformed/three-problems.arazzo.json');
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $this->assertCount(3, $e->problems, $e->getMessage());
            $this->assertStringStartsWith('arazzo:', $e->problems[0]);
            $this->assertStringStartsWith('info.version:', $e->problems[1]);
            $this->assertStringStartsWith('workflows[w].steps:', $e->problems[2]);
        }
    }

    #[Test]
    public function unknown_workflows_are_rejected(): void
    {
        $this->expectException(InvalidArazzoDescriptionException::class);

        ArazzoDescription::fromFile(self::DIR . '/pet-purchase.arazzo.json')->workflow('nope');
    }

    #[Test]
    public function unsupported_extensions_are_rejected(): void
    {
        try {
            ArazzoDescription::fromFile(__FILE__);
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $this->assertSame(ArazzoDescriptionReason::UnsupportedExtension, $e->reason);
        }
    }
}
