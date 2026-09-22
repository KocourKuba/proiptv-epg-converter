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

require_once 'ReportPage.php';

/**
 * The per source page: everything one source serves, channel by channel.
 *
 * It is written into the source directory, next to the epg folder its rows link into,
 * and is rebuilt only when the data behind it has moved on - see is_current().
 */
final class SourceDetail extends ReportPage
{
    /** @var SourceReport */
    private SourceReport $summary;
    /**
     * @var array[] One entry per channel: id, names, picon, file, size.
     */
    private array $channels;
    /** @var string */
    private string $index_link;

    /**
     * @param string $version
     * @param string $source_id
     * @param SourceReport $summary
     * @param array $channels
     * @param string $index_link
     */
    public function __construct(string $version, string $source_id, SourceReport $summary,
                                array  $channels, string $index_link = '')
    {
        parent::__construct($version, $source_id);
        $this->summary = $summary;
        $this->channels = $channels;
        $this->index_link = $index_link;
    }

    /**
     * Whether the page on disk still describes the current data.
     *
     * A page is current when it exists, was written after the last time the source was
     * updated, and is not older than the time to live. Anything else counts as expired
     * and the caller rebuilds it.
     *
     * @param string $path
     * @param int $last_update
     * @param int $ttl Seconds, or 0 for no age limit.
     * @return bool
     */
    public static function is_current(string $path, int $last_update, int $ttl): bool
    {
        if (!is_file($path)) {
            return false;
        }

        $mtime = filemtime($path);
        if ($mtime === false) {
            return false;
        }

        // the source was converted again after this page was written
        if ($last_update > 0 && $mtime < $last_update) {
            return false;
        }

        return $ttl <= 0 || (time() - $mtime) <= $ttl;
    }

    ////////////////////////////////////////////////////////////
    // page

    /**
     * @return string
     */
    protected function kind(): string
    {
        return 'detail page';
    }

    /**
     * One line per source would drown the log of a large configuration.
     *
     * @return int
     */
    protected function log_level(): int
    {
        return Logger::Inf;
    }

    /**
     * The heading is the source name, which is not a link anywhere.
     *
     * @return string
     */
    protected function title_href(): string
    {
        return '';
    }

    /**
     * @return string
     */
    protected function crumb(): string
    {
        if ($this->index_link === '') {
            return '';
        }

        return '<a class="crumb" href="' . self::e($this->index_link) . '">&larr; All sources</a>';
    }

