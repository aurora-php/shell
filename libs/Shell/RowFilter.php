<?php

declare(strict_types=1);

/*
 * This file is part of the 'octris/shell' package.
 *
 * (c) Harald Lapp <harald@octris.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Octris\Shell;

/**
 * Row-based callback filter for shell streams.
 *
 * @copyright   copyright (c) 2021-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
class RowFilter extends \php_user_filter
{
    private bool $supports_close = false;

    private bool $is_closed = false;

    private string $row = '';

    /**
     * Called when creating the filter.
     *
     * @return  bool
     */
    public function onCreate(): bool
    {
        $this->is_closed = false;
        $this->supports_close = ((new \ReflectionFunction($this->params))->getNumberOfRequiredParameters() === 0);
        $this->row = '';

        return true;
    }

    /**
     * Called when closing the filter.
     */
    public function onClose(): void
    {
        if (!$this->is_closed) {
            $this->is_closed = true;

            if ($this->supports_close) {
                try {
                    ($this->params)();
                } catch (\Exception $ignored) {
                }
            }
        }
    }

    /**
     * Called when filter is applied.
     *
     * @param   resource    $in
     * @param   resource    $out
     * @param   int         &$consumed
     * @param   bool        $closing
     * @return  int
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        $data = '';
        $output = '';

        while ($bucket = stream_bucket_make_writeable($in)) {
            $data = $bucket->data;

            while (($pos = strpos($data, "\n")) !== false) {
                $this->row .= substr($data, 0, $pos + 1);
                $data = substr($data, $pos + 1);

                try {
                    $output .= ($this->params)($this->row);

                    $this->row = '';
                } catch (\Exception $e) {
                    $this->onClose();

                    trigger_error('Error invoking filter: ' . $e->getMessage(), E_USER_WARNING);

                    return PSFS_ERR_FATAL;
                }
            }

            if ($data !== '') {
                $this->row .= $data;
            }

            $consumed += $bucket->datalen;
        }

        if ($this->is_closed) {
            return PSFS_FEED_ME;
        }

        if ($closing) {
            $this->is_closed = true;

            if ($this->row !== '') {
                try {
                    $output .= ($this->params)($this->row);

                    $this->row = '';
                } catch (\Exception $e) {
                    $this->onClose();

                    trigger_error('Error invoking filter: ' . $e->getMessage(), E_USER_WARNING);

                    return PSFS_ERR_FATAL;
                }
            }

            if ($this->supports_close) {
                try {
                    $data .= ($this->params)();
                } catch (\Exception $e) {
                    trigger_error('Error ending filter: ' . $e->getMessage(), E_USER_WARNING);

                    return PSFS_ERR_FATAL;
                }
            }
        }

        if ($data !== '') {
            $bucket = stream_bucket_new($this->stream, $output);

            if ($bucket !== false) {
                stream_bucket_append($out, $bucket);
            }
        }

        return PSFS_PASS_ON;
    }
}
