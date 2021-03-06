<?php

/**
 * @link       http://zoopcommerce.github.io/shard
 * @package    Zoop
 * @license    MIT
 */

namespace Zoop\Shard\Serializer\Type;

use Doctrine\Common\Persistence\Mapping\ClassMetadata;

/**
 * Serializes dateTime objects
 *
 * @since   1.0
 * @author  Tim Roediger <superdweebie@gmail.com>
 */
class DateToISO8601 implements TypeSerializerInterface
{
    public function serialize($value, ClassMetadata $metadata, $field)
    {
        switch (true) {
            case $value instanceof \MongoDate:
                $value = new \DateTime("@$value->sec");
            //deliberate fall through
            case $value instanceof \DateTime:
                $value->setTimezone(new \DateTimeZone('UTC'));

                return $value->format('c');
                break;
        }
    }

    public function unserialize($value, ClassMetadata $metadata, $field)
    {
        return new \DateTime($value);
    }
}
