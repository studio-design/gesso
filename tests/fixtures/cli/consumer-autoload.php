<?php

declare(strict_types=1);

// A distinct exit proves the CLI selected the consumer's autoloader before
// the repository's vendor directory. The example exercises a real proxy too.
echo "consumer autoloader selected\n";
exit(23);
