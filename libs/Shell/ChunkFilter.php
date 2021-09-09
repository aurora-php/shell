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
 * Callback filter for shell streams.
 *
 * @copyright   copyright (c) 2021-present by Harald Lapp
 * @author      Harald Lapp <harald@octris.org>
 */
class ChunkFilter extends \php_user_filter
{
    private bool $supports_close = false;

    private bool $is_closed = false;

    /**
     * Called when creating the filter.
     *
     * @return  bool
     */
    public function onCreate(): bool
    {
        $this->is_closed = false;
        $this->supports_close = ((new \ReflectionFunction($this->params))->getNumberOfRequiredParameters() === 0);

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

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $data .= $bucket->data;

            stream_bucket_append($out, $bucket);
        }

        if ($this->is_closed) {
            return PSFS_FEED_ME;
        }

        if ($data !== '') {
            try {
                $data = ($this->params)($data);
            } catch (\Exception $e) {
                $this->onClose();

                trigger_error('Error invoking filter: ' . $e->getMessage(), E_USER_WARNING);

                return PSFS_ERR_FATAL;
            }
        }

        if ($closing) {
            $this->is_closed = true;

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
            $bucket = stream_bucket_new($this->stream, $data);

            if ($bucket !== false) {
                stream_bucket_append($out, $bucket);
            }
        }

        return PSFS_PASS_ON;
    }
}
