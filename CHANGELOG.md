### Version 1.6.1
- A source with an empty id or url is counted as failed instead of skipped.
- A source id that could make the paths built from it leave the working directory is rejected.
- `--json-links` and the link from a source page back to the information page now work with the proc_open worker backend (Windows) as well.
- A ZIP archive whose entries could write outside the source directory is rejected.
- A source whose downloaded file cannot be opened is reported as failed instead of aborting the whole run.

### Version 1.6
- New option `-m, --parallel=[N]`: process sources with up to N worker processes in parallel (default: 1 = sequential). On Linux the workers are forked processes (pcntl), everywhere else separate PHP processes are started, so no extra extension is required.
- Each worker writes its own temporary log, which is merged into the main log as one contiguous block when the source finishes, so the messages of one source never interleave with those of another. After a successful conversion the temporary log is removed; a failed source keeps it and the main log points at it.

### Version 1.5
- Refactored to PSR-4 autoloading (namespace `Proiptv\EpgConverter`, classes in `src/`). No behavior change. `composer install` is optional - without it a built-in autoloader is used.

### Version 1.4.2
- SSL certificate verification enabled by default. New source option `verify_ssl` (default: true) to disable for self-signed certificates.

### Version 1.4.1
- The "Channels (EPG)" column of the information page shows every channel found in the source and, in brackets, the channels that have programmes, e.g. `2100 (2000)`
- The information pages have site icons (favicon, Apple touch icon, web manifest). They are copied into the `favicon` folder of the target directory together with the pages.

### Version 1.4
- Each source gets its own page (`<target>/<id>/index.html`), opened by clicking the source name on the information page. It lists every channel of the source with its picon, EPG id, the display names it can also be found by, the period its guide covers, how deep that guide goes, and the size of its JSON file.
- New option `-j, --json-links`: each channel id on the source page links to its JSON file. Off by default, so a page served publicly does not advertise the files behind it.
- Every run writes `epg_presets.json`, a ready ProIPTV `epg_presets` block with every source that serves EPG. The information page links to it for download.
- New `server_base_url` configuration parameter sets the server address written to `epg_presets.json` and shown on the information page. The configuration file can now be an object with `server_base_url` and `sources`; a plain list of sources is still accepted.
- Sources whose link has no file extension and silently redirects to the real file are processed correctly. The file is saved under the name the server gives it (Content-Disposition or the last url of the redirect chain), and the ETag is taken from the final response instead of a redirect.
- Channels are also found when the channel id is not the first attribute of the tag. Such a source was indexed without any channels at all.
- The version number is kept in the `VERSION` file

### Version 1.3
- New option `-w, --html[=file]`: an HTML information page is written when the run is finished. It lists every source from the configuration with its status (converted / up to date / failed / not run), the number of channels, picons and programmes, the period the guide covers, the number and size of the JSON files and the processing time.
- Significantly speeded up indexing of XMLTV sources and JSON generation. Memory use no longer grows with the size of the source.
- PHP 8.0 or higher is required
- Large sources are no longer cut off by a fixed download timeout. A stalled transfer is detected by a low-speed limit instead.
- A User-Agent is sent with the download, so sources behind a bot filter return the guide instead of an HTML challenge page. When a download fails, the log shows the start of what the server actually sent.
- XMLTV files starting with a UTF-8 BOM or without an XML declaration are accepted
- Channels whose id contains an XML entity (e.g. `A&amp;E`) are no longer left without EPG
- On Windows, the JSON files of channels whose id contains characters like `\` or `:` were deleted as stalled at every run
- Every source writes `channels_info.json` with the list of its EPG ids and channel aliases
- The generated JSON stores the programme category as `main_category` and the date as `year`, and includes the programme images as `icons`
- New option `-f, --force`: process sources even if they are up to date
- New option `-p, --purge`: force purge of stalled files
- The log level option is renamed from `-d, --debug` to `-s, --severity`
- Fixed the `--target` option, which ignored its value
- A failed download is counted as failed
- Directories are created with 0775 permissions
- XML parsing errors are logged with the line and position of the error
- Speeded up logging

### Version 1.2
- Command line arguments: `-c, --config`, `-r, --run` (process only the listed sources), `-t, --target`, `-l, --log`, `-d, --debug`. The script settings are removed from the configuration file, which is now only the list of sources.
- New source parameter `purge_stalled`: the number of days after which JSON files missing from the current source are deleted (7 by default)
- Statistics of indexed, skipped and failed sources, downloaded size and total time are written to the log at the end of the run

### Version 1.1
- ZIP packed XMLTV sources are supported
- The configuration file is passed as an argument and sets `log_level`, `log_path` and the list of `sources`
- The state of each source (ETag, last check) is kept in its database
- `manual_check` sources ignore the ETag header
- PHP 7.4 or higher is supported

### Version 1.0
- First release: XMLTV → JSON converter that creates a JSON file with the EPG of every channel of a source
- Download with redirects (301/302) and ETag support, plain and gz packed XMLTV files
- Source parameters `keep_source` (keep the downloaded file) and `manual_check` (download interval in hours for servers without ETag)
