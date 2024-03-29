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

use Octris\Shell;
use Octris\Shell\StdStream;

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

    protected array $descriptorspec = [];

    protected ?int $pid = null;

    protected array $filter = [];

    private static array $stream_filter_registry = [];

    protected ?int $exit_code = null;

    /**
     * Constructor.
     *
     * @param string $cmd
     * @param array $args
     */
    public function __construct(string $cmd, array $args = [])
    {
        $this->cmd = escapeshellcmd($cmd);
        $this->args = array_map(function ($arg) {
            return escapeshellarg($arg);
        }, $args);

        $this->cwd = getcwd();

        $this->filter = [
            StdStream::STDIN->value => [],
            StdStream::STDOUT->value => [],
            StdStream::STDERR->value => [],
        ];

        $this->setPipe(StdStream::STDOUT, StdStream::STDOUT->getDefault());
        $this->setPipe(StdStream::STDERR, StdStream::STDERR->getDefault());
    }

    /**
     * Create command.
     *
     * @param string $cmd
     * @param array $args
     * @return \Octris\Shell\Command
     */
    public static function __callStatic(string $cmd, array $args): self
    {
        return new static($cmd, ...$args);
    }

    private static function registerStreamFilter(string $class): string
    {
        if (!isset(self::$stream_filter_registry[$class])) {
            self::$stream_filter_registry[$class] = true;

            if (!class_exists($class)) {
                throw new \Exception('Class "' . $class . ' not found."');
            }

            stream_filter_register($class, $class);
        }

        return $class;
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
        $args = array_map(function ($arg) {
            return escapeshellarg($arg);
        }, $args);

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
     * @param StdStream $fd Number of file-descriptor of pipe.
     * @param resource|Command|array $io_spec I/O specification.
     * @return  self
     */
    public function setPipe(StdStream $fd, mixed $io_spec): self
    {
        if ($io_spec instanceof self) {
            // chain commands
            if ($fd == StdStream::STDIN) {
                throw new \InvalidArgumentException('Command chaining not allowed for STDIN');
            }

            $io_spec->setPipe(StdStream::STDIN, StdStream::STDIN->getDefault());

            $this->descriptorspec[$fd->value] = [
                'chain' => $io_spec,
                'write' => function ($str) use ($io_spec) { fwrite($io_spec->pipes[StdStream::STDIN->value], $str); },
                'close' => function () use ($io_spec) { fclose($io_spec->pipes[StdStream::STDIN->value]); },
                'spec' => $fd->getDefault()
            ];

            $this->filter[$fd->value] = [];
        } elseif (is_resource($io_spec)) {
            // assign a stream resource to pipe
            $this->descriptorspec[$fd->value] = [
                'write' => function ($str) {},
                'close' => function () {},
                'spec' => $io_spec
            ];

            $this->filter[$fd->value] = [];
        } elseif (is_array($io_spec) && array_is_list($io_spec)) {
            $cnt = count($io_spec);

            if (!in_array($io_spec[0], ['file', 'pipe']) || !in_array($io_spec[$cnt - 1], ['r', 'w']) || !(($io_spec[0] == 'file' && $cnt == 3) || ($io_spec[0] == 'pipe' && $cnt == 2))) {
                throw new \InvalidArgumentException('Invalid spec type "[' . implode(', ', $io_spec) . ']".');
            }

            $this->descriptorspec[$fd->value] = [
                'write' => function ($str) {},
                'close' => function () {},
                'spec' => $io_spec
            ];

            $this->filter[$fd->value] = [];
        } else {
            throw new \InvalidArgumentException('The second parameter must be a resource, an array spec or an instance of "' . __CLASS__ . '".');
        }

        return $this;
    }

    /**
     * Append a streamfilter.
     *
     * @param StdStream $fd     File-descriptor to add filter for.
     * @param string $class     Name of class of filter.
     * @param mixed $params     Parameters for filter.
     * @param int|null &$id     Id of filter.
     * @return  self
     */
    public function appendStreamFilter(StdStream $fd, string $class, mixed $params, int &$id = null): self
    {
        if (!isset($this->filter[$fd->value])) {
            throw new \RuntimeException('Unable to apply filter to undefined pipe "' . $fd->name . '".');
        }

        $type = ($fd == StdStream::STDIN ? STREAM_FILTER_WRITE : STREAM_FILTER_READ);

        $this->filter[$fd->value][] = function (mixed $stream) use ($class, $type, $params) {
            stream_filter_append($stream, self::registerStreamFilter($class), $type, $params);
        };

        $id = array_key_last($this->filter[$fd->value]);

        return $this;
    }

    /**
     * Prepend a streamfilter.
     *
     * @param StdStream $fd     File-descriptor to add filter for.
     * @param string $class     Name of class of filter.
     * @param mixed $params     Parameters for filter.
     * @param int|null &$id     Id of filter.
     * @return  self
     */
    public function prependStreamFilter(StdStream $fd, string $class, mixed $params, int &$id = null): self
    {
        if (!isset($this->filter[$fd->value])) {
            throw new \RuntimeException('Unable to apply filter to undefined pipe "' . $fd->name . '".');
        }

        $type = ($fd == StdStream::STDIN ? STREAM_FILTER_WRITE : STREAM_FILTER_READ);

        if (!isset($this->filter[$fd->value])) {
            $this->filter[$fd->value] = [];
        }

        $this->filter[$fd->value][] = function (mixed $stream) use ($class, $type, $params) {
            stream_filter_prepend($stream, self::registerStreamFilter($class), $type, $params);
        };

        $id = array_key_last($this->filter[$fd->value]);

        return $this;
    }

    /**
     * Remove streamfilter.
     *
     * @param StdStream $fd
     * @param int $id
     * @return self
     */
    public function removeStreamFilter(StdStream $fd, int $id): self
    {
        if (!isset($this->filter[$fd->value]) || !isset($this->filter[$fd->value][$id])) {
            throw new \InvalidArgumentException('Streamfilter not defined for stream "' . $fd->name . '" and id "' . $id . '".');
        }

        unset($this->filter[$fd->value][$id]);

        return $this;
    }

    /**
     * Return command chain.
     *
     * @return array
     */
    public function getChain(): array
    {
        $result = [ $this ];

        foreach ($this->descriptorspec as $spec) {
            if (isset($spec['chain'])) {
                $result = [...$result, ...$spec['chain']->getChain()];
            }
        }

        return $result;
    }

    /**
     * Return exit code.
     *
     * @return ?int
     */
    public function getExitCode(): ?int
    {
        return $this->exit_code;
    }

    /**
     * Execute command.
     *
     * @return \Generator
     */
    public function exec()
    {
        yield;

        $this->exit_code = null;

        $cmd = 'exec ' . $this->cmd . ' ' . implode(' ', $this->args);

        $specs = array_map(function($p) {
            return $p['spec'];
        }, $this->descriptorspec);

        $ph = proc_open($cmd, $specs, $this->pipes, $this->cwd, $this->env);

        if (!is_resource($ph)) {
            throw new \Exception('error');
        }

        $this->pid = proc_get_status($ph)['pid'];

        yield;

        foreach ($this->pipes as $fd => $sh) {
            foreach ($this->filter[$fd] as $fn) {
                $fn($sh);
            }
        }

        if ($read_output = (isset($this->pipes[StdStream::STDOUT->value]) && is_resource($this->pipes[StdStream::STDOUT->value]))) {
            stream_set_blocking($this->pipes[StdStream::STDOUT->value], false);
        }
        if ($read_error = (isset($this->pipes[StdStream::STDERR->value]) && is_resource($this->pipes[StdStream::STDERR->value]))) {
            stream_set_blocking($this->pipes[StdStream::STDERR->value], false);
        }

        if ($read_error === false && $read_output === false) {
            yield false;
        }

        while ($read_error != false || $read_output != false) {
            if ($read_output != false) {
                if (feof($this->pipes[StdStream::STDOUT->value])) {
                    fclose($this->pipes[StdStream::STDOUT->value]);
                    $read_output = false;
                    $this->descriptorspec[StdStream::STDOUT->value]['close']();
                } else {
                    if (!is_bool($str = fgets($this->pipes[StdStream::STDOUT->value]))) {
                        $this->descriptorspec[StdStream::STDOUT->value]['write']($str);
                    }
                }
            }

            if ($read_error != false) {
                if (feof($this->pipes[StdStream::STDERR->value])) {
                    fclose($this->pipes[StdStream::STDERR->value]);
                    $read_error = false;
                    $this->descriptorspec[StdStream::STDERR->value]['close']();
                } else {
                    if (!is_bool($str = fgets($this->pipes[StdStream::STDERR->value]))) {
                        $this->descriptorspec[StdStream::STDERR->value]['write']($str);
                    }
                }
            }

            $cont = (yield ($read_error != false || $read_output != false));

            if (!$cont) {
                break;
            }
        }

        $status = proc_get_status($ph);

        if ($status['running'] === false && $this->exit_code === null) {
            $this->exit_code = $status['exitcode'];
        }

        proc_close($ph);
    }
}
