# ProIPTV XMLTV EPG converter
## Description
A very fast XMLTV → JSON converter for creating an EPG program source for the [ProIPTV](https://github.com/KocourKuba/proiptv) plugin and [IPTV Channel Editor](https://github.com/KocourKuba/IptvChannelEditor).  
Compatible with the format used by drm-play/ott-play.

Creates JSON files containing a EPG TV program only for the specified EPG ID, which allows you to avoid downloading large XMLTV files and their subsequent processing on the client device. Can be easily configured on any web server or NAS.
Does not require large amounts of RAM and fast CPU to execute the script.

Unlike the ott-play/drm-play format, it supports most XMLTV tags. Uses its own algorithm, optimized for speed and memory consumption, to parse XMLTV, which is successfully used on Dune HD set-top boxes in the ProIPTV plugin.

Downloading files with support for redirects (301/302) and the [Etag] header mechanism (https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/ETag). Both regular and gz/zip packed XMLTV files are supported.

### Requirements
PHP CLI 8.0 or higher. PHP must be built with XML, zlib and sqlite3 support.

### Command line arguments

```
    -c, --config=[file],       Path to configuration file.
    -r, --run=[id],            Id to be processed.
    -r, --run="id1 id2 id3",   List of id's to be processed.
                               If omitted all sources from configuration file will processed.
    -t, --target=[dir],        Directory were epg files for sources  will be stored.
                               If omitted log created in the same directory as configuration file.
    -p, --purge,               Force purge stalled files.
    -f, --force,               Force processing."
    -l, --log=[log path],      Path to log file. If omitted log created in the same directory as configuration file."
    -s, --severity=[level],    Log level [error, warning, info, notice, debug]. Default is 'info'.
    -w, --html[=file],         Generate an html information page when the conversion is finished.
                               If the file is omitted, the page is saved as 'index.html' in the target directory.
    -j, --json-links,          Link each channel on the source detail page to its json file.
                               If omitted, the ids are shown as plain text.
```

<details>
<summary><b>Output results</b></summary>
For example, our source is edem, and the EPG channel ID is '146'. The link to the TV program for the channel will look like this: http://your.server/edem/epg/146.json
</details>

<details>
<summary><b>Information page</b></summary>
With `-w` / `--html` a standalone HTML page is written when the run is finished. It has no external dependencies, so it can be served straight from the target directory.

The page lists every processed source with its status (converted / up to date / failed), the number of channels and picons, the number of TV programs, the range the guide covers, the number and size of the generated json files, and the time the source took. Sources that were skipped as up-to-date report what they currently serve, taken from their database.

Every source in the configuration gets a row, including the ones a `--run` left out - those are marked `not run` and still report what they currently serve. The numbers for each source come from its `<id>.db` and the files on disk, not from the run itself.

Each source also gets its own page at `<target>/<id>/index.html`, reached by clicking the source name on the index. It lists every channel the source serves with its picon, its EPG id, the display names it can also be reached by, the period its guide covers, how deep that guide goes, and the size of its JSON file. A channel whose guide is shorter than a day shows `<1 d`.

With `-j` / `--json-links` each id on that page links to the guide it is served from, and the page points at `channels_info.json` as a link too. Without it - the default - the ids are plain text and the file names are only named, so a page served publicly does not advertise the files behind it. The guides themselves stay reachable by their url either way; the option only decides whether the page links to them.

A detail page is only written when it is missing or expired. A page counts as expired when the source was converted again after the page was written, when the page is older than `Converter::DETAIL_TTL` (7 days by default), or when it was built with the other `--json-links` setting. A source skipped as up to date, or left out by `--run`, keeps the page it already has.
</details>

<details>
<summary><b>Configuration file and sample</b>></summary>
Sample can be found here: `samples/sample_sources.conf`

File format - JSON
`Etag` header support is enabled by default, if the server supports `Etag`, the download will only occur if the file has changed. In manual mode, header is ignored, but is updated after receiving the response headers.

`id` - mandatory. Unique identifier of the source. Will be used to create a directory.  
`url` - mandatory. Link to xmltv  
`keep_source` - optional. If set to true, it saves the original downloaded file.  
`manual_check` - optional. Sets the time for the next file download in hours. Necessary if the server does not support Etag, and the check is done frequently.  
`purge_stalled` - optional. Sets the number of days after which files missing from the current source will be deleted. Default is 7 days.
</details>

<details>
<summary><b>Benchmarks</b></summary>>
The script was tested on VDS with installed Ubuntu 24.04 (4 vCPU / 8 GB RAM).  
Processing xmltv from edem (48Mb packed gz, size of unpacked xmltv 373Mb) - 16 seconds.  
Processing xmltv from gabbarit (228Mb packed gz, size of unpacked xmltv 1.6Gb) - 51 seconds.
</details>

## Описание
Очень быстрый конвертер XMLTV → JSON для создания интернет источника телепрограммы для плагина [ProIPTV](https://github.com/KocourKuba/proiptv) и редактора [IPTV Channel Editor](https://github.com/KocourKuba/IptvChannelEditor).  
Совместим с форматом используемым ott-play/drm-play.

Создает Json файлы содержащие телепрограмму только для указанного EPG ID, что позволяет избегать загрузки больших XMLTV файлов и последующей их обработки на клиентском устройстве. Может быть легко настроен на любом web-сервере или NAS. Не требует больших объемов оперативной памяти или мощного процессора для исполнения скрипта.

В отличие от формата ott-play/drm-play поддерживает большинство тэгов XMLTV. Использует собственный алгоритм, оптимизированный по скорости и потреблению памяти, для разбора XMLTV, который с успехом используется на приставках Dune HD в плагине ProIPTV.

Скачивание файлов с поддержкой редиректов (301/302) и механизма заголовка [Etag](https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/ETag). Поддерживаются как обычные, так и упакованные gz/zip XMLTV файлы.

### Требования
PHP CLI 8.0 или выше. PHP должен быть собран с поддержкой XML, zlib и sqlite3.

### Аргументы командной строки

```
    -c, --config=[file],       Путь к конфигурационному файлу. Обязательный параметр!
    -r, --run=[id],            Id источника для обработки.
    -r, --run="id1 id2 id3",   Возможно задать несколько источников для обработки.
                               Если параметр не задан, то обрабатываются все источники из конфигурационного файла.
    -t, --target=[dir],        Корневой каталог куда будут сохраняться обработанные источники (файлы в json формате).
                               Если не задан, то будет выбран каталог в котором находится конфигурационный файл.
    -p, --purge,               Принудительная очистка неизвестных файлов.
    -f, --force,               Принудительная обработка.
    -l, --log=[log path],      Путь и имя файла лога. Если не задан, то будет сохранятся в каталог в котором находится конфигурационный файл.
    -s, --severity=[level],    Уровень лога [error, warning, info, notice, debug]. По умолчанию - 'info'.
    -w, --html[=file],         Создать html страницу с информацией о результатах конвертации.
                               Если файл не задан, страница сохраняется как 'index.html' в корневом каталоге.
    -j, --json-links,          Делать id каждого канала на странице источника ссылкой на его json файл.
                               Если не задан, id показываются обычным текстом.
```
<details>
<summary><b>Результаты обработки</b></summary>
Например, у нас источник edem, а EPG ID канала '146'. Ссылка на ТВ программу для канала будет выглядеть так:  http://your.server/edem/epg/146.json
</details>

<details>
<summary><b>Информационная страница</b></summary>
С параметром `-w` / `--html` по окончании работы создается html страница. Она не требует внешних библиотек, поэтому ее можно отдавать прямо из корневого каталога.

На странице перечислены все обработанные источники с их статусом (converted / up to date / failed), количеством каналов и пиконов, количеством передач, периодом который покрывает телепрограмма, количеством и размером созданных json файлов и временем обработки. Источники пропущенные как актуальные показывают то, что они отдают сейчас - данные берутся из их базы.

На странице присутствуют все источники из конфигурационного файла, в том числе не попавшие в текущий запуск из-за `--run` - они помечены как `not run` и показывают то, что отдают сейчас. Все цифры по источнику читаются из его `<id>.db` и файлов на диске, а не из результатов запуска.

Для каждого источника также создается своя страница `<target>/<id>/index.html`, переход на нее - по имени источника на главной странице. На ней перечислены все каналы источника: пикон, EPG id, псевдонимы, период, который покрывает его телепрограмма, ее глубина и размер его json файла. Канал с программой меньше суток показывается как `<1 d`.

С параметром `-j` / `--json-links` каждый id на этой странице ведет на телепрограмму этого канала, а `channels_info.json` тоже становится ссылкой. Без него (поведение по умолчанию) id показываются обычным текстом, а имена файлов только упоминаются - чтобы страница, отдаваемая наружу, не публиковала ссылки на файлы. Сами телепрограммы в любом случае доступны по своим адресам, параметр влияет только на ссылки на странице.

Страница источника перезаписывается только если она отсутствует или устарела. Страница считается устаревшей если источник был сконвертирован после ее создания, если она старше `Converter::DETAIL_TTL` (по умолчанию 7 дней), либо если она была создана с другим значением `--json-links`. Источник пропущенный как актуальный или не попавший в `--run` сохраняет уже созданную страницу.
</details>

<details>
<summary><b>Описание конфига и примеры</b></summary>
Пример файла можно найти здесь: `samples/sample_sources.conf`

Формат файла - json

Поддержка `Etag` включена по умолчанию, если сервер поддерживает `Etag`, то скачивание будет произведено только если файл изменился. В ручном режиме заголовок не посылается, но обновляется после получения заголовков

`id` - обязательный. Уникальный идентификатор источника. Будет использован для создания каталога.  
`url` - обязательный. Ссылка на xmltv.
`keep_source` - необязательный. Если задан в true, то сохраняет оригинальный скачанный файл.  
`manual_check` - необязательный. Задает время следующего скачивания файла в часах. Необходим если сервер не поддерживает Etag, а проверка делается часто.  
`purge_stalled` - необязательный. Задает количество дней после которых файлы отстутсвующие в текущем источнике будут удалены. По умолчанию 7 дней.
</details>

<details>
<summary><b>Бенчмарки</b></summary>
Скрипт проверялся на VDS c Ubuntu 24.04 (4 vCPU / 8 ГБ ОЗУ).  
Обработка xmltv от edem (48Mb упакованный gz, размер распакованного xmltv 373Mb) - 16 секунд.  
Обработка xmltv от gabbarit (228Mb упакованный gz, размер распакованного xmltv 1.6Gb) - 51 секунду.  
</details>
