<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Arazzo;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Arazzo\ArazzoDescription;
use Studio\Gesso\Arazzo\ArazzoDescriptionReason;
use Studio\Gesso\Arazzo\ArazzoSourceResolver;
use Studio\Gesso\Arazzo\InvalidArazzoDescriptionException;
use Studio\Gesso\Spec\OpenApiSpecLoader;
use Studio\Gesso\Tests\Helpers\FakeHttpClient;

use function file_get_contents;

final class ArazzoSourceResolverTest extends TestCase
{
    private const DIR = __DIR__ . '/../../fixtures/arazzo';
    private const SPECS = __DIR__ . '/../../fixtures/specs';

    protected function setUp(): void
    {
        OpenApiSpecLoader::reset();
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
    }

    /**
     * @return iterable<string, array{string, ArazzoDescriptionReason}>
     */
    public static function provideUnsafe_or_unsupported_sources_are_rejectedCases(): iterable
    {
        yield 'escapes the base path' => ['malformed/escape-base.arazzo.json', ArazzoDescriptionReason::SourceOutsideBasePath];
        yield 'shadowed by a sibling extension' => ['malformed/sibling-extension.arazzo.json', ArazzoDescriptionReason::AmbiguousSourceFile];
        yield 'asyncapi source' => ['malformed/asyncapi-source.arazzo.json', ArazzoDescriptionReason::UnsupportedSourceType];
    }

    #[Test]
    public function local_sources_map_to_loader_spec_names(): void
    {
        OpenApiSpecLoader::configure(self::SPECS);

        $sources = ArazzoSourceResolver::resolve(ArazzoDescription::fromFile(self::DIR . '/pet-purchase.arazzo.json'));

        $this->assertSame('arazzo-petstore', $sources['petstore']->specName);
        $this->assertSame(OpenApiSpecLoader::load('arazzo-petstore'), $sources['petstore']->spec);
        $this->assertNotNull($sources['petstore']->operations->find('createPet'));
    }

    #[Test]
    #[DataProvider('provideUnsafe_or_unsupported_sources_are_rejectedCases')]
    public function unsafe_or_unsupported_sources_are_rejected(string $file, ArazzoDescriptionReason $reason): void
    {
        OpenApiSpecLoader::configure(self::SPECS);

        try {
            ArazzoSourceResolver::resolve(ArazzoDescription::fromFile(self::DIR . '/' . $file));
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $this->assertSame($reason, $e->reason, $e->getMessage());
        }
    }

    #[Test]
    public function an_unconfigured_remote_source_is_never_fetched(): void
    {
        $client = new FakeHttpClient();
        OpenApiSpecLoader::configure(
            self::SPECS,
            httpClient: $client,
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['workflows.example.test'],
        );

        try {
            ArazzoSourceResolver::resolve(ArazzoDescription::fromFile(self::DIR . '/self-absolute.arazzo.json'));
            $this->fail('Expected InvalidArazzoDescriptionException.');
        } catch (InvalidArazzoDescriptionException $e) {
            $this->assertSame(ArazzoDescriptionReason::SourceNotConfigured, $e->reason);
        }

        $this->assertSame([], $client->sentUrls());
    }

    #[Test]
    public function a_configured_remote_source_loads_through_the_loader(): void
    {
        $url = 'https://workflows.example.test/petstore.json';
        $client = new FakeHttpClient([$url => FakeHttpClient::jsonResponse((string) file_get_contents(self::SPECS . '/arazzo-petstore.json'))]);
        OpenApiSpecLoader::configure(
            self::SPECS,
            httpClient: $client,
            requestFactory: new HttpFactory(),
            allowRemoteRefs: true,
            allowedRemoteRefHosts: ['workflows.example.test'],
            remoteSpecs: ['remote-petstore' => $url],
        );

        $sources = ArazzoSourceResolver::resolve(ArazzoDescription::fromFile(self::DIR . '/self-absolute.arazzo.json'));

        $this->assertSame('remote-petstore', $sources['petstore']->specName);
        $this->assertNotNull($sources['petstore']->operations->find('listPets'));
        $this->assertSame([$url], $client->sentUrls());
    }
}
