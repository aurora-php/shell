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

use \Octris\Shell;

/**
 * Wrapper for proc_open.
 *
 * @copyright   copyright (c) 2021-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
class Command
{
    protected string $cmd = 'true';

    protected array $args = [];

    protected ?string $cwd = null;

    protected array $env = [];

    protected array $pipes = [];

    protected ?int $pid = null;

    protected array $filter = [];

    protected static array $stream_specs = [
        'default' => [ 'pipe', 'w+' ],
        Shell::STDIN => [ 'pipe', 'r' ],
        Shell::STDOUT => [ 'pipe', 'w' ],
        Shell::STDOUT => [ 'pipe', 'w' ]
    ];

    /**
     * Constructor.
     *
     * @param string $cmd
     * @param array $args
     */
    public function __construct(string $cmd, array $args)
    {
        $this->cmd = escapeshellcmd($cmd);
        $this->args = array_map(function ($arg) {
            return escapeshellarg($arg);
        }, $args);

        $this->cwd = getcwd();
    }

    /**
     * Set defaults for a pipe.
     *
     * @param   int                                 $fd             Fd of pipe to set defaults for.
     */
    protected function setDefaults(int $fd)
    {
        $this->pipes[$fd] = [
            'hash' => null,
            'object' => null,
            'fh' => null,
            'spec' => null
        ];
    }

    /**
     * Set current working directory.
     *
     * @param string $path
     * @return self
     */
    public function setCwd(string $path): self
    {
        $this->cwd = $path;

        return $this;
    }

    /**
     * Set environment variables.
     *
     * @param array $env
     * @param bool $merge
     * @return self
     */
    public function setEnv(array $env, bool $merge = true): self
    {
        if ($merge) {
            $this->env = array_merge($this->env, $env);
        } else {
            $this->env = $env;
        }

        return $this;
    }

    /**
     * Set arguments.
     *
     * @param array $args
     * @param bool $merge
     * @return self
     */
    public function setArgs(array $args, bool $merge = true): self
    {
        if ($merge) {
            $this->args = array_merge($this->args, $args);
        } else {
            $this->args = $args;
        }

        return $this;
    }

    /**
     * Set pipe of specified type.
     *
     * @param   int                                 $fd             Number of file-descriptor of pipe.
     * @param   resource|\Octris\Shell\Command      $io_spec        I/O specification.
     * @return  self
     */
    public function setPipe(int $fd, mixed $io_spec): self
    {
        if ($io_spec instanceof self) {
            // chain commands
            $this->pipes[$fd] = [
                'hash'   => spl_object_hash($io_spec),
                'object' => $io_spec,
                'fh'     => $io_spec->usePipeFd(($fd == Shell::STDIN ? Shell::STDOUT : Shell::STDIN)),
                'spec'   => (isset(self::$stream_specs[$fd])
                                ? self::$stream_specs[$fd]
                                : self::$stream_specs['default'])
            ];
        } elseif (is_resource($io_spec)) {
            // assign a stream resource to pipe
            $this->pipes[$fd] = [
                'hash'   => null,
                'object' => null,
                'fh'     => $io_spec,
                'spec'   => (isset(self::$stream_specs[$fd])
                                ? self::$stream_specs[$fd]
                                : self::$stream_specs['default'])
            ];
        } else {
            throw new \InvalidArgumentException('$io_spec is neither a resource nor an instance of ' . __CLASS__);
        }

        return $this;
    }

    /**
     * Add a streamfilter.
     *
     * @param   string                              $name
     * @param   int                                 $fd             File-descriptor to add filter for.
     * @param   callable                            $fn
     * @return  self
     */
    public function appendStreamFilter(string $name, int $fd, callable $fn): self
    {
        if (isset($this->filter[$name])) {
            throw new \InvalidArgumentException('A filter with the name "' . $name . '" already exists.');
        }


/*
        $type = ($fd == 'STREAM_FILTER_READ
        
        $this->filter[$name] = function (mixed $stream, ) use ($fn) {

        };

        //stream_filter_append($stream, register(), $read_write, $callback);

        return $this; */
    }

    /**
     * Returns file handle of a pipe and changes descriptor specification according to the usage
     * through a file handle.
     *
     * @param   int                                 $fd             Number of file-descriptor to return.
     * @return  resource                                            A Filedescriptor.
     */
    public function usePipeFd(int $fd): mixed
    {
        if (!isset($this->pipes[$fd])) {
            $this->setDefaults($fd);
        }

        $this->pipes[$fd]['spec'] = (isset(self::$stream_specs[$fd])
            ? self::$stream_specs[$fd]
            : self::$stream_specs['default']);

        return $fh =& $this->pipes[$fd]['fh'];      /*
                                                     * reference here means:
                                                     * file handle can be changed within the class instance
                                                     * but not outside the class instance
                                                     */
    }

    /**
     * Execute command.
     */
    public function exec()
    {
        $pipes = [];
        $cmd = 'exec ' . $this->cmd . ' ' . implode(' ', $this->args);

        $specs = array_map(function($p) {
            return $p['spec'];
        }, $this->pipes);

        $ph = proc_open($cmd, $specs, $pipes, $this->cwd, $this->env);

        if (is_resource($ph)) {
            $this->pid = proc_get_status($ph)['pid'];
        }

        $read_error = $read_output = true;

        print "progress: ";

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Dual reading of STDOUT and STDERR stops one full pipe blocking
        // the other because the external script is waiting
        while ($read_error != false || $read_output != false) {
            if ($read_output != false) {
                if (feof($pipes[1])) {
                    fclose($pipes[1]);
                    $read_output = false;
                } else {
                    // STDOUT line.
                    // Check conditions here.
                    $str = trim(fgets($pipes[1]));

                    if (preg_match('/^Encoding: .+?([0-9]+(\.[0-9]+))\s*%/', $str, $match)) {
                        print "\r" . str_repeat(' ', 50);
                        print "\rprogress: " . $match[1] . "%";
                    }
                }
            }

            if ($read_error != false) {
                if (feof($pipes[2])) {
                    fclose($pipes[2]);
                    $read_error = false;
                } else {
                    // STDERR line.
                    // Check conditions here.
                    $str = trim(fgets($pipes[2]));
                }
            }
        }

        print "\rprogress: done." . str_repeat(' ', 20) . "\n";

        proc_close($ph);
    }
}
