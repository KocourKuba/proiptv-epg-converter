# Setting up with nginx

The converter serves only static files (JSON guides, HTML report pages), so nginx serves the target directory directly - no PHP is needed. Only the target directory has to be exposed; keep the converter source outside the document root.

## 1. Run the converter

```bash
php run-converter.php -c sources.conf -t /var/www/epg --html
```

Set `server_base_url` in the configuration to the public address of the server:

```json
{
  "server_base_url": "http://epg.example.com",
  "sources": [
    {"id": "edem", "url": "http://epg.my/epg.xml.gz"}
  ]
}
```

## 2. Minimal nginx configuration

```nginx
server {
    listen 80;
    server_name epg.example.com;

    root /var/www/epg;
    index index.html;

    location = / { } # need to allow GET / to internally redirect to /index.html

    # allow only json and html files, deny everything else
    location ~* \.(json|html)$ {
        allow all;
    }

    # serve the site icons copied into the target directory
    location ^~ /favicon/ {
        try_files $uri =404;
    }

    location / {
        deny all;
    }
}
```

Reload nginx:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

## Notes

- Only `.json` and `.html` files are reachable - everything else under the document root is denied, including raw xmltv sources (`<id>/epg.xml.gz` kept when a source has `keep_source: true`) and the per-source `<id>.db` databases.
- Regex locations take priority over the prefix `location /`, so the allow rule wins for `.json` and `.html` files anywhere in the tree.
- The exact match `location = /` lets nginx serve the index at the server root - without it `deny all` in `location /` fires before the internal redirect to `index.html`, so the root returns 403.
- The site icons in the `favicon` folder are served explicitly - without the rule they would be denied as they are not `.json` or `.html`.
- Guides remain available at `http://epg.example.com/<id>/epg/<EPG_ID>.json`.
