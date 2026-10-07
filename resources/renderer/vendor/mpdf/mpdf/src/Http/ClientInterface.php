<?php

namespace CertloomVendor\Mpdf\Http;

use CertloomVendor\Psr\Http\Message\RequestInterface;
interface ClientInterface
{
    public function sendRequest(RequestInterface $request);
}
