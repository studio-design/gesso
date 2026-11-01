<?php

use Studio\Gesso\Coverage\OpenApiCoverageTracker;

if (OpenApiCoverageTracker::hasAnyCoverage('front')) {
    OpenApiCoverageTracker::current()->hasAnyCoverageOn('back');
}

?>
-----
<?php

use Studio\Gesso\Coverage\OpenApiCoverageTracker;

if (OpenApiCoverageTracker::current()->hasAnyCoverageOn('front')) {
    OpenApiCoverageTracker::current()->hasAnyCoverageOn('back');
}

?>