    /**
     * @return string
     */
    protected function subtitle(): string
    {
        $parts = array(self::num(count($this->channels)) . ' channels');

        if ($this->summary->epg_start > 0) {
            $parts[] = 'guide covers ' . self::e(date('d.m.Y', $this->summary->epg_start))
                . ' &ndash; ' . self::e(date('d.m.Y', $this->summary->epg_end));
        }

        $parts[] = $this->generated_by();

        return implode(' &middot; ', $parts);
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
     * @return string
     */
    private function render_cards(): string
    {
        $summary = $this->summary;

        // no aggregate depth here - the table carries it per channel, which is the number
        // that actually says something about a source
        $cards = array(
            array('Channels', self::num($summary->channels), 'unique EPG ids'),
            array('Picons', self::num($summary->picons), 'channel logos'),
            array('Programmes', self::num($summary->programmes), 'guide entries'),
            array('JSON files', self::num($summary->files), convert_bytes($summary->files_size) . ' on disk'),
            array('Updated', $summary->last_update > 0 ? date('Y-m-d', $summary->last_update) : '-',
                $summary->last_update > 0 ? date('H:i', $summary->last_update) : 'never'),
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
            array('', 'l'),
            array('EPG id', 'l'),
            array('Also known as', 'l'),
            array('Start / End', 'l'),
            array('Guide depth', 'r'),
            array('Size', 'r'),
        );

        $html = '<section class="panel">' . PHP_EOL;
        $html .= '<div class="panel-head">';
        $html .= '<h2>Channels</h2>';
        $html .= '<input id="filter" type="search" placeholder="Filter by id or name&hellip;" autocomplete="off">';
        $html .= '</div>' . PHP_EOL;

        $html .= '<div class="scroll"><table id="sources"><thead><tr>';
        foreach ($columns as $index => $column) {
            $html .= '<th class="' . $column[1] . '" data-col="' . $index . '" tabindex="0">'
                . self::e($column[0]) . '</th>';
        }
        $html .= '</tr></thead><tbody>' . PHP_EOL;

        if (empty($this->channels)) {
            $html .= '<tr><td class="empty" colspan="' . count($columns) . '">This source has no channels.</td></tr>' . PHP_EOL;
        }

        foreach ($this->channels as $channel) {
            $html .= $this->render_row($channel);
        }

        $html .= '</tbody></table></div>' . PHP_EOL;
        $html .= '<p class="hint" id="nomatch" hidden>Nothing matches the filter.</p>' . PHP_EOL;
        $html .= '</section>' . PHP_EOL;

        return $html;
    }

    /**
     * @param array $channel
     * @return string
     */
    private function render_row(array $channel): string
    {
        $id = (string)safe_get_value($channel, 'id', '');
        $names = safe_get_value($channel, 'names', array());
        $picon = (string)safe_get_value($channel, 'picon', '');
        //$file = (string)safe_get_value($channel, 'file', '');
        $file = '';
        $size = (int)safe_get_value($channel, 'size', 0);

        $row = '<tr data-key="' . self::e($id . ' ' . implode(' ', $names)) . '">' . PHP_EOL;

        // lazy, because a large source carries thousands of these, and without a referrer,
        // because the picon hosts have no business knowing where this page is served
        $row .= '<td class="l pic">';
        if ($picon !== '') {
            $row .= '<img class="picon" src="' . self::e($picon) . '" alt="" loading="lazy"'
                . ' referrerpolicy="no-referrer" width="40" height="40">';
        }
        // a channel without a picon leaves the cell empty - the column keeps its width
        $row .= '</td>' . PHP_EOL;

        $row .= '<td class="l">';
        $row .= $file !== ''
            ? '<a class="chan" href="./epg/' . rawurlencode($file) . '">' . self::e($id) . '</a>'
            : '<span class="chan">' . self::e($id) . '</span>';
        $row .= '</td>' . PHP_EOL;

        $row .= '<td class="l names">' . (empty($names) ? '&mdash;' : self::e(implode(', ', $names)))
            . '</td>' . PHP_EOL;

        $first = (int)safe_get_value($channel, 'first_ts', 0);
        $last = (int)safe_get_value($channel, 'last_ts', 0);
        $span = ($first > 0 && $last > $first) ? $last - $first : 0;

        // the period the channel carries, sorted by when it starts
        $row .= '<td class="l range" data-v="' . $first . '">';
        $row .= ($first > 0 && $last > 0)
            ? self::e(date('d.m.Y H:i', $first)) . ' &ndash; ' . self::e(date('d.m.Y H:i', $last))
            : '&mdash;';
        $row .= '</td>' . PHP_EOL;

        // sorted on the raw span, so two channels that both round to the same number of
        // days still order against each other
        $row .= '<td class="r" data-v="' . $span . '">' . self::depth($span) . '</td>' . PHP_EOL;

        $row .= self::cell($size, $size > 0 ? convert_bytes($size) : '&mdash;');

        $row .= '</tr>' . PHP_EOL;

        return $row;
    }

    /**
     * How deep a channel's guide goes, from the span it covers in seconds.
     *
     * @param int $span
     * @return string
     */
    private static function depth(int $span): string
    {
        if ($span <= 0) {
            return '&mdash;';
        }

        $days = (int)floor($span / 86400);

        return $days > 0 ? $days . ' d' : '&lt;1 d';
    }

    /**
     * @return string
     */
    private function render_usage(): string
    {
        $html = '<section class="panel">' . PHP_EOL;
        $html .= '<div class="panel-head"><h2>How to use</h2></div>' . PHP_EOL;
        $html .= '<div class="usage">' . PHP_EOL;
        $html .= '<p>Every channel above links to its own guide. The full list of ids this source '
            . 'knows, with the names they can also be reached by, is in '
            . '<a href="./epg/channels_info.json">channels_info.json</a>.</p>' . PHP_EOL;
        $html .= '</div></section>' . PHP_EOL;

        return $html;
    }
}
