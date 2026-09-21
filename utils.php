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

function create_path($path): bool
{
    if (!is_dir($path)) {
        $oldumask = umask(0);
        mkdir($path, 0775, true);
        umask($oldumask);
    }

    return is_dir($path);
}

/**
 * @param string $path
 * @return string
 */
function paved_path(string $path): string
{
    return str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
}

/**
 * Check if url has http?:// scheme
 *
 * @param string $url
 * @return bool
 */
function is_proto_http(string $url): bool
{
    return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
}

/**
 * Safe get value from array or object by key or the keys chain
 *
 * @param object|array $src
 * @param array|string $param
 * @param mixed $default
 * @return mixed
 */
function safe_get_value(object|array $src, array|string $param, mixed $default = null): mixed
{
    // No key to resolve. Null key or empty string is not allowed
    if (empty($param)) {
        return $default;
    }

    // Base case: single key
    if (!is_array($param)) {
        if (is_array($src)) {
            return $src[$param] ?? $default;
        }

        if (is_object($src)) {
            return $src->{$param} ?? $default;
        }

        return $default;
    }

    // Recursive case: key path

    $key = array_shift($param);

    if (is_array($src)) {
        if (!isset($src[$key])) {
            return $default;
        }
        return count($param) ? safe_get_value($src[$key], $param, $default) : $src[$key];
    }

    if (is_object($src)) {
        if (!isset($src->{$key})) {
            return $default;
        }
        return count($param) ? safe_get_value($src->{$key}, $param, $default) : $src->{$key};
    }

    return $default;
}

/**
 * @param int $bytes
 * @return string
 */
function convert_bytes(int $bytes): string
{
    $si_prefix = array('B', 'KB', 'MB', 'GB', 'TB');
    $base = 1024;
    $class = min((int)log($bytes, $base), count($si_prefix) - 1);
    return sprintf('%1.2f', $bytes / pow($base, $class)) . ' ' . $si_prefix[$class];
}

/**
 * @param string $archive
 * @return string|null
 */
function extractGzipFile(string $archive): ?string
{
    // Check if zlib is enabled
    if (!function_exists('gzopen')) {
        Logger::log(Logger::Err, 'Your PHP has no zlib support enabled.');
        return null;
    }

    $destination = pathinfo($archive, PATHINFO_DIRNAME);
    $outfile = $destination . '/' . pathinfo($archive, PATHINFO_FILENAME);
    $gzipped = gzopen($archive, "rb");
    $file = fopen($outfile, "w");

    while ($string = gzread($gzipped, 4096*1000)) {
        fwrite($file, $string, strlen($string));
    }
    gzclose($gzipped);
    fclose($file);

    // Check if file was extracted.
    if (file_exists($outfile)) {
        Logger::log(Logger::Inf, 'File ungzipped successfully.');
        return $outfile;
    }

    Logger::log(Logger::Err, 'Error ungzipping file.');
    return null;
}

/**
 * Decompress/extract a zip archive
 *
 * @param string $archive
 * @return string|null
 */
function extractZipArchive(string $archive): ?string
{
    if (!class_exists('ZipArchive')) {
        Logger::log(Logger::Err, 'Your PHP version does not support unzip functionality.');
        return null;
    }

    $unzip = new ZipArchive;
    // Check if archive is readable.
    if ($unzip->open($archive) !== true) {
        Logger::log(Logger::Err, 'Cannot read .zip archive: ' . $archive);
        return null;
    }

    // Check if zip is empty
    $first_file = $unzip->getNameIndex(0);
    if (empty($first_file)) {
        $unzip->close();
        Logger::log(Logger::Err, 'Empty zip archive.');
        return null;
    }

    $destination = pathinfo($archive, PATHINFO_DIRNAME);
    if (!$unzip->extractTo($destination)) {
        $unzip->close();
        Logger::log(Logger::Err, sprintf('Error unzipping file: %s, status: %s', basename($archive), $unzip->getStatusString()));
        return null;
    }

    $unzip->close();
    return $destination . '/' . $first_file;
}

/**
 * Map a channel id onto the name its JSON file is stored under.
 *
 * Both the writer and the purger must agree on this, otherwise files that are perfectly
 * current look unknown to the purge pass and get deleted.
 *
 * @param string $channel_id
 * @return string
 */
