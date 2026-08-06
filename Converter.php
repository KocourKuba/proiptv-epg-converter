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

require_once 'Logger.php';
require_once 'PerfCollector.php';
require_once 'SqlWrapper.php';
require_once 'utils.php';

global $logger;

class Converter
{
    protected static array $http_response_headers;
    protected string $working_dir;
    private int $download_size = 0;

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
     * @param array $argv
     * @return void
     */
    public function process(array $argv): void
    {
        list(, $config_file) = $argv;

        if (empty($config_file)) {
            echo "Empty configuration file!";
            return;
        }

        if (!file_exists($config_file)) {
            echo "configuration file '$config_file' not found!";
            return;
        }

        $this->working_dir = pathinfo($config_file, PATHINFO_DIRNAME);
        $config = json_decode(file_get_contents($config_file), true);
        if (isset($config['log_path'])) {
            Logger::setLogPath($config['log_path']);
        } else {
            Logger::setLogPath("$this->working_dir/converter.log");
        }

        Logger::log(Logger::Perm, 'ProIPTV EPG Converter v1.1');
        Logger::log(Logger::Perm, 'Working directory: ' . $this->working_dir);

        if (isset($config['log_level'])) {
            Logger::setSeverity($config['log_level']);
            Logger::log(Logger::Perm, 'Log level set to: ' . $config['log_level']);
        }

        if (empty($config['sources'])) {
            Logger::log(Logger::Err, 'Empty sources configuration');
            return;
        }

        $perf_all = new PerfCollector();
        $perf_all->reset('start');

        $total = 0;
        $success = [];
        $failed = [];
        $skipped = [];
        foreach ($config['sources'] as $item) {
            $ret = $this->convert_item($item);
            if ($ret === 0) {
                $failed[] = $item['id'];
            } else if ($ret === 1) {
                $success[] = $item['id'];
            } else {
                $skipped[] = $item['id'];
            }
            $total++;
        }

        $perf_all->setLabel('end');
        $report_all = $perf_all->getReportItem(PerfCollector::TIME, 'start', 'end');
        Logger::log(Logger::Perm, "Total indexed:   " . count($success) . " " . implode(',', $success));
        Logger::log(Logger::Perm, "Total skipped:   " . count($skipped) . " " . implode(',', $skipped));
        Logger::log(Logger::Perm, "Total failed:    " . count($failed) . " " . implode(',', $failed));
        Logger::log(Logger::Perm, "Total processed: " . $total . " (" . convert_bytes($this->download_size) . ")");
        Logger::log(Logger::Perm, "Total time:      $report_all sec");
        Logger::log_separator();
    }

