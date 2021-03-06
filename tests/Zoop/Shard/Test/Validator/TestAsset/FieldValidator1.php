<?php

namespace Zoop\Shard\Test\Validator\TestAsset;

use Zoop\Mystique\Base;
use Zoop\Mystique\Result;

class FieldValidator1 extends Base
{
    public function isValid($value)
    {
        $messages = [];

        if ($value == 'valid' || $value == 'alsoValid') {
            $result = true;
        } else {
            $messages[] = 'invalid name 1';
            $result = false;
        }

        return new Result(['value' => $result, 'messages' => $messages]);
    }
}
