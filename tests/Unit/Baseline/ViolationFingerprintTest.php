<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Baseline\ViolationFingerprint;
use Studio\Gesso\ValidationIssue;

class ViolationFingerprintTest extends TestCase
{
    #[Test]
    public function canonicalization_replaces_numeric_segments_with_a_wildcard(): void
    {
        $this->assertSame('/data/*/id', ViolationFingerprint::canonicalizeInstancePath('/data/0/id'));
        $this->assertSame('/items/*/tags/*', ViolationFingerprint::canonicalizeInstancePath('/items/10/tags/2'));
        $this->assertSame('/*', ViolationFingerprint::canonicalizeInstancePath('/0'));
    }

    #[Test]
    public function canonicalization_keeps_non_numeric_segments_and_the_root_pointer(): void
    {
        $this->assertSame('', ViolationFingerprint::canonicalizeInstancePath(''));
        $this->assertSame('/name', ViolationFingerprint::canonicalizeInstancePath('/name'));
        $this->assertSame('/data/0abc/id', ViolationFingerprint::canonicalizeInstancePath('/data/0abc/id'));
        $this->assertSame('/', ViolationFingerprint::canonicalizeInstancePath('/'));
    }

    #[Test]
    public function from_issue_uses_the_issue_context_and_canonicalizes(): void
    {
        $issue = new ValidationIssue(
            'response.body',
            '[/data/0/id] The data (string) must match the type: integer',
            instancePath: '/data/0/id',
            keyword: 'type',
            method: 'GET',
            path: '/v1/pets',
            statusCode: '200',
            contentType: 'application/json',
        );

        $fingerprint = ViolationFingerprint::fromIssue('front', $issue, 'get', '/v1/pets?page=2');

        $this->assertSame([
            'spec' => 'front',
            'method' => 'GET',
            'path' => '/v1/pets',
            'status_code' => '200',
            'content_type' => 'application/json',
            'category' => 'response.body',
            'parameter' => null,
            'instance_path' => '/data/*/id',
            'keyword' => 'type',
        ], $fingerprint->toArray());
    }

    #[Test]
    public function from_issue_falls_back_to_the_adapter_context_when_the_issue_has_none(): void
    {
        $issue = new ValidationIssue('request.path_match', 'No matching path found');

        $fingerprint = ViolationFingerprint::fromIssue('front', $issue, 'post', '/v1/unknown');

        $this->assertSame([
            'spec' => 'front',
            'method' => 'POST',
            'path' => '/v1/unknown',
            'status_code' => null,
            'content_type' => null,
            'category' => 'request.path_match',
            'parameter' => null,
            'instance_path' => null,
            'keyword' => null,
        ], $fingerprint->toArray());
    }

    #[Test]
    public function fingerprints_of_two_parameters_on_one_operation_differ(): void
    {
        $limit = ViolationFingerprint::fromIssue('front', new ValidationIssue(
            'request.parameter.query',
            '[query.limit] The data (string) must match the type: integer',
            method: 'GET',
            path: '/v1/pets/search',
            parameter: 'limit',
        ), 'GET', '/v1/pets/search');
        $page = ViolationFingerprint::fromIssue('front', new ValidationIssue(
            'request.parameter.query',
            '[query.page] The data (string) must match the type: integer',
            method: 'GET',
            path: '/v1/pets/search',
            parameter: 'page',
        ), 'GET', '/v1/pets/search');

        $this->assertSame('limit', $limit->parameter);
        $this->assertNotSame($limit->key(), $page->key());
    }

    #[Test]
    public function key_distinguishes_a_null_instance_path_from_the_root_pointer(): void
    {
        $root = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', '', 'type');
        $none = new ViolationFingerprint('front', 'GET', '/v1/pets', '200', 'application/json', 'response.body', null, 'type');

        $this->assertNotSame($root->key(), $none->key());
    }

    #[Test]
    public function identical_fingerprints_share_a_key(): void
    {
        $a = new ViolationFingerprint('front', 'GET', '/v1/pets', null, null, 'response.status', null, null);
        $b = new ViolationFingerprint('front', 'GET', '/v1/pets', null, null, 'response.status', null, null);

        $this->assertSame($a->key(), $b->key());
    }
}
