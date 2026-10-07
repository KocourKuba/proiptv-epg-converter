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
 * Outcome of a single source, as reported by Converter::convert_item().
 */
final class SourceStatus
{
    const CONVERTED = 'converted';
    const UP_TO_DATE = 'up to date';
    const FAILED = 'failed';
    /** In the configuration, but left out of this run by --run. */
    const NOT_RUN = 'not run';

    /**
     * Map the tri-state return of the conversion onto a status.
     *
     * @param int $ret
     * @return string
     */
    public static function from_result(int $ret): string
    {
        return match ($ret) {
            1 => self::CONVERTED,
            2 => self::UP_TO_DATE,
            default => self::FAILED,
        };
    }

    /**
     * Class used by the stylesheet for the status badge.
     *
     * @param string $status
     * @return string
     */
    public static function css(string $status): string
    {
        return match ($status) {
            self::CONVERTED => 'ok',
            self::UP_TO_DATE => 'idle',
            self::NOT_RUN => 'skip',
            default => 'bad',
        };
    }
}
