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

    protected $ph;

    protected ?int $pid = null;

    protected array $filter = [];

    protected bool $running = false;

    private static ?string $registered = null;

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
     * @param StdStream $fd Number of file-descriptor of pipe.
     * @param resource|Command $io_spec I/O specification.
     * @return  self
     */
    public function setPipe(StdStream $fd, mixed $io_spec): self
    {
        if ($io_spec instanceof self) {
            // chain commands
            if ($fd == StdStream::STDIN) {
                throw new \InvalidArgumentException('Command chaining not allowed for STDIN');
            }

     //       var_dump([$fd, $fd == StdStream::STDIN, ($fd == StdStream::STDIN ? StdStream::STDOUT : StdStream::STDIN)]);

            /*$io_spec->descriptorspec[StdStream::STDIN->value] = [
                'write' => function ($str) {},
                'spec' => StdStream::STDIN->getDefault()
            ];*/

            $io_spec->setPipe(StdStream::STDIN, StdStream::STDIN->getDefault());

            $this->descriptorspec[$fd->value] = [
                'chain' => $io_spec,
                'write' => [ $io_spec, 'write' ],
                'spec' => $fd->getDefault()
            ];

            $this->filter[$fd->value] = [];
        } elseif (is_resource($io_spec)) {
            // assign a stream resource to pipe
            $this->descriptorspec[$fd->value] = [
                'write' => function ($str) {
                },
                'spec' => $io_spec
            ];

            $this->filter[$fd->value] = [];
        } elseif (is_array($io_spec) && array_is_list($io_spec)) {
            $this->descriptorspec[$fd->value] = [
                'write' => function ($str) {
                },
                'spec' => $io_spec
            ];

            $this->filter[$fd->value] = [];
        } else {
            throw new \InvalidArgumentException('$io_spec is neither a resource, an array nor an instance of ' . __CLASS__);
        }

        return $this;
    }

    /**
     * Append a streamfilter.
     *
     * @param StdStream $fd File-descriptor to add filter for.
     * @param callable $fn
     * @param int                                 &$id
     * @return  self
     */
    public function appendStreamFilter(StdStream $fd, callable $fn, mixed &$id = null): self
    {
        /*if (!isset($this->filter[$fd->value])) {
            throw new \RuntimeException('Unable to apply filter to undefined pipe "' . $fd->name . '".');
        }*/

        $type = ($fd == StdStream::STDIN ? STREAM_FILTER_WRITE : STREAM_FILTER_READ);

        $this->filter[$fd->value][] = function (mixed $stream) use ($type, $fn) {
            stream_filter_append($stream, self::registerStreamFilter(), $type, $fn);
        };

        $id = array_key_last($this->filter[$fd->value]);

        return $this;
    }

    /**
     * Prepend a streamfilter.
     *
     * @param StdStream $fd File-descriptor to add filter for.
     * @param callable $fn
     * @param int                                 &$id
     * @return  self
     */
    public function prependStreamFilter(StdStream $fd, callable $fn, mixed &$id = null): self
    {
        /*if (!isset($this->filter[$fd->value])) {
            throw new \RuntimeException('Unable to apply filter to undefined pipe "' . $fd->name . '".');
        }*/

        $type = ($fd == StdStream::STDIN ? STREAM_FILTER_WRITE : STREAM_FILTER_READ);

        if (!isset($this->filter[$fd->value])) {
            $this->filter[$fd->value] = [];
        }

        $this->filter[$fd->value][] = function (mixed $stream) use ($type, $fn) {
            stream_filter_prepend($stream, self::registerStreamFilter(), $type, $fn);
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
     * Returns file handle of a pipe and changes descriptor specification according to the usage
     * through a file handle.
     *
     * @param   StdStream                           $fd             Number of file-descriptor to return.
     * @param   resource                            $fh             Resource handler.
     */
    private function usePipeFd(StdStream $fd, mixed $fh)
    {

        $this->descriptorspec[$fd->value] = [
            'hash' => null,
            'object' => null,
            'fh' => null,
            'spec' => $fh
        ];
    }

    /**
     * Return nested commands.
     *
     * @return array
     */
    public function getNested(): array
    {
        $result = [ $this->cmd => $this ];

        foreach ($this->descriptorspec as $fd => $spec) {
            if (isset($spec['chain'])) {
                $result = [...$result, ...$spec['chain']->getNested()];
            }
        }

        return $result;
    }

    private function dprint($msg)
    {
        print $this->cmd . ' ' . $msg . "\n";
    }

    /**
     * Start execution.
     */
    public function start()
    {
        if (!$this->running) {
            $this->running = true;

            $this->dprint("started");

            $cmd = 'exec ' . $this->cmd . ' ' . implode(' ', $this->args);

            $specs = array_map(function($p) {
                return $p['spec'];
            }, $this->descriptorspec);

            $this->ph = proc_open($cmd, $specs, $this->pipes, $this->cwd, $this->env);
            $this->dprint('proc_open');

            if (!is_resource($this->ph)) {
                throw new \Exception('error');
            }

            $this->pid = proc_get_status($this->ph)['pid'];
            //var_dump([$this->cmd, $this->pipes, $this->filter]);
        }
    }

    private function write($str)
    {
        var_dump($this->pipes[StdStream::STDIN->value]);

        fwrite($this->pipes[StdStream::STDIN->value], $str);
    }

    /**
     * Execute command.
     */
    public function exec()
    {
        if (!$this->running) {
            return;
        }

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
                } else {
                    if (!is_bool($str = fgets($this->pipes[StdStream::STDERR->value]))) {
                        $this->descriptorspec[StdStream::STDERR->value]['write']($str);
                    }
                }
            }

            $this->dprint("interrupt");
            $break = (yield ($read_error != false || $read_output != false));
            $this->dprint("continue");

            /*if ($break) {
                break;
            }*/
        }

        proc_close($this->ph);

        $this->dprint('done');
    }
}
