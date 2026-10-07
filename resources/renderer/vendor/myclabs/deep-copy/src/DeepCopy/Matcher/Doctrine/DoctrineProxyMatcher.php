<?php

namespace CertloomVendor\DeepCopy\Matcher\Doctrine;

use CertloomVendor\DeepCopy\Matcher\Matcher;
use CertloomVendor\Doctrine\Persistence\Proxy;
/**
 * @final
 */
class DoctrineProxyMatcher implements Matcher
{
    /**
     * Matches a Doctrine Proxy class.
     *
     * {@inheritdoc}
     */
    public function matches($object, $property)
    {
        return $object instanceof Proxy;
    }
}
