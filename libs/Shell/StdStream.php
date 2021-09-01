<?php

/*
 * This file is part of the 'octris/shell' package.
 *
 * (c) Harald Lapp <harald@octris.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Octris\Shell;

/**
 * Standard streams.
 *
 * @copyright   copyright (c) 2021-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
enum StdStream: int
{
    case STDIN = 0;
    case STDOUT = 1;
    case STDERR = 2;

    public function getDefault(): array
    {
        return match($this) {
            self::STDIN => ['pipe', 'r'],
            self::STDOUT => ['pipe', 'w'],
            self::STDERR => ['pipe', 'w']
        };
    }
}
