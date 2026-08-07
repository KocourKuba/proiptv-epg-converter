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

require_once 'Converter.php';

ini_set('memory_limit', '256M');

$shortopts = "c:r:l:t:d:";
$longopts = array('config:', 'run:', 'target', 'log:', 'debig:');
$options = getopt($shortopts, $longopts);
$to_process = [];
$log_path = '';

$converter_config = [];

foreach ($options as $opt => $value) {
    switch ($opt) {
        case 'c':
        case 'config':
            $converter_config[Converter::CONFIG] = $value;
            break;

        case 'r':
        case 'run':
            $converter_config[Converter::RUN] = explode(' ', $value);
            break;

        case 'l':
        case 'log':
            $converter_config[Converter::LOGFILE] = $value;
            break;

        case 't':
        case 'target':
            $converter_config[Converter::WORKDIR] = $value;
            break;

        case 'd':
        case 'debug':
            $converter_config[Converter::SEVERITY] = $value;
            break;
    }
}

if (empty($converter_config['config_file'])) {
    $script_name = basename(__FILE__);
    echo "Usage: $script_name [OPTION]..." . PHP_EOL;
    echo "Mandatory option:" . PHP_EOL;
    echo "  -c, --config=[file],       Filepath to configuration file" . PHP_EOL;
    echo "  -r, --run=[id],            Id to be processed," . PHP_EOL;
    echo "  -r, --run=\"id1 id2 id3\",   List of id's to be processed," . PHP_EOL;
    echo "                             If omitted all sources from configuration file will processed." . PHP_EOL;
    echo "  -t, --target=[dir],        Directory were epg files for sources  will be stored." . PHP_EOL;
    echo "                             If omitted log created in the same directory as configuration file." . PHP_EOL;
    echo "  -l, --log=[log path],      Path to log file. If omitted log created in the same directory as configuration file" . PHP_EOL;
    echo "  -d, --debug=[level],       Log level [error, warning, info, notice, debug]. Default is 'info'" . PHP_EOL;
    echo PHP_EOL;
    echo "Examples: " . PHP_EOL;
    echo "# process all sources from configuration file" . PHP_EOL;
    echo "./$script_name -c /var/www/epg/sources.conf" . PHP_EOL;
    echo PHP_EOL;
    echo "# change log level to 'debug'" . PHP_EOL;
    echo "./$script_name --config=/var/www/epg/sources.conf -d debug" . PHP_EOL;
    echo PHP_EOL;
    echo "# all epg files will be stored in /var/www/epg" . PHP_EOL;
    echo "./$script_name -c sources.conf -t /var/www/epg" . PHP_EOL;
    echo PHP_EOL;
    echo "# process only follow sources with id: it999 and iptvx" . PHP_EOL;
    echo "./$script_name -c /var/www/epg/sources.conf --run=\"it999 iptvx\"" . PHP_EOL;
    echo PHP_EOL;
    echo "#     store processing log to /var/log/epg" . PHP_EOL;
    echo "./$script_name --config=/var/www/epg/sources.conf --log=/var/log/epg.log" . PHP_EOL;
    echo PHP_EOL;
    return;
}

$converter = new Converter();
$converter->process($converter_config);
