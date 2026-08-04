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

class Converter extends SqlWrapper
{
    protected static array $http_response_headers;
    protected array $params;

    protected string $working_dir;

    /**
     * @param array $params
     * @param string $working_dir
     */
    public function __construct(array $params, string $working_dir)
    {
        $this->params = $params;
        $this->working_dir = $working_dir;
    }

    /**
     * @return array
     */
    public static function get_response_headers(): array
    {
        return empty(self::$http_response_headers) ? array() : self::$http_response_headers;
    }

    /**
     * @param string $header
     * @return string
     */
    public static function get_response_header(string $header): string
    {
        return safe_get_value(self::$http_response_headers, $header, '');
    }

    /**
     * @param object $curl
     * @param string $header
     * @return int
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
     * Return filename that successfully downloaded or false
     * @param array $settings
     * @return int
     */
    protected function download(array &$settings): int
    {
        self::$http_response_headers = array();

        $id = $this->params['id'];
        $url = $this->params['url'];
        $manual = $this->params['manual_check'] ?? false;
        $etag = '';

        if ($manual !== false && isset($settings[$id]['last_check'])) {
            $last_check = $settings[$id]['last_check'];
            if ($last_check + $manual * 3600 > time()) {
                Logger::log(severity::Err, "Manual download is not expired.");
                return 2;
            }
        }

        $filename = "$this->working_dir/$id/" . basename($this->params['url']);

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

        $fp = fopen($filename, "w+");
        $opts[CURLOPT_FILE] = $fp;

        $opts[CURLOPT_HTTPHEADER][] = "Accept: */*";
        $opts[CURLOPT_HTTPHEADER][] = "Pragma: no-cache";
        $parsed_url = parse_url($url);
        if (isset($parsed_url['host'])) {
            $opts[CURLOPT_HTTPHEADER][] = "Host: {$parsed_url['host']}";
        }

        if (!empty($settings[$id]['etag'])) {
            $etag = $settings[$id]['etag'];
            $opts[CURLOPT_HTTPHEADER][] = "If-None-Match: $etag";
        }

        $opts[CURLOPT_CUSTOMREQUEST] = "GET";

        $ch = curl_init();
        if ($ch === false) {
            Logger::log(severity::Err, 'Curl init failed!');
            return 1;
        }

        Logger::log(severity::Dbg, "Request headers...");
        foreach ($opts[CURLOPT_HTTPHEADER] as $v) {
            Logger::log(severity::Dbg, $v);
        }

        foreach ($opts as $k => $v) {
            curl_setopt($ch, $k, $v);
        }

        Logger::log(severity::Inf, "Begin download: $url");

        $start_tm = microtime(true);
        $content = curl_exec($ch);
        $execution_tm = microtime(true) - $start_tm;
        $error_no = curl_errno($ch);
        $error_desc = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (!is_null($fp)) {
            fclose($fp);
        }

        Logger::log(severity::Dbg, "Response headers...");
        foreach (self::get_response_headers() as $k => $v) {
            Logger::log(severity::Dbg, "$k: $v");
        }

        if ($http_code < 200 || ($http_code >= 300 && $http_code != 301 && $http_code != 304)) {
            Logger::log(severity::Err, 'HTTP request failed (' . $http_code . ')');
            Logger::log(severity::Err, 'HTTP response: ' . $content);
            return 1;
        }

        if ($error_no !== 0) {
            $msg = sprintf('CURL errno: %s (%s); HTTP error: %s', $error_no, $error_desc, $http_code);
            Logger::log(severity::Err, $msg);
            return 1;
        }

        $new_etag = self::get_response_header('etag');
        if (!empty($new_etag) && $etag !== $new_etag) {
            Logger::log(severity::Inf, "Save new ETag ($new_etag) for: $url");
            $settings[$id]['etag'] = $new_etag;
        }

        $ret = 0;
        if ($http_code == 301 || $http_code == 304) {
            Logger::log(severity::Inf, sprintf('HTTP code (%d) in %.3fs', $http_code, $execution_tm));
            Logger::log(severity::Inf, "Server response that file is not changed");
            $ret = 2;
        } else if (file_exists($filename)) {
            Logger::log(severity::Inf,
                sprintf('Save file: HTTP OK (%d, %d bytes) in %.3fs', $http_code, filesize($filename), $execution_tm));
            Logger::log(severity::Inf, "Downloaded file saved to: $filename");
        } else {
            Logger::log(severity::Err, sprintf('HTTP code (%d) in %.3fs', $http_code, $execution_tm));
            Logger::log(severity::Err, "Saved file '$filename' is not exist!");
            $ret = 1;
        }

        if ($ret !== 1) {
            $settings[$id]['last_check'] = time();
        }

        Logger::log_separator();
        return $ret;
    }

