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
require_once 'Converter.php';

/**
 * @return array|false
 */
ini_set('memory_limit', '256M');

Logger::setLogName('converter.log');
//Logger::setSeverity(severity::Dbg);

if (!file_exists('sources.json')) {
    Logger::log(severity::Err, 'sources.json file not found');
    return false;
}

$config = json_decode(file_get_contents('sources.json'), true);

if ($config === false) {
    Logger::log(severity::Err, 'Bad sources.json configuration');
    return die();
}

if (!isset($config['sources'])) {
    Logger::log(severity::Err, 'No source defined in config.json');
    return die();
}

if (file_exists('settings.json')) {
    $settings = json_decode(file_get_contents('settings.json'), true);
} else {
    $settings = array();
}

$working_dir = $config['dir'] ?? getcwd();
if (str_ends_with($working_dir, '/')) {
    $working_dir  = trim($working_dir, '/');
}

foreach ($config['sources'] as $item) {
    $converter = new Converter($item, $working_dir);
    $converter->convert($settings);
}

if (!empty($settings)) {
    file_put_contents('settings.json', json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
