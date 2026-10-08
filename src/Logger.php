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
    const Perm = 5;

    /** @var string */
    protected static string $log_path = 'converter.log';
    /** @var int */
    protected static int $severity = self::Inf;
    /** @var resource|null Held open so a debug run does not reopen the file per line. */
    protected static $handle = null;
    /** @var string|null Per-source temporary log, or null to write to the main log. */
    protected static ?string $session_path = null;

    public static function setLogPath(string $log_path): void
    {
        self::close();
        self::$log_path = $log_path;
    }

    /**
     * Redirect every following log line to a per-source temporary file. The main log is
     * left alone until merge_session_log() appends the whole block to it.
     *
     * @param string $path
     * @return void
     */
    public static function set_session_log(string $path): void
    {
        self::close();
        self::$session_path = $path;
    }

    /**
     * Append the session log to the main log and go back to writing the main log.
     *
     * @return void
     */
    public static function merge_session_log(): void
    {
        $session = self::$session_path;
        self::close();
        self::$session_path = null;

        if ($session !== null) {
            self::merge_log_file($session);
        }
    }

    /**
     * Append a temporary log to the main log as one contiguous block and remove it,
     * whatever the outcome of the source - the main log then holds everything. The
     * block is written under an exclusive lock so the lines of one source can never
     * interleave with those of another.
     *
     * @param string $path
     * @return void
     */
    public static function merge_log_file(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        $content = file_get_contents($path);
        if ($content !== false && $content !== '') {
            $fp = fopen(self::$log_path, 'a');
            if ($fp === false) {
                // the main log can't take it, so the temporary log is all there is
                return;
            }
            flock($fp, LOCK_EX);
            fwrite($fp, $content);
            fflush($fp);
            flock($fp, LOCK_UN);
            fclose($fp);
        }

        unlink($path);
    }

    /**
     * Flush and release the log file. Safe to call repeatedly.
     *
     * @return void
     */
    public static function close(): void
    {
        if (self::$handle !== null) {
            fclose(self::$handle);
            self::$handle = null;
        }
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

    /**
     * Whether a message of this severity would be written, so callers can skip
     * building log text that would be thrown away.
     *
     * @param int $severity
     * @return bool
     */
    public static function isEnabled(int $severity): bool
    {
        return $severity >= self::$severity;
    }

    public static function log_separator(int $severity = Logger::Inf): void
    {
        self::log($severity, str_repeat('-', 80));
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

        if (substr($value, -1, 1) !== PHP_EOL) {
            $value .= PHP_EOL;
        }

        if (self::$handle === null) {
            $path = self::$session_path ?? self::$log_path;
            $fp = fopen($path, 'a');
            if ($fp === false) {
                return;
            }
            self::$handle = $fp;
        }

        fwrite(self::$handle, date("[Y.m.d H:i:s] ") . $value);

        // keep anything worth diagnosing on disk even if the run dies later
        if ($severity >= self::Wrn) {
            fflush(self::$handle);
        }
    }
}
