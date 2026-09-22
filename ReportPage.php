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
 * Shared chrome of every generated page: the document around the content, the assets
 * that style and drive it, and the formatting helpers the content is built with.
 *
 * Both pages carry their style and script inline, so each one works served on its own
 * with nothing else installed beside it.
 */
abstract class ReportPage
{
    /** Stylesheet of the pages, read from the directory this class lives in. */
    const STYLESHEET = 'epg_report.css';
    /** Script of the pages, read from the directory this class lives in. */
    const SCRIPT = 'epg_report.js';
    /** Home of the converter, linked from the page title. */
    const PROJECT_URL = 'https://github.com/KocourKuba/proiptv-epg-converter';

    /** @var string */
    protected string $version;
    /** @var string */
    protected string $title;

    /**
     * @param string $version
     * @param string $title
     */
    public function __construct(string $version, string $title)
    {
        $this->version = $version;
        $this->title = $title;
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
        $kind = $this->kind();

        $dir = pathinfo($path, PATHINFO_DIRNAME);
        if (!create_path($dir)) {
            Logger::log(Logger::Err, "Can't create directory for $kind: $dir");
            return false;
        }

        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $this->render()) === false) {
            Logger::log(Logger::Err, "Can't write $kind: $tmp");
            return false;
        }

        if (file_exists($path) && !unlink($path)) {
            unlink($tmp);
            Logger::log(Logger::Err, "Can't replace $kind: $path");
            return false;
        }

        if (!rename($tmp, $path)) {
            unlink($tmp);
            Logger::log(Logger::Err, "Can't rename $kind to: $path");
            return false;
        }

        Logger::log($this->log_level(), ucfirst($kind) . " saved to: $path");
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
        $crumb = $this->crumb();
        if ($crumb !== '') {
            $html .= $crumb . PHP_EOL;
        }
        $href = $this->title_href();
        $html .= '<h1>';
        $html .= $href === ''
            ? self::e($this->title)
            : '<a href="' . self::e($href) . '" target="_blank" rel="noopener noreferrer">'
                . self::e($this->title) . '</a>';
        $html .= '</h1>' . PHP_EOL;
        $html .= '<p class="sub">' . $this->subtitle() . '</p>' . PHP_EOL;
        $html .= '</div></header>' . PHP_EOL;

        $html .= '<main class="wrap">' . PHP_EOL;
        $html .= $this->body();
        $html .= '</main>' . PHP_EOL;

        $html .= '<footer class="wrap foot">' . PHP_EOL;
        $html .= '<p>' . $this->footer_text() . '</p>' . PHP_EOL;
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
    // what each page fills in

    /**
     * Contents of <main>, already escaped.
     *
     * @return string
     */
    abstract protected function body(): string;

    /**
     * The line under the heading, already escaped.
     *
     * @return string
     */
    abstract protected function subtitle(): string;

    /**
     * What this page is called in the log.
     *
     * @return string
     */
    abstract protected function kind(): string;

    /**
     * Where the heading links, or '' for a heading that is only text.
     *
     * @return string
     */
    protected function title_href(): string
    {
        return self::PROJECT_URL;
    }

    /**
     * Navigation shown above the heading, already escaped, or '' for none.
     *
     * @return string
     */
    protected function crumb(): string
    {
        return '';
    }

    /**
     * @return string
     */
    protected function footer_text(): string
    {
        return 'Guides are served as plain JSON files.';
    }

    /**
     * @return int
     */
    protected function log_level(): int
    {
        return Logger::Perm;
    }

    /**
     * The generator credit, shared by both subtitles.
     *
     * @return string
     */
    protected function generated_by(): string
    {
        return 'generated ' . self::e(date('Y-m-d H:i:s'))
            . ' by ProIPTV EPG Converter v' . self::e($this->version);
    }

    ////////////////////////////////////////////////////////////
    // formatting helpers

    /**
     * A numeric cell carries its raw value in data-v, so the column sorts as a number
     * and not as the formatted text.
     *
     * @param int $value
     * @param string $text
     * @return string
     */
    protected static function cell(int $value, string $text): string
    {
        return '<td class="r" data-v="' . $value . '">' . self::e($text) . '</td>' . PHP_EOL;
    }

    /**
     * @param int $value
     * @return string
     */
    protected static function num(int $value): string
    {
        return number_format($value, 0, '.', ' ');
    }

    /**
     * @param float $seconds
     * @return string
     */
    protected static function duration(float $seconds): string
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
    protected static function e(string $value): string
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
    protected static function css(): string
    {
        return self::asset(self::STYLESHEET, 'Stylesheet', 'will be unstyled');
    }

    /**
     * The page script, read the same way as the stylesheet.
     *
     * @return string
     */
    protected static function js(): string
    {
        return self::asset(self::SCRIPT, 'Script', 'will not sort or filter');
    }

    /**
     * Read an asset that sits next to this class. Each one is read once per run, however
     * many pages are generated.
     *
     * @param string $name
     * @param string $what
     * @param string $consequence
     * @return string
     */
    private static function asset(string $name, string $what, string $consequence): string
    {
        static $cache = array();
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $path = __DIR__ . DIRECTORY_SEPARATOR . $name;
        if (!is_readable($path)) {
            Logger::log(Logger::Wrn, "$what not found: $path. Pages $consequence.");
            return $cache[$name] = '';
        }

        $content = file_get_contents($path);
        if ($content === false) {
            Logger::log(Logger::Wrn, "Can't read $what: $path. Pages $consequence.");
            return $cache[$name] = '';
        }

        return $cache[$name] = trim($content);
    }
}
