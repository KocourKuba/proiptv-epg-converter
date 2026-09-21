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
require_once 'utils.php';

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
    /** @var int */
    public int $channels;
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
    /** @var float */
    public float $duration;

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
        $this->picons = (int)safe_get_value($params, 'picons', 0);
        $this->programmes = (int)safe_get_value($params, 'programmes', 0);
        $this->files = (int)safe_get_value($params, 'files', 0);
        $this->files_size = (int)safe_get_value($params, 'files_size', 0);
        $this->epg_start = (int)safe_get_value($params, 'epg_start', 0);
        $this->epg_end = (int)safe_get_value($params, 'epg_end', 0);
        $this->last_update = (int)safe_get_value($params, 'last_update', 0);
        $this->duration = (float)safe_get_value($params, 'duration', 0.0);
    }

    /**
     * Depth of the guide in days, rounded down. Zero when the range is unknown.
     *
     * @return int
     */
    public function depth_days(): int
    {
        if ($this->epg_start <= 0 || $this->epg_end <= $this->epg_start) {
            return 0;
        }

        return (int)floor(($this->epg_end - $this->epg_start) / 86400);
    }
}

/**
 * Builds a standalone HTML summary of a conversion run.
 *
 * The page carries its own style and script, so it works served straight out of the
 * target directory by any web server or NAS with nothing else installed.
 */
final class HtmlReport
{
    /** Stylesheet of the info page, read from the directory this class lives in. */
    const STYLESHEET = 'epg_report.css';
    /** Script of the info page, read from the directory this class lives in. */
    const SCRIPT = 'epg_report.js';

    /** @var SourceReport[] */
    private array $sources = array();
    /** @var float */
    private float $total_time = 0.0;
    /** @var string */
    private string $version;
    /** @var string */
    private string $title;

    /**
     * @param string $version
     * @param string $title
     */
    public function __construct(string $version, string $title = 'Powered by ProIPTV EPG Server')
    {
        $this->version = $version;
        $this->title = $title;
    }

    /**
     * @param SourceReport $source
     * @return void
     */
    public function add(SourceReport $source): void
    {
        $this->sources[] = $source;
    }

    /**
     * @param float $total_time
     * @return void
     */
    public function set_run_time(float $total_time): void
    {
        $this->total_time = $total_time;
    }

    /**
     * Write the page. It is put in place by a rename so a visitor never gets served a
     * half written page while the converter is still running.
     *
     * @param string $path
     * @return bool
     */
    public function save(string $path): bool
    {
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        if (!create_path($dir)) {
            Logger::log(Logger::Err, "Can't create directory for info page: $dir");
            return false;
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $this->render()) === false) {
            Logger::log(Logger::Err, "Can't write info page: $tmp");
            return false;
        }

        if (file_exists($path) && !unlink($path)) {
            unlink($tmp);
            Logger::log(Logger::Err, "Can't replace info page: $path");
            return false;
        }

        if (!rename($tmp, $path)) {
            unlink($tmp);
            Logger::log(Logger::Err, "Can't rename info page to: $path");
            return false;
        }

