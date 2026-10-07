<?php

namespace CertloomVendor\Mpdf\File;

class LocalContentLoader implements \CertloomVendor\Mpdf\File\LocalContentLoaderInterface
{
    public function load($path)
    {
        return file_get_contents($path);
    }
}
