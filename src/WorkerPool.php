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

use Throwable;

/**
 * Runs the sources through up to N worker processes instead of one after another.
 *
 * Two interchangeable backends implement the same contract: on Linux the workers are
 * forked children (pcntl), everywhere else separate PHP processes are started through
 * proc_open, so no extra extension is required. Every worker converts exactly one
 * source, writes its result as JSON into the run directory and appends its temporary
 * log to the main log as one contiguous block.
 */
final class WorkerPool
{
    /**
     * Whether any backend is usable on this platform.
     *
     * @return bool
     */
    public static function supported(): bool
    {
        return function_exists('pcntl_fork') || function_exists('proc_open');
    }

    /**
     * Name of the backend that will be used, for the log.
     *
     * @return string
     */
    public static function backend_name(): string
    {
        return function_exists('pcntl_fork') ? 'fork' : 'proc';
    }

    /**
     * Number of CPU cores for the 'auto' setting.
     *
     * @return int
     */
    public static function cpu_count(): int
    {
        $cpus = getenv('NUMBER_OF_PROCESSORS');
        if ($cpus !== false && (int)$cpus > 0) {
            return (int)$cpus;
        }

        if (function_exists('shell_exec')) {
            $n = (int)shell_exec('nproc');
            if ($n > 0) {
                return $n;
            }
        }

        return 2;
    }

    /**
     * Convert the sources in batches of at most $max workers each.
     *
     * The result of every worker is handed to $collect in source order after the whole
     * batch has finished. A worker that produced no result at all is reported as a
     * failure with an empty report.
     *
     * @param Converter $converter
     * @param array $sources
     * @param int $max
     * @param array $options working_dir, log_file, severity, force, purge,
     *                       collect_report, script (path to run-converter.php)
     * @param callable $collect function(int $index, array $result): void
     * @return void
     */
    public static function run(Converter $converter, array $sources, int $max, array $options, callable $collect): void
    {
        $run_dir = $options['working_dir'] . '/.run';
        create_path($run_dir);

        $index = 0;
        foreach (array_chunk($sources, $max) as $batch) {
            $results = self::run_batch($converter, $batch, $run_dir, $index, $options);
            ksort($results);
            foreach ($results as $n => $result) {
                $collect($n, $result);
            }
        }

        // successful workers removed their temporary logs themselves; a failed one
        // keeps its log, so the directory is only removed when nothing is left
        foreach (glob("$run_dir/result-*.json") as $file) {
            unlink($file);
        }
        @rmdir($run_dir);
    }

    /**
     * Run one batch and return its results keyed by the worker index.
     *
     * @param Converter $converter
     * @param array $batch
     * @param string $run_dir
     * @param int $index Starting index, advanced for every source of the batch.
     * @param array $options
     * @return array
     */
    private static function run_batch(Converter $converter, array $batch, string $run_dir, int &$index, array $options): array
    {
        return function_exists('pcntl_fork')
            ? self::run_batch_fork($converter, $batch, $run_dir, $index, $options)
            : self::run_batch_proc($converter, $batch, $run_dir, $index, $options);
    }

    /**
     * pcntl backend: one forked child per source, each converting exactly one source.
     *
     * @param Converter $converter
     * @param array $batch
     * @param string $run_dir
     * @param int $index
     * @param array $options
     * @return array
     */
    private static function run_batch_fork(Converter $converter, array $batch, string $run_dir, int &$index, array $options): array
    {
        $pids = [];
        $files = [];
        $results = [];

        foreach ($batch as $source) {
            $n = $index++;
            $result_file = "$run_dir/result-$n.json";
            $files[$n] = $result_file;

            $pid = pcntl_fork();
            if ($pid === -1) {
                // out of processes - convert this one inline and keep going
                Logger::log(Logger::Wrn, 'Can not fork worker process, source converted inline');
                $results[$n] = self::run_inline($converter, $source, $result_file, $options);
                continue;
            }

            if ($pid === 0) {
                // the child keeps writing to the inherited log handle, which shares the
                // file offset with the parent - release it and switch to a session log
                Logger::close();
                $converter->detach_report();
                Logger::set_session_log("$run_dir/log-$n.log");
                self::run_worker($converter, $source, $result_file, $options);
                exit(0);
            }

            $pids[$pid] = $n;
        }

        while (!empty($pids)) {
            $pid = pcntl_wait($status);
            if ($pid > 0) {
                unset($pids[$pid]);
            }
        }

        foreach ($files as $n => $result_file) {
            if (!isset($results[$n])) {
                $results[$n] = self::read_result($result_file);
            }
        }

        return $results;
    }

