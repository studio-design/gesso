<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Pest configuration
|--------------------------------------------------------------------------
|
| Loaded by the Pest CLI before any tests run. PR1 keeps it intentionally
| empty: the smoke test is self-contained and the wider Pest test surface
| (Laravel auto-assert wiring, expectations against TestResponse) lands
| in PR2, which will populate `uses(...)->in('Integration/Pest')` and
| friends here.
|
| `composer test:pest` runs `pest --colors=always tests/Integration/Pest`
| (the path is baked into the script), so this file does not need to
| filter directories — the script's path argument already does.
*/