    /**
     * @param array $source_params
     * @return int
     */
    protected function convert_item(array $source_params): int
    {
        $perf = new PerfCollector();
        $perf->reset('start_item');

        $source_id = safe_get_value($source_params, 'id');
        if (empty($source_id)) {
            Logger::log(Logger::Err, 'Empty name not allowed in sources.conf');
            return 0;
        }

        $url = safe_get_value($source_params, 'url');
        if (empty($url)) {
            Logger::log(Logger::Err, 'Empty URL not allowed in sources.conf');
            return 0;
        }

        $keep_source = safe_get_value($source_params, 'keep_source', false);
        $manual_check = safe_get_value($source_params, 'manual_check', false);

        $xmltv_source = "$this->working_dir/$source_id/" . basename($url);

        $db = new SqlWrapper();
        $db_path = "$this->working_dir/$source_id/$source_id.db";
        $ret = 0;
        try {
            $json_path = "$this->working_dir/$source_id/epg";
            if (!file_exists($json_path) && !@mkdir($json_path, '0777', true) && !is_dir($json_path)) {
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
            $ret = $this->download($db, $url, $xmltv_source, $manual_check);
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
                $db->exec(sprintf("INSERT OR REPLACE INTO epg_params (param, value) VALUES ('last_update', '%d');", time()));
            }

            $perf->setLabel('end_item');
        } catch(Exception $ex) {
            Logger::log(Logger::Err, $ex->getMessage());
        } finally {
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

        Logger::log(Logger::Inf, "Download time: $report_download secs");
        Logger::log(Logger::Inf, "Uncompressing time: $report_uncompress secs");
        Logger::log(Logger::Inf, "Indexing XMLTV source time: $report_reindex secs");
        Logger::log(Logger::Inf, "Json generation time: $report_convert secs");
        Logger::log(Logger::Inf, "Source conversion time: $report_all secs");
        Logger::log_separator();

        return $ret;
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
     * @param int|false $manual_check
     * @return int
     */
    protected function download(SqlWrapper $db, string $url, string $filename, int $manual_check): int
    {
        self::$http_response_headers = [];

        Logger::log(Logger::Inf, "Begin download: $url");

        $last_check = $db->query_value("SELECT value FROM epg_params WHERE param='last_check';");
        if ($manual_check !== false && $last_check !== 0) {
            Logger::log(Logger::Dbg, "Last check: " . date('Y-m-d H:i:s', $last_check));
            $check_time = $last_check + $manual_check * 3600;
            if ($check_time > time()) {
                Logger::log(Logger::Err, "Manual download come. Next check: " . date('Y-m-d H:i:s', $check_time));
                Logger::log_separator();
                return 2;
            }
        }

        $etag = $db->query_value("SELECT value FROM epg_params WHERE param='etag';");
        Logger::log(Logger::Dbg, "Etag: $etag");

        $opts[CURLOPT_URL] = $url;
        $opts[CURLOPT_SSL_VERIFYPEER] = 0;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        $opts[CURLOPT_CONNECTTIMEOUT] = 30;
        $opts[CURLOPT_TIMEOUT] = 120;
        $opts[CURLOPT_RETURNTRANSFER] = 1;
        $opts[CURLOPT_FOLLOWLOCATION] = 1;
        $opts[CURLOPT_MAXREDIRS] = 5;
        $opts[CURLOPT_FILETIME] = 1;
        $opts[CURLOPT_HEADERFUNCTION] = 'Converter::http_header_function';
        $opts[CURLOPT_ENCODING] = "";

        $tmp_file = $filename . ".tmp";
        $fp = fopen($tmp_file, "w+");
        $opts[CURLOPT_FILE] = $fp;

        $opts[CURLOPT_HTTPHEADER][] = "Accept: */*";
        $opts[CURLOPT_HTTPHEADER][] = "Pragma: no-cache";
        $parsed_url = parse_url($url);
        if (isset($parsed_url['host'])) {
            $opts[CURLOPT_HTTPHEADER][] = "Host: {$parsed_url['host']}";
        }

        if (!$manual_check && !empty($etag)) {
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
            $content = curl_exec($ch);
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
                throw new Exception("HTTP request failed ($http_code)\nHTTP response: $content");
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
            $ret = 1;
        }

        if (file_exists($tmp_file)) {
            unlink($tmp_file);
        }

        Logger::log_separator();

        return $ret;
    }

    /**
     * @param object $curl
     * @param string $header
     * @return int
     * @noinspection PhpUnused
     * @noinspection PhpUnusedParameterInspection
     */
    public static function http_header_function(object $curl, string $header): int
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
        $handle = fopen($filename, "rb");
        $hdr = fread($handle, 8);
        fclose($handle);

        if (0 === mb_strpos($hdr, "\x1f\x8b\x08")) {
            Logger::log(Logger::Dbg, 'GZ signature:  ' . bin2hex(substr($hdr, 0, 3)));
            Logger::log(Logger::Inf, 'UnGZIP: ' . $filename);
            $filename = extractGzipFile($filename);
            if (is_null($filename)) {
                Logger::log(Logger::Err, "Failed to unpack $filename");
            }
        } else if (0 === mb_strpos($hdr, "\x50\x4b\x03\x04")) {
            Logger::log(Logger::Dbg, 'ZIP signature: ' . bin2hex(substr($hdr, 0, 4)));
            Logger::log(Logger::Inf, 'UnZIP: ' . $filename);
            $filename = extractZipArchive($filename);
            if (is_null($filename)) {
                Logger::log(Logger::Err, "Failed to unpack $filename");
            }
        } else if (false !== mb_strpos($hdr, "<?xml")) {
            Logger::log(Logger::Dbg, 'XML signature:  ' . bin2hex(substr($hdr, 0, 3)));
        } else {
            Logger::log(Logger::Err, 'Unsupported format! ' . $filename);
            return null;
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
            $query .= 'CREATE TABLE epg_channels (alias TEXT PRIMARY KEY not null, channel_id TEXT not null, picon_hash TEXT);';
            $query .= 'CREATE TABLE epg_picons (picon_hash TEXT PRIMARY KEY not null, picon_url TEXT);';
            $res = $db->exec_transaction($query);
            if (!$res) {
                throw new Exception("Error transaction: $query");
            }

            $query = '';
            $last_buffer = '';
            while (!feof($file)) {
                // search for open tag <channel>
                $chunk = fread($file, 8192);
                $buffer = $last_buffer . $chunk;
                $pos = strpos($buffer, '<channel id');
                if ($pos === false) {
                    $last_buffer = $chunk;
                    continue;
                }

                // calculate start position in file and seek to + length of searched tag
                $last_buffer = '';
                $start_pos = ftell($file) - strlen($buffer) + $pos;
                fseek($file, $start_pos + 11);

                // read content until closed tag found
                $line = '';
                while (!feof($file)) {
                    // search for closing tag </channel>
                    $chunk = fread($file, 8192);
                    $buffer = $last_buffer . $chunk;
                    $pos = strpos($buffer, '</channel>');
                    if ($pos === false) {
                        $last_buffer = $chunk;
                        continue;
                    }

                    $last_buffer = '';
                    // calculate end position in file
                    $end_pos = ftell($file) - strlen($buffer) + $pos + 10;
                    // seek to start position and read found text
                    fseek($file, $start_pos);
                    $line = fread($file, $end_pos - $start_pos);
                    break;
                }
                if (feof($file) || empty($line)) continue;

                $xml_node = new DOMDocument();
                if (!$xml_node->loadXML($line, LIBXML_NOWARNING | LIBXML_NOERROR)) {
                    Logger::log(Logger::Wrn, "Error parsing xml block:\n$line");
                    foreach (libxml_get_errors() as $error) {
                        $xml_error = "Error [$error->code] at line $error->line, column $error->column: " . trim($error->message) . "\n";
                        Logger::log(Logger::Err, $xml_error);
                    }
                    libxml_clear_errors();
                    continue;
                }
                foreach ($xml_node->getElementsByTagName('channel') as $tag) {
                    $channel_id = $tag->getAttribute('id');
                }

                if (empty($channel_id)) continue;

                $q_channel_id = SqlWrapper::sql_quote($channel_id);
                $picon_hash = '';
                foreach ($xml_node->getElementsByTagName('icon') as $tag) {
                    if (is_proto_http($tag->getAttribute('src'))) {
                        $picon_url = $tag->getAttribute('src');
                        if (!empty($picon_url)) {
                            $picon_hash = md5($picon_url);
                            $query .= sprintf('INSERT OR REPLACE INTO epg_picons (picon_hash, picon_url) VALUES(%s, %s);',
                                SqlWrapper::sql_quote($picon_hash), SqlWrapper::sql_quote($picon_url));
                            break;
                        }
                    }
                }

                $q_picon_hash = SqlWrapper::sql_quote($picon_hash);
                $q_alias = SqlWrapper::sql_quote(mb_convert_case($channel_id, MB_CASE_LOWER, "UTF-8"));
                $query .= sprintf('INSERT OR IGNORE INTO epg_channels (alias,channel_id,picon_hash) VALUES(%s,%s,%s);',
                    $q_alias, $q_channel_id, $q_picon_hash);

                foreach ($xml_node->getElementsByTagName('display-name') as $tag) {
                    $q_alias = SqlWrapper::sql_quote(mb_convert_case($tag->nodeValue, MB_CASE_LOWER, "UTF-8"));
                    $query .= sprintf('INSERT OR IGNORE INTO epg_channels (alias,channel_id,picon_hash) VALUES(%s,%s,%s);',
                        $q_alias, $q_channel_id, $q_picon_hash);
                }
            }
            $db->exec_transaction($query);

            $channels = (int)$db->query_value('SELECT count(DISTINCT channel_id) FROM epg_channels;');
            $picons = (int)$db->query_value('SELECT COUNT(*) FROM epg_picons;');

            $query = 'DROP TABLE IF EXISTS epg_entries;';
            $query .= 'CREATE TABLE epg_entries (channel_id STRING NOT NULL, start INTEGER, end INTEGER, UNIQUE (channel_id, start) ON CONFLICT REPLACE);';
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

        $is_win = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');

        $write_node = function(&$item, $tag, $node_name, $tag_name = null) {
            $value = get_node_value($tag, $node_name);
            if (!empty($value)) {
                $item[$tag_name ?? $node_name] = $value;
            }
        };

        $write_nodes = function(&$item, $tag, $node_name, $tag_name = null) {
            $value = get_node_values($tag, $node_name);
            if (!empty($value)) {
                $item[$tag_name ?? $node_name] = implode(", ", $value);
            }
        };

        $file = fopen($indexed_file, 'rb');
        if ($file === false) {
            Logger::log(Logger::Err, "File $indexed_file can't be opened");
            return false;
        }

        $query = 'SELECT DISTINCT channel_id, picon_url FROM epg_channels as ch LEFT JOIN epg_picons as pic ON ch.picon_hash = pic.picon_hash;';
        $total = 0;
        $channel_ids = $db->fetch_array($query);
        $stored = count($channel_ids);
        foreach ($channel_ids as $row) {
            $channel_id = $row['channel_id'];
            $query = sprintf('SELECT start, end FROM epg_entries WHERE channel_id = %s;', SqlWrapper::sql_quote($channel_id));
            $channel_positions = $db->fetch_array($query);
            if (empty($channel_positions)) {
                Logger::log(Logger::Dbg, "No positions for channel: $channel_id");
                continue;
            }

            $item_str = '';
            foreach ($channel_positions as $pos) {
                fseek($file, $pos['start']);
                $length = $pos['end'] - $pos['start'];
                if ($length <= 0) {
                    Logger::log(Logger::Wrn, "Mismatch start '{$pos['start']}' and end '{$pos['end']}' positions for $channel_id");
                    continue;
                }

                $xml_str = "<tv>" . fread($file, $pos['end'] - $pos['start']) . "</tv>";

                $xml_node = new DOMDocument();
                $res = $xml_node->loadXML($xml_str);
                if ($res === false) {
                    Logger::log(Logger::Wrn, "Exception in line:\n$xml_str");
                    continue;
                }

                $item = [];
                foreach ($xml_node->getElementsByTagName('programme') as $tag) {
                    $item['name'] = get_node_value($tag, 'title');
                    $item['time'] = strtotime($tag->getAttribute('start'));
                    $item['time_to'] = strtotime($tag->getAttribute('stop'));
                    $item['descr'] = get_node_value($tag, 'desc');

                    $icon = get_node_attribute($tag, 'icon', 'src');
                    if (!empty($icon) && is_proto_http($icon)) {
                        $item['icon'] = $icon;
                    }

                    $write_node($item, $tag, 'sub-title');
                    $write_node($item, $tag, 'category');
                    $write_node($item, $tag, 'date');
                    $write_node($item, $tag, 'country');

                    foreach ($tag->getElementsByTagName('credits') as $sub_tag) {
                        $write_nodes($item, $sub_tag, 'director');
                        $write_nodes($item, $sub_tag, 'producer');
                        $write_nodes($item, $sub_tag, 'actor');
                        $write_nodes($item, $sub_tag, 'presenter');
                        $write_nodes($item, $sub_tag, 'writer');
                        $write_nodes($item, $sub_tag, 'editor');
                        $write_nodes($item, $sub_tag, 'composer');
                    }
                    if (!empty($item_str)) {
                        $item_str .= ",\n";
                    }
                    $item_str .= json_encode($item, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
            }

            if (!empty($item_str)) {
                $str = '{' . PHP_EOL;
                if (!empty($row['picon_url'])) {
                    $str .= trim(json_encode(array('epg_picon' => $row['picon_url']), JSON_UNESCAPED_SLASHES), "{}") . ',' . PHP_EOL;
                }
                $str .= '"epg_data": [' . PHP_EOL;
                $str .= $item_str;
                $str .= PHP_EOL . ']}';

                if ($is_win) {
                    $escaped_name = str_replace(array('/','\\','\"','?','*','<','>','|',':'), array('%2F','%5C','%22','%3F','%2A','%3C','%3E','%7C','%3A'), $channel_id);
                } else {
                    $escaped_name = str_replace('/', '%2F', $channel_id);
                }
                $json_file = $json_path . '/' . $escaped_name . '.json';

                $f = fopen($json_file, 'wb');
                fwrite($f, $str);
                fclose($f);
                ++$total;
            }
        }

        fclose($file);
        Logger::log(Logger::Inf, "Json files generated: $total from: $stored existing");
        Logger::log_separator();

        return true;
    }
}