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

require_once 'HtmlReport.php';
require_once 'SourceDetail.php';
require_once 'Logger.php';
require_once 'PerfCollector.php';
require_once 'SqlWrapper.php';
require_once 'utils.php';

global $logger;

class Converter
{
    /** File holding the version string, read from the directory this class lives in. */
    const VERSION_FILE = 'VERSION';
    /** Reported when the version file is missing or empty. */
    const VERSION_UNKNOWN = 'unknown';

    const CONFIG = 'config_file';
    const RUN = 'run';
    const LOGFILE = 'log_file';
    const WORKDIR = 'work_dir';
    const PURGE = 'purge';
    const FORCE = 'force';
    const SEVERITY = 'severity';
    const HTMLPAGE = 'html_page';

    /** Name of the info page when --html is given without one. */
    const HTMLPAGE_DEFAULT = 'index.html';
    /** Name of the per source detail page, written inside the source directory. */
    const DETAIL_PAGE = 'index.html';
    /**
     * How long a detail page stays valid when nothing else marks it stales, in seconds.
     * A converted source rewrites its page regardless, so this is only the backstop that
     * catches a source whose last_update never moves - one configured purge_stalled: -1.
     */
    const DETAIL_TTL = 604800;

    /** Sent with every request - some hosts reject clients that identify as nothing. */
    const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    /** Largest slice of xml handed to DOMDocument at once, in bytes. */
    const PARSE_CHUNK_SIZE = 2097152;
    /** Read buffer used when scanning the source for tags, in bytes. */
    const SCAN_BUFFER_SIZE = 262144;

    /** @var array */
    protected static array $http_response_headers;
    /** @var string */
    protected string $working_dir;
    /** @var int */
    private int $download_size = 0;
    /** @var HtmlReport|null Collects per source statistics. Null when the info page was not requested. */
    private ?HtmlReport $report = null;
    /** @var string Link from a detail page back to the index, or '' when there is none. */
    private string $index_link = '';
    /** @var float|null Seconds the last detail page took, null when it was not rebuilt. */
    private ?float $last_detail_time = null;
    /** @var float Seconds every detail page of this run took together. */
    private float $detail_time = 0.0;

    /**
     * The converter version, taken from the VERSION file beside this class so it can be
     * bumped without touching any code. Read once per run.
     *
     * @return string
     */
    public static function version(): string
    {
        static $version = null;
        if ($version !== null) {
            return $version;
        }

        $path = __DIR__ . DIRECTORY_SEPARATOR . self::VERSION_FILE;
        if (!is_readable($path)) {
            Logger::log(Logger::Wrn, "Version file not found: $path");
            return $version = self::VERSION_UNKNOWN;
        }

        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            Logger::log(Logger::Wrn, "Version file is empty or unreadable: $path");
            return $version = self::VERSION_UNKNOWN;
        }

