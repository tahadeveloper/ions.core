# Frontend assets

Two scaffolds and two Twig functions (4.2). Pick the flow that fits the app:

- **`install:vue`** — a Vue 3 + Vite frontend: dev server with HMR, hashed
  production builds in `public/build/`, wired to templates via `vite()`.
- **`install:assets`** — the no-build variant: plain CSS/JS starters written
  straight into `public/assets/`, linked via `asset()` with automatic
  cache-busting. No node, no bundler.

Both functions ship in `Ions\View\AssetExtension`, registered on the shared
Twig environment by `ViewFactory` — available in every template with zero
configuration. **The PHP side never requires node**: a missing build degrades
to an HTML comment, never a 500.

## `install:vue`

```bash
php bin/ions install:vue        # --force to overwrite existing files
npm install
```

Writes into the host root (refuse-all-or-nothing: if ANY target already
exists the command fails listing the conflicts and writes **nothing**;
`--force` overwrites):

| File | Contents |
| ---- | -------- |
| `package.json` | `name` from the `app.name` slug, `private: true`, scripts `dev`/`build`, devDependencies `vue ^3.5` / `vite ^6` / `@vitejs/plugin-vue ^5` |
| `vite.config.js` | plugin-vue; builds to `public/build/` with `manifest: 'manifest.json'` (a plain string keeps the manifest at `public/build/manifest.json` — Vite ≥5 would bury `manifest: true` in `public/build/.vite/`); `publicDir: false` (the default would copy the whole `public/` tree into the build output); `base` switching to `/build/` for builds so code-split chunks, preloads and CSS `url()`s resolve in production; a dev-server CORS allowlist (below); input `resources/js/app.js`; the inline hot-file plugin (below) |
| `resources/js/app.js` | `createApp(App).mount('#app')` entry |
| `resources/js/App.vue` | Small example component |
| `.gitignore` | Appends `node_modules/`, `public/build/`, `public/hot` (created when missing; idempotent — re-runs never duplicate lines) |

These are **generated host files** — the framework takes no PHP or npm
dependency, and the version ranges are a starting point you own and bump
like any other host file.

Then load the entry in your layout:

```twig
<head>
    {{ vite('resources/js/app.js') }}
</head>
<body>
    <div id="app"></div>
</body>
```

### Dev vs build (the `public/hot` file)

`vite()` is mode-aware, Laravel-style, via a `public/hot` marker file:

- **`npm run dev`** — the scaffolded `vite.config.js` contains a tiny inline
  plugin (`ions-hot`) that writes the dev server's origin (e.g.
  `http://localhost:5173`) to `public/hot` when the server starts and removes
  it on shutdown. While the file exists, `vite()` emits the HMR client plus
  the dev-server entry:

  ```html
  <script type="module" src="http://localhost:5173/@vite/client"></script>
  <script type="module" src="http://localhost:5173/resources/js/app.js"></script>
  ```

- **`npm run build`** — a second inline plugin (`ions-hot-clean`) deletes any
  stale `public/hot` first (a leftover hot file would keep emitting dev URLs
  in production), then Vite writes hashed assets + `manifest.json` to
  `public/build/`. `vite()` resolves the entry through the manifest — CSS
  `<link>`s first, then the module script:

  ```html
  <link rel="stylesheet" href="https://example.com/build/assets/app-deadbeef.css">
  <script type="module" src="https://example.com/build/assets/app-4ed993c7.js"></script>
  ```

The inline plugins exist so no extra npm package (`laravel-vite-plugin`) is
needed — vanilla Vite doesn't write a hot file natively.

If you maintain your own `vite.config.js` with `manifest: true`, Vite ≥5
writes the manifest to `public/build/.vite/manifest.json` — `vite()` falls
back to that location automatically when `public/build/manifest.json` is
absent. A hot file whose contents do not start with `http://`/`https://`
is ignored (manifest mode is used instead).

### Dev-server CORS (custom local domains)

Vite ≥6.0.9 only answers cross-origin requests from localhost pages by
default. The scaffolded config allows `localhost`/`127.0.0.1`/`[::1]` and
any `*.test` domain. If your app runs on a different local domain (e.g.
`http://myapp.local`), add its origin to `server.cors.origin` in
`vite.config.js` or the browser will refuse to load dev-server modules.

When neither `public/hot` nor `public/build/manifest.json` exists (or the
entry is missing from the manifest), `vite()` **returns an HTML comment**
(`<!-- vite: manifest not found; run npm run build -->`) and logs a warning
to `var/logs/view.log` — a missing build never breaks the page.

## `install:assets`

```bash
php bin/ions install:assets     # --force to overwrite existing files
```

Writes `public/assets/css/app.css` and `public/assets/js/app.js` — committed
starter files served as-is (no `resources/` + publish step, no `.gitignore`
changes). Link them with `asset()`:

```twig
<link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
<script src="{{ asset('assets/js/app.js') }}" defer></script>
```

## Twig function reference

| Function | Returns | Behaviour |
| -------- | ------- | --------- |
| `vite(entry)` | HTML (safe-marked) | `public/hot` exists with an `http(s)://` origin → HMR client + dev-server entry script. Else `public/build/manifest.json` (falling back to `public/build/.vite/manifest.json`) → CSS links + hashed module script (URLs based on `app.app_url`); the manifest is read once per request. Missing manifest/entry → HTML comment + `view.log` warning. Never throws. |
| `asset(path)` | URL string (auto-escaped) | `rtrim(app.app_url, '/') . '/' . path` for a file under `public/`. Appends `?v=<filemtime>` when the file exists; no buster when missing or when the path contains `..`. Never throws. |

`asset()` works for ANY file under `public/` (`asset('uploads/logo.png')`),
not just the `install:assets` starters. Vite's build output needs no buster —
filenames are content-hashed already.

## Production notes

- Run `npm ci && npm run build` as a **deploy/CI step** — the recommended
  setup (and what `install:vue` scaffolds) is `public/build/` in `.gitignore`
  with CI building the assets, so hashed artifacts never churn your diffs.
  Committing `public/build/` instead also works if your hosting can't run
  node; remove the `.gitignore` entry in that case.
- Never let `public/hot` reach production: it forces dev-server URLs. The
  scaffolded config removes it on every build and on dev-server shutdown,
  and it is `.gitignore`d — but check for leftovers if styles vanish after a
  kill -9'd dev server.
- `app.app_url` must be set correctly (scheme + host) — both functions build
  absolute URLs from it (`doctor` warns when it is missing).