    /**
     * @return string|null
     */
    public function uncompress(): ?string
    {
        $filename = $this->working_dir . '/' . $this->params['id'] . '/' . basename($this->params['url']);
        $handle = fopen($filename, "rb");
        $hdr = fread($handle, 8);
        fclose($handle);

        if (0 === mb_strpos($hdr, "\x1f\x8b\x08")) {
            Logger::log(severity::Inf, 'GZ signature:  ' . bin2hex(substr($hdr, 0, 3)));
            Logger::log(severity::Inf, 'Ungzipping: ' . $filename);
            $filename = extractGzipFile($filename);
            if (is_null($filename)) {
                Logger::log(severity::Err, "Failed to unpack $filename");
            }
        } else if (false !== mb_strpos($hdr, "<?xml")) {
            Logger::log(severity::Inf, 'XMLT signature:  ' . bin2hex(substr($hdr, 0, 3)));
        } else {
            Logger::log(severity::Err, 'Unsupported format! For file: ' . $filename);
            return null;
        }

        return $filename;
    }

    public function indexing(string $filename): bool
    {
        /// Reindex channels and picons
        Logger::log(severity::Err, 'Start index channels and picons...');

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

            $name = $this->params['id'];
            $db_name = "$this->working_dir/$name/$name.db";
            $this->open_db($db_name);

            $query = 'DROP TABLE IF EXISTS epg_channels;';
            $query .= 'DROP TABLE IF EXISTS epg_picons;';
            $query .= 'CREATE TABLE epg_channels (alias TEXT PRIMARY KEY not null, channel_id TEXT not null, picon_hash TEXT);';
            $query .= 'CREATE TABLE epg_picons (picon_hash TEXT PRIMARY KEY not null, picon_url TEXT);';
            $res = $this->exec_transaction($query);
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
                    Logger::log(severity::Err, "Error parsing xml file:\n$line");
                    foreach (libxml_get_errors() as $error) {
                        $xml_error = "Error [$error->code] at line $error->line, column $error->column: " . trim($error->message) . "\n";
                        Logger::log(severity::Err, $xml_error);
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
            $this->exec_transaction($query);

            $channels = (int)$this->query_value('SELECT count(DISTINCT channel_id) FROM epg_channels;');
            $picons = (int)$this->query_value('SELECT COUNT(*) FROM epg_picons;');

            Logger::log(severity::Inf, "Total channels id's: $channels");
            Logger::log(severity::Inf, "Total known picons:  $picons");

            /// Reindex positions
            Logger::log(severity::Inf, 'Start indexing entries...');


            Logger::log(severity::Inf, "Indexing positions for: '$filename'");

            $query = 'DROP TABLE IF EXISTS epg_entries;';
            $query .= 'CREATE TABLE epg_entries (channel_id STRING NOT NULL, start INTEGER, end INTEGER, UNIQUE (channel_id, start) ON CONFLICT REPLACE);';
            $res = $this->exec_transaction($query);
            if (!$res) {
                throw new Exception("Error transaction: $query");
            }

            $this->exec('BEGIN;');

            $query = 'INSERT INTO epg_entries (channel_id, start, end) VALUES(:channel_id, :start, :end);';
            $stm = $this->prepare($query);
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
                        Logger::log(severity::Err, "Error inserting position start: $start_program_block end: $tag_end_pos for channel: $prev_channel");
                    }
                    $prev_channel = $channel_id;
                    $start_program_block = $tag_start_pos;
                }
            }

            $this->exec('COMMIT;');

            // Cleanup channels without positions
            $query = 'SELECT COUNT(distinct channel_id) FROM epg_channels WHERE channel_id NOT IN (SELECT distinct channel_id FROM epg_entries);';
            $cnt = $this->query_value($query);

            if ($cnt) {
                Logger::log(severity::Inf, "Purged channels without information: $cnt");
                $this->exec('DELETE FROM epg_channels WHERE channel_id NOT IN (SELECT distinct channel_id FROM epg_entries);');
            }

            $total_epg = (int)$this->query_value('SELECT count(DISTINCT channel_id) FROM epg_entries;');
            $total_blocks = (int)$this->query_value('SELECT COUNT(*) FROM epg_entries;');

            Logger::log(severity::Inf, "Total unique epg id's indexed: $total_epg, total blocks: $total_blocks");
            $ret = true;
        } catch (Exception $ex) {
            Logger::log(severity::Err, $ex->getMessage());
        } finally {
            fclose($file);
        }

        return $ret;
    }

    /**
     * @param string $path
     * @param string $filename
     * @return bool
     */
    protected function db2json(string $path, string $filename): bool
    {
        if (!file_exists($path)) {
            Logger::log(severity::Err, "File $path does not exist");
            return false;
        }

        $ext_epg = function($items, $tag, $name) {
            $value = get_node_value($tag, $name);
            if (!empty($value)) {
                $items[$name] = $value;
            }
        };

        $file = fopen($filename, 'rb');
        if ($file === false) {
            Logger::log(severity::Err, "File $path can't be opened");
            return false;
        }


        $query = 'SELECT DISTINCT channel_id, picon_url FROM epg_channels as ch LEFT JOIN epg_picons as pic ON ch.picon_hash = pic.picon_hash;';
        $total = 0;
        $channel_ids = $this->fetch_array($query);
        $stored = count($channel_ids);
        foreach ($channel_ids as $row) {
            $channel_id = $row['channel_id'];
            $query = sprintf('SELECT start, end FROM epg_entries WHERE channel_id = %s;', SqlWrapper::sql_quote($channel_id));
            $channel_positions = $this->fetch_array($query);
            if (empty($channel_positions)) {
                Logger::log(severity::Dbg, "No positions for channel: $channel_id");
                continue;
            }

            $icon = $row['picon_url'];
            $items = array();
            foreach ($channel_positions as $pos) {
                fseek($file, $pos['start']);
                $length = $pos['end'] - $pos['start'];
                if ($length <= 0) {
                    Logger::log(severity::Dbg, "Mismatch start '{$pos['start']}' and end '{$pos['end']}' positions for $channel_id");
                    continue;
                }

                $xml_str = "<tv>" . fread($file, $pos['end'] - $pos['start']) . "</tv>";

                $xml_node = new DOMDocument();
                $res = $xml_node->loadXML($xml_str);
                if ($res === false) {
                    Logger::log(severity::Err, "Exception in line:\n$xml_str");
                    continue;
                }

                foreach ($xml_node->getElementsByTagName('programme') as $tag) {
                    $item = array();
                    $item['name'] = get_node_value($tag, 'title');
                    $item['time'] = strtotime($tag->getAttribute('start'));
                    $item['time_to'] = strtotime($tag->getAttribute('stop'));
                    $item['descr'] = get_node_value($tag, 'desc');

                    $ext_epg($item, $tag, 'sub-title');
                    $ext_epg($item, $tag, 'category');
                    $ext_epg($item, $tag, 'date');
                    $ext_epg($item, $tag, 'country');
                    $ext_epg($item, $tag, 'image');

                    foreach ($tag->getElementsByTagName('credits') as $sub_tag) {
                        $ext_epg($item, $sub_tag, 'director');
                        $ext_epg($item, $sub_tag, 'producer');
                        $ext_epg($item, $sub_tag, 'actor');
                        $ext_epg($item, $sub_tag, 'presenter');
                        $ext_epg($item, $sub_tag, 'writer');
                        $ext_epg($item, $sub_tag, 'editor');
                        $ext_epg($item, $sub_tag, 'composer');
                    }

                    $items[] = $item;
                }
            }

            if (!empty($items)) {
                $all_items = array('epg_icon' => $icon, 'epg_data' => $items);
                $json_file = $path . '/' . str_replace(array('/',':'), array('%2F','%3A'), $channel_id) . '.json';
                $content = json_encode($all_items, JSON_UNESCAPED_UNICODE);
                file_put_contents($json_file, $content);
                ++$total;
            }
        }

        fclose($file);
        Logger::log(severity::Inf, "Json files generated: $total from: $stored");
        Logger::log_separator();

        return true;
    }

    /**
     * Download and index xmltv source
     *
     * @param array $settings
     * @return bool
     */
    public function convert(array &$settings): bool
    {
        $perf = new PerfCollector();
        $perf->reset('start');

        $ret = false;
        $name = safe_get_value($this->params, 'id');
        $url = safe_get_value($this->params, 'url');
        $keep_source = safe_get_value($this->params, 'keep_source', false);
        $manual_check = safe_get_value($this->params, 'manual_check', false);

        $source = $this->working_dir . '/' . $this->params['id'] . '/' . basename($url);

        try {
            $path = "$this->working_dir/$name/epg";
            if (!file_exists($path) && !@mkdir($path, '0777', true) && !is_dir($path)) {
                throw new Exception("Directory '$path' can't be created");
            }

            Logger::log_separator();
            Logger::log(severity::Inf, "Start conversion");
            Logger::log(severity::Inf, "Id: '$name'");
            Logger::log(severity::Inf, "Url: '$url'");
            Logger::log(severity::Inf, "Keep source: " . var_export($keep_source, true));
            Logger::log(severity::Inf, "Manual check: " . var_export($manual_check, true));

            $perf->setLabel('download_start');
            $res = $this->download($settings);
            $perf->setLabel('download_end');
            if ($res === 1) {
                throw new Exception("Failed to download file");
            }

            if ($res === 0) {
                $perf->setLabel('uncompress_start');
                $uncompressed = $this->uncompress();
                $perf->setLabel('uncompress_end');
                if (is_null($uncompressed)) {
                    throw new Exception("Failed to uncompress file.");
                }

                $perf->setLabel('index_start');
                $res = $this->indexing($uncompressed);
                $perf->setLabel('index_end');
                if ($res === false) {
                    throw new Exception("Failed to indexing XMLTV");
                }

                $perf->setLabel('convert_start');
                $res = $this->db2Json($path, $uncompressed);
                if ($res === false) {
                    throw new Exception("Failed to convert to JSON");
                }
                $perf->setLabel('convert_end');
            }

            $perf->setLabel('end');
            $report_download = $perf->getReportItem(PerfCollector::TIME, 'download_start', 'download_end');
            $report_uncompress = $perf->getReportItem(PerfCollector::TIME, 'uncompress_start', 'uncompress_end');
            $report_reindex = $perf->getReportItem(PerfCollector::TIME, 'index_start', 'index_end');
            $report_convert = $perf->getReportItem(PerfCollector::TIME, 'convert_start', 'convert_end');
            $report_all = $perf->getReportItem(PerfCollector::TIME, 'start', 'end');
            Logger::log(severity::Inf, "Download time: $report_download secs");
            Logger::log(severity::Inf, "Uncompress time: $report_uncompress secs");
            Logger::log(severity::Inf, "Indexing XMLTV source time: $report_reindex secs");
            Logger::log(severity::Inf, "Json generation time: $report_convert secs");
            Logger::log(severity::Inf, "Process conversion time: $report_all secs");

            $settings[$name]['last_update'] = time();
            $ret = true;
        } catch(Exception $ex) {
            Logger::log(severity::Err, $ex->getMessage());
            unset($settings[$name]);
        } finally {
            $keep_source = safe_get_value($this->params, 'keep_source', false);
            if (!$keep_source && file_exists($source)) {
                Logger::log(severity::Dbg, "Remove source file: $source");
                unlink($source);
            }

            if (!empty($uncompressed) && $source !== $uncompressed) {
                Logger::log(severity::Dbg, "Remove uncompressed file: $uncompressed");
                unlink($uncompressed);
            }
        }
        Logger::log_separator();

        return $ret;
    }
}