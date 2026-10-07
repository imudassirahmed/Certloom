<?php

namespace CertloomVendor;

if (!\function_exists('CertloomVendor\dd')) {
    function dd(...$args)
    {
        if (\function_exists('CertloomVendor\dump')) {
            dump(...$args);
        } else {
            \var_dump(...$args);
        }
        die;
    }
}
