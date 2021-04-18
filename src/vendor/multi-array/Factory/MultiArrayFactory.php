<?php

namespace Sethink\MultiArray\Factory;

use Sethink\MultiArray\MultiArray;

/**
 * Class MultiArrayFactory
 *
 * @author Nate Brunette <n@tebru.net>
 */
class MultiArrayFactory
{
    public function make($jsonOrArray, $delimiter = '.')
    {
        return new MultiArray($jsonOrArray, $delimiter);
    }
}
