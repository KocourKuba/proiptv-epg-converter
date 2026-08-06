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
class Logger
{
    const Dbg = 0;
    const Ntc = 1;
    const Inf = 2;
    const Wrn = 3;
    const Err = 4;

    protected static string $log_path = 'default.log';
    protected static int $severity = self::Inf;

    public static function setLogPath(string $log_path): void
    {
        self::$log_path = $log_path;
    }

    public static function setSeverity(string $severity): void
    {
        switch (strtolower($severity)) {
            case 'debug':
                self::$severity = self::Dbg;
                break;
            case 'notice':
                self::$severity = self::Ntc;
                break;
            case 'info':
                self::$severity = self::Inf;
                break;
            case 'warning':
                self::$severity = self::Wrn;
                break;
            case 'error':
                self::$severity = self::Err;
                break;
        }
    }

    public static function log_separator(): void
    {
        $fp = fopen(self::$log_path, "a");
        if($fp)
        {
            fwrite($fp, date("[Y.m.d H:i:s] ") . str_repeat('-', 80) . PHP_EOL);
            fclose($fp);
        }

    }

    /**
     * @param int $severity
     * @param string $value
     * @return void
     */
    public static function log(int $severity, string $value): void
    {
        if ($severity < self::$severity) {
            return;
        }

        if (!str_ends_with($value, PHP_EOL))
        {
            $value .= PHP_EOL;
        }

        $fp = fopen(self::$log_path, "a");
        if($fp)
        {
            fwrite($fp, date("[Y.m.d H:i:s] ") . $value);
            fclose($fp);
        }
    }
}
