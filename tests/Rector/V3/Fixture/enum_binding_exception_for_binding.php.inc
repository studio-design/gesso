<?php

use Studio\Gesso\Exception\EnumBindingException;

throw EnumBindingException::forBinding($reason, 'message', $enumFqcn, previous: $previous);

?>
-----
<?php

use Studio\Gesso\Exception\EnumBindingException;

throw new \Studio\Gesso\Exception\EnumBindingException($reason, 'message', $enumFqcn, previous: $previous);

?>
