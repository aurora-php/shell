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

use \Octris\Shell\Command;

/**
 * Create command.
 *
 * @copyright   copyright (c) 2021-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
class Shell
{
    /**
     * Constructor.
     */
    protected function __construct(private Command $cmd)
    {
    }

    /**
     * Create command.
     *
     * @param Command $cmd
     * @return self
     */
    public static function create(Command $cmd): self
    {
        return new static($cmd);
    }

    /**
     * Execute command.
     */
    public function exec()
    {
        $chain = $this->cmd->getChain();

        $generators = [];

        foreach ($chain as $item) {
            $generators[] = $gen = $item->exec();

            $gen->send(true);
        }

        while (count($generators) > 0) {
            $generators = array_filter($generators, function ($fn) {
                if (!($is_active = ($fn->send(true) === true))) {
                    $fn->send(false);
                };

                return $is_active;
            });

            usleep(5000);
        }
    }
}
