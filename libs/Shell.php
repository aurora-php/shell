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

namespace Octris;

/**
 * Create command.
 *
 * @copyright   copyright (c) 2021-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
class Shell
{
    const STDIN = 0;
    const STDOUT = 1;
    const STDERR = 2;

    /**
     * Create command instance.
     *
     * @param string $cmd
     * @param array $args
     * @return \Octris\Shell\Command
     */
    public static function __callStatic(string $cmd, array $args): \Octris\Shell\Command
    {
        return new \Octris\Shell\Command($cmd, ...$args);
    }
}
