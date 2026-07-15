<?php

declare(strict_types=1);

namespace Studio\Gesso\Tests\Unit\Laravel;

use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Laravel\RouteParity\LaravelRouteParityAnalyzer;

use function array_column;

final class LaravelRouteParityAnalyzerTest extends TestCase
{
    #[Test]
    public function matches_parameterized_route_after_runtime_prefix_normalization(): void
    {
        $route = new Route(['GET'], 'api/v2/pets/{pet}', static fn() => null);
        $route->name('pets.show');

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec(['/v2/pets/{petId}' => ['get' => ['operationId' => 'showPet']]])],
            [$route],
            ['/api'],
        );

        $this->assertCount(1, $result->matched);
        $this->assertSame('GET', $result->matched[0]['method']);
        $this->assertSame('/v2/pets/{petId}', $result->matched[0]['openapi_path']);
        $this->assertSame('/api/v2/pets/{pet}', $result->matched[0]['route_uri']);
        $this->assertSame([], $result->documentedButNotRegistered);
        $this->assertSame([], $result->registeredButUndocumented);
    }

    #[Test]
    public function implicit_head_matches_an_explicit_openapi_head_operation(): void
    {
        $route = new Route(['GET'], 'pets', static fn() => null);

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec([
                '/pets' => [
                    'get' => ['operationId' => 'listPets'],
                    'head' => ['operationId' => 'inspectPets'],
                ],
            ])],
            [$route],
        );

        $this->assertSame(['GET', 'HEAD'], array_column($result->matched, 'method'));
        $this->assertSame([], $result->documentedButNotRegistered);
        $this->assertSame([], $result->registeredButUndocumented);
    }

    #[Test]
    public function expands_optional_parameters_and_multi_method_routes(): void
    {
        $optional = new Route(['GET'], 'users/{user?}', static fn() => null);
        $multiMethod = new Route(['GET', 'POST'], 'pets', static fn() => null);

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec([
                '/users' => ['get' => []],
                '/users/{id}' => ['get' => []],
                '/pets' => ['get' => [], 'post' => []],
            ])],
            [$optional, $multiMethod],
        );

        $this->assertCount(4, $result->matched);
        $this->assertSame([], $result->documentedButNotRegistered);
        $this->assertSame([], $result->registeredButUndocumented);
    }

    #[Test]
    public function selected_specs_are_compared_independently(): void
    {
        $route = new Route(['GET'], 'pets', static fn() => null);

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            [
                'front' => $this->spec(['/pets' => ['get' => []]]),
                'admin' => $this->spec(['/pets' => ['get' => []]]),
            ],
            [$route],
        );

        $this->assertCount(2, $result->matched);
        $this->assertSame(['front', 'admin'], array_column($result->matched, 'spec'));
        $this->assertSame([], $result->ambiguous);
    }

    #[Test]
    public function filters_by_prefix_middleware_domain_and_route_name(): void
    {
        $included = new Route(['GET'], 'api/pets', static fn() => null);
        $included->middleware('api')->domain('api.example.com')->name('pets.index');

        $wrongMiddleware = new Route(['GET'], 'api/internal', static fn() => null);
        $wrongMiddleware->middleware('web')->domain('api.example.com')->name('internal.index');

        $excludedByName = new Route(['GET'], 'api/health', static fn() => null);
        $excludedByName->middleware('api')->domain('api.example.com')->name('health.check');

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec(['/api/pets' => ['get' => []]])],
            [$included, $wrongMiddleware, $excludedByName],
            filters: [
                'prefix' => 'api',
                'middleware' => ['api'],
                'domains' => ['api.example.com'],
                'excluded_route_names' => ['health.*'],
            ],
        );

        $this->assertCount(1, $result->matched);
        $this->assertSame('pets.index', $result->matched[0]['route_name']);
        $this->assertSame([], $result->registeredButUndocumented);
    }

    #[Test]
    public function classifies_unmatched_operation_id_and_path_patterns_as_external(): void
    {
        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec([
                '/gateway/forms/{formId}' => ['get' => ['operationId' => 'forms.show']],
                '/gateway/proxy/chat' => ['post' => ['operationId' => 'proxyChat']],
                '/local/missing' => ['delete' => ['operationId' => 'deleteMissing']],
            ])],
            [],
            filters: [
                'excluded_operation_ids' => ['forms.*'],
                'excluded_openapi_paths' => ['/gateway/proxy/*'],
            ],
        );

        $this->assertSame(
            ['forms.show', 'proxyChat'],
            array_column($result->externalOperations, 'operation_id'),
        );
        $this->assertSame(
            ['deleteMissing'],
            array_column($result->documentedButNotRegistered, 'operation_id'),
        );
    }

    #[Test]
    public function documented_side_exclusions_do_not_hide_matched_operations(): void
    {
        $route = new Route(['GET'], 'gateway/forms/{form}', static fn() => null);

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec([
                '/gateway/forms/{formId}' => ['get' => ['operationId' => 'forms.show']],
            ])],
            [$route],
            filters: [
                'excluded_operation_ids' => ['forms.*'],
                'excluded_openapi_paths' => ['/gateway/*'],
            ],
        );

        $this->assertCount(1, $result->matched);
        $this->assertSame([], $result->externalOperations);
        $this->assertSame([], $result->documentedButNotRegistered);
    }

    #[Test]
    public function reports_fallback_and_custom_method_without_openapi_declaration(): void
    {
        $fallback = new Route(['GET'], '{fallbackPlaceholder}', static fn() => null);
        $fallback->fallback()->name('fallback');
        $custom = new Route(['CONNECT'], 'tunnel', static fn() => null);

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec([])],
            [$fallback, $custom],
        );

        $this->assertSame('fallback_route', $result->ambiguous[0]['kind']);
        $this->assertSame('CONNECT', $result->unsupported[0]['method']);
    }

    #[Test]
    public function preserves_additional_operation_capitalization(): void
    {
        $upper = new Route(['COPY'], 'documents', static fn() => null);

        $result = (new LaravelRouteParityAnalyzer())->analyze(
            ['front' => $this->spec([
                '/documents' => [
                    'additionalOperations' => [
                        'COPY' => [],
                        'copy' => [],
                    ],
                ],
            ])],
            [$upper],
        );

        $this->assertCount(1, $result->matched);
        $this->assertSame('COPY', $result->matched[0]['method']);
        $this->assertSame('copy', $result->documentedButNotRegistered[0]['method']);
    }

    /**
     * @param array<string, array<string, mixed>> $paths
     *
     * @return array<string, mixed>
     */
    private function spec(array $paths): array
    {
        return ['openapi' => '3.2.0', 'paths' => $paths];
    }
}
