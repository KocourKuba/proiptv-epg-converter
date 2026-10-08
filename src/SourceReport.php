<?php
/**
 * The MIT License (MIT)
 *
 * @Author: sharky72 (https://github.com/KocourKuba)
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense
 * of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included
 * in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL
 * THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 */

namespace Proiptv\EpgConverter;

/**
 * Everything the page shows about one source.
 *
 * The counters come from the source database rather than from the run itself, so a
 * source that was skipped as up to date still reports what it currently serves.
 */
final class SourceReport
{
    /** @var string */
    public string $id;
    /** @var string */
    public string $url;
    /** @var string One of the SourceStatus constants. */
    public string $status;
    /** @var string */
    public string $error;
    /** @var int Channels that have a guide, i.e. the ones the source serves. */
    public int $channels;
    /** @var int Every channel the source lists, with a guide or not. 0 if not known. */
    public int $channels_total;
    /** @var int */
    public int $picons;
    /** @var int */
    public int $programmes;
    /** @var int */
    public int $files;
    /** @var int */
    public int $files_size;
    /** @var int */
    public int $epg_start;
    /** @var int */
    public int $epg_end;
    /** @var int */
    public int $last_update;
    /** @var float Seconds spent fetching the source. */
    public float $download_time;
    /** @var float Seconds spent on everything after the download: unpacking, indexing, json. */
    public float $process_time;
    /** @var string Detail page of this source, relative to its directory, or '' if none. */
    public string $detail = '';

    /**
     * Built from an array so the call site names what it passes - there are too many
     * counters here for a positional argument list to stay readable.
     *
     * @param array $params
     */
    public function __construct(array $params)
    {
        $this->id = (string)safe_get_value($params, 'id', '');
        $this->url = (string)safe_get_value($params, 'url', '');
        $this->status = (string)safe_get_value($params, 'status', SourceStatus::FAILED);
        $this->error = (string)safe_get_value($params, 'error', '');
        $this->channels = (int)safe_get_value($params, 'channels', 0);
        $this->channels_total = (int)safe_get_value($params, 'channels_total', 0);
        $this->picons = (int)safe_get_value($params, 'picons', 0);
        $this->programmes = (int)safe_get_value($params, 'programmes', 0);
        $this->files = (int)safe_get_value($params, 'files', 0);
        $this->files_size = (int)safe_get_value($params, 'files_size', 0);
        $this->epg_start = (int)safe_get_value($params, 'epg_start', 0);
        $this->epg_end = (int)safe_get_value($params, 'epg_end', 0);
        $this->last_update = (int)safe_get_value($params, 'last_update', 0);
        $this->download_time = (float)safe_get_value($params, 'download_time', 0.0);
        $this->process_time = (float)safe_get_value($params, 'process_time', 0.0);
    }

}
