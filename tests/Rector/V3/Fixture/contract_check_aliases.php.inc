<?php

namespace App\Tests;

use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\OpenApiContractChecks;

final class ContractTest
{
    public function checks(ExploredCase $case): ContractCheck
    {
        $plan = new \Studio\Gesso\Fuzz\ContractCheckPlan();

        return OpenApiContractChecks::statusCodeConformance();
    }
}

?>
-----
<?php

namespace App\Tests;

use Studio\Gesso\Fuzz\ContractCheck;
use Studio\Gesso\Fuzz\ExploredCase;
use Studio\Gesso\Fuzz\OpenApiContractChecks;

final class ContractTest
{
    public function checks(ExploredCase $case): \Studio\Gesso\Contract\ContractCheck
    {
        $plan = new \Studio\Gesso\Contract\ContractCheckPlan();

        return \Studio\Gesso\Contract\OpenApiContractChecks::statusCodeConformance();
    }
}

?>