        Logger::log(Logger::Perm, "Info page saved to: $path");
        return true;
    }

    /**
     * @return string
     */
    public function render(): string
    {
        $html = '<!DOCTYPE html>' . PHP_EOL;
        $html .= '<html lang="en">' . PHP_EOL;
        $html .= '<head>' . PHP_EOL;
        $html .= '<meta charset="utf-8">' . PHP_EOL;
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL;
        $html .= '<title>' . self::e($this->title) . '</title>' . PHP_EOL;
        // a missing stylesheet is reported by css() - emit no empty <style> for it
        $css = self::css();
        if ($css !== '') {
            $html .= '<style>' . PHP_EOL . $css . PHP_EOL . '</style>' . PHP_EOL;
        }
        $html .= '</head>' . PHP_EOL;
        $html .= '<body>' . PHP_EOL;

        $html .= '<header class="top"><div class="wrap">' . PHP_EOL;
        $html .= '<h1>' . self::e($this->title) . '</h1>' . PHP_EOL;
        $html .= '<p class="sub">XMLTV guides converted to JSON &middot; generated '
            . self::e(date('Y-m-d H:i:s'))
            . ' by ProIPTV EPG Converter v' . self::e($this->version) . '</p>' . PHP_EOL;
        $html .= '</div></header>' . PHP_EOL;

        $html .= '<main class="wrap">' . PHP_EOL;
        $html .= $this->render_cards();
        $html .= $this->render_table();
        $html .= $this->render_usage();
        $html .= '</main>' . PHP_EOL;

        $html .= '<footer class="wrap foot">' . PHP_EOL;
        $html .= '<p>Guides are served as plain JSON files. This page is rewritten after every '
            . 'conversion run.</p>' . PHP_EOL;
        $html .= '</footer>' . PHP_EOL;

        // a missing script is reported by js() - emit no empty <script> for it
        $js = self::js();
        if ($js !== '') {
            $html .= '<script>' . PHP_EOL . $js . PHP_EOL . '</script>' . PHP_EOL;
        }
        $html .= '</body>' . PHP_EOL . '</html>' . PHP_EOL;

        return $html;
    }

    ////////////////////////////////////////////////////////////
    // private functions

    /**
     * @return array
     */
    private function totals(): array
    {
        $totals = array(
            'sources' => count($this->sources),
            'ok' => 0,
            'idle' => 0,
            'skip' => 0,
            'bad' => 0,
            'channels' => 0,
            'programmes' => 0,
            'files' => 0,
            'files_size' => 0,
        );

        foreach ($this->sources as $source) {
            ++$totals[SourceStatus::css($source->status)];
            $totals['channels'] += $source->channels;
            $totals['programmes'] += $source->programmes;
            $totals['files'] += $source->files;
            $totals['files_size'] += $source->files_size;
        }

        return $totals;
    }

    /**
     * @return string
     */
    private function render_cards(): string
    {
        $totals = $this->totals();

        $cards = array(
            array('Sources', self::num($totals['sources']),
                sprintf('%d converted / %d up to date / %d failed / %d not run',
                    $totals['ok'], $totals['idle'], $totals['bad'], $totals['skip'])),
            array('Channels', self::num($totals['channels']), 'unique EPG ids'),
            array('Programmes', self::num($totals['programmes']), 'guide entries'),
            array('JSON files', self::num($totals['files']), convert_bytes($totals['files_size']) . ' on disk'),
            array('Run time', self::duration($this->total_time), 'total'),
        );

        $html = '<section class="cards">' . PHP_EOL;
        foreach ($cards as $card) {
            $html .= '<div class="card">';
            $html .= '<span class="k">' . self::e($card[0]) . '</span>';
            $html .= '<span class="v">' . self::e($card[1]) . '</span>';
            $html .= '<span class="n">' . self::e($card[2]) . '</span>';
            $html .= '</div>' . PHP_EOL;
        }
        $html .= '</section>' . PHP_EOL;

        return $html;
    }

    /**
     * @return string
     */
    private function render_table(): string
    {
        $columns = array(
            array('Source', 'l'),
            array('Status', 'l'),
            array('Channels', 'r'),
            array('Picons', 'r'),
            array('Programmes', 'r'),
            array('Guide range', 'l'),
            array('Files', 'r'),
            array('Size', 'r'),
            array('Updated', 'l'),
            array('Time', 'r'),
        );

        $html = '<section class="panel">' . PHP_EOL;
        $html .= '<div class="panel-head">';
        $html .= '<h2>Sources</h2>';
        $html .= '<input id="filter" type="search" placeholder="Filter by name or url&hellip;" autocomplete="off">';
        $html .= '</div>' . PHP_EOL;

        $html .= '<div class="scroll"><table id="sources"><thead><tr>';
        foreach ($columns as $index => $column) {
            $html .= '<th class="' . $column[1] . '" data-col="' . $index . '" tabindex="0">'
                . self::e($column[0]) . '</th>';
        }
        $html .= '</tr></thead><tbody>' . PHP_EOL;

        if (empty($this->sources)) {
            $html .= '<tr><td class="empty" colspan="' . count($columns) . '">No sources were processed.</td></tr>' . PHP_EOL;
        }

        foreach ($this->sources as $source) {
            $html .= $this->render_row($source);
        }

        $html .= '</tbody></table></div>' . PHP_EOL;
        $html .= '<p class="hint" id="nomatch" hidden>Nothing matches the filter.</p>' . PHP_EOL;
        $html .= '</section>' . PHP_EOL;

        return $html;
    }

    /**
     * @param SourceReport $source
     * @return string
     */
    private function render_row(SourceReport $source): string
    {
        $depth = $source->depth_days();
        $range = $source->epg_start > 0
            ? self::e(date('d.m.Y', $source->epg_start)) . ' &ndash; ' . self::e(date('d.m.Y', $source->epg_end))
            : '&mdash;';
        $updated = $source->last_update > 0 ? self::e(date('Y-m-d H:i', $source->last_update)) : '&mdash;';

        $row = '<tr data-key="' . self::e($source->id . ' ' . $source->url) . '">' . PHP_EOL;

        $row .= '<td class="l src">';
        // a source that has nothing on disk has no index to link to either
        $row .= $source->files > 0
            ? '<a class="name" href="./' . rawurlencode($source->id) . '/epg/channels_info.json">' . self::e($source->id) . '</a>'
            : '<span class="name">' . self::e($source->id) . '</span>';
        if ($source->url !== '') {
            $row .= '<span class="url" title="' . self::e($source->url) . '">' . self::e($source->url) . '</span>';
        }
        if ($source->error !== '') {
            $row .= '<span class="err">' . self::e($source->error) . '</span>';
        }
        $row .= '</td>' . PHP_EOL;

        $row .= '<td class="l"><span class="badge ' . SourceStatus::css($source->status) . '">'
            . self::e($source->status) . '</span></td>' . PHP_EOL;

        $row .= self::cell($source->channels, self::num($source->channels));
        $row .= self::cell($source->picons, self::num($source->picons));
        $row .= self::cell($source->programmes, self::num($source->programmes));

        $row .= '<td class="l" data-v="' . $depth . '">' . $range;
        if ($depth > 0) {
            $row .= ' <span class="tag">' . $depth . ' d</span>';
        }
        $row .= '</td>' . PHP_EOL;

        $row .= self::cell($source->files, self::num($source->files));
        $row .= self::cell($source->files_size, convert_bytes($source->files_size));
        $row .= '<td class="l" data-v="' . $source->last_update . '">' . $updated . '</td>' . PHP_EOL;
        $row .= self::cell((int)round($source->duration * 1000), self::duration($source->duration));

        $row .= '</tr>' . PHP_EOL;

        return $row;
    }

    /**
     * @return string
     */
    private function render_usage(): string
    {
        $sample = empty($this->sources) ? 'source' : $this->sources[0]->id;

        $html = '<section class="panel">' . PHP_EOL;
        $html .= '<div class="panel-head"><h2>How to use</h2></div>' . PHP_EOL;
        $html .= '<div class="usage">' . PHP_EOL;
        $html .= '<p>The guide of a single channel is one JSON file, addressed by its EPG id:</p>' . PHP_EOL;
        $html .= '<pre><code>' . self::e("http://your.server/$sample/epg/") . '&lt;epg_id&gt;.json</code></pre>' . PHP_EOL;
        $html .= '<p>The ids a source knows, along with the display names they can also be reached '
            . 'by, are listed in <code>channels_info.json</code>:</p>' . PHP_EOL;
        $html .= '<pre><code>' . self::e("http://your.server/$sample/epg/channels_info.json") . '</code></pre>' . PHP_EOL;
        $html .= '</div></section>' . PHP_EOL;

        return $html;
    }

    /**
     * A numeric cell carries its raw value in data-v, so the column sorts as a number
     * and not as the formatted text.
     *
     * @param int $value
     * @param string $text
     * @return string
     */
    private static function cell(int $value, string $text): string
    {
        return '<td class="r" data-v="' . $value . '">' . self::e($text) . '</td>' . PHP_EOL;
    }

    /**
     * @param int $value
     * @return string
     */
    private static function num(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    /**
     * @param float $seconds
     * @return string
     */
    private static function duration(float $seconds): string
    {
        if ($seconds <= 0) {
            return '-';
        }

        if ($seconds < 10) {
            return sprintf('%.2f s', $seconds);
        }

        if ($seconds < 60) {
            return sprintf('%.1f s', $seconds);
        }

        $minutes = (int)floor($seconds / 60);
        if ($minutes < 60) {
            return sprintf('%d m %02d s', $minutes, (int)round($seconds - $minutes * 60));
        }

        return sprintf('%d h %02d m', (int)floor($minutes / 60), $minutes % 60);
    }

    /**
     * @param string $value
     * @return string
     */
    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The stylesheet lives beside this class as a plain .css file, so it can be edited
     * and validated as one. It is inlined into the page rather than linked, which keeps
     * the generated page a single self-contained file.
     *
     * @return string
     */
    private static function css(): string
    {
        $path = __DIR__ . DIRECTORY_SEPARATOR . self::STYLESHEET;
        if (!is_readable($path)) {
            Logger::log(Logger::Wrn, "Stylesheet not found: $path. Info page will be unstyled.");
            return '';
        }

        $css = file_get_contents($path);
        if ($css === false) {
            Logger::log(Logger::Wrn, "Can't read stylesheet: $path. Info page will be unstyled.");
            return '';
        }

        return trim($css);
    }

    /**
     * The page script lives beside this class as a plain .js file, so it can be edited
     * and linted as one. Like the stylesheet it is inlined rather than linked, which
     * keeps the generated page a single self-contained file.
     *
     * @return string
     */
    private static function js(): string
    {
        $path = __DIR__ . DIRECTORY_SEPARATOR . self::SCRIPT;
        if (!is_readable($path)) {
            Logger::log(Logger::Wrn, "Script not found: $path. Info page will not sort or filter.");
            return '';
        }

        $js = file_get_contents($path);
        if ($js === false) {
            Logger::log(Logger::Wrn, "Can't read script: $path. Info page will not sort or filter.");
            return '';
        }

        return trim($js);
    }
}
