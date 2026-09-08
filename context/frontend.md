# Frontend (single-page Blade + fetch)

## Related File Index

| File Location | Function |
|---|---|
| `resources/views/tos/index.blade.php` | Whole flow (create form + results container); rendered with or without `$tos` |
| `resources/views/tos/_results.blade.php` | Results fragment swapped into `#results-container` by fetch |
| `resources/views/tos/chat.blade.php` | Chat page (static route — keep above `/{tos}` wildcard) |
| `resources/views/layouts/app.blade.php` | Shared layout extended by the TOS views |
| `routes/web.php` | Route order is load-bearing — static routes above `GET /tos/{tos}` |
| `resources/js/app.js` + `resources/css/app.css` | Vite entries; Tailwind v4 via `@tailwindcss/vite` |

## How it works

- One URL serves both states: `GET /tos/create` (no `$tos`) and `GET /tos/{tos}`
  (shared/direct link, with `$tos`) render the **same** `tos.index` view.
- Every action after load is `fetch()` against the JSON endpoints, swapping
  `#results-container` with the returned `html` fragment and updating the address
  bar via `history.pushState` only — no navigation, no reload.
- Create form (`#tos-form`) posts `multipart/form-data` to `route('tos.store')`;
  lesson panels are repeatable (JS add/remove). Non-JS clients get classic
  redirect-with-flash fallback via `respond()`.

## Rules

- **Route order in `routes/web.php` is load-bearing**: `/tos/create`, `/tos/chat`,
  `POST /tos`, `/tos/history` must all stay ABOVE `GET /tos/{tos}` — the wildcard
  swallows them otherwise (file header comment explains this).
- AJAX detection is `$request->wantsJson() || $request->ajax()` — keep both checks;
  fetch callers must send `Accept: application/json` or `X-Requested-With: XMLHttpRequest`.
- Generation takes 10–30s+ (pooled Gemini + retries): keep a loading state on the
  generate button; there is currently no progress indicator (README-listed next step).

## Build

- Vite + Tailwind v4 (`@tailwindcss/vite`, entry `resources/css/app.css` + `resources/js/app.js`).
- `npm run dev` for HMR, `npm run build` before shipping; `vite.config.js` ignores
  `storage/framework/views/**` in watch.
