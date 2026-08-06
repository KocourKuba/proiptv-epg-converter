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
 * @param string $archive
 * @return string|null
 */
function extractGzipFile(string $archive): ?string
{
    // Check if zlib is enabled
    if (!function_exists('gzopen')) {
        Logger::log(severity::Err, 'Your PHP has no zlib support enabled.');
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
        Logger::log(severity::Inf, 'File ungzipped successfully.');
        return $outfile;
    }

    Logger::log(severity::Err, 'Error ungzipping file.');
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
        Logger::log(severity::Err, 'Your PHP version does not support unzip functionality.');
        return null;
    }

    $unzip = new ZipArchive;
    // Check if archive is readable.
    if ($unzip->open($archive) !== true) {
        Logger::log(severity::Err, 'Cannot read .zip archive: ' . $archive);
        return null;
    }

    // Check if zip is empty
    $first_file = $unzip->getNameIndex(0);
    if (empty($first_file)) {
        $unzip->close();
        Logger::log(severity::Err, 'Empty zip archive.');
        return null;
    }

    $destination = pathinfo($archive, PATHINFO_DIRNAME);
    if (!$unzip->extractTo($destination)) {
        $unzip->close();
        Logger::log(severity::Err, sprintf('Error unzipping file: %s, status: %s', basename($archive), $unzip->getStatusString()));
        return null;
    }

    $unzip->close();
    return $destination . '/' . $first_file;
}

/**
 * @param DOMElement $node
 * @param string $name
 * @return string
 */
function get_node_value(DOMElement $node, string $name): string
{
    $value = '';
    foreach ($node->getElementsByTagName($name) as $element) {
        if (!empty($element->nodeValue)) {
            $value = $element->nodeValue;
            break;
        }
    }

    return $value;
}

/**
 * @param DOMElement $node
 * @param string $name
 * @return array
 */
function get_node_values(DOMElement $node, string $name): array
{
    $values = array();
    foreach ($node->getElementsByTagName($name) as $element) {
        if (!empty($element->nodeValue)) {
            $values[] = $element->nodeValue;
        }
    }

    return $values;
}

/**
 * @param DOMElement $node
 * @param string $name
 * @param string $attribute
 * @return string
 */
function get_node_attribute(DOMElement $node, string $name, string $attribute): string
{
    $value = '';
    foreach ($node->getElementsByTagName($name) as $element) {
        $value = $element->getAttribute($attribute);
        break;
    }

    return $value;
}