function escape_channel_filename(string $channel_id): string
{
    static $is_win = null;
    if ($is_win === null) {
        $is_win = (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN');
    }

    if ($is_win) {
        return str_replace(
            array('/', '\\', '"', '?', '*', '<', '>', '|', ':'),
            array('%2F', '%5C', '%22', '%3F', '%2A', '%3C', '%3E', '%7C', '%3A'),
            $channel_id);
    }

    return str_replace('/', '%2F', $channel_id);
}

/**
 * Collect every field of a <programme> element in a single pass over its children.
 *
 * Walking the child list once is what makes this cheap. Reaching for
 * getElementsByTagName() per field re-scans the subtree each time, and iterating the
 * returned live DOMNodeList is quadratic in the number of nodes, which dominates the
 * run time on channels that carry a lot of TV programs.
 *
 * @param DOMElement $tag
 * @return array
 */
function parse_programme_node(DOMElement $tag): array
{
    static $roles = array(
        'director' => true, 'producer' => true, 'actor' => true, 'presenter' => true,
        'writer' => true, 'editor' => true, 'composer' => true,
    );

    $title = '';
    $desc = '';
    $icon = '';
    $sub_title = '';
    $category = '';
    $date = '';
    $country = '';
    $images = [];
    $credits = [];

    for ($node = $tag->firstChild; $node !== null; $node = $node->nextSibling) {
        if ($node->nodeType !== XML_ELEMENT_NODE) continue;

        // first non-empty value wins, matching get_node_value()
        switch ($node->nodeName) {
            case 'title':
                if ($title === '') $title = $node->nodeValue;
                break;
            case 'desc':
                if ($desc === '') $desc = $node->nodeValue;
                break;
            case 'sub-title':
                if ($sub_title === '') $sub_title = $node->nodeValue;
                break;
            case 'category':
                if ($category === '') $category = $node->nodeValue;
                break;
            case 'date':
                if ($date === '') $date = $node->nodeValue;
                break;
            case 'country':
                if ($country === '') $country = $node->nodeValue;
                break;
            case 'icon':
                if ($icon === '') $icon = $node->getAttribute('src');
                break;
            case 'image':
                $value = $node->nodeValue;
                if ($value !== '') $images[] = $value;
                break;
            case 'credits':
                $found = [];
                for ($credit = $node->firstChild; $credit !== null; $credit = $credit->nextSibling) {
                    if ($credit->nodeType !== XML_ELEMENT_NODE) continue;
                    $role = $credit->nodeName;
                    if (!isset($roles[$role])) continue;
                    $value = $credit->nodeValue;
                    if ($value !== '') $found[$role][] = $value;
                }
                // a later <credits> overrides an earlier one per role, as before
                foreach ($found as $role => $values) {
                    $credits[$role] = implode(', ', $values);
                }
                break;
        }
    }

    // key order here defines the key order in the generated JSON
    $item = array(
        'name' => $title,
        'time' => strtotime($tag->getAttribute('start')),
        'time_to' => strtotime($tag->getAttribute('stop')),
        'descr' => $desc,
    );

    if ($icon !== '' && is_proto_http($icon)) $item['icon'] = $icon;
    if ($sub_title !== '') $item['sub-title'] = $sub_title;
    if ($category !== '') $item['main_category'] = $category;
    if ($date !== '') $item['year'] = $date;
    if ($country !== '') $item['country'] = $country;
    if (!empty($images)) $item['icons'] = $images;

    foreach ($credits as $role => $value) {
        $item[$role] = $value;
    }

    return $item;
}

/**
 * @param LibXMLError $error
 * @param string $xml_str
 * @return string
 */
function display_xml_error(LibXMLError $error, string $xml_str): string
{
    $xml = explode("\n", $xml_str);
    $return  = "\n" . $xml[$error->line - 1] . "\n";
    $return .= str_repeat('-', $error->column) . "^\n";

    switch ($error->level) {
        case LIBXML_ERR_WARNING:
            $return .= "Warning $error->code: ";
            break;
        case LIBXML_ERR_ERROR:
            $return .= "Error $error->code: ";
            break;
        case LIBXML_ERR_FATAL:
            $return .= "Fatal Error $error->code: ";
            break;
    }

    $return .= trim($error->message) .
        "\n  Line:   $error->line" .
        "\n  Column: $error->column";

    return "$return\n--------------------------------------------";
}


