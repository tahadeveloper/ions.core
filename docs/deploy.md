# Deploying an Ions application

How to put a host app (the [skeleton](skeleton.md) layout) behind a real web
server. The contract is small: every request that does not match an existing
file under `public/` must reach the front controller `public/index.php`
(`Kernel::boot()` + `Kernel::run()`), and **only** `public/` may be the web
root — `.env`, `config/`, `app/`, `var/`, `views/` and `vendor/` are siblings
of `public/` and must never be reachable over HTTP.

```
my-app/
├── .env            # secrets — outside the web root
├── app/ config/ routes/ views/ var/ vendor/
└── public/         # ← web root. Nothing else is exposed.
    ├── index.php   # front controller
    ├── .htaccess   # Apache rewrite (shipped by the skeleton)
    ├── uploads/  lang/
    └── build/ or assets/   # only after install:vue / install:assets
```

> **Headers:** the framework already sends the security headers
> (`X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, CSP,
> `Permissions-Policy`, and HSTS on HTTPS requests) from
> `Ions\Security\SecurityHeaders` — do **not** duplicate them in the server
> config, or you will fight the app's per-route overrides
> (`app.security.csp` / `app.security.hsts` / `app.security.permissions_policy`).

> **Host headers:** set `config('app.trusted_hosts')` in production so the
> framework rejects requests with forged `Host` headers (`php bin/ions doctor`
> warns when it is empty) — see [config.md](config.md). Defense-in-depth at the
> server level: only answer for hostnames you actually serve, as the catch-all
> blocks below do.

## nginx

```nginx
# Catch-all: drop requests whose Host matches no server_name (444 closes the
# connection without a response) so host-header probes never reach the app.
server {
    listen 80 default_server;
    return 444;
}

server {
    listen 80;
    server_name example.com;

    root /var/www/my-app/public;
    index index.php;

    # Uploads: nginx rejects bodies over this size before PHP ever runs.
    # Keep it in sync with upload_max_filesize/post_max_size in php.ini.
    client_max_body_size 20m;

    # Front controller: serve existing files, route everything else to PHP.
    # No `$uri/` on purpose — a front-controller app has no directory indexes.
    location / {
        try_files $uri /index.php?$query_string;
    }

    # Never execute PHP under uploads/ — defense-in-depth alongside the
    # framework's upload magic-byte validation. Must sit above the php location.
    location ~ ^/uploads/.*\.php$ {
        return 404;
    }

    location ~ \.php$ {
        # Only index.php exists; anything else 404s via try_files.
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # or 127.0.0.1:9000
        # Use $realpath_root instead of $document_root for symlink-swap deploys
        # (opcache caches by real path, so a swapped symlink serves stale code).
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    # Deny dotfiles (.env copies, .git, …) but keep ACME challenges working.
    location ~ /\.(?!well-known) {
        deny all;
    }

    # Hashed/buster-stamped assets (install:vue builds to public/build/,
    # install:assets to public/assets/) can be cached hard.
    location ~ ^/(build|assets)/ {
        expires 1y;
        add_header Cache-Control "public, immutable";
        access_log off;
    }
}
```

Because `root` points at `public/`, the app root's `.env`, `config/`, `app/`
and `var/` are **outside the document root** — nginx cannot serve them, so no
`deny` rules are needed for them. The dotfile `deny` only guards against files
accidentally copied *into* `public/`.

## Apache

The skeleton ships `public/.htaccess` with the rewrite (existing-file
passthrough → `index.php`, dotfiles denied, `Options -MultiViews -Indexes`,
and the `Authorization`-header passthrough for php-fpm). The vhost
just needs `DocumentRoot` on `public/` and `AllowOverride All` so the
`.htaccess` applies:

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/my-app/public

    <Directory /var/www/my-app/public>
        AllowOverride All
        Require all granted

        # mod_proxy_fcgi strips the Authorization header by default, which
        # breaks Bearer-token API auth. The skeleton .htaccess also ships the
        # rewrite-based workaround, so either one suffices.
        CGIPassAuth On
    </Directory>

    # Never execute PHP under uploads/ — defense-in-depth alongside the
    # framework's upload magic-byte validation. (Under mod_php,
    # `php_admin_flag engine off` or `RemoveHandler .php` in this Directory
    # block works too; the Require rule covers both mod_php and php-fpm.)
    <Directory /var/www/my-app/public/uploads>
        <FilesMatch \.php$>
            Require all denied
        </FilesMatch>
    </Directory>

    # php-fpm via mod_proxy_fcgi (or use mod_php):
    <FilesMatch \.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost"
    </FilesMatch>
</VirtualHost>
```

Prefer `AllowOverride None`? Inline the same rules in the `<Directory>` block
(`RewriteEngine On`, the dotfile deny, the `-f`/`-d` passthrough, the
`index.php` fallback) — see `skeleton/public/.htaccess` for the exact rules.

> The `.htaccess` denies **all** dotfiles, including `.well-known` — if you do
> webroot ACME (Let's Encrypt) on Apache, add an exception above the deny rule:
> `RewriteRule ^\.well-known/ - [L]`.

## PHP-FPM pool

Nothing Ions-specific — a standard pool works. The checklist items that do
matter:

- PHP 8.2+ (8.3 recommended), with `opcache` enabled in production
  (`opcache.validate_timestamps=0` only if your deploy restarts FPM).
- The pool user (`www-data`) must be able to **write** `var/` (cache, logs,
  compiled Twig) and `public/uploads/`; everything else can be read-only.
- Optional: `ions preload:generate` writes `var/cache/preload.php` for
  `opcache.preload` — see [performance.md](performance.md).

## TLS-terminating proxies (read before going behind a load balancer)

When nginx/an LB terminates TLS and forwards plain HTTP to PHP, the framework
only sees the proxy's hop — configure
[`app.trusted_proxies`](config.md#apptrusted_proxies) (4.3+) so it trusts that
proxy's `X-Forwarded-*` headers:

```php
// config/app.php
'trusted_proxies' => ['10.0.0.0/8'],   // your proxy IPs/CIDRs
// or, single load balancer in front of every request:
'trusted_proxies' => ['*'],            // trust the directly connecting peer
```

With it, `X-Forwarded-Proto: https` from the proxy makes
`Request::isSecure()` `true`, so HSTS (`app.security.hsts`) is emitted,
`session.cookie_secure => 'auto'` resolves secure, and `getClientIp()`
returns the real client from `X-Forwarded-For`. The header set is
configurable via
[`app.trusted_proxy_headers`](config.md#apptrusted_proxy_headers)
(`'aws-elb'`, `'traefik'`, RFC 7239 `'forwarded'`, or a bitmask).

**Without** `trusted_proxies` configured, requests behind such a proxy look
like plain HTTP: do **not** use `cookie_secure => 'auto'` there — keep the
4.1 default `true`, and emit HSTS from the proxy that actually speaks TLS.
Only ever list proxies you control: trusting arbitrary peers lets clients
spoof their IP and scheme.

When PHP-FPM sits directly behind the TLS-speaking nginx above (same box,
`fastcgi_param HTTPS on;` set by nginx on the TLS vhost), none of this
applies — the request is seen as secure without any proxy trust.

## Worker mode (FrankenPHP / RoadRunner / Swoole)

Classic php-fpm deployments need nothing from this section. For persistent
worker runtimes the framework boots once and handles many requests via
`Kernel::resetForRequest()` / `Ions\Runtime\WorkerRunner` — experimental in
4.1; read [worker-mode.md](worker-mode.md) for the lifecycle, the state table
and a FrankenPHP example before using it in production.

## Deploy checklist

```bash
composer install --no-dev --optimize-autoloader

# .env on the server: real APP_KEY (64-char hex), APP_DEBUG=false,
# APP_URL=https://example.com, DB_* credentials.

php bin/ions migrate              # run app/Database/Schema migrations
npm ci && npm run build           # only when using install:vue (Vite)

php bin/ions optimize             # route + config + discovery caches
php bin/ions doctor               # sanity-check key, perms, DB, security posture
```

Re-run `php bin/ions optimize` on **every** deploy (stale route/config caches
ship old code paths); `php bin/ions optimize:clear` undoes it. `doctor` exits
non-zero on failures, so it slots into CI/CD pipelines (`--json` for machines).

If the app defines scheduled tasks (`App\Schedule`), add the single cron
entry — see [scheduler.md](scheduler.md):

```cron
* * * * * cd /var/www/my-app && php bin/ions schedule:run >> /dev/null 2>&1
```

Queue workers (`php bin/ions queue:work`) need a supervisor (systemd,
supervisord) to keep them alive — see
[cache-queue-events.md](cache-queue-events.md).
