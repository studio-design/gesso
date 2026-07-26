# Symfony quickstart

```bash
composer require --dev "studio-design/gesso:^2.0" symfony/http-foundation symfony/http-kernel
```

Register the extension in `phpunit.xml`. Gesso finds spec files through the
PHPUnit extension, not a Symfony bundle:

```xml
<extensions>
    <bootstrap class="Studio\Gesso\PHPUnit\OpenApiCoverageExtension">
        <parameter name="spec_base_path" value="openapi"/>
        <parameter name="specs" value="petstore"/>
    </bootstrap>
</extensions>
```

Mix `Studio\Gesso\Symfony\OpenApiAssertions` into a PHPUnit or `WebTestCase`
class and name the spec with `#[OpenApiSpec]` (or override `openApiSpec()`):

```php
use PHPUnit\Framework\TestCase;
use Studio\Gesso\Attribute\OpenApiSpec;
use Studio\Gesso\Symfony\OpenApiAssertions;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

#[OpenApiSpec('petstore')]
class PetsApiTest extends TestCase
{
    use OpenApiAssertions;

    public function test_pets_index(): void
    {
        $request = Request::create('/pets', 'GET');
        $response = new Response('[{"id":1,"name":"Fido"}]', 200, [
            'Content-Type' => 'application/json',
        ]);

        $this->assertResponseMatchesOpenApiSchema($request, $response);
    }
}
```

The trait adds three assertions: `assertResponseMatchesOpenApiSchema($request, $response)`,
`assertRequestMatchesOpenApiSchema($request)`, and
`assertClientMatchesOpenApiSchema($client)`, which runs both against the last
client call.

Run the CI-tested [`examples/symfony`](https://github.com/studio-design/gesso/tree/main/examples/symfony) fixture:

```bash
composer test
```

For a full application, call `assertClientMatchesOpenApiSchema($client)` after `KernelBrowser::request()`. Both forms record coverage.
