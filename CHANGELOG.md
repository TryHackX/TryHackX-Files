# Changelog

Notable TryHackX Files changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

Detailed pre-2.69 development notes were condensed when all public documentation was standardized
in English for the first GitHub release.

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
  whitelisted, and switching the captcha off leaves nobody's. The static `public/.htaccess`
  policy stays as-is: it is the fallback for responses PHP did not stamp, and every page that
  can render a challenge is a PHP page.
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