        return $version = trim($raw);
    }

    /**
     * @return array
     */
    protected static function get_response_headers(): array
    {
        return empty(self::$http_response_headers) ? [] : self::$http_response_headers;
    }

    /**
     * @param string $header
     * @return string
     */
    protected static function get_response_header(string $header): string
    {
        return safe_get_value(self::$http_response_headers, $header, '');
    }

    /**
     * @param array $converter_config
     * @return void
     */
    public function process(array $converter_config): void
    {
        $config_file = (string)safe_get_value($converter_config, self::CONFIG, '');
        if (!file_exists($config_file)) {
            echo "Error! Configuration file '$config_file' not found!";
            return;
        }

        if (empty($converter_config[self::WORKDIR])) {
            $this->working_dir = pathinfo($converter_config[self::CONFIG], PATHINFO_DIRNAME);
        } else {
            $this->working_dir = paved_path($converter_config[self::WORKDIR]);
        }

        if (!create_path($this->working_dir)) {
            echo "Error: Can't create directory '$this->working_dir'!";
            return;
        }

        if (!empty($converter_config[self::SEVERITY])) {
            Logger::setSeverity($converter_config[self::SEVERITY]);
        }

        if (empty($converter_config[self::LOGFILE])) {
            Logger::setLogPath("$this->working_dir/converter.log");
        } else {
            create_path(pathinfo($converter_config[self::LOGFILE], PATHINFO_DIRNAME));
            Logger::setLogPath($converter_config[self::LOGFILE]);
        }

        $sources = json_decode(file_get_contents($config_file), true);

        Logger::log(Logger::Perm, 'ProIPTV EPG Converter v' . self::version());
        Logger::log(Logger::Perm, 'Working directory: ' . $this->working_dir);

        $html_page = $this->resolve_html_page($converter_config);
        if ($html_page !== null) {
            Logger::log(Logger::Inf, "Info page: $html_page");
            $this->report = new HtmlReport(self::version());
            // the detail pages can only point back at an index that sits at the root of
            // the target directory, one level above them
            $page_dir = str_replace('\\', '/', (string)pathinfo($html_page, PATHINFO_DIRNAME));
            $this->index_link = ($page_dir === str_replace('\\', '/', $this->working_dir))
                ? '../' . basename($html_page)
                : '';
        }

        if (empty($sources)) {
            Logger::log(Logger::Err, 'Empty sources configuration');
            return;
        }

        $perf_all = new PerfCollector();
        $perf_all->reset('start');

        $force_processing = isset($converter_config[self::FORCE]);
        Logger::log(Logger::Inf, 'Force procesing: ' . var_export($force_processing, true));
        $force_purge = isset($converter_config[self::PURGE]);
        Logger::log(Logger::Inf, 'Force purge: ' . var_export($force_purge, true));

        if (!empty($converter_config[self::RUN])) {
            Logger::log(Logger::Inf, 'Run only: ' . implode(',',$converter_config[self::RUN]));
        }

        $success = [];
        $failed = [];
        $skipped = [];
        foreach ($sources as $item) {
            // an entry with no id is rejected by convert_item, which reports why - read it
            // without assuming it is there, since a missing key is a warning as of PHP 8.0
            $item_id = (string)safe_get_value($item, 'id', '');
            // --run holds strings and the id is cast to one, so a strict match is exact.
            // It also keeps a JSON id given as a number (id: 146) out of the loose
            // int-to-string comparison, whose rules changed in PHP 8.0.
            if (!empty($converter_config[self::RUN]) && !in_array($item_id, $converter_config[self::RUN], true)) {
                // the page covers the whole configuration, not just what this run touched
                if ($this->report !== null) {
                    $this->report_not_run($item);
                }
                continue;
            }

            $ret = $this->convert_item($item, $force_processing, $force_purge);
            if ($ret === 0) {
                $failed[] = $item_id;
            } else if ($ret === 1) {
                $success[] = $item_id;
            } else {
                $skipped[] = $item_id;
            }
        }

        $perf_all->setLabel('end');
        $report_all = $perf_all->getReportItem(PerfCollector::TIME, 'start', 'end');

        $index_time = 0.0;
        if ($this->report !== null && $html_page !== null) {
            $perf_all->setLabel('html_start');
            $this->report->set_run_time((float)$report_all);
            $this->report->save($html_page);
            $perf_all->setLabel('html_end');

            $index_time = (float)$perf_all->getReportItem(PerfCollector::TIME, 'html_start', 'html_end');
            Logger::log(Logger::Inf, "Info page generation time: $index_time secs");
        }

        Logger::log(Logger::Perm, "Total downloaded: " . convert_bytes($this->download_size));
        Logger::log(Logger::Perm, "Total converted:  " . count($success) . " " . implode(',', $success));
        Logger::log(Logger::Perm, "Total skipped:    " . count($skipped) . " " . implode(',', $skipped));
        Logger::log(Logger::Perm, "Total failed:     " . count($failed) . " " . implode(',', $failed));
        if ($this->report !== null) {
            // the conversion total above does not include the pages, which are written
            // after it is measured
            Logger::log(Logger::Perm, "Total html time:  "
                . round($this->detail_time + $index_time, 2) . " sec");
        }
        Logger::log(Logger::Perm, "Total time:       $report_all sec");
        Logger::log_separator();
        Logger::close();
    }

    /**
     * @param array $source_params
     * @param bool $force_processing
     * @param bool $force_purge
     * @return int
     */
    protected function convert_item(array $source_params, bool $force_processing, bool $force_purge): int
    {
        $perf = new PerfCollector();
        $perf->reset('start_item');

        $source_id = safe_get_value($source_params, 'id');
        if (empty($source_id)) {
            Logger::log(Logger::Err, 'Empty name not allowed in sources.conf');
            // nothing identifies this entry, but the page should still show that the
            // configuration holds an entry that cannot be processed
            $this->report_broken_entry('(no id)', (string)safe_get_value($source_params, 'url', ''),
                'Empty id in configuration');
            return 0;
        }

        $url = safe_get_value($source_params, 'url');
        if (empty($url)) {
            Logger::log(Logger::Err, 'Empty URL not allowed in sources.conf');
            $this->report_broken_entry($source_id, '', 'Empty url in configuration');
            return 0;
        }

        $keep_source = safe_get_value($source_params, 'keep_source', false);
        $manual_check = safe_get_value($source_params, 'manual_check', false);
        $purge_stalled = safe_get_value($source_params, 'purge_stalled', 7);

        $xmltv_source = "$this->working_dir/$source_id/" . basename($url);

        $db = new SqlWrapper();
        $db_path = "$this->working_dir/$source_id/$source_id.db";
        $json_path = "$this->working_dir/$source_id/epg";
        $ret = 0;
        $error = '';
        try {
            if (!create_path($json_path)) {
                throw new Exception("Directory '$json_path' can't be created");
            }

            $db->open_db($db_path);
            $db->exec('CREATE TABLE IF NOT EXISTS epg_params (param TEXT NOT NULL UNIQUE, value TEXT);');

            Logger::log_separator();
            Logger::log(Logger::Inf, "Start conversion source id: '$source_id'");
            Logger::log(Logger::Inf, "Source url: '$url'");
            Logger::log(Logger::Inf, "Keep source: " . var_export($keep_source, true));
            Logger::log(Logger::Inf, "Manual check: " . var_export($manual_check, true));

            $perf->setLabel('download_start');
            $ret = $this->download($db, $url, $xmltv_source, $manual_check, $force_processing);
            $perf->setLabel('download_end');
            if ($ret === 0) {
                throw new Exception("Failed to download file");
            }

            if ($ret === 1) {
                $perf->setLabel('uncompress_start');
                $uncompressed = $this->uncompress($xmltv_source);
                $perf->setLabel('uncompress_end');
                if (is_null($uncompressed)) {
                    throw new Exception("Failed to uncompress file.");
                }

                $perf->setLabel('index_start');
                $res = $this->indexing($db, $uncompressed);
                $perf->setLabel('index_end');
                if ($res === false) {
                    throw new Exception("Failed to indexing XMLTV");
                }

                $perf->setLabel('convert_start');
                $res = $this->db2Json($db, $json_path, $uncompressed);
                if ($res === false) {
                    throw new Exception("Failed to convert to JSON");
                }
                $perf->setLabel('convert_end');

                if (!$force_purge) {
                    $perf->setLabel('purge_start');
                    $purged = $this->purge_stalled($db, $json_path, $purge_stalled);
                    if (!empty($purged)) {
                        Logger::log(Logger::Inf, "Purge files: " . implode(',', $purged));
                    }
                    $perf->setLabel('purge_end');
                }

                $this->save_channels_info($db, $json_path);
            }

            if ($force_purge) {
                $purged = $this->purge_stalled($db, $json_path, 0);
                if (!empty($purged)) {
                    Logger::log(Logger::Inf, "Purge files: " . implode(',', $purged));
                }
            }
            $perf->setLabel('end_item');
        } catch(Exception $ex) {
            $error = $ex->getMessage();
            Logger::log(Logger::Err, $error);
        } finally {
            // a source that threw never reached the label above, and its elapsed time is
            // exactly what is worth knowing about it
            $perf->setLabel('end_item');

            if (!$keep_source && file_exists($xmltv_source)) {
                Logger::log(Logger::Dbg, "Remove source file: $xmltv_source");
                unlink($xmltv_source);
            }

            if (!empty($uncompressed) && $xmltv_source !== $uncompressed) {
                Logger::log(Logger::Dbg, "Remove uncompressed file: $uncompressed");
                unlink($uncompressed);
            }
        }

        $report_download = $perf->getReportItem(PerfCollector::TIME, 'download_start', 'download_end');
        $report_uncompress = $perf->getReportItem(PerfCollector::TIME, 'uncompress_start', 'uncompress_end');
        $report_reindex = $perf->getReportItem(PerfCollector::TIME, 'index_start', 'index_end');
        $report_convert = $perf->getReportItem(PerfCollector::TIME, 'convert_start', 'convert_end');
        $report_all = $perf->getReportItem(PerfCollector::TIME, 'start_item', 'end_item');

        // collected before the timings are logged, so the detail page this may write is
        // reported inside this source's block rather than after its separator
        $this->report?->add($this->collect_stats($db, $source_id, $url, $json_path,
            SourceStatus::from_result($ret), $error, (float)$report_all));

        Logger::log(Logger::Inf, "Download time: $report_download secs");
        Logger::log(Logger::Inf, "Uncompressing time: $report_uncompress secs");
        Logger::log(Logger::Inf, "Indexing XMLTV source time: $report_reindex secs");
        Logger::log(Logger::Inf, "Json generation time: $report_convert secs");
        Logger::log(Logger::Inf, "Source conversion time: $report_all secs");
        if ($this->last_detail_time !== null) {
            Logger::log(Logger::Inf, "Detail page generation time: $this->last_detail_time secs");
        }
        Logger::log_separator();

        return $ret;
    }

    /**
     * Report a configuration entry that cannot be processed at all.
     *
     * @param string $source_id
     * @param string $url
     * @param string $error
     * @return void
     */
    protected function report_broken_entry(string $source_id, string $url, string $error): void
    {
        if ($this->report === null) {
            return;
        }

        $this->report->add(new SourceReport(array(
            'id' => $source_id,
            'url' => $url,
            'status' => SourceStatus::FAILED,
            'error' => $error,
        )));
    }

    /**
     * Report a source the run left alone, so the page still covers everything the
     * configuration lists. Its database is opened only if it already exists - a source
     * that was never converted must not get an empty one created behind its back.
     *
     * @param array $source_params
     * @return void
     */
    protected function report_not_run(array $source_params): void
    {
        $source_id = safe_get_value($source_params, 'id');
        if (empty($source_id)) {
            // not "not run" - an entry with no id cannot be processed by any run, and the
            // page should say so rather than drop it
            $this->report_broken_entry('(no id)', (string)safe_get_value($source_params, 'url', ''),
                'Empty id in configuration');
            return;
        }

        $db = new SqlWrapper();
        $db_path = "$this->working_dir/$source_id/$source_id.db";
        if (file_exists($db_path)) {
            $db->open_db($db_path);
        }

        $this->report->add($this->collect_stats($db, $source_id,
            (string)safe_get_value($source_params, 'url', ''),
            "$this->working_dir/$source_id/epg",
            SourceStatus::NOT_RUN, '', 0.0));

        // a run that touches nothing still rebuilds any detail page that went missing
        if ($this->last_detail_time !== null) {
            Logger::log(Logger::Inf, "Detail page generation time for '$source_id': $this->last_detail_time secs");
        }
    }

    /**
     * Read back what this source currently serves.
     *
     * The counters are taken from the database and from the files on disk rather than
     * from the run, so a source that was skipped as up-to-date reports the same numbers
     * as one that was just converted.
     *
     * @param SqlWrapper $db
     * @param string $source_id
     * @param string $url
     * @param string $json_path
     * @param string $status
     * @param string $error
     * @param float $duration
     * @return SourceReport
     */
    protected function collect_stats(SqlWrapper $db, string $source_id, string $url, string $json_path,
                                     string     $status, string $error, float $duration): SourceReport
    {
        // a source that never got as far as creating its tables has nothing to report,
        // and asking for them anyway would only put an error in the log
        $params = [];
        if ($db->is_open()
            && $db->query_value("SELECT name FROM sqlite_master WHERE type='table' AND name='epg_params';")) {
            foreach ($db->fetch_array('SELECT param, value FROM epg_params;') as $row) {
                $params[$row['param']] = $row['value'];
            }
        }

        // One pass over the directory serves both pages: the summary needs the count and
        // the total, the detail page needs the size of each channel's file.
        // channels_info.json is the index of the directory, not a channel, so it adds to
        // the size on disk but not to the number of guides served
        $files = 0;
        $files_size = 0;
        $files_index = [];
        if (is_dir($json_path)) {
            foreach (new FilesystemIterator($json_path, FilesystemIterator::SKIP_DOTS) as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'json') continue;
                $size = (int)$file->getSize();
                $files_size += $size;
                $name = $file->getFilename();
                if ($name !== 'channels_info.json') {
                    ++$files;
                    $files_index[$name] = $size;
                }
            }
        }

        $report = new SourceReport(array(
            'id' => $source_id,
            'url' => $url,
            'status' => $status,
            'error' => $error,
            'channels' => safe_get_value($params, 'channels'),
            'picons' => safe_get_value($params, 'picons'),
            'programmes' => safe_get_value($params, 'programmes'),
            'files' => $files,
            'files_size' => $files_size,
            'epg_start' => safe_get_value($params, 'epg_start'),
            'epg_end' => safe_get_value($params, 'epg_end'),
            'last_update' => safe_get_value($params, 'last_update', safe_get_value($params, 'last_check', 0)),
            'duration' => $duration,
        ));

        $report->detail = $this->ensure_detail_page($db, $report, $files_index);

        return $report;
    }

    /**
     * Make sure the source has a current detail page, and say where it is.
     *
     * The page is only written when it is missing or expired, because rendering one row
     * per channel is the expensive part of a run for a source with thousands of them.
     *
     * @param SqlWrapper $db
     * @param SourceReport $summary
     * @param array $files_index
     * @return string Name of the page inside the source directory, or '' if there is none.
     */
    protected function ensure_detail_page(SqlWrapper $db, SourceReport $summary, array $files_index): string
    {
        $this->last_detail_time = null;

        // nothing indexed yet - a source that has never been converted has nothing to show
        if (!$db->is_open() || $summary->channels === 0) {
            return '';
        }

        $path = "$this->working_dir/$summary->id/" . self::DETAIL_PAGE;

        // a source converted in this run has by definition moved on, so its page is
        // rewritten without consulting the timestamps
        if ($summary->status !== SourceStatus::CONVERTED
            && SourceDetail::is_current($path, $summary->last_update, self::DETAIL_TTL)) {
            Logger::log(Logger::Dbg, "Detail page still current: $path");
            return self::DETAIL_PAGE;
        }

        $perf = new PerfCollector();
        $perf->reset('detail_start');

        $channels = $this->load_channels($db, $files_index);
        $page = new SourceDetail(self::version(), $summary->id, $summary, $channels, $this->index_link);
        $saved = $page->save($path);

        $perf->setLabel('detail_end');
        $this->last_detail_time = (float)$perf->getReportItem(PerfCollector::TIME, 'detail_start', 'detail_end');
        $this->detail_time += $this->last_detail_time;

        return $saved ? self::DETAIL_PAGE : '';
    }

    /**
     * Collect what the detail page lists: every channel with its display names, picon and
     * the guide file it is served from.
     *
     * epg_channels holds one row per alias, so the rows are read in channel order and
     * folded into one entry per channel as they stream past.
     *
     * @param SqlWrapper $db
     * @param array $files_index
     * @return array
     */
    protected function load_channels(SqlWrapper $db, array $files_index): array
    {
        // a database written by an older version has no ranges table - join it only when
        // it is there, so those sources still list their channels, just without a depth
        $has_ranges = (bool)$db->query_value(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='epg_ranges';");

        $query = 'SELECT ch.channel_id, ch.alias_orig, pic.picon_url'
            . ($has_ranges ? ', rng.first_ts, rng.last_ts' : '')
            . ' FROM epg_channels AS ch LEFT JOIN epg_picons AS pic ON ch.picon_hash = pic.picon_hash'
            . ($has_ranges ? ' LEFT JOIN epg_ranges AS rng ON rng.channel_id = ch.channel_id' : '')
            . ' ORDER BY ch.channel_id, ch.alias_orig;';

        $result = $db->query($query);
        if ($result === false) {
            return [];
        }

        $channels = [];
        $current = null;
        $entry = null;

        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // channel_id is declared TEXT but sqlite reads it with NUMERIC affinity, so an
            // id like "36.6" comes back as a float - force it back to text
            $id = is_string($row['channel_id']) ? $row['channel_id'] : (string)$row['channel_id'];
            if ($id !== $current) {
                if ($entry !== null) {
                    $channels[] = $entry;
                }

                $current = $id;
                $file = escape_channel_filename($id) . '.json';
                $known = isset($files_index[$file]);
                $entry = array(
                    'id' => $id,
                    'names' => [],
                    'picon' => '',
                    'file' => $known ? $file : '',
                    'size' => $known ? $files_index[$file] : 0,
                    'first_ts' => (int)safe_get_value($row, 'first_ts', 0),
                    'last_ts' => (int)safe_get_value($row, 'last_ts', 0),
                );
            }

            if ($entry['picon'] === '' && !empty($row['picon_url'])) {
                $entry['picon'] = (string)$row['picon_url'];
            }

            $alias = is_string($row['alias_orig']) ? $row['alias_orig'] : (string)$row['alias_orig'];
            if ($alias !== '' && $alias !== $id && !in_array($alias, $entry['names'], true)) {
                $entry['names'][] = $alias;
            }
        }

        if ($entry !== null) {
            $channels[] = $entry;
        }

        return $channels;
    }

    /**
     * Where the info page goes, or null when it was not requested.
     *
     * @param array $converter_config
     * @return string|null
     */
    protected function resolve_html_page(array $converter_config): ?string
    {
        $page = safe_get_value($converter_config, self::HTMLPAGE);
        if ($page === null) {
            return null;
        }

        // --html without a value, or pointing at a directory, names the file itself
        if ($page === true || $page === '') {
            return "$this->working_dir/" . self::HTMLPAGE_DEFAULT;
        }

        $page = (string)$page;
        if (is_dir($page) || str_ends_with($page, '/') || str_ends_with($page, '\\')) {
            return rtrim($page, '/\\') . '/' . self::HTMLPAGE_DEFAULT;
        }

        return $page;
    }

    /**
     * Check and download file
     * Return 0 in case of any error
     * Return 1 in case success download
     * Return 2 in case file not changed or manual check not performed
     *
     * @param SqlWrapper $db
     * @param string $url
     * @param string $filename
     * @param int $manual_check
     * @param bool $force_processing
     * @return int
     */
    protected function download(SqlWrapper $db, string $url, string $filename, int $manual_check, bool $force_processing): int
    {
        self::$http_response_headers = [];

        Logger::log(Logger::Inf, "Begin download: $url");

        $last_check = $db->query_value("SELECT value FROM epg_params WHERE param='last_check';");
        if (!$force_processing && $last_check !== 0) {
            Logger::log(Logger::Dbg, "Last check: " . date('Y-m-d H:i:s', $last_check));
            $check_time = $last_check + $manual_check * 3600;
            if ($check_time > time()) {
                Logger::log(Logger::Inf, "Up to date. Expired at: " . date('Y-m-d H:i:s', $check_time));
                Logger::log_separator();
                return 2;
            }
        }

        $etag = $db->query_value("SELECT value FROM epg_params WHERE param='etag';");
        Logger::log(Logger::Dbg, "Etag: $etag");

        $opts = [];
        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_SSL_VERIFYPEER] = 0;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        $opts[CURLOPT_CONNECTTIMEOUT] = 30;
        // No overall deadline: a multi-gigabyte source on a slow link needs far more than
        // a fixed timeout. A stalled transfer is caught by the low-speed limit instead.
        $opts[CURLOPT_TIMEOUT] = 0;
        $opts[CURLOPT_LOW_SPEED_LIMIT] = 512;
        $opts[CURLOPT_LOW_SPEED_TIME] = 120;
        $opts[CURLOPT_RETURNTRANSFER] = 1;
        $opts[CURLOPT_FOLLOWLOCATION] = 1;
        $opts[CURLOPT_MAXREDIRS] = 5;
        $opts[CURLOPT_FILETIME] = 1;
        $opts[CURLOPT_HEADERFUNCTION] = 'Converter::http_header_function';
        $opts[CURLOPT_ENCODING] = "";
        // Several sources sit behind a bot filter that answers a request without a
        // user agent with an HTML challenge page instead of the guide.
        $opts[CURLOPT_USERAGENT] = self::USER_AGENT;

        $tmp_file = $filename . ".tmp";
        $fp = fopen($tmp_file, "w+");
        $opts[CURLOPT_FILE] = $fp;

        $opts[CURLOPT_HTTPHEADER][] = "Accept: */*";
        $opts[CURLOPT_HTTPHEADER][] = "Pragma: no-cache";
        // Host is deliberately not pinned here - curl derives it per request, and a fixed
        // one would be carried over to a redirect that points at a different host.

        if (!$force_processing && !$manual_check && !empty($etag)) {
            $opts[CURLOPT_HTTPHEADER][] = "If-None-Match: $etag";
        }

        $opts[CURLOPT_CUSTOMREQUEST] = "GET";

        try {
            $ch = curl_init();
            if ($ch === false) {
                throw new Exception('Curl init failed!');
            }

            Logger::log(Logger::Dbg, "--- Request headers ---");
            foreach ($opts[CURLOPT_HTTPHEADER] as $v) {
                Logger::log(Logger::Dbg, $v);
            }

            foreach ($opts as $k => $v) {
                curl_setopt($ch, $k, $v);
            }

            $start_tm = microtime(true);
            curl_exec($ch);
            $execution_tm = microtime(true) - $start_tm;
            $error_no = curl_errno($ch);
            $error_desc = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (!is_null($fp)) {
                fclose($fp);
                $fp = null;
            }

            Logger::log(Logger::Dbg, "--- Response headers ---");
            foreach (self::get_response_headers() as $k => $v) {
                Logger::log(Logger::Dbg, "$k: $v");
            }

            if ($http_code < 200 || ($http_code >= 300 && $http_code != 301 && $http_code != 304)) {
                // the body went to $tmp_file, so curl_exec only returned a bool - show the
                // start of what the server actually sent, which is what explains the failure
                $body = file_exists($tmp_file) ? trim(file_get_contents($tmp_file, false, null, 0, 512)) : '';
                throw new Exception("HTTP request failed ($http_code)\nHTTP response: $body");
            }

            if ($error_no !== 0) {
                $msg = sprintf('CURL errno: %s (%s); HTTP error: %s', $error_no, $error_desc, $http_code);
                throw new Exception($msg);
            }

            $ret = 0;
            if ($http_code == 301 || $http_code == 304) {
                Logger::log(Logger::Inf, sprintf('HTTP code (%d) in %.3fs', $http_code, $execution_tm));
                Logger::log(Logger::Inf, "Server response that file is not changed");
                $ret = 2;
            } else if (file_exists($tmp_file)) {
                $download = filesize($tmp_file);
                $this->download_size += $download;

                Logger::log(Logger::Inf,
                    sprintf('Save file: HTTP OK (%d, %d bytes) in %.3fs', $http_code, $download, $execution_tm));
                Logger::log(Logger::Inf, "Downloaded file saved to: $filename");

                if (file_exists($filename)) {
                    unlink($filename);
                }
                rename($tmp_file, $filename);
                $ret = 1;
            } else {
                Logger::log(Logger::Err, sprintf('HTTP code (%d) in %.3fs', $http_code, $execution_tm));
                Logger::log(Logger::Err, "Saved file '$filename' is not exist!");
            }

            if ($ret !== 0) {
                $new_etag = self::get_response_header('etag');
                if (!empty($new_etag) && $etag !== $new_etag) {
                    Logger::log(Logger::Dbg, "Save new ETag ($new_etag) for: $url");
                    $db->exec(sprintf("INSERT OR REPLACE INTO epg_params (param, value) VALUES ('etag', %s);", SqlWrapper::sql_quote($new_etag)));
                }

                $db->exec(sprintf("INSERT OR REPLACE INTO epg_params (param, value) VALUES ('last_check', '%d');", time()));
            }
        } catch (Exception $ex) {
            Logger::log(Logger::Err, $ex->getMessage());
            if (!is_null($fp)) {
                fclose($fp);
            }

            if (file_exists($filename)) {
                unlink($filename);
            }
            $ret = 0;
        }

        if (file_exists($tmp_file)) {
            unlink($tmp_file);
        }

        Logger::log_separator();

        return $ret;
    }

    /**
     * Left untyped on purpose: curl_init() hands back a resource before PHP 8.0 and a
     * CurlHandle object from 8.0 on, so any type here breaks one of the two.
     *
     * @param CurlHandle $curl
     * @param string $header
     * @return int
     * @noinspection PhpUnused
     * @noinspection PhpUnusedParameterInspection
     */
    public static function http_header_function(CurlHandle $curl, string $header): int
    {
        $len = strlen($header);
        $header = explode(':', $header, 2);
        if (count($header) == 2) {
            $header_name = trim($header[0]);
            $header_value = trim($header[1]);
            self::$http_response_headers[strtolower($header_name)] = $header_value;
        }
        return $len;
    }

    /**
     * @param string $filename
     * @return string|null
     */
    protected function uncompress(string $filename): ?string
    {
        if (!file_exists($filename)) {
            Logger::log(Logger::Err, "File not exist: $filename");
            return null;
        }

        $handle = fopen($filename, "rb");
        if ($handle === false) {
            Logger::log(Logger::Err, "Can't open file: $filename");
            return null;
        }

        $hdr = fread($handle, 64);
        fclose($handle);

        if (strncmp($hdr, "\x1f\x8b\x08", 3) === 0) {
            Logger::log(Logger::Dbg, 'GZ signature:  ' . bin2hex(substr($hdr, 0, 3)));
            Logger::log(Logger::Inf, 'UnGZIP: ' . $filename);
            $unpacked = extractGzipFile($filename);
            if (is_null($unpacked)) {
                Logger::log(Logger::Err, "Failed to unpack $filename");
            }
            $filename = $unpacked;
        } else if (strncmp($hdr, "\x50\x4b\x03\x04", 4) === 0) {
            Logger::log(Logger::Dbg, 'ZIP signature: ' . bin2hex(substr($hdr, 0, 4)));
            Logger::log(Logger::Inf, 'UnZIP: ' . $filename);
            $unpacked = extractZipArchive($filename);
            if (is_null($unpacked)) {
                Logger::log(Logger::Err, "Failed to unpack $filename");
            }
            $filename = $unpacked;
        } else {
            // tolerate an utf-8 bom and any leading whitespace, and accept a guide that
            // opens straight at <tv> without an XML declaration
            $probe = ltrim($hdr, "\xEF\xBB\xBF \t\r\n");
            if (strncmp($probe, '<?xml', 5) === 0 || strncmp($probe, '<tv', 3) === 0) {
                Logger::log(Logger::Dbg, 'XML signature: ' . bin2hex(substr($probe, 0, 5)));
            } else {
                Logger::log(Logger::Err, 'Unsupported format! ' . $filename);
                Logger::log(Logger::Err, 'First bytes: ' . bin2hex(substr($hdr, 0, 16)));
                return null;
            }
        }

        return $filename;
    }

    /**
     * @param SqlWrapper $db
     * @param string $filename
     * @return bool
     */
    protected function indexing(SqlWrapper $db, string $filename): bool
    {
        // Reindex channels and picons
        Logger::log(Logger::Inf, "Start indexing $filename");

        libxml_use_internal_errors(true);
        $file = false;
        $ret = false;
        try {
            if (!file_exists($filename)) {
                throw new Exception("File $filename not exist");
            }

            $file = fopen($filename, 'rb');
            if (!$file) {
                throw new Exception("Can't open file: $filename");
            }

            $query = 'DROP TABLE IF EXISTS epg_channels;';
            $query .= 'DROP TABLE IF EXISTS epg_picons;';
            $query .= 'CREATE TABLE epg_channels (alias TEXT PRIMARY KEY NOT NULL, alias_orig TEXT NOT NULL, channel_id TEXT NOT NULL, picon_hash TEXT);';
            $query .= 'CREATE TABLE epg_picons (picon_hash TEXT PRIMARY KEY not null, picon_url TEXT);';
            $res = $db->exec_transaction($query);
            if (!$res) {
                throw new Exception("Error transaction: $query");
            }

            // Bound parameters instead of one concatenated mega-query: the old form held
            // every INSERT for the whole file in a single string before sqlite even saw it,
            // and made sqlite parse each statement from scratch.
            $db->exec('BEGIN;');

            $stm_picon = $db->prepare('INSERT OR REPLACE INTO epg_picons (picon_hash, picon_url) VALUES(:picon_hash, :picon_url);');
            $stm_channel = $db->prepare('INSERT OR IGNORE INTO epg_channels (alias,alias_orig,channel_id,picon_hash) VALUES (:alias,:alias_orig,:channel_id,:picon_hash);');

            /** @var string $picon_hash */
            /** @var string $picon_url */
            /** @var string $alias */
            /** @var string $alias_orig */
            /** @var string $channel_id */
            $stm_picon->bindParam(':picon_hash', $picon_hash);
            $stm_picon->bindParam(':picon_url', $picon_url);
            $stm_channel->bindParam(':alias', $alias);
            $stm_channel->bindParam(':alias_orig', $alias_orig);
            $stm_channel->bindParam(':channel_id', $channel_id);
            $stm_channel->bindParam(':picon_hash', $picon_hash);

            // Single forward pass: the buffer slides over the file so every byte is read
            // once, instead of seeking back to re-read each <channel> block.
            $buffer = '';
            $eof = false;
            $have_open = false;
            $scan_from = 0;
            $open_tag = '<channel id';
            $open_len = 11;     // strlen('<channel id')
            $close_tag = '</channel>';
            $close_len = 10;    // strlen('</channel>')

            while (true) {
                if (!$have_open) {
                    $open_pos = strpos($buffer, $open_tag);
                    if ($open_pos === false) {
                        if ($eof) break;
                        // keep just enough tail for a tag straddling two reads
                        if (strlen($buffer) > $open_len - 1) {
                            $buffer = substr($buffer, -($open_len - 1));
                        }
                        $chunk = fread($file, self::SCAN_BUFFER_SIZE);
                        if ($chunk === false || $chunk === '') $eof = true; else $buffer .= $chunk;
                        continue;
                    }

                    if ($open_pos > 0) {
                        $buffer = substr($buffer, $open_pos);
                    }
                    $have_open = true;
                    $scan_from = $open_len;
                }

                $close_pos = strpos($buffer, $close_tag, $scan_from);
                if ($close_pos === false) {
                    if ($eof) break;
                    // never re-scan what was already searched
                    $scan_from = max($open_len, strlen($buffer) - ($close_len - 1));
                    $chunk = fread($file, self::SCAN_BUFFER_SIZE);
                    if ($chunk === false || $chunk === '') $eof = true; else $buffer .= $chunk;
                    continue;
                }

                $end_pos = $close_pos + $close_len;
                $line = substr($buffer, 0, $end_pos);
                $buffer = substr($buffer, $end_pos);
                $have_open = false;

                $xml_node = new DOMDocument();
                if ($xml_node->loadXML($line, LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
                    Logger::log(Logger::Err, "Error parsing xml block:");
                    foreach (libxml_get_errors() as $error) {
                        Logger::log(Logger::Err, display_xml_error($error, $line));
                    }
                    libxml_clear_errors();
                    continue;
                }

                $channel = $xml_node->documentElement;
                $channel_id = $channel === null ? '' : $channel->getAttribute('id');
                if (empty($channel_id)) continue;

                // one walk of the children collects the picon and every display-name
                $picon_hash = '';
                $picon_url = '';
                $names = [];
                for ($node = $channel->firstChild; $node !== null; $node = $node->nextSibling) {
                    if ($node->nodeType !== XML_ELEMENT_NODE) continue;
                    if ($node->nodeName === 'display-name') {
                        $names[] = $node->nodeValue;
                    } else if ($node->nodeName === 'icon' && $picon_url === '') {
                        $src = $node->getAttribute('src');
                        if ($src !== '' && is_proto_http($src)) {
                            $picon_url = $src;
                            $picon_hash = md5($src);
                        }
                    }
                }

                if ($picon_url !== '') {
                    $stm_picon->execute();
                }

                $alias = mb_convert_case($channel_id, MB_CASE_LOWER, "UTF-8");
                $alias_orig = $channel_id;
                $stm_channel->execute();

                foreach ($names as $name) {
                    $alias = mb_convert_case($name, MB_CASE_LOWER, "UTF-8");
                    $alias_orig = $name;
                    $stm_channel->execute();
                }
            }

            $db->exec('COMMIT;');

            $channels = (int)$db->query_value('SELECT count(DISTINCT channel_id) FROM epg_channels;');
            $picons = (int)$db->query_value('SELECT COUNT(*) FROM epg_picons;');

            $query = 'DROP TABLE IF EXISTS epg_entries;';
            $query .= 'CREATE TABLE epg_entries (channel_id TEXT NOT NULL, start INTEGER, end INTEGER, UNIQUE (channel_id, start) ON CONFLICT REPLACE);';
            $res = $db->exec_transaction($query);
            if (!$res) {
                throw new Exception("Error transaction: $query");
            }

            $db->exec('BEGIN;');

            $query = 'INSERT INTO epg_entries (channel_id, start, end) VALUES(:channel_id, :start, :end);';
            $stm = $db->prepare($query);
            /** @var string $prev_channel */
            /** @var int $start_program_block */
            /** @var int $tag_end_pos */
            $stm->bindParam(':channel_id', $prev_channel);
            $stm->bindParam(':start', $start_program_block);
            $stm->bindParam(':end', $tag_end_pos);

            $start_program_block = 0;
            $prev_channel = null;
            fseek($file, 0);
            while (!feof($file)) {
                $tag_start_pos = ftell($file);
                $line = stream_get_line($file, 0, "</programme>");
                if ($line === false) break;

                $offset = strpos($line, '<programme');
                if ($offset === false) {
                    // check if end
                    $end_tv = strpos($line, "</tv>");
                    if ($end_tv !== false) {
                        $tag_end_pos = $end_tv + $tag_start_pos;
                        $stm->execute();
                        break;
                    }

                    // if open tag not found - skip chunk
                    continue;
                }

                // end position include closing tag!
                // $tag_end_pos = ftell($file);
                // append position of open tag to file position of chunk
                $tag_start_pos += $offset;
                // calculate channel id
                $ch_start = strpos($line, 'channel="', $offset);
                if ($ch_start === false) {
                    continue;
                }

                $ch_start += 9;
                $ch_end = strpos($line, '"', $ch_start);
                if ($ch_end === false) {
                    continue;
                }

                $channel_id = substr($line, $ch_start, $ch_end - $ch_start);
                if (empty($channel_id)) continue;

                // This attribute is read straight out of the raw text, while the channel
                // table holds ids the XML parser already decoded. Without decoding here an
                // id like "A&amp;E" never matches its channel and the whole channel is lost.
                if (str_contains($channel_id, '&')) {
                    $channel_id = html_entity_decode($channel_id, ENT_QUOTES | ENT_XML1, 'UTF-8');
                }

                if ($prev_channel === null) {
                    $prev_channel = $channel_id;
                    $start_program_block = $tag_start_pos;
                } else if ($prev_channel !== $channel_id) {
                    $tag_end_pos = $tag_start_pos;
                    $res = $stm->execute();
                    if ($res === false) {
                        Logger::log(Logger::Err, "Error inserting position start: $start_program_block end: $tag_end_pos for channel: $prev_channel");
                    }
                    $prev_channel = $channel_id;
                    $start_program_block = $tag_start_pos;
                }
            }

            $db->exec('COMMIT;');

            // Cleanup channels without positions
            $query = 'SELECT COUNT(distinct channel_id) FROM epg_channels WHERE channel_id NOT IN (SELECT distinct channel_id FROM epg_entries);';
            $cnt = $db->query_value($query);

            if ($cnt) {
                $db->exec('DELETE FROM epg_channels WHERE channel_id NOT IN (SELECT distinct channel_id FROM epg_entries);');
            }

            $total_epg = (int)$db->query_value('SELECT count(DISTINCT channel_id) FROM epg_entries;');
            $total_blocks = (int)$db->query_value('SELECT COUNT(*) FROM epg_entries;');

            // kept for the info page, which is also drawn for sources that were skipped
            // as up to date and so have nothing but the database to report from
            $served = (int)$db->query_value('SELECT count(DISTINCT channel_id) FROM epg_channels;');
            self::store_param($db, 'channels', $served);
            self::store_param($db, 'picons', $picons);

            Logger::log(Logger::Inf, "Total known channels id's: $channels");
            Logger::log(Logger::Inf, "Total channels without information: $cnt");
            Logger::log(Logger::Inf, "Total known picons:  $picons");
            Logger::log(Logger::Inf, "Total unique epg id's indexed: $total_epg, total blocks: $total_blocks");
            $ret = true;
        } catch (Exception $ex) {
            Logger::log(Logger::Err, $ex->getMessage());
        } finally {
            fclose($file);
        }

        return $ret;
    }

    /**
     * @param SqlWrapper $db
     * @param string $json_path
     * @param string $indexed_file
     * @return bool
     */
    protected function db2json(SqlWrapper $db, string $json_path, string $indexed_file): bool
    {
        Logger::log(Logger::Inf, 'Start JSON conversion...');

        if (!file_exists($indexed_file)) {
            Logger::log(Logger::Err, "File $indexed_file does not exist");
            return false;
        }

        $file = fopen($indexed_file, 'rb');
        if ($file === false) {
            Logger::log(Logger::Err, "File $indexed_file can't be opened");
            return false;
        }

        $query = 'SELECT DISTINCT channel_id, picon_url FROM epg_channels as ch LEFT JOIN epg_picons as pic ON ch.picon_hash = pic.picon_hash;';
        $picons = [];
        $stored = 0;
        $result = $db->query($query);
        if ($result === false) {
            fclose($file);
            return false;
        }
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            // cast so a channel without a picon still registers as a known key
            $picons[$row['channel_id']] = (string)$row['picon_url'];
            ++$stored;
        }

        $total = 0;
        $written = [];
        $channel_id = null;
        $picon_url = '';
        $item_str = '';
        $programmes = 0;
        $epg_start = 0;
        $epg_end = 0;
        $ch_first = 0;
        $ch_last = 0;
        $ranges = [];

        $flush = function () use (&$channel_id, &$picon_url, &$item_str, &$total,
                                 &$ch_first, &$ch_last, &$ranges, $json_path) {
            if ($channel_id === null || $item_str === '') {
                return;
            }

            // the span this channel covers, kept for the detail page. Collected here
            // because every programme of it has just been decoded anyway
            if ($ch_first > 0) {
                $ranges[$channel_id] = array($ch_first, $ch_last);
            }

            $str = '{' . PHP_EOL;
            if ($picon_url !== '') {
                $str .= trim(json_encode(array('epg_picon' => $picon_url), JSON_UNESCAPED_SLASHES), "{}") . ',' . PHP_EOL;
            }
            $str .= '"epg_data": [' . PHP_EOL;
            $str .= $item_str;
            $str .= PHP_EOL . ']}';

            file_put_contents($json_path . '/' . escape_channel_filename($channel_id) . '.json', $str);
            ++$total;
        };

        // One ordered pass over every block beats a SELECT per channel: the rows arrive
        // already grouped by channel, so each channel is flushed when the id changes and
        // nothing more than the current channel is ever held in memory.
        $result = $db->query('SELECT channel_id, start, end FROM epg_entries ORDER BY channel_id, start;');
        if ($result === false) {
            fclose($file);
            return false;
        }

        while ($pos = $result->fetchArray(SQLITE3_ASSOC)) {
            // epg_entries.channel_id is declared STRING, which sqlite reads as NUMERIC
            // affinity, so an id like "36.6" comes back as a float - force it back to text
            $row_id = is_string($pos['channel_id']) ? $pos['channel_id'] : (string)$pos['channel_id'];
            if ($row_id !== $channel_id) {
                $flush();
                $channel_id = $row_id;
                $item_str = '';
                $ch_first = 0;
                $ch_last = 0;
                // a <programme> may name a channel that has no <channel> element - skip it,
                // as driving the loop from epg_channels used to do
                if (!isset($picons[$channel_id])) {
                    $channel_id = null;
                    continue;
                }
                $picon_url = $picons[$channel_id];
                $written[$channel_id] = true;
            }

            fseek($file, $pos['start']);
            $length = $pos['end'] - $pos['start'];
            if ($length <= 0) {
                Logger::log(Logger::Wrn, "Mismatch start '{$pos['start']}' and end '{$pos['end']}' positions for $channel_id");
                continue;
            }

            $block = fread($file, $length);

            // A DOM tree costs roughly 20x the source it is built from, so a block is
            // parsed in pieces cut on </programme> boundaries to keep memory bounded.
            $block_len = strlen($block);
            $offset = 0;
            while ($offset < $block_len) {
                if ($block_len - $offset <= self::PARSE_CHUNK_SIZE) {
                    $piece = substr($block, $offset);
                    $offset = $block_len;
                } else {
                    $cut = strrpos($block, '</programme>', ($offset + self::PARSE_CHUNK_SIZE) - $block_len);
                    if ($cut === false || $cut <= $offset) {
                        // single programme larger than the chunk size - take the whole rest
                        $piece = substr($block, $offset);
                        $offset = $block_len;
                    } else {
                        $cut += 12;
                        $piece = substr($block, $offset, $cut - $offset);
                        $offset = $cut;
                    }
                }

                $xml_str = "<tv>$piece</tv>";
                $xml_node = new DOMDocument();
                if ($xml_node->loadXML($xml_str, LIBXML_NOWARNING | LIBXML_NOERROR) === false) {
                    Logger::log(Logger::Err, "Error parsing xml block:");
                    foreach (libxml_get_errors() as $error) {
                        Logger::log(Logger::Err, display_xml_error($error, $xml_str));
                    }
                    libxml_clear_errors();
                    continue;
                }

                // walk the siblings directly - iterating the live DOMNodeList that
                // getElementsByTagName() returns is quadratic in the number of <programme>
                for ($tag = $xml_node->documentElement->firstChild; $tag !== null; $tag = $tag->nextSibling) {
                    if ($tag->nodeType !== XML_ELEMENT_NODE || $tag->nodeName !== 'programme') continue;

                    $item = parse_programme_node($tag);
                    if (!empty($item_str)) {
                        $item_str .= ",\n";
                    }
                    $item_str .= json_encode($item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

                    // the span the source covers, collected here because every programme
                    // is already decoded - a separate pass over it would not be free
                    ++$programmes;
                    if ($item['time'] > 0) {
                        if ($epg_start === 0 || $item['time'] < $epg_start) {
                            $epg_start = $item['time'];
                        }
                        if ($ch_first === 0 || $item['time'] < $ch_first) {
                            $ch_first = $item['time'];
                        }
                    }
                    if ($item['time_to'] > $epg_end) {
                        $epg_end = $item['time_to'];
                    }
                    if ($item['time_to'] > $ch_last) {
                        $ch_last = $item['time_to'];
                    }
                }
            }
        }

        $flush();

        if (Logger::isEnabled(Logger::Dbg)) {
            foreach ($picons as $id => $unused) {
                if (!isset($written[$id])) {
                    Logger::log(Logger::Dbg, "No positions for channel: $id");
                }
            }
        }

        // Written in one go after the pass rather than inside it: the loop is stepping a
        // cursor over epg_entries, and this keeps the two off each other's toes.
        $db->exec('DROP TABLE IF EXISTS epg_ranges;');
        $db->exec('CREATE TABLE epg_ranges (channel_id TEXT NOT NULL PRIMARY KEY, first_ts INTEGER, last_ts INTEGER);');
        $db->exec('BEGIN;');
        $stm = $db->prepare('INSERT OR REPLACE INTO epg_ranges (channel_id, first_ts, last_ts) VALUES(:channel_id, :first_ts, :last_ts);');
        /** @var string $range_id */
        /** @var int $first_ts */
        /** @var int $last_ts */
        $stm->bindParam(':channel_id', $range_id);
        $stm->bindParam(':first_ts', $first_ts);
        $stm->bindParam(':last_ts', $last_ts);
        foreach ($ranges as $range_id => $range) {
            // a numeric looking id comes back from the array key as an int - make it text
            $range_id = (string)$range_id;
            $first_ts = $range[0];
            $last_ts = $range[1];
            $stm->execute();
        }
        $db->exec('COMMIT;');

        self::store_param($db, 'programmes', $programmes);
        self::store_param($db, 'epg_start', $epg_start);
        self::store_param($db, 'epg_end', $epg_end);

        fclose($file);
        Logger::log(Logger::Inf, "Json files generated: $total from: $stored existing");
        Logger::log(Logger::Inf, "Total programmes: $programmes"
            . ($epg_start > 0 ? ", guide range: " . date('Y-m-d H:i', $epg_start) . ' - ' . date('Y-m-d H:i', $epg_end) : ''));
        Logger::log_separator();

        return true;
    }

    /**
     * @param SqlWrapper $db
     * @param string $json_path
     * @param int $max_days
     * @return array
     */
    protected function purge_stalled(SqlWrapper $db, string $json_path, int $max_days): array
    {
        if ($max_days === -1) {
            return [];
        }
        $db->exec(sprintf("INSERT OR REPLACE INTO epg_params (param, value) VALUES ('last_update', '%d');", time()));
        $known_channels = $db->fetch_array('SELECT DISTINCT channel_id from epg_channels;', 'channel_id');

        $known_channels = array_map(function ($channel_id) {
            return escape_channel_filename((string)$channel_id) . '.json';
        }, $known_channels);

        $files = [];
        foreach (glob("$json_path/*.json") as $file) {
            $files[] = pathinfo($file, PATHINFO_BASENAME);
        }

        $stalled = array_diff($files, $known_channels, array('channels_info.json'));
        $purged = [];
        if (!empty($stalled)) {
            Logger::log(Logger::Dbg, "Stalled files: " . implode(',', $stalled));
            $now = time();
            array_map(function ($filename) use ($json_path, $now, $max_days, &$purged) {
                $filepath = $json_path . '/' . $filename;
                $mtime = filemtime($filepath);
                if ($mtime + $max_days * 86400 < $now) {
                    $purged[] = pathinfo($filepath, PATHINFO_FILENAME);
                    unlink($filepath);
                }
            }, $stalled);
        }

        return $purged;
    }

    /**
     * Remember a value in epg_params so a later run - or the info page after a run that
     * downloaded nothing - can read it back.
     *
     * @param SqlWrapper $db
     * @param string $param
     * @param int|string $value
     * @return void
     */
    protected static function store_param(SqlWrapper $db, string $param, int|string $value): void
    {
        $db->exec(sprintf('INSERT OR REPLACE INTO epg_params (param, value) VALUES (%s, %s);',
            SqlWrapper::sql_quote($param), SqlWrapper::sql_quote((string)$value)));
    }

    protected function save_channels_info(SqlWrapper $db, string $json_path): void
    {
        $info['epg_id'] = $db->fetch_array('SELECT DISTINCT channel_id from epg_channels order by channel_id;', 'channel_id');
        $aliases = $db->fetch_array('SELECT alias, channel_id from epg_channels WHERE alias_orig != channel_id ORDER BY alias;');
        foreach ($aliases as $channel) {
            $info['epg_aliases'][$channel['alias']] = $channel['channel_id'];
        }
        file_put_contents("$json_path/channels_info.json", json_encode($info, JSON_UNESCAPED_UNICODE));
    }
}