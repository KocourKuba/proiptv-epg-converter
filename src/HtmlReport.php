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
 * Builds a standalone HTML summary of a conversion run.
 *
 * The page carries its own style and script, so it works served straight out of the
 * target directory by any web server or NAS with nothing else installed.
 */
final class HtmlReport extends ReportPage
{
    /** @var SourceReport[] */
    private array $sources = array();
    /** @var float */
    private float $total_time = 0.0;
    /** @var bool Whether the presets file is beside the page, and the page may link to it. */
    private bool $presets_saved = false;
    /** @var string Address the target directory is served from. */
    private string $base_url = Converter::PRESET_BASE_URL;

    /**
     * @param string $version
     * @param string $title
     */
    public function __construct(string $version, string $title = 'Powered by ProIPTV EPG Server')
    {
        parent::__construct($version, $title);
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
     * @param string $base_url Address the target directory is served from, without a trailing slash.
     * @return void
     */
    public function set_base_url(string $base_url): void
    {
        $this->base_url = $base_url;
    }

    /**
     * @param bool $saved Whether the presets file was written beside the page.
     * @return void
     */
    public function set_presets_saved(bool $saved): void
    {
        $this->presets_saved = $saved;
    }

    /**
     * @return string
     */
    protected function kind(): string
    {
        return 'info page';
    }

    /**
     * @return string
     */
    protected function subtitle(): string
    {
        return 'XMLTV guides converted to JSON &middot; ' . $this->generated_by();
    }

    /**
     * @return string
     */
    protected function footer_text(): string
    {
        return 'Guides are served as plain JSON files. This page is rewritten after every '
            . 'conversion run.';
    }

    /**
     * @return string
     */
    protected function body(): string
    {
        return $this->render_cards() . $this->render_table() . $this->render_usage();
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
            array('Channels (EPG)', 'r', 'found in the source (with programmes)'),
            array('Picons', 'r'),
            array('Programmes', 'r'),
            array('Files', 'r'),
            array('Size', 'r'),
            array('Updated (GMT)', 'l'),
            array('Download', 'r', 'time spent fetching the source'),
            array('Processing', 'r', 'time spent unpacking, indexing and writing json'),
        );

        $html = '<section class="panel">' . PHP_EOL;
        $html .= '<div class="panel-head">';
        $html .= '<h2>Sources</h2>';
        $html .= '<input id="filter" type="search" placeholder="Filter by name&hellip;" autocomplete="off">';
        $html .= '</div>' . PHP_EOL;

        $html .= '<div class="scroll"><table id="sources"><thead><tr>';
        foreach ($columns as $index => $column) {
            $title = isset($column[2]) ? ' title="' . self::e($column[2]) . '"' : '';
            $html .= '<th class="' . $column[1] . '" data-col="' . $index . '" tabindex="0"' . $title . '>'
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
        $updated = $source->last_update > 0 ? self::e(date('Y-m-d H:i', $source->last_update)) : '&mdash;';

        // the xmltv source url is deliberately kept off the page, and out of its markup -
        // the info page is meant to be served publicly, the upstream links are not
        $row = '<tr data-key="' . self::e($source->id) . '">' . PHP_EOL;

        $row .= '<td class="l src">';
        // a source with no detail page - one that has nothing on disk yet - has nowhere
        // to link to, and stays plain text
        $row .= $source->detail !== ''
            ? '<a class="name" href="./' . rawurlencode($source->id) . '/' . rawurlencode($source->detail) . '">'
                . self::e($source->id) . '</a>'
            : '<span class="name">' . self::e($source->id) . '</span>';
        if ($source->error !== '') {
            $row .= '<span class="err">' . self::e($source->error) . '</span>';
        }
        $row .= '</td>' . PHP_EOL;

        $row .= '<td class="l"><span class="badge ' . SourceStatus::css($source->status) . '">'
            . self::e($source->status) . '</span></td>' . PHP_EOL;

        // found (with guide) - a source indexed before the total was kept has only the second
        if ($source->channels_total > 0) {
            $row .= self::cell($source->channels_total,
                self::num($source->channels_total) . ' (' . self::num($source->channels) . ')');
        } else {
            $row .= self::cell($source->channels, self::num($source->channels));
        }
        $row .= self::cell($source->picons, self::num($source->picons));
        $row .= self::cell($source->programmes, self::num($source->programmes));

        $row .= self::cell($source->files, self::num($source->files));
        $row .= self::cell($source->files_size, convert_bytes($source->files_size));
        $row .= '<td class="l" data-v="' . $source->last_update . '">' . $updated . '</td>' . PHP_EOL;
        $row .= self::cell((int)round($source->download_time * 1000), self::duration($source->download_time));
        $row .= self::cell((int)round($source->process_time * 1000), self::duration($source->process_time));

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
        $html .= '<pre><code>' . self::e("$this->base_url/$sample/epg/") . '&lt;epg_id&gt;.json</code></pre>' . PHP_EOL;
        $html .= '<p>The ids a source knows, along with the display names they can also be reached '
            . 'by, are listed in <code>channels_info.json</code>:</p>' . PHP_EOL;
        $html .= '<pre><code>' . self::e("$this->base_url/$sample/epg/channels_info.json") . '</code></pre>' . PHP_EOL;
        if ($this->presets_saved) {
            // the placeholder is only there when the configuration gave no address
            $replace = $this->base_url === Converter::PRESET_BASE_URL
                ? ' - replace <code>' . self::e(Converter::PRESET_BASE_URL) . '</code> in it with the address of this page'
                : '';
            $html .= '<p>The sources are ready to use as ProIPTV epg presets, one for each source, '
                . 'named by its id' . $replace . ': <a href="./' . Converter::PRESETS_FILE . '" download>'
                . self::e(Converter::PRESETS_FILE) . '</a></p>' . PHP_EOL;
        }
        $html .= '</div></section>' . PHP_EOL;

        return $html;
    }
}
