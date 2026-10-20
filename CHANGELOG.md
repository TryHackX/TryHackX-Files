# Changelog

Notable TryHackX Files changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

Detailed pre-2.69 development notes were condensed when all public documentation was standardized
in English for the first GitHub release.

## [2.81.0] - 2026-10-21

A second full review of the application — upload server, request pipeline, domain logic, front
end, repository and a live pass on phones and desktop — and the fixes it led to. The database
schema stays at 64.

### Security

- Upload names can no longer smuggle a blocked extension (`.exe`, `.php`, `.html`, …) past the
  check. A last extension longer than 100 bytes made the name be cut after the check, which could
  expose an inner `.exe`; trailing dots and spaces did the same. Such files are stored as
  `….blocked`.
- Strangers can no longer lock an account out of signing in. Wrong passwords are counted per
  account and per network, so only the network that used up its guesses waits out the window
  while the owner signs in from anywhere else; with a captcha configured, every sign-in limit asks
  for the captcha instead of refusing.
- The panel's settings forms (General, Storage, Security, E-mail, System/cron) need a password
  typed in the last 10 minutes, like the API's sensitive actions — including switching the panel's
  own password gate off. Rerouting outgoing mail used to be enough to take over the owner account
  from a panel tab left open.
- Wrong passwords typed inside a signed-in session — panel gate, re-confirmation prompt, 2FA
  setup/disable/recovery codes, signing out other devices, e-mail, password and account deletion,
  "delete all my files" — share one budget per account: 10 per 15 minutes, then 429 until the
  window ends. A stolen session can no longer test about 40 passwords per 5 minutes.
- The site name is plain text everywhere: Settings → General and the installer store it without
  markup, control or bidirectional characters (at most 50), and every page head and header escape
  it, so it can no longer inject a meta refresh into every page.
- IPv6 clients are counted, capped and banned per /64 network on both sides — PHP and the upload
  server (IPv4 per address): concurrent downloads, connections per file, the guest transfer
  allowance, the accounts-per-address sign-up cap, report and delete-token budgets. IP bans are
  stored as the /64 and still match bans stored for an exact address.
- Every upload needs the upload token the site hands out; the upload server no longer opens an
  implicit per-address guest session, which rotating addresses turned into an unlimited one.