    /**
     * proc_open backend: one fresh PHP process per source, started through the
     * --worker entry point of run-converter.php.
     *
     * @param Converter $converter
     * @param array $batch
     * @param string $run_dir
     * @param int $index
     * @param array $options
     * @return array
     */
    private static function run_batch_proc(Converter $converter, array $batch, string $run_dir, int &$index, array $options): array
    {
        $procs = [];
        $results = [];

        foreach ($batch as $source) {
            $n = $index++;
            $result_file = "$run_dir/result-$n.json";
            $payload = array(
                'working_dir' => $options['working_dir'],
                'log_file' => $options['log_file'],
                'severity' => $options['severity'],
                'force' => (bool)$options['force'],
                'purge' => (bool)$options['purge'],
                'collect_report' => (bool)$options['collect_report'],
                'source' => $source,
                'result_file' => $result_file,
                'session_log' => "$run_dir/log-$n.log",
            );

            $command = array(
                PHP_BINARY !== '' ? PHP_BINARY : 'php',
                $options['script'],
                '--worker=' . base64_encode((string)json_encode($payload)),
            );

            $pipes = [];
            $proc = proc_open($command, array(
                0 => array('pipe', 'r'),
                1 => array('pipe', 'w'),
                2 => array('pipe', 'w'),
            ), $pipes);
            if (!is_resource($proc)) {
                $source_id = (string)safe_get_value($source, 'id', '');
                Logger::log(Logger::Err, "Can't start worker process for source: $source_id");
                $results[$n] = self::failed_result($source_id);
                continue;
            }

            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $procs[$n] = array($proc, $pipes[1], $pipes[2], '');
        }

        $running = true;
        while ($running) {
            $running = false;
            foreach ($procs as $n => &$info) {
                $status = proc_get_status($info[0]);
                if ($status['running']) {
                    $running = true;
                }
                $info[3] .= (string)stream_get_contents($info[2]);
            }
            unset($info);
            if ($running) {
                usleep(50000);
            }
        }

        foreach ($procs as $n => $info) {
            $info[3] .= (string)stream_get_contents($info[2]);
            stream_get_contents($info[1]);
            fclose($info[1]);
            fclose($info[2]);
            proc_close($info[0]);

            if ($info[3] !== '') {
                Logger::log(Logger::Wrn, "Worker $n: " . trim($info[3]));
            }

            $result = self::read_result("$run_dir/result-$n.json");
            $results[$n] = $result ?? self::failed_result(self::source_id($batch, $n, $index));
        }

        return $results;
    }

    /**
     * Shared worker body for both backends: convert the source, save the result and
     * merge the temporary log into the main log.
     *
     * @param Converter $converter
     * @param array $source
     * @param string $result_file
     * @param array $options
     * @return void
     */
    private static function run_worker(Converter $converter, array $source, string $result_file, array $options): void
    {
        $source_id = (string)safe_get_value($source, 'id', '');
        try {
            $result = $converter->convert_detached($source, (bool)$options['force'], (bool)$options['purge']);
            self::write_result($result_file, $source_id, $result);
            Logger::merge_session_log($result['ret'] !== 0, $source_id);
        } catch (Throwable $ex) {
            self::write_result($result_file, $source_id, array('ret' => 0, 'bytes' => 0, 'report' => null, 'detail_time' => 0.0));
            Logger::merge_session_log(false, $source_id);
        }
    }

    /**
     * Convert one source in the parent when no worker could be started for it.
     *
     * @param Converter $converter
     * @param array $source
     * @param string $result_file
     * @param array $options
     * @return array|null
     */
    private static function run_inline(Converter $converter, array $source, string $result_file, array $options): ?array
    {
        $source_id = (string)safe_get_value($source, 'id', '');
        $result = $converter->convert_detached($source, (bool)$options['force'], (bool)$options['purge']);
        self::write_result($result_file, $source_id, $result);
        return self::read_result($result_file);
    }

    /**
     * @param string $result_file
     * @param string $source_id
     * @param array $result convert_item() result
     * @return void
     */
    public static function write_result(string $result_file, string $source_id, array $result): void
    {
        $data = array(
            'id' => $source_id,
            'ret' => (int)$result['ret'],
            'bytes' => (int)$result['bytes'],
            'detail_time' => (float)$result['detail_time'],
            'report' => $result['report'],
        );

        $tmp = $result_file . '.tmp';
        file_put_contents($tmp, (string)json_encode($data), LOCK_EX);
        rename($tmp, $result_file);
    }

    /**
     * @param string $result_file
     * @return array|null
     */
    public static function read_result(string $result_file): ?array
    {
        if (!file_exists($result_file)) {
            return null;
        }

        $data = json_decode((string)file_get_contents($result_file), true);
        if (!is_array($data)) {
            return null;
        }

        if (!empty($data['report']) && is_array($data['report'])) {
            $report = new SourceReport($data['report']);
            $report->detail = (string)safe_get_value($data['report'], 'detail', '');
            $data['report'] = $report;
        } else {
            $data['report'] = null;
        }

        return $data;
    }

    /**
     * @param string $source_id
     * @return array
     */
    private static function failed_result(string $source_id): array
    {
        return array(
            'id' => $source_id,
            'ret' => 0,
            'bytes' => 0,
            'detail_time' => 0.0,
            'report' => null,
        );
    }

    /**
     * The id of the source a proc worker ran for, looked up by its index.
     *
     * @param array $batch
     * @param int $n
     * @param int $index Index the batch ended at.
     * @return string
     */
    private static function source_id(array $batch, int $n, int $index): string
    {
        $pos = $n - ($index - count($batch));
        return isset($batch[$pos]) ? (string)safe_get_value($batch[$pos], 'id', '') : '';
    }
}
