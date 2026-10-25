<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Fuzz;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\LinkChainCheck;
use Studio\Gesso\Fuzz\LinkFollower;
use Studio\Gesso\HttpMethod;
use Studio\Gesso\Internal\ObservedResponse;
use Studio\Gesso\Spec\OpenApiSpecLoader;

use function array_keys;

final class LinkFollowerTest extends TestCase
{
    private LinkFollower $follower;

    protected function setUp(): void
    {
        OpenApiSpecLoader::reset();
        OpenApiSpecLoader::configure(__DIR__ . '/../../fixtures/specs');
        $this->follower = new LinkFollower('links-chain', OpenApiSpecLoader::load('links-chain'));
    }

    protected function tearDown(): void
    {
        OpenApiSpecLoader::reset();
    }

    #[Test]
    public function links_resolve_by_operation_id_and_local_operation_ref(): void
    {
        $links = $this->follower->links('POST', '/pets', 201);

        $this->assertSame(['GetPet', 'DeletePet'], array_keys($links));
        $this->assertSame(['GET', '/pets/{petId}'], $this->methodAndPath($this->follower->target('GetPet', $links['GetPet'])));
        $this->assertSame(['DELETE', '/pets/{petId}'], $this->methodAndPath($this->follower->target('DeletePet', $links['DeletePet'])));
        $this->assertSame([], $this->follower->links('GET', '/pets/{petId}', 404));
    }

    #[Test]
    public function bare_and_qualified_parameters_override_the_generated_case(): void
    {
        $links = $this->follower->links('POST', '/pets', 201);
        $parent = new ExploredCase((object) ['name' => 'Rex'], [], [], [], HttpMethod::POST, '/pets');
        $response = ObservedResponse::from(['status' => 201, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{"id": 42, "name": "Rex"}']);

        foreach (['GetPet', 'DeletePet'] as $name) {
            $target = $this->follower->target($name, $links[$name]);
            $child = $this->follower->apply($name, $links[$name], $target, $this->follower->generate($name, $target, 3), $parent, $response);
            $this->assertSame(42, $child->pathParams['petId'], $name);
            $this->assertSame('/pets/42', $child->uri(), $name);
        }
    }

    #[Test]
    public function linked_headers_replace_generated_headers_case_insensitively(): void
    {
        $link = ['operationId' => 'getPet', 'parameters' => ['path.petId' => 1, 'header.x-token' => 'new']];
        $target = $this->follower->target('Token', $link);
        $parent = new ExploredCase(null, [], [], [], HttpMethod::POST, '/pets');
        $response = ObservedResponse::from(['status' => 201, 'headers' => [], 'body' => '']);

        $child = $this->follower->apply('Token', $link, $target, $this->follower->generate('Token', $target, 1)->withHeaders(['X-Token' => 'old']), $parent, $response);

        $this->assertSame(['x-token' => 'new'], $child->headers);
    }

    #[Test]
    public function generation_is_seed_deterministic(): void
    {
        $target = $this->follower->target('GetPet', $this->follower->links('POST', '/pets', 201)['GetPet']);

        $first = $this->follower->generate('GetPet', $target, 11);
        $second = $this->follower->generate('GetPet', $target, 11);

        $this->assertSame($first->uri(), $second->uri());
        $this->assertSame($first->replayToken(), $second->replayToken());
    }

    #[Test]
    public function chain_checks_only_fire_on_lifecycle_violations(): void
    {
        $this->assertSame(LinkChainCheck::UseAfterFree, LinkFollower::check(HttpMethod::DELETE, 204, '/pets/1', HttpMethod::GET, 200, '/pets/1'));
        $this->assertNull(LinkFollower::check(HttpMethod::DELETE, 204, '/pets/1', HttpMethod::GET, 404, '/pets/1'));
        $this->assertNull(LinkFollower::check(HttpMethod::DELETE, 404, '/pets/1', HttpMethod::GET, 200, '/pets/1'));
        $this->assertNull(LinkFollower::check(HttpMethod::DELETE, 204, '/pets/1', HttpMethod::GET, 200, '/pets'), 'a collection read is not use-after-free');
        $this->assertSame(LinkChainCheck::EnsureResourceAvailability, LinkFollower::check(HttpMethod::POST, 201, '/pets', HttpMethod::GET, 404, '/pets/1'));
        $this->assertSame(LinkChainCheck::EnsureResourceAvailability, LinkFollower::check(HttpMethod::PUT, 200, '/pets/1', HttpMethod::GET, 410, '/pets/1'));
        $this->assertNull(LinkFollower::check(HttpMethod::POST, 201, '/pets', HttpMethod::DELETE, 404, '/pets/1'));
        $this->assertNull(LinkFollower::check(HttpMethod::POST, 400, '/pets', HttpMethod::GET, 404, '/pets/1'));
        $this->assertNull(LinkFollower::check(HttpMethod::POST, 202, '/pets', HttpMethod::GET, 404, '/pets/1'), '202 Accepted has not finished creating');
        $this->assertNull(LinkFollower::check(HttpMethod::DELETE, 202, '/pets/1', HttpMethod::GET, 200, '/pets/1'), '202 Accepted has not finished deleting');
    }

    /**
     * @param array{method: string, path: string, operation: array<string, mixed>} $target
     *
     * @return array{string, string}
     */
    private function methodAndPath(array $target): array
    {
        return [$target['method'], $target['path']];
    }
}