- File and collection passwords: a correct password (for example for one's own upload) no longer
  resets the guessing budget. Each network has 20 wrong guesses per security window over all
  targets, and one file accepts at most 100 wrong guesses from all networks together.
- Anonymous abuse reports are limited to 20 per network per security window even without a
  captcha, a repeated report of the same file is not stored again, and the text is capped at 5000
  characters.
- Every answer PHP serves itself (previews, embeds, ad banners) takes a stream slot — small
  answers in a pool of their own (`php_small_stream_max_global` 8, `php_small_stream_max_per_ip`
  6) — so slow readers can no longer hold every PHP worker. Previews and embeds use up the
  downloader's transfer allowance like downloads, and one-time or capped links can no longer be
  embedded.
- Creating an API key or a webhook needs a recent password and sends the owner a notice; a
  password change (own, by reset link or by an administrator) also removes the account's webhooks.
- QR codes are drawn off the upload server's event loop and capped at 512 bytes, so anonymous
  requests can no longer stall every upload and download.
- A crafted collection link can no longer keep one-time or capped collections unavailable for
  everyone, and one account's unresponsive webhook receiver can no longer delay everybody's
  deliveries for hours.
- The language links and the live language switch no longer put a password-reset token back into
  the address bar and the browser history.
- Accounts created by an administrator are recorded in the audit log (`user_created`, with the
  role).

### Fixed

- Buying a plan or an ad works in Chrome and Edge: checkout hands the buyer to PayU or
  Przelewy24 through a short "Continue to payment" page instead of a redirect the browser's
  form-action policy blocked (the button did nothing while an order was created).
- Ads: an edit sends back to review only what was bought, a rejection with a refund is final, and
  approval checks that a completed order paid for each placement — unpaid or refunded placements
  could go live. A renewal charges for and renews only the placements renewed with the ad.
- A paid order that cannot be delivered (a second order for a paid draft, an ad or account deleted
  meanwhile, a short payment) is parked once with one "Paid order not delivered" notice to the
  administrators instead of being retried every 15 minutes; its failure details now stay in the
  audit log. A second checkout for an ad with an open order is refused.
- Granting days to a lifetime member keeps the membership lifetime, the bulk "+N days" extends
  only the chosen plan's subscribers, and "active subscribers" counts paid plans only.
- Accounts created from the panel's "Add user" form no longer get a hidden 500 MiB storage cap
  that overrode their group and paid plan.
- Maintenance mode: the notice links to an administrator sign-in whose dialog offers only signing
  in (sign-up, recovery and statistics answered 503 there), the file, collection and plan pages and
  the panel show it to everyone else (the panel used to stay on "Loading…"), `/api/v1` answers only
  an administrator's key, and payment notifications keep being processed.
- Array-shaped fields (`name[]=x`) are treated as missing instead of causing a 500 and a PHP fatal
  error in the log.
- Dead file and collection links answer 404 (missing or malformed id) or 410 (expired, used
  one-time, at the download cap) with the reason, `noindex` and no download button, instead of a
  200 page whose button could only fail; the message says whether a file or a collection expired,
  was used or reached its limit.
- A failed migration step is no longer re-run by every request: for 5 minutes requests get a short
  503, then it is tried again; `php scripts/migrate.php` retries at once.
- The integrity report and the mail worker honour `DATA_PATH` (the worker sees mail settings
  changed in the panel without a restart); while the database is down a page dials it once instead
  of 10–20 times; a refused checkout no longer burns its idempotency key.
- Behind a front proxy listed in `TRUSTED_PROXIES` (or with `TRUSTED_PROXY_HEADER`) downloads,
  collection ZIPs and uploads no longer fail with 403: the upload server resolves the client
  address exactly as PHP does.
- Files deleted after their last allowed download, and the files of an account an administrator
  deletes, stay in the quarantine when it is on (deleting one's own account stays an erasure);
  with the quarantine on, bulk deletes no longer copy and hash every file inside the request.
- Every removal path — retention, storage policy, moderation, administrator delete or clean-up,
  "delete all my files", delete after the last download — sends the owner's `delete` webhook.
- A download whose file was deleted or whose cap changed meanwhile is still finalized and charged;
  one bad reservation no longer stops the cleanup of the others; an abandoned collection ZIP
  closes its member files at once.
- Text and JSON downloads keep their size and progress (Apache no longer gzips proxied
  downloads), and scripts and fonts get their long cache lifetime when mod_expires is enabled.
- Files per session: the plans page, "My plan", the upload card and the session line show the cap
  the upload server applies — the group's own cap, or the flat setting where the group leaves 0.
- "Not downloaded for N days" goes back at most `traffic_detail_days` (the detailed log) and says
  so, instead of listing files that are still downloaded.
- Ad reviewers with only "approve ads" see the creatives; a guest's page with several pictures
  from this site keeps them all; a used promo code can only be disabled and the panel says so; an
  administrator's grant or revocation prints no receipt; a broadcast resent within seconds is not
  sent twice; a banner PHP refuses for its size is reported as too large.
- Upload page: refusals are explained in the page's language (size with the real limit, empty
  file, quota, inactive account, ban) instead of the upload server's English JSON; Retry is
  offered only where it can help; a refused token is renewed once instead of an empty
  "Verification" dialog that locked the page; a failed delete keeps the row; right after signing in
  the session line shows the account's cap instead of the guest's.
- Signing out on a public page reloads it, so signing in again from the same dialog works (it
  failed on a stale security token) and the bell disappears.
- The live language switch translates the whole page — dialog titles, text-area hints and the
  upload page's rows, cards and session line included — also after a toast or dialog was added.
- Panel: a wrong password at the settings check keeps the administrator on it with the error (it
  showed the settings as if the password had been accepted and dropped the change); the password
  re-check prompt settles however it is closed and no longer closes the form underneath; dashboard upload rows keep their running time and a single kill button; messages in
  the ads, moderation, groups, languages and account-tools dialogs are visible; lists the server
  refuses show the reason instead of "Loading…"; "My files" keeps its page on refresh; the Users
  search works; bans accept IPv6 and the `<network>::/64` form; writes are sent once per click; ad
  and promo dates no longer move a day on servers outside UTC.
- Hidden elements stay hidden (a locked collection showed "Download all", which led to a raw 403
  page), errors are no longer drawn behind the collection password dialog, "Link copied" appears
  only when copying worked, and the report form marks all the fields the server requires.
- Phones: ticking rows no longer pushes the table down, the Tracking filter, notification
  preferences, banner cropper and a long uploads path no longer widen the page, the download
  button is on the first screen, the signed-in header fits one row, small controls are
  finger-sized, an upload row's remove button stays beside a long status, and selects and text
  areas no longer make iOS zoom in.
- Debian: running `scripts/install-debian.sh` again keeps the installation's service account, its
  PHP-FPM setup and the absence of a local MariaDB, and leaves existing runtime directories alone;
  the documented dedicated-account / PHP-FPM procedure works as written; after the uploads
  directory is changed the panel tells you how to open it to the upload service.
- Docker: `FILEHOST_DB_POOL_MAX`, `FILEHOST_DB_ACQUIRE_TIMEOUT`, `FILEHOST_IO_THREADS` and
  `APP_ENV` from `.env` reach the container, and PHP accepts banners up to the 5 MiB allowance.

### Changed

- The maintenance scripts (`cleanup.php`, `file-quarantine.php`, `mail-worker.php`, `migrate.php`,
  `check-storage-integrity.php --repair-missing`) refuse to run as root (exit 77) and print the
  `sudo -u <service account>` command; `--allow-root` lets a deliberate root run through.
- `scripts/install-debian.sh` has a `FILEHOST_UNITS_ONLY=1` mode that only re-renders the units
  and the storage drop-in and restarts the services.
- `public/.htaccess` sets `LimitRequestBody 0` for `/api/upload` and `/api/sharex`: file size
  limits belong to the upload server alone.
- `/ready` names the failing path, the error and the usual fix when upload storage is not
  writable; webhooks that failed every delivery for 24 hours are switched off with a status saying
  so; the upload server reads `TRUSTED_PROXIES` / `TRUSTED_PROXY_HEADER` from `config.local.php`;
  the administrator-kill check is a read instead of a write per second and transfer.
- The `delete` webhook payload carries `reason` (retention, storage_policy, admin_delete,
  admin_cleanup, owner_delete_all) when one of those paths removed the file.
- Old security-event rows are pruned by the scheduled cleanup; an unmountable storage directory
  no longer prints its path on the public error page; `check-storage-integrity.php` prints a short
  summary and the full report with `--json`.
- FastAPI 0.143.2 for the upload server; test tools Ruff 0.17.1, uv 0.13.1.
- Polish wording reviewed (about a hundred strings), counts read correctly for 1, 2–4 and 5+,
  Spanish uses "tú" and Chinese 您 and 套餐 consistently; messages are announced to screen
  readers, every field has a name, the light theme's error colour meets 4.5:1, and every keyboard
  stop shows the focus ring.
- PHP test runs fail on any deprecation or notice; the repository policy check also verifies that
  the locks satisfy every version range; a shell test covers the Debian installer's re-run rules.
- SECURITY.md names contact@tryhackx.org for reports when GitHub's private form is not available;
  the docs describe Docker as tested end to end in CI and the release procedure as practised.

### Removed

- The implicit per-address guest upload session (see Security), the robots.txt/security.txt
  allow-list in `public/.htaccess` (those files never shipped), unused token and recovery helpers,
  duplicate captcha script tags and dead CSS/JS.

### Upgrade notes

- No schema change. Install the new hash-locked requirements into the virtual environment
  (FastAPI 0.143.2) and restart `filehost-upload` and `filehost-mail-worker`.
- Uploads without the site's upload token are refused (403); integrations should use API keys
  with `/api/sharex`.
- Run maintenance scripts as the service account; a root cron line for `cleanup.php` now exits 77.
- The cache rules need `a2enmod expires`. For a dedicated PHP-FPM pool set
  `request_terminate_timeout = 360s` and keep `php_stream_max_global` +
  `php_small_stream_max_global` below `pm.max_children` (see docs/DEBIAN.md).
- Accounts created by an administrator before this release keep their 500 MiB override;
  `SELECT id, username FROM <prefix>users WHERE storage_limit = 524288000` lists them.
- A password change now removes the account's webhooks; add them again afterwards.

## [2.80.1] - 2026-10-14

### Fixed

- Finished downloads are counted again, and download caps hold. Behind Apache's proxy rules the
  connection to the upload server is not kept alive: once Apache has every byte the response
  promised, it closes the socket. The upload server cancelled the stream at that moment, and its
  accounting only marked a transfer complete after one more, final message that a cancelled
  stream never sends, so nearly every full download was filed as "released". Download counters
  stayed at zero, `max_downloads` never refused a second download, "delete after the last
  download" never deleted, and owners got no download notifications or webhooks. One-time links
  and transfer quotas were not affected. A response with a known length now counts as complete
  once every promised byte has been handed over; a transfer that ends short, or one an
  administrator stops, is still released without counting. This had been the case since before
  2.80.0; the Docker smoke test, which now runs end to end, is what caught it.

### Upgrade notes

- Files that already received more downloads than their cap allowed start refusing further
  downloads after the update, because the counter starts moving again. No schema change.

## [2.80.0] - 2026-10-14

### Security

- Actions that hand out privileges or rewire money (creating administrators, changing roles or
  passwords, editing groups and plans, payment, ad and CSP settings, broadcasts) now ask for a
  password entered in the last 10 minutes in the API itself, not only on the panel page; a session
  restored from a cookie, or a tab left open, could previously call them directly. Only the
  install owner can set another administrator's password, and nobody can set the owner's.
- Sign-in is harder to brute-force. Failures and wrong 2FA codes are also counted per account
  (a captcha, or a 15-minute wait where none is configured), five wrong codes end a pending 2FA
  sign-in, an authenticator code cannot be reused, and a 2FA account whose secret cannot be read is
  no longer let in with the password alone. "Sign out other devices" ends their live sessions, and
  a replayed (stolen) device cookie ends every session of the account.
- Behind a reverse proxy the client address is read from the right of `X-Forwarded-For`, past your
  own proxies, so a client can no longer pick its own address to dodge limits and bans.
  `CF-Connecting-IP` and `X-Real-IP` are believed only when named in the new `TRUSTED_PROXY_HEADER`.
- Sessions use strict mode and cookie-only ids, HTTPS responses carry HSTS, every page runs under
  the strict script policy, and signing out is POST-only. The users list no longer sends password
  hashes or encrypted 2FA secrets to the browser.
- Text stored by users can no longer inject attributes into admin screens: a collection name or ad
  alt text containing a quote used to add a handler that the panel ran with the administrator's
  session. Output is escaped, and those characters are replaced when such a name is saved.
- File and collection passwords can no longer be guessed through the preview unlock, the collection
  form or a ZIP's member passwords; they share the Download button's budget (a captcha where one
  can be shown, and after 20 wrong passwords per address a pause).
- Previews are no longer free downloads. They streamed the whole file without counting, so a
  one-time link could be read without burning it and a capped file or a transfer allowance was
  bypassed; they are now withheld in those cases. Streams PHP serves itself take a slot under a
  site-wide and a per-address cap, follow the group speed limit and stop after 5 minutes, so slow
  viewers cannot pin every PHP-FPM worker.
- Raw HTML and AdSense ad creatives can be saved by administrators only.
- The upload server can no longer be stopped by a handful of visitors. A signed-in download held a
  database connection while asking for a second, so twenty at once froze everything; waits now end
  after 10 s with a 503. A slow webhook receiver gets 15 s in total, `POST /api/qr` refuses bodies
  over 8 KiB, and a timed-out thumbnail no longer leaves ffmpeg running. It also sends no CORS
  headers, answers `/reload` and token-less `/metrics` only to local callers, and hides server paths.
  The public `api.php?action=health` reports only up or down, not paths, config state or PHP version.
- The upload service runs in a much tighter systemd sandbox (read-only file system except
  `uploads/` and `data/`, no capabilities, filtered system calls), so a runaway thumbnail or ffmpeg
  process cannot modify the code or `config/`. The Docker image starts from PHP's production
  settings and pins its base images by digest.

### Added

- The panel answers a refused sensitive action with an inline password prompt and repeats it. New
  optional settings, all with working defaults, are listed under Upgrade notes.
- `scripts/install-debian.sh` can skip MariaDB (`FILEHOST_INSTALL_DB=0`), use PHP-FPM
  (`FILEHOST_PHP_SAPI=fpm`) and opens storage kept outside the application directory for the
  sandboxed service. CI also covers PHP 8.5, MariaDB 11.8 and Python 3.13.

### Changed

- The upload service runs on `uvloop` on Linux and every Python dependency is at its newest
  compatible release (fastapi 0.143, uvicorn 0.54, starlette 1.8, pydantic 2.14, cryptography
  50.0.2). One universal lock compiled with `uv` installs on Debian and Windows; `websockets`,
  `watchfiles`, `python-dotenv` and PyYAML, which nothing used, are no longer installed.
- One shared stylesheet, `assets/css/base.css`, carries the tokens, header, buttons, account modal
  and toasts for every page (the copies had drifted), and muted text and light-theme status colours
  meet WCAG AA contrast. Public pages ship only the translations their scripts use (about 9 KB
  instead of 130 KB), and the panel skips admin-only scripts for accounts that cannot use them.
- The five-minute schema re-check no longer marks the schema "not ready", so uploads are not
  answered with 503 while it runs. The REST API, `ads.txt`, payment webhooks and the health probe
  open no session, and the upload server writes no access log of its own (Apache already does).
- Announcements to all accounts are written in a few statements and a repeat within ten minutes is
  a retry; old traffic is folded with an index range instead of a table scan; "top downloaded"
  offers only the periods the detailed log still covers; e-mail footers are translated.
- The installer refuses MySQL older than 8.0.1 or MariaDB older than 10.6 (the e-mail outbox needs
  `SKIP LOCKED`; the documented minimum said 10.4). The Docker image is PHP 8.5 on Debian 13.

### Fixed

- "Stay signed in" works on every page and API call, not only the panel, and uploads made on a
  remembered device belong to the account. Several tabs opening at once no longer look like a
  stolen cookie, and a brief database outage signs nobody out. Maintenance mode keeps the 2FA step
  reachable, and changing the password keeps this browser signed in.
- Uploads made after signing out, or after another account signs in in the same tab, no longer
  belong to the previous account: signing out ends the tab's upload session and signing in hands
  the new account its own. Changing the password or the 2FA settings keeps this browser
  remembered, not only signed in.
- One-time and download-capped images no longer offer embed codes: every embedded view counts as a
  download, so the first page showing one burned the link. The "File removed by moderation"
  notification has a description instead of a raw key, and empty API-key and webhook tables no
  longer cut their message off on phones.
- Saving the uploads path no longer corrupts `config/config.local.php` when it contains `$0`.
  Removing html, svg, xml and similar types from the blocked extensions is no longer silently
  undone; they stay blocked and saving says so.
- Collection ZIPs containing a file over 2 GiB are complete, files with long non-Latin names can be
  uploaded, and a slow download is no longer cut off as if an administrator had stopped it.
- Ad add-ons added after checkout no longer go live on the cheaper payment, rejecting an ad
  refunds the payment that bought it, and `collection_bottom` shows on every collection page. A
  promo order cancelled after 72 hours and then paid is delivered, and a paid order that can never
  be delivered is parked in the audit log instead of re-notifying the buyer every 15 minutes.
- Deleting files always removes their bytes ("Clean files", reverting an upload by delete token,
  files already missing with quarantine on). Collections stop at 200 files, and a refused save no
  longer renames the collection. Moderators can use the collection list and bulk delete.
- Notification e-mails from the cleanup job (expiry warnings, quota notices, payment results) were
  silently dropped and are sent now. The mail worker no longer stays `failed` after a minute of
  database downtime, and captcha verification no longer calls `curl_close()` (deprecated in PHP 8.5).
- Files picked on the upload page before the session was ready are no longer uploaded
  anonymously. Smaller page bugs are gone: the password strength meter, "belonging to 12ame",
  collection-page errors, notice-page icons, dark mode set in the panel, double submits and
  hard-coded Polish and English text.
- Phones: the header no longer makes pages wider than the screen, so the account button and dialogs
  are reachable; the notification list opens under the header; fields are 16px so iOS does not
  zoom; the dashboard chart and the panel tab bar fit; the panel password page can be autofilled.

### Removed

- API actions with no caller left (`verify_token`, `increment_token`, `user_rename_collection`,
  `user_check`, `stats`, `get_user_stats`), the helpers behind them, dead code in the upload
  server, 43 unused translation keys and `.htaccess` guards for directories outside `public/`;
  `php-intl` from the Debian installer, `mysqli`, `zip` and `python3-pip` from the Docker image.

### Upgrade notes

- No schema change: the database stays at schema 64. Restart the upload server and the mail
  worker after updating, or rebuild the Docker image.
- Rebuild the Python environment from the new `requirements-lock.txt` (Python 3.11+): install into
  `venv.new` with `--require-hashes`, stop the services, swap it into `venv`, start them. On Linux
  `venv/bin/python -c "import uvloop, httptools"` must succeed.
- Re-render the systemd units from `scripts/filehost-upload.service` and
  `scripts/filehost-mail-worker.service` (`scripts/install-debian.sh` does it), then run
  `systemctl daemon-reload`. The upload service can now write only `uploads/` and `data/`; storage
  elsewhere (`UPLOADS_PATH`, `DATA_PATH`, `FILE_QUARANTINE_PATH`) needs the drop-in
  `filehost-upload.service.d/10-storage.conf`, which the installer writes. Re-run it after moving them.
- MySQL 8.0.1+ or MariaDB 10.6+ is required and checked by the installer.
- Monitoring that parses `api.php?action=health` must read the new answer,
  `{success, php: {database_connected}, python: {status, database_connected} | null, python_running}`;
  `php.version` is gone. A call to `/reload` through a proxy now gets 403.
- Optional, all with working defaults: settings `php_stream_max_global` (4; keep it below the
  PHP-FPM pool's `pm.max_children`), `php_stream_max_per_ip` (2) and `php_stream_max_seconds` (300)
  without a panel field; environment `FILEHOST_DB_ACQUIRE_TIMEOUT` (10 s) and `FILEHOST_IO_THREADS`
  (64); and, behind a proxy that sets `CF-Connecting-IP` or `X-Real-IP`,
  `define('TRUSTED_PROXY_HEADER', 'CF-Connecting-IP');` in `config/config.local.php`.
- Nobody is signed out by the upgrade itself. Everyone signs in once more only if the PHP session
  store moves, for example to the dedicated PHP-FPM pool that docs/DEBIAN.md recommends.

## [2.79.2] - 2026-08-26

### Added

- The panel gate now resists being guessed at. It had CSRF and an audit line and nothing else:
  a wrong password could be retried without limit, which matters because the gate is reached
  *with* a valid session — a machine left signed in, or a stolen session cookie. Attempts are
  now counted per account and address through the existing `auth` limiter (ten in five
  minutes), and once that is spent the form is withdrawn rather than merely rejecting, so
  there is nothing left to submit against.
- A captcha appears on the gate from the third wrong password, using whichever provider is
  configured. By then it is not a typo. It is skipped entirely when the captcha feature is
  off, and the counter lives in the session — clearing that costs the attacker the session
  that got them to the gate in the first place, and the sign-in form they land on is already
  throttled and captcha'd.

### Changed

- The drifting-orb background is one stylesheet now, `assets/css/background.css`, and the
  panel finally shows it. The rules had been copied into `index.css` and `download.css` and the
  copies had already diverged — the download one was missing the third orb — while the panel
  carried the markup with no styles at all, so the elements rendered and did nothing. The panel
  matching the rest of the site was the point; removing a duplicate that was already wrong came
  with it.
- `download.php` and `collection.php` stop rendering their own background layer. They emitted
  one and then included the site header, which emits another, so both pages had been stacking
  two sets of orbs at double opacity. The shared partial carries the ids the download page
  parallaxes, so that still works.

## [2.79.1] - 2026-08-25

### Changed

- The panel's re-authentication gate now looks like the rest of the product. It was a
  standalone page carrying its own private copy of the palette — hand-picked hex values, a
  different font stack, a card perfectly centred in the viewport — so the one screen that asks
  for a password was the one screen that did not look like the site. It now loads the site's
  own stylesheet and reuses the sign-in modal's card, input and button classes, renders the
  same drifting-orb background as the public pages, and follows the same placement rule the
  modal documents: raised toward the top on desktop, centred on phones.
- The gate also honours the `theme` cookie the panel already sets, so someone working in the
  light theme is no longer thrown onto a dark page halfway through.
- The background layer moved into `src/includes/bg_decoration.php`. It was markup duplicated
  between the site header and nothing else; now the gate renders the identical element instead
  of an approximation that would drift the first time the palette changed.

## [2.79.0] - 2026-08-25

### Added

- The captcha is no longer hard-wired to reCAPTCHA v2. **Settings → Security → Captcha
  provider** picks one of four — Cloudflare Turnstile, reCAPTCHA v3, reCAPTCHA v2 or hCaptcha —
  and the whole stack follows: the verification endpoint, the widget the browser renders, and
  the Content-Security-Policy origins the page admits.
- Keys are kept **per provider**, so evaluating one does not cost you another. Switching the
  selector away from reCAPTCHA v2 and back leaves its site key and secret exactly where they
  were; every provider has its own pair, each secret encrypted at rest through
  `Database::setSecretSetting()` and never prefilled back into the form.
- reCAPTCHA v3 brings its score threshold into the panel (**Minimum score**, default 0.5). It
  is the only provider judged that way on purpose: hCaptcha's enterprise answer also carries a
  `score`, but there higher means *more* risk, so applying v3's rule to it would reject the
  humans and pass the bots.
- `public/assets/js/captcha.js`: one front-end adapter (`FHCaptcha`) that the upload page, the
  download page and the auth modal all share, instead of three hand-rolled copies of the
  grecaptcha dance. reCAPTCHA v3 has no widget to click, so it is folded in behind the same
  render/getResponse/reset API — the caller gets its token from the same callback as everyone
  else, and `reset()` fetches a fresh one because v3 tokens expire after two minutes.

### Changed

- The Content-Security-Policy now admits **only the selected provider's** origins, and only
  while the captcha is switched on. Choosing Turnstile no longer leaves Google's script hosts
  whitelisted, and switching the captcha off leaves nobody's.

### Fixed

- Every PHP page was being served **two** Content-Security-Policy headers — PHP's, and the
  `Header setifempty` fallback from `public/.htaccess` — and a browser handed two of them
  enforces both, so the effective policy was their intersection. `setifempty` was meant to
  prevent exactly this and cannot: behind `mod_proxy_fcgi`, mod_headers runs its fixup before
  the backend's headers are merged, so it inspects an empty table and adds its policy anyway.
  Nothing had noticed because both policies happened to allow the same things; the moment the
  captcha provider became selectable it would have blocked Turnstile and hCaptcha outright,
  and it was already silently blocking the AdSense relaxation for anyone who enabled ads. The
  fallback is now scoped with `<FilesMatch>` to the extensions Apache serves from disk itself,
  so documents get exactly one policy — PHP's — and static assets still get one.
- Captcha verification retries once. A single request was enough to lose a solved challenge to
  a timeout whenever the uplink was saturated, and the visitor was told the captcha had failed
  on a challenge they had just completed. Two attempts at 5 s connect / 8 s total.
- A captcha that is switched **on** but whose selected provider has no secret now rejects
  responses instead of waving them through. With the feature off, verification stays the
  historical no-op so a fresh install still works out of the box.
- No schema change: 2.79.0 stays on schema 64 and adds settings keys only. An upgrade needs no
  migration and no keys re-entered — reCAPTCHA v2 stays selected, reading the same
  `recaptcha_site_key` / `recaptcha_secret_key` it always did.

## [2.78.0] - 2026-08-25

### Added

- Changing the address on an account is now confirmed from both ends. The old flow asked only
  the new address, which meant a session someone else was holding — with the password, which it
  already required — could move an account away and the real owner would never hear about it.
  Now the address on file has to approve first, and that message doubles as the warning: it
  says what was asked, that nothing has changed yet, and that whoever asked knows the password.
  Only after that does the new address get its own link, proving the mailbox exists and is
  readable. When it lands, both addresses are told what changed and that every session and API
  key was signed out with it.
- The account page finally renders the result of those links. `msg=` and `err=` were being put
  in the URL and read by nothing, so confirming an e-mail change looked like it had done
  nothing at all — and the halfway point of a two-step flow is exactly when a person needs to
  be told where to look next.

### Fixed

- Transactional e-mails carrying a link arrive as HTML instead of as their own source code.
  `Database::sendEmail()` escapes its body, which is right for anything that might contain text
  a person typed and wrong for the templates this project writes itself: activation, password
  recovery and e-mail change all built markup and had it escaped on the way out, so recipients
  saw `<a href='...'>` as words and had no link to click. Those three now go through
  `sendTemplate()`, which renders application-authored HTML as-is; every value interpolated
  into them is escaped where it is interpolated. Plain-text mail is untouched, and
  administrator-authored broadcasts keep their sanitizer.

### Changed

- Database schema 64: `users.email_change_stage`. Any e-mail change still in flight at upgrade
  time is dropped — it was issued under the one-sided rule and finishing it would skip the
  confirmation the new rule exists to require. The Python sidecar moves to 64 with it.

## [2.77.2] - 2026-08-25

### Fixed

- The "signed out N devices" toast said `{n}`. The browser-side translator substitutes `:name`,
  not `{name}`, which every other string in the file already knew; this one was written to the
  PHP convention and shipped straight past the tests, because nothing asserts on a toast.
- Signing in on a browser that already held a device token left the old row behind instead of
  replacing it. One browser could therefore own several entries in its own device list, all but
  one of them credentials nobody could match to anything. A sign-in is a statement about the
  device making it, so the previous token for that browser is dropped first — and choosing
  "this browser session only" now stops it being remembered at all rather than quietly leaving
  the last token alive.

## [2.77.1] - 2026-08-25

### Changed

- The remembered-devices list marks the browser reading it instead of leaving it
  indistinguishable, and "sign out other devices" now spares that one. Both follow from what
  the button is for: someone has lost a device and is pressing it from a device that is
  definitely not the problem, so signing themselves out too only costs a password prompt on the
  wrong machine. A session-only sign-in has no current token, so there is nothing to spare and
  everything still goes.

## [2.77.0] - 2026-08-25

### Added

- Persistent sign-in. The sign-in form can offer a duration — this browser session, 30 minutes,
  1 hour, 3 hours, 1 day, 7 days, 30 days, or as long as the administrator allows — and anything
  beyond the session is backed by a credential of its own, because a PHP session cannot outlive
  the browser. Each device gets a *series* and a *secret*; only their hashes are stored, so a
  leaked database yields nothing that can be presented back, and the secret is replaced on every
  single use. A valid series arriving with a stale secret means two parties hold the same
  cookie: which one is the owner is unknowable, so every token for that account is destroyed and
  an audit line is written. A silent theft becomes a visible logout.
  There is no "forever" option — an unbounded credential cannot be issued safely, so "as long as
  allowed" resolves to the configured ceiling.
- **Account → Remembered devices** lists what can currently sign the account in without a
  password — browser, address, when it was trusted, when it was last used, when it stops working
  — and revokes all of them behind a password check. A credential the owner cannot see is one
  they cannot manage.
- A second gate in front of the panel. Being signed in says a browser authenticated once; it
  does not say the account owner is at the keyboard now, which is exactly the gap a shared
  machine, an abandoned tab or a month-old device cookie opens. Administrators and moderators
  are asked for their password again after an idle period, configurable in
  **Settings → Security → Sessions and sign-in** along with the scope and the persistent
  sign-in ceiling. Working in the panel keeps the window open; walking away closes it. Setting
  the window to 0 turns the gate off entirely.

### Changed

- Database schema 63: new table `remember_tokens`, with a `CASCADE` foreign key so a deleted
  account cannot leave a live credential behind. The Python sidecar refuses readiness on any
  other schema, so both halves must be deployed together — see [MIGRATION.md](docs/MIGRATION.md).
- Restoring a session from a cookie is deliberately not a credential check. `recent_auth_at`
  stays unset, so the panel and every destructive account action still ask for the password,
  exactly as they would after the session had simply expired.
- `revokeAuthenticationArtifacts()` now includes `remember_tokens`, so a password change, a 2FA
  change or a deactivated account takes every device's copy with it. Turning persistent sign-in
  off in the panel revokes the outstanding ones too, rather than only stopping new ones.

## [2.76.12] - 2026-08-25

### Added

- `scripts/mail-worker-hardening.sh` switches the mail worker's sandbox between the shipped
  `NoNewPrivileges=true` and a relaxed unit that lets `PHP mail()` reach a setgid helper. The
  restriction is this project's own choice, so changing it should be one command rather than a
  hand-written drop-in — but it cannot be a button in the panel. `no_new_privs` is one-way for
  the life of a process, so nothing already running can clear it; only systemd can decide it,
  at exec time, from a unit file root owns. Letting the web user rewrite that file and restart
  the service would turn a panel compromise into control of a root-owned unit, which is the
  precise thing the flag exists to prevent. Root presses the button; the panel says which one
  to press.
- **Settings -> E-mail** shows what the worker actually reports: when it last ran, its PHP
  version, whether its sandbox is strict or relaxed, and the command that changes it. The panel
  cannot read any of that from its own process — the kernel flag belongs to one process tree
  and PHP-FPM is not that tree, so it always answers "no" regardless of how the worker runs.
  The worker therefore publishes a snapshot into the data directory it already owns, rewritten
  only when something changes, and a worker that stopped reads as stale rather than as good
  news.

## [2.76.11] - 2026-08-25

### Added

- **Settings -> E-mail** gains a safeguard for `PHP mail()`, because that method cannot be made
  to work everywhere and cannot simply be forbidden either. `mail()` hands the message to
  whatever `sendmail_path` points at; with Postfix that is a setgid `postdrop`, which
  `NoNewPrivileges` disarms, after which the helper retries an unwritable maildrop every ten
  seconds and `mail()` never returns. Other agents - msmtp, ssmtp, an exim that needs no
  privilege transition - are perfectly happy under the same flag. The administrator therefore
  chooses what happens in that one combination: report an error instead of sending (the
  default, so a queue can never be swallowed by a blocking call), divert to the local mail
  server, or call `mail()` anyway. It has no effect on any other host or transport, and none at
  all outside a hardened service, where the flag is not set.
- The worker warns once an hour, and only for the combination nothing can rescue: `mail()`
  under `NoNewPrivileges` with the safeguard switched off. The other cases either work or fail
  with their own message, so they no longer produce a second, redundant line.

## [2.76.10] - 2026-08-25

### Fixed

- The Docker transfer smoke test throttles downloads again, and therefore runs at all. It has
  failed in every recorded run of this repository, always on the same assertion: no download
  reservation was ever observed in the `started` state. The script slowed transfers to
  128 KiB/s by writing `limit_download_guest` into the settings table — a key that stopped
  driving anything when limits moved into groups. The migration copied it into the guest
  group's `limit_download` once, at install time, and the upload server has read the group row
  ever since; the panel writes the flat key back only for legacy readers. With the throttle
  left at zero the 512 KiB payload was delivered in a single burst, so the reservation passed
  through `started` in less time than one `docker compose exec` takes to answer, and every
  later assertion that interrupts a transfer in flight was equally doomed. The script now sets
  the column that is read, and two tests pin the rule it got wrong: a guest resolves to the
  guest group's `limit_download`, a stale flat setting does not override it, and a missing
  guest group is an error rather than an unthrottled download.
- The same smoke test now reports its own failures. It asserted with bare `test` under
  `set -e` inside a CI container nobody can attach to, so a failing run produced one line:
  "Process completed with exit code 1". Every comparison now names the label, the expected
  value and the actual one, and an `ERR` trap reports the line and command behind any other
  non-zero exit.

### Changed

- Every pinned dependency is now at its current release: `cryptography` 50.0.0, `idna` 3.19,
  `python-dotenv` 1.2.3, `typing-inspection` 0.4.4, `uvicorn` 0.52.4 and `websockets` 17.0.1 at
  runtime, plus `packaging`, `pip`, `pygments`, `setuptools` and `wheel` for development.
  `pydantic-core` is the one exception and not a choice: `pydantic` 2.13.4 requires exactly
  2.46.4, so 2.48.0 cannot be installed until pydantic itself moves.

## [2.76.9] - 2026-08-25

### Added

- A third e-mail sending method. **Settings → E-mail → Sending method** now offers *Local mail
  server*, which submits to the MTA already running on this host over SMTP — `127.0.0.1:25`
  unless `FILEHOST_LOCAL_MTA=host:port` says otherwise. It is the transport a hardened service
  can actually use, and it should be preferred to `PHP mail()` wherever Postfix, Exim or
  anything similar runs beside the application. `PHP mail()` stays for hosts that offer nothing
  else, and the external SMTP relay is unchanged.
- `filehost-mail-worker` reports liveness to systemd between messages, and the unit asks for it
  with `WatchdogSec=120`. A delivery that blocks despite everything below is now killed and
  restarted instead of stalling the queue indefinitely, and `systemctl status` shows the live
  queue depth. The unit also gained `LogRateLimitIntervalSec`/`LogRateLimitBurst`, `ProtectHome`,
  `ProtectKernelTunables`, `ProtectControlGroups`, `RestrictSUIDSGID`, `RestrictRealtime`,
  `RestrictAddressFamilies` and a restart rate limit.

### Fixed

- The mail worker can no longer be wedged forever by PHP `mail()`. The unit sets
  `NoNewPrivileges=true`, which strips the setgid bit from `/usr/sbin/postdrop` on exec. The
  helper then cannot create its file in `/var/spool/postfix/maildrop`, and Postfix's answer to
  that condition is to log a warning, sleep ten seconds and try the same thing again, with no
  attempt limit. `mail()` therefore never returns: the worker stopped inside its very first
  delivery, held that message under an unexpiring claim, delivered nothing for four days and
  reported `active (running)` the whole time. Nothing in the outbox was lost — every retry,
  back-off and dead-letter rule was still correct — but none of them ran, because the process
  never got back to them. The socket transports have no privilege transition to lose, and every
  step of one is bounded, so the same broken MTA now costs one message one attempt.
- A database connection dropped between handing a message to the transport and recording the
  outcome no longer sends that message twice. The worker keeps one connection open across
  batches, so MySQL's `wait_timeout` eventually closes an idle one; the finalising `UPDATE` then
  failed, the row stayed `sending`, and the next expired lease delivered it again. Claim and
  finalisation now retry once on a fresh connection.
- SMTP submission is bounded and explains itself. The connect, every read, every write and the
  conversation as a whole have deadlines; a short socket write is completed rather than silently
  truncating the message; and a refusal is stored as the server's own reply
  (`SMTP RCPT TO rejected: 550 …`) instead of `Mail transport returned failure.`
- SMTP submission encodes the frozen HTML body as base64. It is 8-bit UTF-8 whose lines can
  exceed the 998 octets SMTP allows — a local `sendmail` accepted that, a server on a socket is
  not obliged to.
- A failing transport is visible again. With `FILEHOST_MINIMAL_LOGS=1` the worker printed
  nothing at all when delivery failed, which is how an outage lasted four days. It now writes
  one line per batch that failed — deduplicated for five minutes, because a transport outage
  affects every queued message at once — and one line for every message that exhausts its retry
  budget.
- The worker re-reads settings while it runs. Settings are cached for the lifetime of the
  process, which is one request on the web side but weeks in a service, so a transport or SMTP
  host changed in the panel used to require a restart nobody knew to perform. Only the
  in-process copy is dropped; the shared cache every web request depends on is left alone.

### Changed

- Python dependencies: `cffi` 2.1.0 → 2.1.1 and `starlette` 1.3.1 → 1.6.0, with development
  tools `ruff` 0.16.0 → 0.16.4 and `pip-tools` 7.6.0 → 7.6.1. `pydantic-core` stays at 2.46.4:
  `pydantic` 2.13.4 requires exactly that version, so 2.48.0 cannot be installed beside it.
- The streaming-download contract the sidecar depends on — that Starlette classifies an async
  generator's built-in `aclose` as a *synchronous* background task, and would therefore drop the
  coroutine it returns — is now asserted by tests against the installed Starlette rather than
  only described in a comment.

## [2.76.8] - 2026-08-17

### Fixed

- `action=delete` now deletes through the same durable path as the delete link and the admin
  list. It used to issue its own `DELETE` and then remove the bytes on a best-effort basis with
  the result discarded, so a failed removal — a file still held open, a full or read-only volume
  — orphaned them permanently: the row was already gone, so nothing could ever retry. The bytes
  are queued in the same transaction that drops the row, and the worker finishes the job. The
  old path also cleaned up moderation reports but not collection membership, leaving rows in
  `collection_files` pointing at a file that no longer existed; there is no foreign key there to
  catch it.
- The sidecar's advisory locks are scoped to the installation. `GET_LOCK()` names are server-wide,
  and the upload-capacity, download-concurrency and webhook-claim locks were bare constants, so
  two installations sharing one MySQL server serialised against each other — the upload lock
  worst of all, since every upload takes it. They now carry a database-and-prefix hash, matching
  what the PHP side has always done.
- The Python sidecar no longer leaks a file descriptor per download. The streaming reader holds
  the file open while suspended at a yield, and a consumer that stops early left the generator
  unfinalised, so its `async with` never ran its exit. Descriptors accumulated for the lifetime
  of the process, deleted files kept occupying disk on Linux, and on Windows the file could not
  be removed at all — so purging it after a delete failed and the bytes were orphaned. The
  reader is now closed explicitly once the response completes.
- `/metrics` degrades instead of failing when the database is unavailable. It refreshed settings
  unguarded, so the endpoint monitoring reads to detect an outage answered HTTP 500 during one.
  The refresh is now best-effort and the cached values carry the scrape.
- Shutting the sidecar down no longer leaves the module-global pool pointing at a closed
  aiomysql pool, which handed a dead pool to the next lifespan in the same process.
- The sidecar reads `config.local.php` as UTF-8 rather than the locale code page. On a Windows
  development box the ANSI page mangled any non-ASCII database password, so the sidecar failed
  to authenticate while the PHP side — which reads the same file as bytes — worked normally.
- Removed the remaining PHP 8.5 deprecations: `curl_close()` in six call sites and
  `imagedestroy()` in the advertisement image cropper. Both have been no-ops since PHP 8.0 and
  were writing two notices per call into the production error log.
- `composer analyse` no longer fails on a default Debian install. `register_argc_argv` is Off in
  Debian's `php.ini`, and static analysis believes the ini rather than the CLI SAPI, so `$argv`
  was reported as possibly undefined in three CLI scripts. They now take it from `$_SERVER`.

### Changed

- The HTTP test harness picks an unused loopback port instead of a fixed one, and its readiness
  probe requires the application's own health payload rather than accepting any reply. A fixed
  port that something else already held made the whole class run against a foreign server and
  report about twenty unrelated assertion failures; it now reports the busy port once. The
  `php -S` child is also terminated when startup fails, where it previously survived and stopped
  the runner from exiting.
- Dropped `ReflectionMethod::setAccessible()` from two tests: a no-op since PHP 8.1, deprecated in
  8.5, and `failOnWarning` would have turned the suite red once the matrix reaches 8.5.

## [2.76.7] - 2026-08-17

### Fixed

- The PayU transport no longer makes PHP 8.5 log "The predefined locally scoped
  `$http_response_header` variable is deprecated" on every request that compiles the file. PHP
  8.5 raises that notice at compile time for the *implicit* variable, so it appeared whether or
  not the affected branch ever ran; declaring the variable before the call makes it an ordinary
  local that the HTTP wrapper still fills in, on every supported PHP version. The same
  declaration was added to the HTTP test harness, which `failOnWarning` would otherwise fail the
  moment the CI matrix gains 8.5, and the one P24 already had is now documented as load-bearing.

## [2.76.6] - 2026-08-17

### Fixed

- Deleting one's own upload from the panel's "My files" tab no longer fails with "Invalid delete
  token". The list shipped the row's `delete_token` column to the browser and the delete button
  sent it straight back, but that column holds a bcrypt hash rather than the token, so the
  verification could never succeed. An own-file delete is now authorised by the session that owns
  the row, and the bulk "delete selected" action in the same tab is fixed by the same change.

### Security

- The "My files" listing no longer exposes any form of a file's delete capability. It previously
  emitted the stored `delete_token` for every row and rendered it into the page markup: a bcrypt
  hash on current uploads, but the live plaintext capability on any row predating the switch to
  hashed tokens.
- Guest uploads are unaffected and still require the per-upload delete token, with the existing
  failure counter and CAPTCHA gate. That gate no longer applies to owners deleting their own
  files, so unrelated failed attempts from a shared address can no longer lock an account out of
  its own panel.

## [2.76.5] - 2026-08-02

### Fixed

- Fixed the pre-configuration settings-cache fallback so the Debian installer writes to the
  private project-level `data/` directory instead of attempting to create `src/data/` inside
  the read-only source tree.
- Fresh web installations now run and verify the complete schema migration chain before creating
  the administrator, so Python and mail workers never start against the legacy baseline schema.
- The Python configuration reader now ignores commented storage-path examples instead of treating
  the Windows example as an active relative path on Linux.

## [2.76.4] - 2026-08-02

### Changed

- Simplified the shared footer into two lightweight, translated lines, removed its boxed styling
  and hard-coded theme colours, and reserved monospace typography for the version badge only.
- Replaced per-column, six-row table skeletons with one compact loading indicator so small result
  sets no longer shrink from a wide 366-pixel placeholder after loading.

## [2.76.3] - 2026-08-02

### Changed

- Adopted **TryHackX Files** as the public product name and published canonical project and author
  links for the first GitHub release.
- Added a consistent, higher-contrast site footer with automatic `2026` to current-year display,
  project/profile links and a JavaScript-assembled licensing contact action.
- Preserved existing `FILEHOST_*`, service, path, database, storage-manifest and HTTP integration
  identifiers so existing installations and deployment automation remain compatible.

## [2.76.2] - 2026-08-02

### Added

- Added a stable first-load table skeleton which covers auto-layout until real rows have settled,
  preventing long names, e-mail addresses, badges and actions from visibly shifting during load.
- Added an English, safety-allowlisted GitHub release procedure for PowerShell and Debian.
- Added source-available dual-licensing documentation, third-party notices and a contribution
  policy for commercial sublicensing.

### Changed

- Replaced the MIT project license with PolyForm Noncommercial 1.0.0. Commercial use now requires
  a separate written agreement; bundled third-party components retain their own licenses.
- Hardened Git and Docker exclusions for credentials, runtime data, dumps, caches, local tools and
  generated build output while retaining public storage-protection files and test sources.

### Fixed

- Explicit file-list loads no longer send a background ETag that could return `304 Not Modified`
  and leave an empty table behind its loader.
- Table loaders now always reveal an error or empty state, use `aria-busy`, respect reduced-motion
  preferences and leave existing rows visible during background refreshes.

## [2.76.1] - 2026-08-02

### Fixed

- Restored natural, semantic table column widths after the fixed-layout regression; data-heavy
  views now reserve space for names, dates and action controls without clipping rounded edges.
- Tightened sorting indicators, centred compact table values and made system/default group badges
  wrap consistently below the group name.
- Made protected-file password fields in collection ZIP dialogs full-size and aligned the public
  collection dialog with the shared raised modal behaviour, including Escape, backdrop and focus.
- Registered all bulk Premium and plan-limit declarative actions and guarded bulk-plan opening
  against options that have not finished loading.
- Centred the housekeeping save action on Settings -> System.

## [2.76.0] - 2026-08-01

- Fixed file-options notifications and the account password-strength handler.
- Added configurable protected-file policies for collection ZIP downloads.
- Added safe preview-and-execute bulk Premium grants with delegated permission support.
- Expanded reCAPTCHA controls to repeated protected-file password failures.
- Normalized Premium quota units, refined ad-zone assignment, and stabilized sortable tables.

## [2.75.0] - 2026-08-01

### Added

- Schema 62 adds an upload-IP index for server-side file sorting and upgrades the role-bound
  Moderator group into a complete limit profile.
- Access Control now exposes bounded username, e-mail and new-password length settings. Existing
  credentials keep wider compatibility bounds on sign-in and verification paths, so tightening a
  policy does not lock out accounts created under the previous policy.
- Every ordinary panel table now has tri-state client sorting. Shift-click adds secondary keys
  with numbered priorities when the account has `tables.multi_sort`; Files, My files and Users
  retain their data-aware sorters and now use the same three-state cycle.
- The all-files browser can sort by uploader IP when `files.see_ip` and `files.sort_all` are both
  available.

### Changed

- The Moderator system group exposes file, storage, speed, concurrency, transfer and retention
  limits in the same editor as other groups. For moderators, the enforced result combines the
  role group with the account's default/Premium group and selects the more favourable allowance.
- Active upload/download tables expose numeric progress, byte and duration values to the generic
  sorter instead of comparing their formatted labels.

### Fixed

- Upload and download kill checks no longer treat an unchanged MySQL heartbeat timestamp as a
  missing transfer row. Fast polling therefore cannot leave a completed transfer stuck or turn a
  successful download into a generic connection error.
- The third click on a sorted column now removes that criterion instead of restarting the cycle.

### Security

- Configurable identity limits are clamped to protocol and storage-safe hard ceilings. Password
  creation remains capped at bcrypt's 72-byte boundary, while login and current-password checks
  keep bounded legacy compatibility.
- Moderator limit lookup is bound to the canonical `staff_group_id` assigned by the role rather
  than accepting a substitutable plan group.

## [2.74.0] - 2026-07-29

### Added

- Schema 61 introduces renewable per-group download-transfer allowances for day, week, month or
  year periods. Authenticated usage is accounted per account and guest usage per IP, with an
  atomic reservation before any response body is sent.
- Each Premium plan can independently select which live group limits appear on its public card,
  including the transfer allowance.
- Files, My files and Users support Shift-click multi-column sorting with numbered priorities.
  The advanced mode is controlled by the new `tables.multi_sort` group permission.
- Canonical server-side identity and request-size bounds: usernames are 3–32 safe characters,
  e-mail addresses 254 characters, passwords at most 1024 characters and browser API bodies at
  most 16 MiB.

### Changed

- Unlimited downloads now use a large-chunk asynchronous stream instead of an uninterruptible
  kernel path, preserving bounded memory while making the administration kill action effective.
- Live transfer polling refreshes once per second.
- Minimal-log mode now also suppresses routine cancellation, cleanup and automatic
  post-limit-deletion messages while retaining warnings and errors.
- The Polish home-page advertising-zone name is shortened to “Góra strony (nad hero)”.

### Fixed

- Cancelling an upload now persists an opaque cancellation state long enough for the browser to
  abort its XHR, discard the partial file and show “Interrupted by an administrator”; the server
  no longer drains the remaining request body.
- Interrupted downloads settle only the bytes actually sent and never trigger completion-only
  counters, notifications or one-time-link effects.
- Password length bounds now cover every password-bearing form and verification path, including
  file/collection locks, 2FA, SMTP, reCAPTCHA and the installer.

### Security

- Transfer limits use transactional `used + reserved + requested` enforcement, preventing
  concurrent downloads from overselling the remaining allowance.
- Sort columns and directions are allowlisted at both controller and repository boundaries.

## [2.73.1] - 2026-07-29

### Changed

- Removed redundant moderator icons from the group and user lists.
- Plan and Moderator badges in the user list now use a consistently spaced vertical layout.

## [2.73.0] - 2026-07-29

### Added

- Schema 60 creates one non-deletable, permissions-only Moderator system group and assigns it
  automatically to every account whose role is `moderator`.
- Existing moderator permission profiles are merged during migration so delegated access is not
  silently lost when moving to the shared role group.

### Changed

- Moderator permissions are now configured once in **Settings → Groups**. User management only
  selects the role; plan/limits and Premium remain an independent group assignment, and both
  groups are shown in the user list.
- The Moderator group cannot be selected as a plan, account limit group or default group, and
  quota/retention fields do not appear in its editor.
- Payment-provider card traits now use a wrapping layout, preventing PayU's active and built-in
  badges from overlapping on narrow cards.

## [2.72.0] - 2026-07-29

### Added

- Schema 59 separates the account's plan/limits group from an optional moderator permission
  profile. Existing moderator assignments are preserved during migration.
- Promo codes can target every paid plan or one specific paid plan; scope is revalidated inside
  the transactional checkout reservation.
- An ignored `scripts/filehost-env.bat` convention, with a tracked example, configures both
  Windows service launchers without editing application source.

### Changed

- Buying, granting, expiring or revoking Premium changes only the plan group and no longer alters
  a moderator's delegated capabilities.
- The duplicate Moderation → Permissions view was removed. Groups remain managed in
  Settings → Groups, while the independent profile is assigned in Manage user.
- The promo-code form uses a wider aligned layout, a clear scope/plan selector and a dedicated
  enabled-state field.
- Deployment documentation now states exactly where `FILEHOST_MINIMAL_LOGS` belongs and explains
  why the durable mail worker is still required with Postfix.

## [2.71.0] - 2026-07-29

### Added

- Granular staff permissions for abuse-report visibility/resolution/file deletion, traffic,
  audit, Premium metrics/payments/subscribers/refunds and advertising refunds.
- Role boundary: administrators always hold every capability; only moderators can receive
  staff-only group grants; ordinary users can never receive them through a group.
- The installer-created owner account is permanently protected from demotion, deactivation and
  deletion, even when another administrator exists.
- Built-in Przelewy24 REST v1 integration for Premium and Advertising: Basic Auth, exact SHA-384
  signatures, transaction registration, signed callbacks, mandatory verification, status
  polling and asynchronous signed refunds.
- P24 protocol tests using an in-memory transport, including tampered callback rejection.
- `REFUNDING` payment state and durable refund correlation for asynchronous providers.
- Native Debian 12/13 setup script, systemd units and deployment guide without Docker.
- `FILEHOST_MINIMAL_LOGS=1` for uvicorn and the mail worker.

### Changed

- Premium and Advertising now render and update one shared receipt/invoice configuration.
- The administration UI explains the shared sales-document settings in both locations.
- Moderation, Premium and Advertising navigation/actions are rendered from exact capabilities
  instead of broad role checks; controllers and route policy enforce the same permissions.
- The Moderation area now includes an administrator-only Permissions tab backed by the same
  group configuration shown under Settings.
- Advertising refunds depend on advertising approval access, and Premium refunds depend on
  payment-ledger visibility.
- P24 refund acceptance leaves the payment pending until the authenticated result callback;
  entitlement revocation happens only after successful finalization.
- The web installer and all public documentation are in English.
- README, migration, storage, testing, Docker, P24, roadmap and Debian documentation were aligned
  with version 2.71.0 and schema 58.
- `.gitignore` now excludes the entire private runtime state except the intentional denial
  sentinels, along with staged configuration, environment variants and private keys.

### Fixed

- Atomic quarantine publication now retries short-lived Windows sharing violations without
  weakening collision checks or leaving a valid deletion job failed.

### Security

- Payment callbacks validate signatures before mutations, then correlate provider, session,
  order, amount and currency.
- Provider events and fulfillment/refunds are idempotent and use durable leases.
- Delegated moderation endpoints are default-deny and re-check exact permissions server-side.
- The obsolete `src/data` settings cache was removed and both Git and Docker now exclude that
  legacy runtime path, preventing encrypted local state from being published accidentally.

### Verified

- Targeted route, permission, payment, owner-protection and P24 suites passed locally.
- The full release validation result is recorded in the release handoff and CI output rather
  than hard-coded here.
- Live P24 sandbox verification remains externally blocked until merchant credentials exist.
- Docker remains intentionally uninstalled locally; its first green CI/host smoke run remains
  an open roadmap item.

## [2.70.0] - 2026-07-29

### Added

- Administrative in-app/e-mail broadcasts with standard or sanitized custom HTML.
- Public collection pagination and whole-row links opening files in a new tab.
- Advertising invoices using the Premium sales-document rules.
- Initial GitHub metadata: `.gitattributes`, `SECURITY.md` and expanded runtime ignores.

### Changed

- Responsive manual-refund actions, advertising image thumbnails and clearer fallback-retention
  text.
- Configuration-dependent container processes wait for installation.
- Przelewy24 design was compared to official OpenAPI 1.0.17.

### Fixed

- File-filter size bounds no longer reference a missing `writeSizeBound`.
- Group management no longer fails through missing pagination/validation symbols.
- “Select all” is scoped independently to files and collections.
- Invalid 2FA passwords no longer open an empty QR modal.
- Docker smoke termination targets the real upload process.

## [2.69.1] - 2026-07-29

- Added schema 58 recoverable file quarantine, atomic capture/restore/purge and operator tooling.
- Added historical migration contracts, forward-repair, snapshots and failure-injection tests.
- Added PHPStan, Ruff, pinned development dependencies and repository-policy checks.
- Split panel JavaScript/CSS/PHP into responsibility-focused modules and removed inline event
  execution under strict CSP.
- Added safe DOM renderers, protocol allowlists and extensive upload/payment/storage regressions.

## Condensed development history

### 2.57.0–2.67.0 (2026-07-22 to 2026-07-27)

- Built the advertising platform: public zones, AdSense/custom creatives, paid placements,
  packages, add-ons, review queue, cropper, rotation priorities, metrics and buyer management.
- Added granular advertising permissions and integrated ad purchases with the payment ledger.
- Expanded notification, audit, performance, concurrency, migration and deployment hardening.
- Performed repeated full-code audits and live PayU purchase tests.

### 2.47.0–2.56.0 (2026-07-20 to 2026-07-21)

- Converted PayU from a template into a native OAuth/order/callback/status integration.
- Added Premium plans, discounts, sales metrics, subscribers, purchase history and refunds.
- Added durable in-app/e-mail notifications and user/admin notification preferences.
- Moved retention into groups, expanded filtering/collections and fixed payment fulfillment,
  charts, MIME handling, interrupted uploads and multiple UI regressions.

### 2.39.0–2.46.0 (2026-07-19 to 2026-07-20)

- Added configurable storage paths and documented operating-system disk pooling.
- Introduced system/default groups with capability grants and group-controlled limits.
- Secured collection password, expiry, one-time and download-limit enforcement in Python.
- Completed administration-panel UX, language management and modal/accessibility fixes.

### 2.31.0–2.38.0 (2026-07-18 to 2026-07-19)

- Added PHPUnit/pytest suites and CI.
- Split the monolithic database and API code into repositories, controllers and declarative
  routes while retaining compatibility delegates.
- Completed panel and collection UX reviews, including nested-modal behavior and 2FA QR sizing.

### 2.20.0–2.30.0 (2026-07-17 to 2026-07-18)

- Implemented full PL/EN internationalization infrastructure and converted public pages, e-mail
  and the administration panel.
- Added shared front-end API/UI utilities and repository layers for settings, files, users,
  recovery, groups, metrics and moderation.
- Hardened secrets, CSRF, installer/public layout and scheduled cleanup.

### 2.7.0–2.19.0 (2026-07-15 to 2026-07-17)

- Added the web installer, account/file controls, groups and live panel updates.
- Added collections, one-time links, thumbnails, ShareX/API keys, REST API v1, webhooks, 2FA,
  rate limiting, Prometheus metrics and the initial Docker Compose configuration.
- Introduced configurable `public/` document root and extensive file-access protections.

### 2.6.0 (2026-01)

- Baseline application before the structured audit and refactoring series.
