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

    private static ?string $registered = null;

    protected static array $stream_specs = [
        'default' => ['pipe', 'w+'],
        Shell::STDIN => ['pipe', 'r'],
        Shell::STDOUT => ['pipe', 'w'],
        Shell::STDOUT => ['pipe', 'w']
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

    private static function registerStreamFilter(): string
    {
        if (is_null(self::$registered)) {
            self::$registered = \Octris\Shell\StreamFilter::class;

            if (!class_exists(self::$registered)) {
                throw new \Exception('Class "' . self::$registered . ' not found."');
            }

            stream_filter_register(self::$registered, self::$registered);
        }

        return self::$registered;
    }

    /**
     * Set defaults for a pipe.
     *
     * @param int $fd Fd of pipe to set defaults for.
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
     * @param int $fd Number of file-descriptor of pipe.
     * @param resource|\Octris\Shell\Command $io_spec I/O specification.
     * @return  self
     */
    public function setPipe(int $fd, mixed $io_spec): self
    {
        if ($io_spec instanceof self) {
            // chain commands
            $this->pipes[$fd] = [
                'hash' => spl_object_hash($io_spec),
                'object' => $io_spec,
                'fh' => $io_spec->usePipeFd(($fd == Shell::STDIN ? Shell::STDOUT : Shell::STDIN)),
                'spec' => (isset(self::$stream_specs[$fd])
                    ? self::$stream_specs[$fd]
                    : self::$stream_specs['default'])
            ];

            $this->filter[$fd] = [];
        } elseif (is_resource($io_spec)) {
            // assign a stream resource to pipe
            $this->pipes[$fd] = [
                'hash' => null,
                'object' => null,
                'fh' => $io_spec,
                'spec' => (isset(self::$stream_specs[$fd])
                    ? self::$stream_specs[$fd]
                    : self::$stream_specs['default'])
            ];

            $this->filter[$fd] = [];
        } else {
            throw new \InvalidArgumentException('$io_spec is neither a resource nor an instance of ' . __CLASS__);
        }

        return $this;
    }

    /**
     * Append a streamfilter.
     *
     * @param int $fd File-descriptor to add filter for.
     * @param callable $fn
     * @param int                                 &$id
     * @return  self
     */
    public function appendStreamFilter(int $fd, callable $fn, mixed &$id = null): self
    {
        if (!isset($this->filter[$fd])) {
            throw new \RuntimeException('Pipe is not set for "' . $fd . '".');
        }

        $type = ($fd == Shell::STDIN ? STREAM_FILTER_WRITE : STREAM_FILTER_READ);

        $this->filter[$fd][] = function (mixed $stream) use ($type, $fn) {
            stream_filter_append($stream, self::registerStreamFilter(), $type, $fn);
        };

        $id = array_key_last($this->filter[$fd]);

        return $this;
    }

    /**
     * Prepend a streamfilter.
     *
     * @param int $fd File-descriptor to add filter for.
     * @param callable $fn
     * @param int                                 &$id
     * @return  self
     */
    public function prependStreamFilter(int $fd, callable $fn, mixed &$id = null): self
    {
        $type = ($fd == Shell::STDIN ? STREAM_FILTER_WRITE : STREAM_FILTER_READ);

        if (!isset($this->filter[$fd])) {
            $this->filter[$fd] = [];
        }

        $this->filter[$fd][] = function (mixed $stream) use ($type, $fn) {
            stream_filter_prepend($stream, self::registerStreamFilter(), $type, $fn);
        };

        $id = array_key_last($this->filter[$fd]);

        return $this;
    }

    /**
     * Remove streamfilter.
     *
     * @param int $fd
     * @param int $id
     * @return self
     */
    public function removeStreamFilter(int $fd, int $id): self
    {
        if (!isset($this->filter[$fd]) || !isset($this->filter[$fd][$id])) {
            throw new \InvalidArgumentException('Streamfilter not defined for stream "' . $fd . '" and id "' . $id . '".');
        }

        unset($this->filter[$fd][$id]);

        return $this;
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

        if (!is_resource($ph)) {
            throw new \Exception('error');
        }

        $this->pid = proc_get_status($ph)['pid'];

        foreach ($pipes as $fd => $sh) {
            foreach ($this->filter[$fd] as $fn) {
                $fn($sh);
            }
        }

        if ($read_output = (isset($pipes[Shell::STDOUT]) && is_resource($pipes[shell::STDOUT]))) {
            stream_set_blocking($pipes[Shell::STDOUT], false);
        }
        if ($read_error = (isset($pipes[Shell::STDERR]) && is_resource($pipes[shell::STDERR]))) {
            stream_set_blocking($pipes[Shell::STDERR], false);
        }

        while ($read_error != false || $read_output != false) {
            if ($read_output != false) {
                if (feof($pipes[Shell::STDOUT])) {
                    fclose($pipes[Shell::STDOUT]);
                    $read_output = false;
                } else {
                    fgets($pipes[Shell::STDOUT]);
                }
            }

            if ($read_error != false) {
                if (feof($pipes[Shell::STDERR])) {
                    fclose($pipes[Shell::STDERR]);
                    $read_error = false;
                } else {
                    fgets($pipes[Shell::STDERR]);
                }
            }
        }

        proc_close($ph);
    }
}
