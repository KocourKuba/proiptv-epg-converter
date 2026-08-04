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

/**
 * @param $value
 * @param string $logname
 * @param string $method
 * @return void
 */
enum severity
{
    case Dbg;
    case Notice;
    case Inf;
    case Warn;
    case Err;
}

class Logger
{
    protected static string $logName = 'default.log';
    protected static severity $severity = severity::Inf;

    public static function setLogName(string $logName): void
    {
        self::$logName = $logName;
    }

    public static function setSeverity(severity $severity): void
    {
        self::$severity = $severity;
    }

    public static function log_separator(): void
    {
        $fp = fopen(self::$logName, "ab");
        if($fp)
        {
            fwrite($fp, date("[Y.m.d H:i:s] ") . str_repeat('-', 80) . PHP_EOL);
            fclose($fp);
        }

    }

    /**
     * @param severity $severity
     * @param string $value
     * @return void
     */
    public static function log(severity $severity, string $value): void
    {
        if ($severity < self::$severity) {
            return;
        }

        if (!str_ends_with($value, PHP_EOL))
        {
            $value .= PHP_EOL;
        }

        $fp = fopen(self::$logName, "ab");
        if($fp)
        {
            fwrite($fp, date("[Y.m.d H:i:s] ") . $value);
            fclose($fp);
        }
    }
}
