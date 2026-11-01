<?php

use Studio\Gesso\Spec\OpenApiSpecLoader;

OpenApiSpecLoader::configure('openapi/bundled', enumBasePath: 'openapi');
OpenApiSpecLoader::configure('openapi', ['/api'], null, null, false, 'openapi/enums', ['example.com']);
OpenApiSpecLoader::configure('openapi', ['/api'], null, null, false);
OpenApiSpecLoader::configure('openapi', allowedRemoteRefHosts: ['example.com']);
OpenApiSpecLoader::configure('openapi', [], null, null, false, ['v3.example.com'], 1024);
OpenApiSpecLoader::configure('openapi', [], null, null, false, $unknown);
OpenApiSpecLoader::configure('openapi', [], null, null, false, null);
OpenApiSpecLoader::configure(...$arguments);
\Other\Loader::configure('a', [], null, null, false, 'b');

?>
-----
<?php

use Studio\Gesso\Spec\OpenApiSpecLoader;

OpenApiSpecLoader::configure('openapi/bundled');
OpenApiSpecLoader::configure('openapi', ['/api'], null, null, false, ['example.com']);
OpenApiSpecLoader::configure('openapi', ['/api'], null, null, false);
OpenApiSpecLoader::configure('openapi', allowedRemoteRefHosts: ['example.com']);
OpenApiSpecLoader::configure('openapi', [], null, null, false, ['v3.example.com'], 1024);
OpenApiSpecLoader::configure('openapi', [], null, null, false, $unknown);
OpenApiSpecLoader::configure('openapi', [], null, null, false);
OpenApiSpecLoader::configure(...$arguments);
\Other\Loader::configure('a', [], null, null, false, 'b');

?>
