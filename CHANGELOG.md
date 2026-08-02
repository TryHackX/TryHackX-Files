# Changelog

Notable TryHackX Files changes are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

Detailed pre-2.69 development notes were condensed when all public documentation was standardized
in English for the first GitHub release.

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
