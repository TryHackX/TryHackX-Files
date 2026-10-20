#!/usr/bin/env python3
"""
TryHackX Files Upload Server - Python FastAPI
Streaming file transfer backed by MySQL
Version kept in sync with the PHP application release.
"""

import os
import sys
import re
import time

# Windows consoles may not support the symbols used in diagnostic messages.
if hasattr(sys.stdout, "reconfigure"):
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
import asyncio
import secrets
import mimetypes
import zipfile
import hashlib
import hmac
import json
import base64
import errno
import io
import signal
import subprocess
import threading
import http.client
import ipaddress
import socket
import ssl
from collections import OrderedDict
from concurrent.futures import ThreadPoolExecutor
from datetime import UTC, date, datetime, timedelta
from decimal import Decimal
from contextlib import asynccontextmanager
from pathlib import Path
from urllib.parse import unquote, quote, urlsplit

MINIMAL_LOGS = os.getenv("FILEHOST_MINIMAL_LOGS", "").strip().lower() in {
    "1", "true", "yes", "on",
}


def info_log(message: str) -> None:
    """Write routine operational output unless minimal logging is enabled."""
    if not MINIMAL_LOGS:
        print(message, flush=True)


# Thumbnail engine: Pillow for images, a bundled static ffmpeg (imageio-ffmpeg)
# for a video poster frame. Imported lazily-tolerant so the server still boots if either is
# missing — the /thumb endpoint just reports "unavailable" and the UI falls back to icons.
try:
    from PIL import Image, ImageOps
    import imageio_ffmpeg
    THUMBS_AVAILABLE = True
except Exception as _thumb_err:  # pragma: no cover
    THUMBS_AVAILABLE = False
    print(f"Warning: thumbnails disabled (Pillow/imageio-ffmpeg unavailable): {_thumb_err}")

APP_DIR = Path(__file__).parent.absolute()
# config.local.php moved out of the web root into config/ (St1). The server stays in the
# project root, so the config is one dir over.
CONFIG_FILE = APP_DIR / "config" / "config.local.php"
SERVER_VERSION = "2.81.0"


def _decode_php_single_quoted(value: str) -> str:
    """Decode PHP's two escapes inside a single-quoted literal: \\ and \'."""
    output = []
    index = 0
    while index < len(value):
        char = value[index]
        if char == "\\" and index + 1 < len(value) and value[index + 1] in ("\\", "'"):
            output.append(value[index + 1])
            index += 2
            continue
        output.append(char)
        index += 1
    return "".join(output)


def _php_constant(name: str) -> str:
    """Read one string constant out of config.local.php (no PHP execution).

    The installer writes single-quoted literals. A hand-added constant such as
    TRUSTED_PROXIES is often written with double quotes, so a plain double-quoted literal
    (no escapes, no `$` interpolation) is read too; anything fancier is ignored.
    """
    try:
        if CONFIG_FILE.exists():
            match = re.search(
                r"(?m)^[ \t]*define\s*\(\s*['\"]" + re.escape(name)
                + r"['\"]\s*,\s*(?:'((?:\\.|[^'\\])*)'|\"([^\"\\$]*)\")\s*\)",
                CONFIG_FILE.read_text(encoding="utf-8", errors="replace"),
            )
            if match:
                if match.group(1) is not None:
                    return _decode_php_single_quoted(match.group(1)).strip()
                return match.group(2).strip()
    except Exception:
        pass
    return ""


def _storage_root(constant: str, default: Path) -> Path:
    """
    Mirror of the storage-path logic in src/config.php: config.local.php may move uploads
    (or generated data) onto another disk via UPLOADS_PATH / DATA_PATH. An absolute path is
    used as-is, a relative one resolves against the project root. Both sides MUST agree —
    otherwise PHP and this server would look for the same file in different places.
    """
    raw = _php_constant(constant).replace("\\", "/").rstrip("/")
    if not raw:
        return default
    path = Path(raw)
    return path if path.is_absolute() else (APP_DIR / raw)


UPLOADS_DIR = _storage_root("UPLOADS_PATH", APP_DIR / "uploads")
DATA_DIR = _storage_root("DATA_PATH", APP_DIR / "data")
STAGING_DIR = UPLOADS_DIR / ".staging"
STORAGE_MANIFEST_NAME = ".filehost-storage-v1.json"
SUPPORTED_SCHEMA_VERSION = 64
try:
    DOWNLOAD_LEASE_SECONDS = max(
        5,
        min(600, int(os.getenv("FILEHOST_DOWNLOAD_LEASE_SECONDS", "120"))),
    )
except (TypeError, ValueError):
    DOWNLOAD_LEASE_SECONDS = 120
# A live transfer renews its lease several times per lease period, so a short configured
# lease (the CI smoke uses 5 s) cannot expire under a healthy download between two renewals.
DOWNLOAD_HEARTBEAT_SECONDS = max(1.0, min(15.0, DOWNLOAD_LEASE_SECONDS / 3))
# How often a running download looks for an administrator's kill (one indexed read).
KILL_CHECK_SECONDS = 1.0
try:
    CI_PREBODY_DELAY_SECONDS = (
        max(
            0,
            min(5_000, int(os.getenv("FILEHOST_CI_PREBODY_DELAY_MS", "0"))),
        ) / 1000
        if os.getenv("FILEHOST_CI_SMOKE") == "1"
        else 0
    )
except (TypeError, ValueError):
    CI_PREBODY_DELAY_SECONDS = 0
INSTANCE_ID = secrets.token_hex(16)

# Generated thumbnails live outside uploads/ so they never collide with the stored file
# (download/collection code globs the file's own dir and would otherwise pick a thumb).
THUMBS_DIR = DATA_DIR / "thumbs"
THUMB_MAX_DEFAULT = 400  # longest edge in px, when the panel setting is unset/out of range
THUMB_GENERATION_TIMEOUT = 30
# Two ffmpeg attempts (seek 1 s, then 0 s) must fit inside the worker's own deadline, so the
# outer kill never lands while a second ffmpeg is still running.
THUMB_FFMPEG_ATTEMPT_TIMEOUT = 12
THUMB_NEGATIVE_TTL = 300
THUMB_MAX_OUTPUT_BYTES = 16 * 1024 * 1024
try:
    THUMB_MAX_INPUT_BYTES = max(
        1024 * 1024,
        min(1024 * 1024 * 1024, int(os.getenv("FILEHOST_THUMB_MAX_INPUT_BYTES", str(256 * 1024 * 1024)))),
    )
except (TypeError, ValueError):
    THUMB_MAX_INPUT_BYTES = 256 * 1024 * 1024
try:
    THUMB_MAX_SOURCE_PIXELS = max(
        1_000_000,
        min(100_000_000, int(os.getenv("FILEHOST_THUMB_MAX_SOURCE_PIXELS", "40000000"))),
    )
except (TypeError, ValueError):
    THUMB_MAX_SOURCE_PIXELS = 40_000_000
try:
    THUMB_WORKERS = max(1, min(4, int(os.getenv("FILEHOST_THUMB_WORKERS", "2"))))
except (TypeError, ValueError):
    THUMB_WORKERS = 2
thumb_semaphore = asyncio.Semaphore(THUMB_WORKERS)
thumb_locks = {}
thumb_negative_cache = {}
if THUMBS_AVAILABLE:
    Image.MAX_IMAGE_PIXELS = THUMB_MAX_SOURCE_PIXELS

PORT = 8001
# Bind to loopback only: all client traffic is proxied by Apache to 127.0.0.1:8001,
# so the upload server never needs to be reachable directly from the network.
HOST = "127.0.0.1"


def _env_number(name: str, default: float, low: float, high: float) -> float:
    try:
        return max(low, min(high, float(os.getenv(name, str(default)))))
    except (TypeError, ValueError):
        return default


# A request never waits forever for a database connection: a saturated pool answers 503
# instead of parking every coroutine behind it.
DB_ACQUIRE_TIMEOUT = _env_number("FILEHOST_DB_ACQUIRE_TIMEOUT", 10.0, 1.0, 120.0)
# The readiness probe (schema check plus a staging write) is cached this long between
# requests; /ready itself always probes afresh.
READINESS_CACHE_SECONDS = 5.0
# A webhook delivery gets this long in total, name lookup included, however slowly the
# receiver answers.
WEBHOOK_DELIVERY_DEADLINE = 15.0
WEBHOOK_CONCURRENCY = 5
# Fairness: one account holds at most this many of the delivery slots, and each webhook has
# at most one delivery in flight, so a receiver that never answers delays only its owner.
WEBHOOK_PER_ACCOUNT = 2
WEBHOOK_CLAIM_CANDIDATES = 50
WEBHOOK_START_DELAY_SECONDS = 5.0
WEBHOOK_IDLE_POLL_SECONDS = 5.0
# A hook that has failed every attempt for a day (and at least this many in a row) is switched
# off, with a last_status saying so; the owner can add it again once the receiver is back.
WEBHOOK_DISABLE_AFTER_FAILURES = 20
WEBHOOK_DISABLE_AFTER_SECONDS = 86400
# The QR renderer only ever needs a link or an otpauth:// URI.
QR_POST_MAX_BYTES = 8 * 1024
# An otpauth URI carries the site name twice next to a username of up to 50 characters, so
# 512 bytes leave room for a long site name. One render of that size costs about 30 ms of
# pure-Python CPU; the old 1024-character ceiling cost about 50 ms, and in UTF-8 more.
QR_DATA_MAX_BYTES = 512
# Rendering never runs on the event loop: one worker thread draws the codes, and a burst
# beyond a few waiting renders is answered 503 rather than queued behind them.
QR_MAX_PENDING = 4
QR_CACHE_ENTRIES = 64
# Stored names must fit one filesystem path component (255 bytes on ext4/xfs) with room for
# the `.blocked` / `.upload` suffixes.
FILENAME_MAX_BYTES = 200

# Blocking work runs on separate pools so one slow kind cannot starve another: file I/O of
# every transfer (aiofiles, manifests, bcrypt) uses the default executor, enlarged at startup;
# webhook deliveries, thumbnail workers and the maintenance run each get their own threads.
IO_THREADS = int(_env_number("FILEHOST_IO_THREADS", 64, 8, 256))
WEBHOOK_EXECUTOR = ThreadPoolExecutor(
    max_workers=WEBHOOK_CONCURRENCY, thread_name_prefix="filehost-webhook"
)
THUMB_EXECUTOR = ThreadPoolExecutor(
    max_workers=THUMB_WORKERS, thread_name_prefix="filehost-thumb"
)
MAINTENANCE_EXECUTOR = ThreadPoolExecutor(
    max_workers=1, thread_name_prefix="filehost-maintenance"
)
# One thread on purpose: segno is pure Python and holds the GIL, so more render threads
# would only take more turns away from the event loop.
QR_EXECUTOR = ThreadPoolExecutor(max_workers=1, thread_name_prefix="filehost-qr")

BLOCKED_EXTENSIONS = frozenset({
    'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
    'exe', 'bat', 'cmd', 'sh', 'bash', 'cgi', 'pl', 'py', 'rb',
    'asp', 'aspx', 'jsp', 'jspx', 'cfm',
    'html', 'htm', 'xhtml', 'svg', 'shtml', 'xml',
    'htaccess', 'htpasswd'
})

def load_php_config() -> dict:
    """Load database configuration from config.local.php."""
    config = {
        'host': 'localhost',
        'user': '',
        'password': '',
        'database': '',
        'prefix': ''
    }

    if not CONFIG_FILE.exists():
        return config

    try:
        # Decode as UTF-8 like _php_constant() does, never the locale code page: PHP writes
        # this file as bytes, and on a Windows dev box the ANSI page would mangle any non-ASCII
        # database password into a silent authentication failure.
        content = CONFIG_FILE.read_text(encoding="utf-8", errors="replace")

        patterns = {
            'host': r"define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*'((?:\\.|[^'\\])*)'\s*\)",
            'user': r"define\s*\(\s*['\"]DB_USER['\"]\s*,\s*'((?:\\.|[^'\\])*)'\s*\)",
            'password': r"define\s*\(\s*['\"]DB_PASS['\"]\s*,\s*'((?:\\.|[^'\\])*)'\s*\)",
            'database': r"define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*'((?:\\.|[^'\\])*)'\s*\)",
            'prefix': r"define\s*\(\s*['\"]DB_PREFIX['\"]\s*,\s*'((?:\\.|[^'\\])*)'\s*\)",
        }

        for key, pattern in patterns.items():
            match = re.search(pattern, content)
            if match:
                config[key] = _decode_php_single_quoted(match.group(1))

        return config

    except Exception as e:
        print(f"Configuration load failed: {e}")
        return config

try:
    import uvicorn
    from fastapi import FastAPI, Request, HTTPException, Header, BackgroundTasks
    from fastapi.responses import PlainTextResponse, JSONResponse, StreamingResponse, FileResponse
    from starlette.concurrency import iterate_in_threadpool
    from starlette.requests import ClientDisconnect
    import aiofiles
    import aiomysql
    import bcrypt
    from fastapi.responses import Response
except ImportError as e:
    print("=" * 60)
    print(f"ERROR: missing Python dependency: {e}")
    print("Run scripts/start-upload-server.bat or scripts/install-debian.sh.")
    print("=" * 60)
    sys.exit(1)

async def _emit_completed_download_side_effects(context: dict) -> None:
    """Publish best-effort observability only after the durable completion commit."""
    completion = context.get("completion")
    if not isinstance(completion, dict):
        return
    kind = completion.get("kind")
    resource_id = str(completion.get("resource_id") or "")
    if kind == "file" and resource_id:
        await notify_file_download(
            resource_id,
            completion.get("viewer_id"),
            completion.get("owner_id"),
            completion.get("name"),
        )
        await enqueue_webhook_event(
            completion.get("owner_id"),
            "download",
            {
                "file": {
                    "id": resource_id,
                    "name": completion.get("name"),
                    "mime": completion.get("mime"),
                    "size": completion.get("size"),
                }
            },
        )
        METRICS["downloads"] += 1
    elif kind == "collection" and resource_id:
        await notify_collection_download(
            resource_id,
            completion.get("viewer_id"),
            completion.get("owner_id"),
            completion.get("name"),
        )
        METRICS["collection_zips"] += 1


async def _finalize_download_context(
    context: dict,
    terminal_state: str,
    sent_bytes: int,
) -> None:
    reservation_id = str(context.get("reservation_id") or "")
    try:
        transitioned = await finish_download_reservation(
            reservation_id,
            terminal_state,
            sent_bytes,
        )
    except Exception as exc:
        if terminal_state != "completed":
            raise
        # The download could not be counted. Settle it as released with the bytes that did
        # leave, now: the allowance is charged and the live row freed, instead of the reaper
        # releasing it minutes later with the last heartbeat's count (0 for a short transfer).
        print(
            f"⚠️  Download {reservation_id[:12]} could not be completed "
            f"({type(exc).__name__}: {exc}); settling it as released"
        )
        await finish_download_reservation(reservation_id, "released", sent_bytes)
        return
    if terminal_state == "completed" and transitioned:
        await _emit_completed_download_side_effects(context)


class ActualTransferAccountingMiddleware:
    """Record response body bytes accepted by ASGI instead of a promised file size."""

    def __init__(self, app):
        self.app = app

    async def __call__(self, scope, receive, send):
        if scope.get("type") != "http":
            await self.app(scope, receive, send)
            return

        sent_bytes = 0
        response_status = 500
        response_content_length = None
        response_body_finished = False
        heartbeat_task = None
        reservation_started = False
        finished_normally = False

        async def renew_lease_periodically():
            # Renewal runs on its own timer rather than on sends: a client that drains slowly
            # can hold a single send for longer than the whole lease, and a live transfer must
            # not look abandoned to the reaper while that happens.
            while True:
                await asyncio.sleep(DOWNLOAD_HEARTBEAT_SECONDS)
                context = scope.get("filehost.traffic") or {}
                active_id = safe_int(context.get("active_id"), 0)
                if active_id:
                    await heartbeat_download(
                        active_id,
                        str(context.get("reservation_id") or ""),
                        sent_bytes,
                    )

        async def counted_send(message):
            nonlocal sent_bytes, response_status, response_content_length
            nonlocal response_body_finished, heartbeat_task, reservation_started
            if message.get("type") == "http.response.start":
                response_status = safe_int(message.get("status"), 500)
                for raw_name, raw_value in message.get("headers") or []:
                    if raw_name.lower() == b"content-length":
                        try:
                            response_content_length = max(0, int(raw_value))
                        except (TypeError, ValueError):
                            response_content_length = None
                        break
            await send(message)
            if (
                message.get("type") == "http.response.body"
                and 200 <= response_status < 400
            ):
                sent_bytes += len(message.get("body") or b"")
                if not message.get("more_body", False):
                    response_body_finished = True
            elif (
                message.get("type") == "http.response.pathsend"
                and 200 <= response_status < 400
            ):
                sent_bytes += max(0, safe_int(response_content_length, 0))
                response_body_finished = True
            context = scope.get("filehost.traffic") or {}
            active_id = safe_int(context.get("active_id"), 0)
            reservation_id = str(context.get("reservation_id") or "")
            if (
                reservation_id
                and not reservation_started
                and message.get("type") == "http.response.body"
                and len(message.get("body") or b"") > 0
                and 200 <= response_status < 400
            ):
                await mark_download_reservation_started(reservation_id)
                reservation_started = True
            if active_id and heartbeat_task is None:
                await heartbeat_download(active_id, reservation_id, sent_bytes)
                heartbeat_task = asyncio.create_task(renew_lease_periodically())

        try:
            await self.app(scope, receive, counted_send)
            finished_normally = True
        finally:
            if heartbeat_task is not None:
                # No need to wait for it: a renewal racing the finalization below is a no-op,
                # because both guard on the reservation still being reserved/started.
                heartbeat_task.cancel()
            context = scope.get("filehost.traffic")
            if context and sent_bytes > 0 and 200 <= response_status < 400:
                await log_traffic(
                    str(context.get("ip") or "unknown"),
                    sent_bytes,
                    "download",
                    context.get("file_id"),
                    context.get("user_id"),
                )
            reservation_id = str((context or {}).get("reservation_id") or "")
            if reservation_id:
                interrupted = bool(
                    (context.get("transfer_state") or {}).get("interrupted")
                )
                if response_content_length is not None:
                    # Every promised byte was handed to the server: that IS completion. The
                    # closing empty message is not required. Apache proxies these routes
                    # without persistent connections, so it hangs up the moment Content-Length
                    # is satisfied, and that disconnect cancels the stream before the closing
                    # message, usually while the reader is still finding end-of-file. Waiting
                    # for that message recorded nearly every finished download as "released":
                    # counters never moved and download caps were never reached.
                    body_complete = sent_bytes == response_content_length
                else:
                    # Chunked (the collection ZIP): only the terminating chunk tells the
                    # client the body is over, so nobody can hang up before it is sent.
                    body_complete = finished_normally and response_body_finished
                response_complete = (
                    200 <= response_status < 400
                    and not interrupted
                    and body_complete
                )
                terminal_state = (
                    "completed" if response_complete else "released"
                )
                try:
                    await asyncio.shield(
                        _finalize_download_context(
                            context,
                            terminal_state,
                            sent_bytes,
                        )
                    )
                except Exception as exc:
                    print(
                        f"⚠️  Download reservation finalization failed "
                        f"({reservation_id[:12]}): {type(exc).__name__}"
                    )


@asynccontextmanager
async def app_lifespan(_app):
    await startup()
    try:
        yield
    finally:
        await shutdown()


app = FastAPI(
    title="TryHackX Files Upload Server",
    docs_url=None,
    redoc_url=None,
    lifespan=app_lifespan,
)
app.add_middleware(ActualTransferAccountingMiddleware)
# No CORS middleware on purpose. Every browser reaches this server same-origin through the
# Apache proxy, so a cross-origin policy grants nothing legitimate; a wildcard one let any
# website drive uploads from its visitors' browsers and read back the delete token.

db_pool = None
db_config_cache = None
db_config_mtime = 0
db_pool_lock = asyncio.Lock()
# (pool object, monotonic time) of the last successful readiness probe.
readiness_cache = {"pool": None, "checked_at": 0.0}


def _release_if_acquired(pool):
    """Done-callback that hands back a connection an abandoned acquire still obtained."""

    def callback(task):
        if not task.cancelled() and task.exception() is None:
            pool.release(task.result())

    return callback


async def acquire_bounded(pool, wait_seconds: float | None = None):
    """Take a connection from an aiomysql pool, or answer 503 instead of waiting forever."""
    limit = DB_ACQUIRE_TIMEOUT if wait_seconds is None else wait_seconds
    task = asyncio.ensure_future(pool.acquire())
    try:
        done, _ = await asyncio.wait({task}, timeout=limit)
    except BaseException:
        task.add_done_callback(_release_if_acquired(pool))
        task.cancel()
        raise
    if task in done:
        return task.result()
    task.add_done_callback(_release_if_acquired(pool))
    task.cancel()
    raise HTTPException(status_code=503, detail="Database is busy")


class _BoundedAcquire:
    """`await pool.acquire()` and `async with pool.acquire()` with a deadline."""

    __slots__ = ("_pool", "_conn")

    def __init__(self, pool):
        self._pool = pool
        self._conn = None

    def __await__(self):
        return acquire_bounded(self._pool).__await__()

    async def __aenter__(self):
        self._conn = await acquire_bounded(self._pool)
        return self._conn

    async def __aexit__(self, exc_type, exc, tb):
        conn, self._conn = self._conn, None
        if conn is not None:
            self._pool.release(conn)
        return False


class BoundedPool:
    """The aiomysql pool every request uses, with a bounded wait for a free connection.

    The pool is small (FILEHOST_DB_POOL_MAX, default 20) and aiomysql waits for a free
    connection without any limit. Combined with code that holds one connection while asking
    for another, a burst of requests used to park forever; now the waiting ones give up.
    """

    def __init__(self, pool):
        self._pool = pool

    def acquire(self):
        return _BoundedAcquire(self._pool)

    def release(self, conn):
        return self._pool.release(conn)

    def close(self):
        self._pool.close()

    async def wait_closed(self):
        await self._pool.wait_closed()

    def __getattr__(self, name):
        return getattr(self._pool, name)


class StorageNotWritable(HTTPException):
    """The upload storage probe failed: 503 for every caller, with the cause kept for /ready."""

    def __init__(self, problem: dict):
        super().__init__(status_code=503, detail="Upload storage is not writable")
        self.problem = problem


_STORAGE_HINTS = {
    "EROFS": (
        "The upload service may write only the paths its systemd unit allows (ReadWritePaths=). "
        "Re-render the unit with the installer (FILEHOST_UNITS_ONLY=1 bash scripts/install-debian.sh) "
        "so it opens this path, then restart filehost-upload."
    ),
    "EACCES": "The upload service's account cannot write here: check the owner and mode of the directory.",
    "EPERM": "The upload service's account cannot write here: check the owner and mode of the directory.",
    "ENOENT": "A directory on this path does not exist.",
    "ENOTDIR": "Part of this path is a file, not a directory.",
    "ENOSPC": "The disk is full.",
    "EDQUOT": "The disk quota of the upload service's account is used up.",
}


def _storage_problem(exc: Exception, path: Path) -> dict:
    """Which path failed the readiness probe, with what error, and what usually fixes it."""
    code = getattr(exc, "errno", None)
    name = errno.errorcode.get(code, "") if isinstance(code, int) else ""
    return {
        "path": str(getattr(exc, "filename", None) or path),
        "error": name or type(exc).__name__,
        "reason": str(getattr(exc, "strerror", None) or exc),
        "hint": _STORAGE_HINTS.get(name, ""),
    }


async def require_storage_ready(force: bool = False):
    """Fail closed unless DB schema and same-filesystem upload staging are writable.

    A successful probe is reused for READINESS_CACHE_SECONDS: it is three queries plus a file
    created and removed in staging, and it guards every upload step and every download.
    """
    pool = await get_db_pool()
    if not pool:
        raise HTTPException(status_code=503, detail="Database unavailable")
    if (
        not force
        and readiness_cache["pool"] is pool
        and time.monotonic() - readiness_cache["checked_at"] < READINESS_CACHE_SECONDS
    ):
        return pool
    readiness_cache["pool"] = None

    try:
        prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"SELECT `setting_key`, `setting_value` FROM `{prefix}settings` "
                    f"WHERE `setting_key` IN ('schema_version', 'schema_ready')"
                )
                schema = {str(key): value for key, value in await cur.fetchall()}
                schema_version = int(schema.get("schema_version", 0))
                if schema_version != SUPPORTED_SCHEMA_VERSION:
                    raise RuntimeError(
                        f"unsupported schema {schema_version}; expected {SUPPORTED_SCHEMA_VERSION}"
                    )
                if str(schema.get("schema_ready", "0")) != "1":
                    raise RuntimeError("schema verification marker is not ready")
                # Probe every security-sensitive column used by the byte-serving pipeline.
                await cur.execute(
                    f"SELECT `password_hash`, `expires_at`, `max_downloads`, `one_time`, "
                    f"`consumed_at`, `consume_reservation_id`, `on_limit_action` "
                    f"FROM `{prefix}files` LIMIT 0"
                )
                await cur.execute(
                    f"SELECT `reservation_id`, `effect_type`, `resource_type`, "
                    f"`resource_id`, `applied_at` "
                    f"FROM `{prefix}download_reservation_effects` LIMIT 0"
                )
    except HTTPException:
        raise
    except Exception as exc:
        print(f"Readiness database check failed: {type(exc).__name__}: {exc}")
        raise HTTPException(status_code=503, detail="Database schema is not ready")

    probe = None
    target = STAGING_DIR
    try:
        STAGING_DIR.mkdir(parents=True, exist_ok=True)
        target = THUMBS_DIR
        THUMBS_DIR.mkdir(parents=True, exist_ok=True)
        probe = target = STAGING_DIR / f".ready-{os.getpid()}-{secrets.token_hex(8)}"
        descriptor = os.open(probe, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        os.close(descriptor)
        probe.unlink()
    except Exception as exc:
        if probe is not None:
            try:
                probe.unlink(missing_ok=True)
            except Exception:
                pass
        problem = _storage_problem(exc, target)
        print(
            f"Readiness storage check failed: {problem['error']} ({problem['reason']}) "
            f"at {problem['path']}"
        )
        # Public routes answer the generic 503; only /ready (never proxied) shows the cause.
        raise StorageNotWritable(problem)

    readiness_cache["pool"] = pool
    readiness_cache["checked_at"] = time.monotonic()
    return pool

async def _get_db_pool_unlocked():
    """Return the MySQL pool, reloading configuration when it changes."""
    global db_pool, db_config_cache, db_config_mtime

    try:
        current_mtime = CONFIG_FILE.stat().st_mtime if CONFIG_FILE.exists() else 0
    except OSError:
        current_mtime = 0

    if current_mtime != db_config_mtime:
        db_config_mtime = current_mtime
        new_config = load_php_config()

        if db_config_cache != new_config and db_pool is not None:
            info_log("Configuration changed; reconnecting to the database...")
            db_pool.close()
            await db_pool.wait_closed()
            db_pool = None

        db_config_cache = new_config

    if not db_config_cache or not db_config_cache.get('database'):
        return None

    if db_pool is None:
        try:
            db_pool = BoundedPool(await aiomysql.create_pool(
                host=db_config_cache['host'],
                user=db_config_cache['user'],
                password=db_config_cache['password'],
                db=db_config_cache['database'],
                charset='utf8mb4',
                autocommit=True,
                minsize=1,
                maxsize=max(
                    5,
                    min(100, safe_int(os.getenv("FILEHOST_DB_POOL_MAX"), 20)),
                )
            ))
            info_log(f"Connected to database: {db_config_cache['host']}/{db_config_cache['database']}")
        except HTTPException:
            raise
        except Exception as e:
            print(f"Database connection failed: {e}")
            return None

    return db_pool


async def get_db_pool():
    """Serialize configuration reloads and pool creation across concurrent requests."""
    async with db_pool_lock:
        return await _get_db_pool_unlocked()

def generate_file_id() -> str:
    """A new file id: the upload time in hex plus 32 random bits."""
    timestamp = hex(int(time.time()))[2:]
    random_part = secrets.token_hex(4)
    return f"{timestamp}{random_part}"

def generate_delete_token() -> str:
    """A new delete token (only its bcrypt hash is stored)."""
    return secrets.token_hex(16)

def safe_int(v, default: int = 0) -> int:
    """Safely convert value to int, return default on failure"""
    try:
        return int(v) if v else default
    except (ValueError, TypeError):
        return default


def build_lock_name(suffix: str) -> str:
    """Build an advisory-lock name scoped to this installation.

    `GET_LOCK()` names are server-wide, not per-schema, so two installations sharing one
    MySQL/MariaDB server would block each other on any name that is a bare constant. Every
    lock on the PHP side already namespaces itself by database and table prefix — see
    Database::migrate() and FileManager::processDeletionQueue() — and this is the same rule
    for the sidecar. Truncated to keep the whole name inside MySQL's 64-character limit.
    """
    config = db_config_cache or {}
    identity = f"{config.get('database', '')}:{config.get('prefix', '')}"
    return f"fh:{hashlib.sha1(identity.encode()).hexdigest()[:16]}:{suffix}"


def _php_trim(value: str) -> str:
    """PHP's trim(): exactly these six characters, not every Unicode space."""
    return value.strip(" \t\n\r\x00\x0b")


def _is_ip_literal(value: str) -> bool:
    """PHP's FILTER_VALIDATE_IP without flags: an IPv4 or IPv6 literal, no zone index."""
    if not value or "%" in value:
        return False
    try:
        ipaddress.ip_address(value)
    except ValueError:
        return False
    return True


def resolve_client_ip(server: dict, trusted_proxies, trusted_header: str = "") -> str:
    """
    Python mirror of filehostResolveClientIp() in src/includes/ClientIp.php, which stays the
    source of truth (tests/fixtures/client-ip.json holds the cases both sides must pass).

    `server` uses PHP's $_SERVER names. Without a configured proxy the peer (REMOTE_ADDR) is
    the answer. Behind one, X-Forwarded-For is read from the right past our own proxies, or
    the single header the operator named is believed. Proxies are compared, and the answer
    returned, exactly as written: PHP binds tokens to this string, so it must be byte-equal.
    """
    remote = _php_trim(str(server.get("REMOTE_ADDR") or ""))
    if remote == "":
        return "unknown"
    trusted = [entry for entry in (_php_trim(str(item)) for item in trusted_proxies) if entry]
    if not trusted or remote not in trusted:
        return remote

    header = _php_trim(trusted_header).lower()
    if header in ("", "x-forwarded-for"):
        chain = [_php_trim(part) for part in str(server.get("HTTP_X_FORWARDED_FOR") or "").split(",")]
        for candidate in reversed(chain):
            if candidate == "":
                continue
            if not _is_ip_literal(candidate):
                # Garbage where our proxy should have written an address: do not guess.
                return remote
            if candidate not in trusted:
                return candidate
        return remote

    server_key = {
        "cf-connecting-ip": "HTTP_CF_CONNECTING_IP",
        "x-real-ip": "HTTP_X_REAL_IP",
    }.get(header)
    if server_key is None:
        return remote
    value = _php_trim(str(server.get(server_key) or ""))
    return value if _is_ip_literal(value) else remote


def throttle_key(ip: str) -> str:
    """
    Python mirror of filehostThrottleKey() in src/includes/ClientIp.php: the key every
    per-guest counter, cap, quota subject and ban is kept under, on both sides.

    IPv4 counts per address. IPv6 counts per /64, the block one customer is routinely handed:
    counting single addresses gave one /64 2^64 fresh budgets. An IPv4-mapped IPv6 address is
    the IPv4 one, and anything that is not an address comes back unchanged, as in PHP.
    """
    if not isinstance(ip, str) or "%" in ip:
        return ip
    try:
        address = ipaddress.ip_address(ip)
    except ValueError:
        return ip
    if address.version == 4:
        return ip
    if address.ipv4_mapped is not None:
        return str(address.ipv4_mapped)
    return f"{ipaddress.IPv6Address((int(address) >> 64) << 64).compressed}/64"


def _same_client_condition(column: str, ip: str) -> tuple[str, tuple]:
    """SQL that matches every stored address counted under `ip`'s throttle key.

    Rows keep the exact address, which is what the live list shows. IPv6 rows are matched by
    their /64 network on the packed form, so the spelling of an address cannot matter; a row
    that already stores the key itself matches too. INET6_ATON() only ever sees valid IPv6
    strings: on anything else ("unknown", a /64 key) it raises a warning per row.
    """
    key = throttle_key(ip)
    try:
        address = ipaddress.ip_address(ip) if "%" not in ip else None
    except ValueError:
        address = None
    if address is None or address.version == 4 or address.ipv4_mapped is not None:
        values = tuple(dict.fromkeys((ip, key)))
        return f"`{column}` IN ({', '.join(['%s'] * len(values))})", values
    network = (int(address) >> 64).to_bytes(8, "big").hex().upper()
    return (
        f"(`{column}` IN (%s, %s) OR CASE WHEN IS_IPV6(`{column}`) "
        f"THEN HEX(LEFT(INET6_ATON(`{column}`), 8)) END = %s)",
        (ip, key, network),
    )


def _download_lock_name(ip: str) -> str:
    """The advisory lock that serializes one client's concurrency counts (per throttle key)."""
    return build_lock_name(
        "download:" + hashlib.sha256(throttle_key(ip).encode()).hexdigest()[:24]
    )


_trusted_proxy_cache: dict = {"source": None, "proxies": (), "header": ""}


def _trusted_proxy_settings() -> tuple[tuple[str, ...], str]:
    """TRUSTED_PROXIES and TRUSTED_PROXY_HEADER from config.local.php, as PHP reads them.

    Re-read whenever the file changes (one stat() per request), so a proxy the operator adds
    applies here at the same moment it applies to PHP.
    """
    try:
        source = (str(CONFIG_FILE), CONFIG_FILE.stat().st_mtime_ns)
    except OSError:
        source = (str(CONFIG_FILE), None)
    if _trusted_proxy_cache["source"] != source:
        proxies = _php_constant("TRUSTED_PROXIES")
        _trusted_proxy_cache.update(
            source=source,
            proxies=tuple(proxies.split(",")) if proxies else (),
            header=_php_constant("TRUSTED_PROXY_HEADER"),
        )
    return _trusted_proxy_cache["proxies"], _trusted_proxy_cache["header"]


def request_client_ip(request: Request) -> str:
    """
    The client address exactly as PHP's getClientIP() names it for the same visitor.

    Every request reaches this server through the loopback Apache hop, and mod_proxy APPENDS
    the address it accepted the connection from to X-Forwarded-For. So the rightmost entry is
    what PHP sees as REMOTE_ADDR and everything left of it is the X-Forwarded-For PHP
    received; from there PHP's own rules apply (resolve_client_ip). uvicorn does not rewrite
    the client first: its one-hop rule named the front proxy instead of the visitor whenever
    TRUSTED_PROXIES was set, and every token PHP had bound to the visitor was refused here.
    """
    peer = request.client.host if request.client else ""
    try:
        peer_is_loopback = ipaddress.ip_address(peer).is_loopback
    except ValueError:
        return peer or "unknown"
    forwarded = request.headers.getlist("x-forwarded-for")
    if not peer_is_loopback or not forwarded:
        # Not proxied: nothing the caller says about other addresses is believed.
        return peer

    chain = ", ".join(forwarded).split(",")
    remote = _php_trim(chain[-1])
    if not _is_ip_literal(remote):
        return peer
    server = {
        "REMOTE_ADDR": remote,
        "HTTP_X_FORWARDED_FOR": ",".join(chain[:-1]),
        "HTTP_CF_CONNECTING_IP": ", ".join(request.headers.getlist("cf-connecting-ip")),
        "HTTP_X_REAL_IP": ", ".join(request.headers.getlist("x-real-ip")),
    }
    proxies, header = _trusted_proxy_settings()
    return resolve_client_ip(server, proxies, header)


def request_is_direct_loopback(request: Request) -> bool:
    """True only for a local caller talking to this port directly, never through Apache.

    Every request Apache proxies carries X-Forwarded-For, so its absence plus a loopback
    peer identifies a local process (the installer, a health check, a Prometheus scrape).
    """
    if "x-forwarded-for" in request.headers:
        return False
    peer = request.client.host if request.client else ""
    try:
        return ipaddress.ip_address(peer).is_loopback
    except ValueError:
        return False


# Cache for dynamic settings
settings_cache = {
    'blocked_extensions': None,
    'max_file_size': 5 * 1024 * 1024 * 1024,  # Default 5GB
    'max_upload_folder_mb': 0,  # 0 = unlimited
    # Transfer limits live on the groups only (the flat limit_* settings are a legacy mirror
    # the panel keeps for older readers and are deliberately not loaded here).
    'groups': {},               # A8: {group_id: {limit_upload, limit_download, max_file_size_mb, ...}}
    'default_group_id': None,   # id of the group registered users fall back to (group_id NULL)
    'thumb_max': THUMB_MAX_DEFAULT,  # longest edge of a generated thumbnail, in px
    'collection_counts_files': False,  # pt 1: does a collection ZIP count as a download of each member?
    'collection_protected_file_policy': 'prompt_skip',
    # Built-in maintenance scheduler (see cleanup_scheduler). Off unless the operator says
    # otherwise, so an installation with a real cron entry does not end up with two.
    'cron_enabled': False,
    'cron_interval': 15,        # minutes between runs
    'cron_php_binary': '',      # explicit CLI path; empty = whatever `php` resolves to
    'checked_at': 0
}

async def update_settings():
    """Update settings from database"""
    global settings_cache
    
    if time.time() - settings_cache['checked_at'] < 60 and settings_cache['blocked_extensions'] is not None:
        return

    pool = await get_db_pool()
    if not pool:
        raise RuntimeError("Database unavailable while loading settings")

    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}settings"

        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                # Fetch blocked extensions
                await cur.execute(f"SELECT `setting_value` FROM `{table}` WHERE `setting_key` = 'blocked_extensions'")
                row = await cur.fetchone()
                if row and row[0]:
                    exts = {e.strip().lower() for e in row[0].split(',') if e.strip()}
                    settings_cache['blocked_extensions'] = exts
                else:
                    settings_cache['blocked_extensions'] = set(BLOCKED_EXTENSIONS)

                # Fetch max file size
                await cur.execute(f"SELECT `setting_value` FROM `{table}` WHERE `setting_key` = 'system_max_file_size_mb'")
                row = await cur.fetchone()
                if row and row[0]:
                    settings_cache['max_file_size'] = safe_int(row[0]) * 1024 * 1024
                
                # Fetch max upload folder size
                await cur.execute(f"SELECT `setting_value` FROM `{table}` WHERE `setting_key` = 'max_upload_folder_mb'")
                row = await cur.fetchone()
                if row and row[0]:
                    settings_cache['max_upload_folder_mb'] = safe_int(row[0])
                else:
                    settings_cache['max_upload_folder_mb'] = 0

                # Optional bearer token for /metrics (empty = loopback-only scraping).
                await cur.execute(f"SELECT `setting_value` FROM `{table}` WHERE `setting_key` = 'metrics_token'")
                row = await cur.fetchone()
                settings_cache['metrics_token'] = (row[0] if row and row[0] else '')

                # Thumbnail size (longest edge, px). Clamped to a sane range so a bad value in
                # the settings table can't ask Pillow for a 1-pixel or a gigapixel thumbnail.
                await cur.execute(f"SELECT `setting_value` FROM `{table}` WHERE `setting_key` = 'thumbnail_max_px'")
                row = await cur.fetchone()
                thumb_px = safe_int(row[0]) if row and row[0] else 0
                settings_cache['thumb_max'] = thumb_px if 64 <= thumb_px <= 2000 else THUMB_MAX_DEFAULT

                # pt 1: whether a collection ZIP also bumps each member file's counter. Both
                # readings are defensible — the bytes did leave the server, but nobody opened
                # that file's page — so it is a setting rather than a decision baked in here.
                await cur.execute(f"SELECT `setting_value` FROM `{table}` WHERE `setting_key` = 'collection_counts_file_downloads'")
                row = await cur.fetchone()
                settings_cache['collection_counts_files'] = bool(row and str(row[0]) == '1')

                await cur.execute(
                    f"SELECT `setting_value` FROM `{table}` WHERE `setting_key` = 'collection_protected_file_policy'"
                )
                row = await cur.fetchone()
                collection_policy = str(row[0] if row and row[0] else 'prompt_skip')
                settings_cache['collection_protected_file_policy'] = (
                    collection_policy if collection_policy in {
                        'prompt_skip', 'remember_access', 'require_collection_password'
                    } else 'prompt_skip'
                )

                # Maintenance scheduler — read every refresh, so switching it on or changing
                # the interval takes effect on the next tick rather than on the next restart.
                cron_keys = ['cron_enabled', 'cron_interval', 'cron_php_binary']
                cron_ph = ','.join(['%s'] * len(cron_keys))
                await cur.execute(
                    f"SELECT `setting_key`, `setting_value` FROM `{table}` WHERE `setting_key` IN ({cron_ph})",
                    tuple(cron_keys)
                )
                cron_map = dict(await cur.fetchall())
                settings_cache['cron_enabled'] = str(cron_map.get('cron_enabled', '0')) == '1'
                settings_cache['cron_interval'] = safe_int(cron_map.get('cron_interval', 15)) or 15
                settings_cache['cron_php_binary'] = (cron_map.get('cron_php_binary') or '').strip()

                # A8: cache user groups (named limit profiles). A registered user's limits come
                # from their group; group_id NULL resolves to the default group. Wrapped so a
                # missing table on an un-migrated DB never aborts the rest of the settings load.
                try:
                    groups_table = f"{prefix}groups"
                    await cur.execute(
                        f"SELECT `id`, `slug`, `max_file_size_mb`, `max_files_per_session`, `storage_quota_mb`, "
                        f"`limit_upload`, `limit_download`, `concurrent_downloads`, "
                        f"`concurrent_connections_per_file`, `transfer_quota_bytes`, "
                        f"`transfer_quota_period`, `is_default` "
                        f"FROM `{groups_table}`"
                    )
                    grows = await cur.fetchall()
                    groups_map = {}
                    default_gid = None
                    for r in grows:
                        gid = r[0]
                        groups_map[gid] = {
                            'slug': str(r[1] or ''),
                            'max_file_size_mb': safe_int(r[2]),
                            'max_files_per_session': safe_int(r[3]),
                            'storage_quota_mb': safe_int(r[4]),
                            'limit_upload': safe_int(r[5]),
                            'limit_download': safe_int(r[6]),
                            'concurrent_downloads': safe_int(r[7]),
                            'concurrent_connections_per_file': safe_int(r[8]),
                            'transfer_quota_bytes': safe_int(r[9]),
                            'transfer_quota_period': str(r[10] or 'week'),
                        }
                        if r[11]:
                            default_gid = gid
                    settings_cache['groups'] = groups_map
                    settings_cache['default_group_id'] = default_gid
                except Exception as ge:
                    # Transfers refuse to run without group limits (503), never unthrottled.
                    print(f"⚠️  Failed to load groups: {ge}")

                settings_cache['checked_at'] = time.time()

    except Exception as e:
        print(f"⚠️  Failed to update settings: {e}")
        # Initialize defaults if failed first time
        if settings_cache['blocked_extensions'] is None:
            settings_cache['blocked_extensions'] = set(BLOCKED_EXTENSIONS)

def _merge_group_limits(primary: dict, staff: dict | None) -> dict:
    """Return the more favourable limits from the plan/default and staff profiles."""
    if not staff:
        return dict(primary)
    merged = dict(primary)
    unlimited_fields = (
        "max_file_size_mb",
        "max_files_per_session",
        "storage_quota_mb",
        "limit_upload",
        "limit_download",
        "concurrent_downloads",
        "concurrent_connections_per_file",
    )
    for field in unlimited_fields:
        plan_value = max(0, safe_int(primary.get(field), 0))
        staff_value = max(0, safe_int(staff.get(field), 0))
        merged[field] = 0 if plan_value == 0 or staff_value == 0 else max(plan_value, staff_value)

    periods = {"day": 86400, "week": 7 * 86400, "month": 30 * 86400, "year": 365 * 86400}
    plan_quota = max(0, safe_int(primary.get("transfer_quota_bytes"), 0))
    staff_quota = max(0, safe_int(staff.get("transfer_quota_bytes"), 0))
    plan_period = str(primary.get("transfer_quota_period") or "week")
    staff_period = str(staff.get("transfer_quota_period") or "week")
    if plan_quota == 0 or staff_quota == 0:
        merged["transfer_quota_bytes"] = 0
        merged["transfer_quota_period"] = plan_period if plan_quota == 0 else staff_period
    elif staff_quota / periods.get(staff_period, periods["week"]) > plan_quota / periods.get(plan_period, periods["week"]):
        merged["transfer_quota_bytes"] = staff_quota
        merged["transfer_quota_period"] = staff_period
    else:
        merged["transfer_quota_bytes"] = plan_quota
        merged["transfer_quota_period"] = plan_period
    return merged


async def get_user_group_limits(user_id, cur=None):
    """
    A8: resolve a registered user's effective group limits.

    Returns the cached limit dict for the user's group (or the default group when their
    group_id is NULL / dangling). Guests resolve to the system `guest` group. One small
    indexed lookup is needed only for authenticated users.

    A caller that already holds a pooled connection MUST pass its cursor. Asking the pool
    for a second connection while holding one is how a burst of downloads used to deadlock
    the whole pool: every request held one connection and waited for another.
    """
    groups = settings_cache.get('groups') or {}
    if not groups:
        raise HTTPException(status_code=503, detail="User group limits unavailable")
    if not user_id:
        for limits in groups.values():
            if limits.get('slug') == 'guest':
                return limits
        raise HTTPException(status_code=503, detail="Guest group limits unavailable")

    gid = None
    staff_gid = None
    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''

    async def lookup(active_cursor):
        await active_cursor.execute(
            f"SELECT `group_id`, `staff_group_id`, `role` FROM `{prefix}users` "
            f"WHERE `id` = %s AND `is_active` = 1",
            (user_id,),
        )
        return await active_cursor.fetchone()

    try:
        if cur is not None:
            row = await lookup(cur)
        else:
            pool = await get_db_pool()
            if not pool:
                raise HTTPException(status_code=503, detail="Database unavailable")
            async with pool.acquire() as conn:
                async with conn.cursor() as own_cursor:
                    row = await lookup(own_cursor)
    except HTTPException:
        raise
    except Exception as e:
        raise HTTPException(status_code=503, detail="User group lookup unavailable") from e
    if not row:
        raise HTTPException(status_code=403, detail="User is inactive")
    if row[0]:
        gid = row[0]
    if str(row[2] or "") == "moderator":
        staff_gid = row[1]

    primary = groups.get(gid) if gid else None
    if not primary:
        dgid = settings_cache.get('default_group_id')
        primary = groups.get(dgid) if dgid else None
    if primary:
        staff = groups.get(staff_gid) if staff_gid else None
        if staff_gid and (not staff or staff.get("slug") != "moderator"):
            raise HTTPException(status_code=503, detail="Moderator group limits unavailable")
        return _merge_group_limits(primary, staff)
    raise HTTPException(status_code=503, detail="Default user group is unavailable")

async def throttled_iterator(iterator, limit_Bps: int):
    """
    Async iterator wrapper for throttling upload speed.
    limit_Bps: limit in bytes per second
    
    Applies smooth throttling by tracking bytes and adding delays.
    """
    # For uploads, we can't control chunk size (client sends what it sends)
    # So we use cumulative timing approach but with frequent small sleeps
    
    limit_Bps = max(1024, int(limit_Bps))
    bytes_in_window = 0
    window_start = time.monotonic()
    window_size = 0.1  # 100ms windows for smoother throttling
    bytes_per_window = limit_Bps * window_size
    
    async for chunk in iterator:
        yield chunk
        bytes_in_window += len(chunk)
        
        # Check if we've exceeded our budget for this window
        while bytes_in_window >= bytes_per_window:
            elapsed = time.monotonic() - window_start
            if elapsed < window_size:
                await asyncio.sleep(window_size - elapsed)
            
            # Move to next window
            bytes_in_window -= bytes_per_window
            window_start = time.monotonic()

async def download_was_killed(active_id: int) -> bool:
    """Has an administrator severed this download? (its active_downloads row deleted)

    A read only. It used to refresh `heartbeat_at` as well, about one committed UPDATE per
    second per transfer, which the middleware's lease timer (heartbeat_download) already does.
    """
    if not active_id:
        return False
    pool = await get_db_pool()
    if not pool:
        return False
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}active_downloads"
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"SELECT 1 FROM `{table}` "
                    f"WHERE `id` = %s AND `instance_id` = %s LIMIT 1",
                    (active_id, INSTANCE_ID),
                )
                return await cur.fetchone() is None
    except Exception:
        return False


async def heartbeat_download(
    active_id: int,
    reservation_id: str = "",
    bytes_sent: int = 0,
) -> bool:
    """Refresh a live transfer lease; false means it was killed or belongs to another worker."""
    if not active_id:
        return False
    pool = await get_db_pool()
    if not pool:
        return False
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}active_downloads"
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"UPDATE `{table}` SET `heartbeat_at` = %s "
                    f"WHERE `id` = %s AND `instance_id` = %s",
                    (int(time.time()), active_id, INSTANCE_ID),
                )
                alive = cur.rowcount == 1
                if not alive:
                    # See download_was_killed(): unchanged timestamps are not
                    # proof that the row was removed by an administrator.
                    await cur.execute(
                        f"SELECT 1 FROM `{table}` "
                        f"WHERE `id` = %s AND `instance_id` = %s LIMIT 1",
                        (active_id, INSTANCE_ID),
                    )
                    alive = await cur.fetchone() is not None
                if alive and reservation_id:
                    reservations = f"{prefix}download_reservations"
                    now = int(time.time())
                    await cur.execute(
                        f"UPDATE `{reservations}` SET `lease_until` = %s, "
                        f"`bytes_sent` = GREATEST(`bytes_sent`, %s), `updated_at` = %s "
                        f"WHERE `id` = %s AND `state` IN ('reserved', 'started')",
                        (
                            now + DOWNLOAD_LEASE_SECONDS,
                            max(0, int(bytes_sent)),
                            now,
                            reservation_id,
                        ),
                    )
                return alive
    except Exception:
        return False


async def _apply_download_start_effects(
    cur,
    prefix: str,
    reservation_id: str,
    now: int,
    strict: bool = True,
):
    """Consume token/one-time claims exactly once when the first body byte is accepted.

    `strict` is for the first byte: a lost claim stops the transfer before it is served. At
    finalization the bytes have already left, so a claim or token that disappeared meanwhile
    (the file was deleted, a cleanup ran) is logged and settled instead: raising there rolled
    the whole finalization back and left the reservation to the reaper.
    """
    effects = f"{prefix}download_reservation_effects"
    tokens = f"{prefix}download_tokens"
    await cur.execute(
        f"SELECT `resource_type`, `resource_id` FROM `{effects}` "
        f"WHERE `reservation_id` = %s AND `effect_type` = 'one_time' "
        f"AND `applied_at` IS NULL FOR UPDATE",
        (reservation_id,),
    )
    for resource_type, resource_id in await cur.fetchall():
        if resource_type not in {"file", "collection"}:
            if strict:
                raise RuntimeError("Invalid one-time download effect")
            print(f"⚠️  Ignoring an invalid one-time effect of download {reservation_id[:12]}")
            continue
        table = f"{prefix}{'files' if resource_type == 'file' else 'collections'}"
        await cur.execute(
            f"UPDATE `{table}` SET `consumed_at` = %s, "
            f"`consume_reservation_id` = NULL "
            f"WHERE `id` = %s AND `consumed_at` IS NULL "
            f"AND `consume_reservation_id` = %s",
            (now, resource_id, reservation_id),
        )
        if cur.rowcount != 1:
            if strict:
                raise RuntimeError(
                    f"Lost one-time claim for {resource_type}:{resource_id}"
                )
            print(f"⚠️  One-time claim for {resource_type}:{resource_id} was gone when its download ended")

    await cur.execute(
        f"UPDATE `{effects}` SET `applied_at` = %s "
        f"WHERE `reservation_id` = %s AND `effect_type` = 'one_time' "
        f"AND `applied_at` IS NULL",
        (now, reservation_id),
    )
    await cur.execute(
        f"UPDATE `{tokens}` SET `used` = 1, `used_at` = COALESCE(`used_at`, %s), "
        f"`reserved_until` = NULL "
        f"WHERE `reservation_id` = %s AND `used` = 1",
        (now, reservation_id),
    )
    if cur.rowcount != 1:
        if strict:
            raise RuntimeError("Lost reserved download token")
        print(f"⚠️  Download token of {reservation_id[:12]} was gone when its download ended")


async def _release_unstarted_download_effects(
    cur,
    prefix: str,
    reservation_id: str,
):
    """Return claims only while they are still owned and no response byte was sent."""
    effects = f"{prefix}download_reservation_effects"
    tokens = f"{prefix}download_tokens"
    await cur.execute(
        f"SELECT `resource_type`, `resource_id` FROM `{effects}` "
        f"WHERE `reservation_id` = %s AND `effect_type` = 'one_time' "
        f"AND `applied_at` IS NULL FOR UPDATE",
        (reservation_id,),
    )
    for resource_type, resource_id in await cur.fetchall():
        if resource_type not in {"file", "collection"}:
            continue
        table = f"{prefix}{'files' if resource_type == 'file' else 'collections'}"
        await cur.execute(
            f"UPDATE `{table}` SET `consume_reservation_id` = NULL "
            f"WHERE `id` = %s AND `consumed_at` IS NULL "
            f"AND `consume_reservation_id` = %s",
            (resource_id, reservation_id),
        )
    await cur.execute(
        f"UPDATE `{tokens}` SET `used` = 0, `reservation_id` = NULL, "
        f"`reserved_until` = NULL "
        f"WHERE `reservation_id` = %s AND `used_at` IS NULL",
        (reservation_id,),
    )


async def _apply_download_counter_effects(
    cur,
    prefix: str,
    reservation_id: str,
    now: int,
):
    """Commit durable counters after the complete requested representation was sent.

    The bytes are gone either way, so nothing here may undo the finalization: a file or
    collection deleted during its download is skipped, and a cap that was already spent (an
    embed or an administrator moved the counter meanwhile) is logged, not raised. Raising used
    to roll the whole completion back; the reaper then released it with the last heartbeat's
    byte count, so the transfer allowance was never charged.
    """
    effects = f"{prefix}download_reservation_effects"
    await cur.execute(
        f"SELECT `resource_type`, `resource_id` FROM `{effects}` "
        f"WHERE `reservation_id` = %s AND `effect_type` = 'counter' "
        f"AND `applied_at` IS NULL FOR UPDATE",
        (reservation_id,),
    )
    delete_after_limit = []
    for resource_type, resource_id in await cur.fetchall():
        if resource_type not in {"file", "collection"}:
            print(f"⚠️  Ignoring an invalid counter effect of download {reservation_id[:12]}")
            continue
        table = f"{prefix}{'files' if resource_type == 'file' else 'collections'}"
        await cur.execute(
            f"UPDATE `{table}` SET `downloads` = `downloads` + 1 "
            f"WHERE `id` = %s "
            f"AND (`max_downloads` IS NULL OR `downloads` < `max_downloads`)",
            (resource_id,),
        )
        counted = cur.rowcount == 1
        await cur.execute(
            f"SELECT `downloads`, `max_downloads`, `on_limit_action` "
            f"FROM `{table}` WHERE `id` = %s",
            (resource_id,),
        )
        row = await cur.fetchone()
        if row is None:
            info_log(f"{resource_type} {resource_id} was deleted during its download; nothing to count")
            continue
        if not counted:
            print(f"⚠️  Download cap of {resource_type}:{resource_id} was already spent; not counted again")
        if (
            row
            and row[1] is not None
            and int(row[0]) >= int(row[1])
            and (row[2] or "keep") == "delete"
        ):
            delete_after_limit.append((resource_type, str(resource_id)))

    await cur.execute(
        f"UPDATE `{effects}` SET `applied_at` = %s "
        f"WHERE `reservation_id` = %s AND `effect_type` = 'counter' "
        f"AND `applied_at` IS NULL",
        (now, reservation_id),
    )
    return delete_after_limit


async def _settle_reserved_quota(cur, prefix: str, quota: tuple, actual_bytes: int, now: int):
    """Turn the allowance a reservation held into the bytes it really sent."""
    subject_type, subject_key, period, period_start, reserved = quota
    if not (subject_type and subject_key and period):
        return
    reserved_bytes = max(0, safe_int(reserved, 0))
    await cur.execute(
        f"UPDATE `{prefix}transfer_quota_usage` SET "
        f"`reserved_bytes` = GREATEST(0, `reserved_bytes` - %s), "
        f"`used_bytes` = `used_bytes` + %s, `updated_at` = %s "
        f"WHERE `subject_type` = %s AND `subject_key` = %s "
        f"AND `period` = %s AND `period_start` = %s",
        (
            reserved_bytes,
            min(max(0, actual_bytes), reserved_bytes),
            now,
            subject_type,
            subject_key,
            period,
            period_start,
        ),
    )


async def mark_download_reservation_started(reservation_id: str) -> None:
    if not re.fullmatch(r"[a-f0-9]{32}", reservation_id or ""):
        return
    pool = await get_db_pool()
    if not pool:
        raise RuntimeError("Database unavailable while starting download")
    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
    table = f"{prefix}download_reservations"
    now = int(time.time())
    try:
        async with pool.acquire() as conn:
            await conn.begin()
            try:
                async with conn.cursor() as cur:
                    await cur.execute(
                        f"SELECT `state` FROM `{table}` WHERE `id` = %s FOR UPDATE",
                        (reservation_id,),
                    )
                    row = await cur.fetchone()
                    if not row or row[0] != "reserved":
                        await conn.commit()
                        return
                    await _apply_download_start_effects(
                        cur, prefix, reservation_id, now
                    )
                    await cur.execute(
                        f"UPDATE `{table}` SET `state` = 'started', "
                        f"`started_at` = COALESCE(`started_at`, %s), "
                        f"`lease_until` = %s, `updated_at` = %s "
                        f"WHERE `id` = %s AND `state` = 'reserved'",
                        (
                            now,
                            now + DOWNLOAD_LEASE_SECONDS,
                            now,
                            reservation_id,
                        ),
                    )
                    if cur.rowcount != 1:
                        raise RuntimeError("Download reservation start race")
                await conn.commit()
            except Exception:
                await conn.rollback()
                raise
    except Exception as exc:
        print(f"⚠️  Download reservation start failed: {type(exc).__name__}")
        raise


async def finish_download_reservation(
    reservation_id: str,
    state: str,
    bytes_sent: int,
    require_expired: bool = False,
) -> bool:
    if (
        not re.fullmatch(r"[a-f0-9]{32}", reservation_id or "")
        or state not in {"completed", "released"}
    ):
        return False
    pool = await get_db_pool()
    if not pool:
        raise RuntimeError("Database unavailable while finalizing download")
    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
    table = f"{prefix}download_reservations"
    now = int(time.time())
    delete_after_limit = []
    async with pool.acquire() as conn:
        await conn.begin()
        try:
            async with conn.cursor() as cur:
                expiry_clause = " AND `lease_until` < %s" if require_expired else ""
                params = (
                    (reservation_id, now)
                    if require_expired
                    else (reservation_id,)
                )
                await cur.execute(
                    f"SELECT `state`, `active_download_id`, `quota_subject_type`, "
                    f"`quota_subject_key`, `quota_period`, `quota_period_start`, "
                    f"`quota_reserved_bytes` FROM `{table}` "
                    f"WHERE `id` = %s{expiry_clause} FOR UPDATE",
                    params,
                )
                row = await cur.fetchone()
                if not row or row[0] not in {"reserved", "started"}:
                    await conn.commit()
                    return False
                (
                    previous_state,
                    active_id,
                    quota_subject_type,
                    quota_subject_key,
                    quota_period,
                    quota_period_start,
                    quota_reserved_bytes,
                ) = row
                actual_bytes = max(0, int(bytes_sent))

                if previous_state == "reserved" and (
                    actual_bytes > 0 or state == "completed"
                ):
                    await _apply_download_start_effects(
                        cur, prefix, reservation_id, now, strict=False
                    )
                    previous_state = "started"

                if state == "completed":
                    delete_after_limit = await _apply_download_counter_effects(
                        cur, prefix, reservation_id, now
                    )
                elif previous_state == "reserved" and actual_bytes == 0:
                    await _release_unstarted_download_effects(
                        cur, prefix, reservation_id
                    )

                await _settle_reserved_quota(
                    cur,
                    prefix,
                    (
                        quota_subject_type,
                        quota_subject_key,
                        quota_period,
                        quota_period_start,
                        quota_reserved_bytes,
                    ),
                    actual_bytes,
                    now,
                )

                await cur.execute(
                    f"UPDATE `{table}` SET `state` = %s, "
                    f"`bytes_sent` = GREATEST(`bytes_sent`, %s), "
                    f"`finished_at` = %s, `lease_until` = %s, `updated_at` = %s "
                    f"WHERE `id` = %s AND `state` IN ('reserved', 'started')",
                    (state, actual_bytes, now, now, now, reservation_id),
                )
                if cur.rowcount != 1:
                    raise RuntimeError("Download reservation finalization race")
                if active_id:
                    await cur.execute(
                        f"DELETE FROM `{prefix}active_downloads` WHERE `id` = %s",
                        (active_id,),
                    )
            await conn.commit()
        except Exception:
            await conn.rollback()
            raise

    if state == "completed":
        for resource_type, resource_id in delete_after_limit:
            if resource_type == "file":
                await delete_file_after_limit(resource_id)
            elif resource_type == "collection":
                await delete_collection_after_limit(resource_id)
    return True


async def _force_release_download_reservation(reservation_id: str, bytes_sent: int) -> bool:
    """Last resort for an expired reservation whose finalization keeps failing.

    Released without counting, without effect bookkeeping beyond what keeps nobody else
    locked out: its one-time claims and token are settled the way a release settles them
    (consumed once bytes left, handed back otherwise), its allowance is charged with the
    bytes the heartbeats recorded and its live row goes. Every statement is conditional, so
    a partly applied earlier attempt is harmless.
    """
    pool = await get_db_pool()
    if not pool:
        raise RuntimeError("Database unavailable while releasing download")
    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
    table = f"{prefix}download_reservations"
    effects = f"{prefix}download_reservation_effects"
    tokens = f"{prefix}download_tokens"
    now = int(time.time())
    async with pool.acquire() as conn:
        await conn.begin()
        try:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"SELECT `state`, `active_download_id`, `quota_subject_type`, "
                    f"`quota_subject_key`, `quota_period`, `quota_period_start`, "
                    f"`quota_reserved_bytes`, `bytes_sent` FROM `{table}` "
                    f"WHERE `id` = %s AND `lease_until` < %s FOR UPDATE",
                    (reservation_id, now),
                )
                row = await cur.fetchone()
                if not row or row[0] not in {"reserved", "started"}:
                    await conn.commit()
                    return False
                sent = max(0, int(bytes_sent), safe_int(row[7], 0))
                await cur.execute(
                    f"SELECT `resource_type`, `resource_id` FROM `{effects}` "
                    f"WHERE `reservation_id` = %s AND `effect_type` = 'one_time' "
                    f"AND `applied_at` IS NULL",
                    (reservation_id,),
                )
                for resource_type, resource_id in await cur.fetchall():
                    if resource_type not in {"file", "collection"}:
                        continue
                    resource_table = f"{prefix}{'files' if resource_type == 'file' else 'collections'}"
                    consumed = ", `consumed_at` = %s" if sent > 0 else ""
                    await cur.execute(
                        f"UPDATE `{resource_table}` SET `consume_reservation_id` = NULL{consumed} "
                        f"WHERE `id` = %s AND `consume_reservation_id` = %s "
                        f"AND `consumed_at` IS NULL",
                        ((now,) if sent > 0 else ()) + (resource_id, reservation_id),
                    )
                if sent > 0:
                    await cur.execute(
                        f"UPDATE `{tokens}` SET `used` = 1, `used_at` = COALESCE(`used_at`, %s), "
                        f"`reserved_until` = NULL WHERE `reservation_id` = %s",
                        (now, reservation_id),
                    )
                else:
                    await cur.execute(
                        f"UPDATE `{tokens}` SET `used` = 0, `reservation_id` = NULL, "
                        f"`reserved_until` = NULL WHERE `reservation_id` = %s AND `used_at` IS NULL",
                        (reservation_id,),
                    )
                await cur.execute(
                    f"UPDATE `{effects}` SET `applied_at` = %s "
                    f"WHERE `reservation_id` = %s AND `applied_at` IS NULL",
                    (now, reservation_id),
                )
                await _settle_reserved_quota(cur, prefix, tuple(row[2:7]), sent, now)
                await cur.execute(
                    f"UPDATE `{table}` SET `state` = 'released', "
                    f"`bytes_sent` = GREATEST(`bytes_sent`, %s), "
                    f"`finished_at` = %s, `lease_until` = %s, `updated_at` = %s "
                    f"WHERE `id` = %s AND `state` IN ('reserved', 'started')",
                    (sent, now, now, now, reservation_id),
                )
                if row[1]:
                    await cur.execute(
                        f"DELETE FROM `{prefix}active_downloads` WHERE `id` = %s",
                        (row[1],),
                    )
            await conn.commit()
        except Exception:
            await conn.rollback()
            raise
    return True


# Consecutive failed finalizations per expired reservation, before the reaper force-releases it.
REAPER_FORCE_RELEASE_AFTER = 5
_reaper_failures: dict = {}


async def reap_expired_download_reservations(limit: int = 100) -> int:
    """Idempotently release expired leases without racing a late heartbeat.

    Each reservation is handled on its own: one that cannot be finalized is logged and
    skipped, and after REAPER_FORCE_RELEASE_AFTER failed ticks it is force-released. Before,
    the first such row aborted every tick, and the scheduled maintenance after it.
    """
    pool = await get_db_pool()
    if not pool:
        return 0
    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
    table = f"{prefix}download_reservations"
    now = int(time.time())
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            await cur.execute(
                f"SELECT `id`, `bytes_sent` FROM `{table}` "
                f"WHERE `state` IN ('reserved', 'started') AND `lease_until` < %s "
                f"ORDER BY `lease_until` ASC LIMIT %s",
                (now, max(1, min(1000, int(limit)))),
            )
            expired = list(await cur.fetchall())

    released = 0
    for reservation_id, bytes_sent in expired:
        key = str(reservation_id)
        try:
            if await finish_download_reservation(
                key, "released", safe_int(bytes_sent), require_expired=True
            ):
                released += 1
            _reaper_failures.pop(key, None)
            continue
        except Exception as exc:
            failures = _reaper_failures.get(key, 0) + 1
            print(
                f"⚠️  Expired download {key[:12]} could not be released "
                f"(attempt {failures}): {type(exc).__name__}: {exc}"
            )
        if failures < REAPER_FORCE_RELEASE_AFTER:
            _reaper_failures[key] = failures
            continue
        try:
            if await _force_release_download_reservation(key, safe_int(bytes_sent)):
                released += 1
            _reaper_failures.pop(key, None)
            print(f"⚠️  Expired download {key[:12]} force-released after {failures} failed attempts")
        except Exception as exc:
            _reaper_failures[key] = failures
            print(f"⚠️  Force-release of download {key[:12]} failed: {type(exc).__name__}: {exc}")
    # Reservations no longer in the expired set were settled elsewhere; forget their streaks.
    current = {str(reservation_id) for reservation_id, _bytes in expired}
    for key in [key for key in _reaper_failures if key not in current]:
        _reaper_failures.pop(key, None)
    return released


def parse_single_range_header(value: str, size: int):
    """Return an inclusive (start, end), ignore multi-range, reject malformed single ranges."""
    if not value:
        return None
    if ',' in value:
        return None
    match = re.fullmatch(r"bytes=(\d*)-(\d*)", value.strip())
    if not match or size < 1:
        raise HTTPException(
            status_code=416,
            detail="Invalid byte range",
            headers={"Content-Range": f"bytes */{max(0, size)}"},
        )
    first, last = match.groups()
    if first == '' and last == '':
        raise HTTPException(
            status_code=416,
            detail="Invalid byte range",
            headers={"Content-Range": f"bytes */{size}"},
        )
    if first == '':
        suffix = int(last)
        if suffix <= 0:
            raise HTTPException(
                status_code=416,
                detail="Invalid byte range",
                headers={"Content-Range": f"bytes */{size}"},
            )
        start = max(0, size - suffix)
        end = size - 1
    else:
        start = int(first)
        end = int(last) if last != '' else size - 1
    if start >= size or end < start:
        raise HTTPException(
            status_code=416,
            detail="Range not satisfiable",
            headers={"Content-Range": f"bytes */{size}"},
        )
    return start, min(end, size - 1)


async def close_stream_reader(reader) -> None:
    """Finalise a streaming reader once its response is done.

    Must stay a real `async def` wrapper. Handing `reader.aclose` to BackgroundTasks directly
    does not work: Starlette classifies the task with an `iscoroutinefunction()` check, which
    is False for an async generator's built-in `aclose`, so the task would be run in a worker
    thread and the coroutine it returns dropped without ever being awaited — the generator
    would stay suspended and its file stay open, which is the bug this is here to prevent.
    """
    try:
        await reader.aclose()
    except Exception as exc:  # noqa: BLE001
        info_log(f"Stream reader close failed: {exc}")


async def throttled_file_reader(
    path: Path,
    limit_Bps: int,
    active_id: int = 0,
    start: int = 0,
    end: int | None = None,
    transfer_state: dict | None = None,
):
    """Read a byte range with optional throttling and an admin kill check."""
    limit_Bps = max(0, int(limit_Bps))
    if limit_Bps > 0:
        chunk_size = max(1024, min(64 * 1024, limit_Bps // 10))
        chunk_delay = chunk_size / limit_Bps
    else:
        chunk_size = 512 * 1024
        chunk_delay = 0.0
    last_kill_check = 0.0

    async with aiofiles.open(path, 'rb') as f:
        if start > 0:
            await f.seek(start)
        remaining = None if end is None else end - start + 1
        while True:
            start_time = time.monotonic()
            if active_id and start_time - last_kill_check >= KILL_CHECK_SECONDS:
                last_kill_check = start_time
                if await download_was_killed(active_id):
                    if transfer_state is not None:
                        transfer_state["interrupted"] = True
                    info_log(f"Download {active_id} severed by admin")
                    break

            read_size = chunk_size if remaining is None else min(chunk_size, remaining)
            if read_size <= 0:
                break
            chunk = await f.read(read_size)
            if not chunk:
                break

            yield chunk
            if remaining is not None:
                remaining -= len(chunk)

            elapsed = time.monotonic() - start_time
            wait_time = chunk_delay - elapsed

            if wait_time > 0:
                await asyncio.sleep(wait_time)

def _truncate_utf8(filename: str, limit: int) -> str:
    """Shorten a name to `limit` UTF-8 bytes, keeping its extension and whole characters.

    Filesystems cap one path component in BYTES (255 on ext4/xfs), not characters: a
    hundred CJK characters are already 300 bytes, so a character count let such names
    through to a failing open().
    """
    if len(filename.encode("utf-8")) <= limit:
        return filename
    name, ext = os.path.splitext(filename)
    if len(ext.encode("utf-8")) > limit // 2:
        name, ext = filename, ""
    budget = limit - len(ext.encode("utf-8"))
    name = name.encode("utf-8")[:budget].decode("utf-8", errors="ignore")
    return name + ext


# What Windows drops from the end of a name, and what browsers trim when they save a
# download (Firefox strips trailing dots and any whitespace, Chrome replaces them): a file
# stored as "invoice.exe." or "invoice.exe " ends up on the recipient's disk as "invoice.exe".
_TRAILING_DOTS_AND_SPACES = re.compile(r"[\s.᠎﻿]+$")
BLOCKED_SUFFIX = ".blocked"


def sanitize_filename(filename: str, blocked_extensions) -> str:
    """One safe path component: no separators or control characters, bounded in bytes.

    The blocked-extension check runs on the name exactly as it is stored, after every step
    that can change its last extension. Truncation can expose an inner `.exe` once an
    over-long last extension is cut off, so checking the name before truncating it let
    `"A"*196 + ".exe" + "B"*150` through as `AAAA….exe`.
    """
    filename = os.path.basename(filename)
    filename = re.sub(r'[<>:"/\\|?*\x00-\x1f\x7f]', '_', filename)
    filename = _TRAILING_DOTS_AND_SPACES.sub("", filename)
    if filename in ("", ".", ".."):
        return "file"

    filename = _TRAILING_DOTS_AND_SPACES.sub("", _truncate_utf8(filename, FILENAME_MAX_BYTES))
    if not filename:
        return "file"

    ext = filename.rsplit('.', 1)[-1].lower() if '.' in filename else ''
    if ext in blocked_extensions:
        filename = (
            _truncate_utf8(filename, FILENAME_MAX_BYTES - len(BLOCKED_SUFFIX)) + BLOCKED_SUFFIX
        )

    if filename == STORAGE_MANIFEST_NAME:
        filename += ".upload"

    return filename


async def sanitize_filename_async(filename: str) -> str:
    """Sanitize filename using dynamic blocked extensions"""
    await update_settings()
    return sanitize_filename(filename, settings_cache['blocked_extensions'] or BLOCKED_EXTENSIONS)


def _upload_failure(exc: Exception) -> HTTPException:
    """Log why an upload broke; tell the client only that it did.

    The exception text of a filesystem error names the absolute staging path, which used
    to be returned verbatim as the response detail.
    """
    if not isinstance(exc, ClientDisconnect):
        print(f"Upload failed: {type(exc).__name__}: {exc}")
    return HTTPException(status_code=500, detail="Upload failed")

async def authorize_upload_identity(token: str | None, ip: str):
    """Fail-closed ban/token decision without consuming the upload slot yet.

    Every upload carries a token from PHP: minted after the CAPTCHA, or on request while the
    CAPTCHA is off, under PHP's per-network rate limit. A token-less upload used to get an
    implicit guest session per exact address, so each fresh IPv6 address of one /64 started a
    new session and the per-session file cap never applied.
    """
    if not token:
        return False, None, None
    pool = await require_storage_ready()
    prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
    now = int(time.time())
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                # A ban covers the exact address and its whole /64 (BanRepository stores IPv6
                # bans by network; rows written before that hold the exact address).
                await cur.execute(
                    f"SELECT 1 FROM `{prefix}blacklists` WHERE `type`='ip' "
                    f"AND `value` IN (%s, %s) "
                    f"AND (`expires_at` IS NULL OR `expires_at` > %s)",
                    (ip, throttle_key(ip), now),
                )
                if await cur.fetchone():
                    return False, None, None

                await cur.execute(
                    f"SELECT `setting_key`, `setting_value` FROM `{prefix}settings` "
                    f"WHERE `setting_key` IN "
                    f"('recaptcha_token_lifetime','recaptcha_max_files_per_session_guest',"
                    f"'recaptcha_max_files_per_session_auth')"
                )
                policy = {str(key): value for key, value in await cur.fetchall()}

                lifetime = max(1, safe_int(policy.get("recaptcha_token_lifetime"), 120))
                # Tokens stay bound to the exact address PHP minted them for.
                await cur.execute(
                    f"SELECT t.`user_id`, t.`files_count` FROM `{prefix}upload_tokens` t "
                    f"LEFT JOIN `{prefix}users` u ON u.`id` = t.`user_id` "
                    f"WHERE t.`token` = %s AND t.`ip_address` = %s AND t.`created_at` > %s "
                    f"AND (t.`user_id` IS NULL OR u.`is_active` = 1)",
                    (token, ip, now - lifetime * 60),
                )
                row = await cur.fetchone()
                if row:
                    default_limit_key = (
                        "recaptcha_max_files_per_session_auth"
                        if row[0]
                        else "recaptcha_max_files_per_session_guest"
                    )
                    return True, (int(row[0]) if row[0] else None), {
                        "token": token,
                        "ip": ip,
                        "default_limit": max(0, safe_int(policy.get(default_limit_key), 0)),
                    }
                # A supplied but invalid capability is never silently downgraded to guest.
                return False, None, None
    except HTTPException:
        raise
    except Exception as exc:
        print(f"Upload authorization unavailable: {type(exc).__name__}: {exc}")
        raise HTTPException(status_code=503, detail="Upload authorization unavailable")


async def reserve_upload_slot(reservation: dict | None, group_limits: dict | None) -> bool:
    """Atomically reserve one token/session slot after every size/quota precondition passed."""
    if not reservation:
        return False
    limit = max(0, safe_int(reservation.get("default_limit"), 0))
    if group_limits and safe_int(group_limits.get("max_files_per_session"), 0) > 0:
        limit = safe_int(group_limits.get("max_files_per_session"), 0)

    pool = await require_storage_ready()
    prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            if limit > 0:
                await cur.execute(
                    f"UPDATE `{prefix}upload_tokens` SET `files_count` = `files_count` + 1 "
                    f"WHERE `token` = %s AND `ip_address` = %s AND `files_count` < %s",
                    (reservation["token"], reservation["ip"], limit),
                )
            else:
                await cur.execute(
                    f"UPDATE `{prefix}upload_tokens` SET `files_count` = `files_count` + 1 "
                    f"WHERE `token` = %s AND `ip_address` = %s",
                    (reservation["token"], reservation["ip"]),
                )
            if cur.rowcount != 1:
                raise HTTPException(status_code=429, detail="Upload session file limit reached")
    return True


async def release_upload_slot(reservation: dict | None):
    if not reservation:
        return
    try:
        pool = await get_db_pool()
        if not pool:
            return
        prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"UPDATE `{prefix}upload_tokens` "
                    f"SET `files_count` = GREATEST(`files_count` - 1, 0) "
                    f"WHERE `token` = %s AND `ip_address` = %s",
                    (reservation["token"], reservation["ip"]),
                )
    except Exception as exc:
        print(f"Upload slot release failed: {type(exc).__name__}: {exc}")


async def reserve_upload_capacity(
    pool,
    user_id: int | None,
    group_limits: dict | None,
    incoming_size: int,
    folder_limit_mb: int,
):
    """Reserve bytes atomically so concurrent uploads cannot all pass the same quota check."""
    prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
    reservation_id = secrets.token_hex(16)
    lock_name = build_lock_name("upload-storage")
    conn = None
    locked = False
    try:
        conn = await pool.acquire()
        async with conn.cursor() as cur:
                await cur.execute("SELECT GET_LOCK(%s, 5)", (lock_name,))
                lock_row = await cur.fetchone()
                if not lock_row or safe_int(lock_row[0]) != 1:
                    raise HTTPException(status_code=503, detail="Upload capacity limiter is busy")
                locked = True
                await cur.execute("START TRANSACTION")
                now = int(time.time())
                reservations = f"{prefix}upload_storage_reservations"
                await cur.execute(
                    f"DELETE FROM `{reservations}` WHERE `expires_at` < %s",
                    (now,),
                )

                await cur.execute(
                    f"SELECT COALESCE(SUM(`size`), 0) FROM `{reservations}`"
                )
                reserved_global = safe_int((await cur.fetchone() or (0,))[0])
                if folder_limit_mb > 0:
                    await cur.execute(f"SELECT COALESCE(SUM(`size`), 0) FROM `{prefix}files`")
                    row = await cur.fetchone()
                    used = safe_int(row[0]) if row else 0
                    if (
                        used + reserved_global + incoming_size
                        > folder_limit_mb * 1024 * 1024
                    ):
                        raise HTTPException(status_code=507, detail="Server storage limit reached")

                if user_id:
                    await cur.execute(
                        f"SELECT `storage_limit` FROM `{prefix}users` "
                        f"WHERE `id` = %s AND `is_active` = 1",
                        (user_id,),
                    )
                    row = await cur.fetchone()
                    if not row:
                        raise HTTPException(status_code=403, detail="User is inactive")
                    limit = safe_int(row[0])
                    if limit <= 0 and group_limits:
                        limit = safe_int(group_limits.get("storage_quota_mb"), 0) * 1024 * 1024
                    if limit > 0:
                        await cur.execute(
                            f"SELECT COALESCE(SUM(`size`), 0) FROM `{prefix}files` "
                            f"WHERE `user_id` = %s",
                            (user_id,),
                        )
                        usage_row = await cur.fetchone()
                        used = safe_int(usage_row[0]) if usage_row else 0
                        await cur.execute(
                            f"SELECT COALESCE(SUM(`size`), 0) FROM `{reservations}` "
                            f"WHERE `user_id` = %s",
                            (user_id,),
                        )
                        reserved_user = safe_int((await cur.fetchone() or (0,))[0])
                        if used + reserved_user + incoming_size > limit:
                            raise HTTPException(status_code=507, detail="Storage quota exceeded")

                await cur.execute(
                    f"INSERT INTO `{reservations}` "
                    f"(`id`, `user_id`, `size`, `expires_at`, `created_at`) "
                    f"VALUES (%s, %s, %s, %s, %s)",
                    (reservation_id, user_id, incoming_size, now + 24 * 3600, now),
                )
                await cur.execute("COMMIT")
                return reservation_id
    except HTTPException:
        if conn is not None:
            try:
                await conn.rollback()
            except Exception:
                pass
        raise
    except Exception as exc:
        if conn is not None:
            try:
                await conn.rollback()
            except Exception:
                pass
        print(f"Upload capacity check failed: {type(exc).__name__}: {exc}")
        raise HTTPException(status_code=503, detail="Upload capacity unavailable")
    finally:
        if conn is not None:
            try:
                if locked:
                    async with conn.cursor() as cur:
                        await cur.execute("SELECT RELEASE_LOCK(%s)", (lock_name,))
            except Exception:
                pass
            finally:
                pool.release(conn)


async def release_upload_capacity(reservation_id: str | None):
    """Release an upload byte reservation after commit, refusal or disconnect."""
    if not reservation_id:
        return
    try:
        pool = await get_db_pool()
        if not pool:
            return
        prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"DELETE FROM `{prefix}upload_storage_reservations` WHERE `id` = %s",
                    (reservation_id,),
                )
    except Exception as exc:
        print(f"Upload capacity release failed: {type(exc).__name__}: {exc}")


async def reconcile_upload_storage():
    """Clean stale staging and report DB/filesystem drift without deleting ready user data."""
    try:
        pool = await require_storage_ready()
        cutoff = time.time() - 24 * 3600
        removed_staging = 0
        for candidate in STAGING_DIR.iterdir():
            try:
                if candidate.stat(follow_symlinks=False).st_mtime < cutoff:
                    await remove_upload_directory(candidate)
                    removed_staging += 1
            except FileNotFoundError:
                continue

        prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(f"SELECT `id` FROM `{prefix}files`")
                database_ids = {str(row[0]) for row in await cur.fetchall()}

        disk_ids = {
            path.name
            for path in UPLOADS_DIR.iterdir()
            if path.is_dir() and re.fullmatch(r"[A-Za-z0-9_-]{1,32}", path.name)
        }
        missing_bytes = database_ids - disk_ids
        orphan_bytes = disk_ids - database_ids
        if removed_staging or missing_bytes or orphan_bytes:
            print(
                "Upload reconciliation: "
                f"staging_removed={removed_staging}, "
                f"db_without_bytes={len(missing_bytes)}, "
                f"bytes_without_db={len(orphan_bytes)}"
            )
    except Exception as exc:
        print(f"Upload reconciliation unavailable: {type(exc).__name__}: {exc}")


async def enforce_ip_allowed(pool, ip: str):
    """Refuse a banned address, or any address of a banned IPv6 /64."""
    prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"SELECT 1 FROM `{prefix}blacklists` WHERE `type`='ip' "
                    f"AND `value` IN (%s, %s) "
                    f"AND (`expires_at` IS NULL OR `expires_at` > %s)",
                    (ip, throttle_key(ip), int(time.time())),
                )
                if await cur.fetchone():
                    raise HTTPException(status_code=403, detail="IP banned")
    except HTTPException:
        raise
    except Exception as exc:
        raise HTTPException(status_code=503, detail="Ban policy unavailable") from exc


def cleanup_upload_directory(path: Path):
    """Remove one validated staging/final upload directory without following links."""
    allowed_parents = {UPLOADS_DIR.resolve(), STAGING_DIR.resolve()}
    try:
        if path.parent.resolve() not in allowed_parents:
            raise RuntimeError("refusing unexpected upload cleanup path")
        if path.is_symlink():
            path.unlink()
            return
        if not path.exists():
            return
        for child in path.iterdir():
            if child.is_symlink() or child.is_file():
                child.unlink()
            else:
                raise RuntimeError("refusing nested directory in upload staging")
        path.rmdir()
    except FileNotFoundError:
        return


async def remove_upload_directory(path: Path):
    """cleanup_upload_directory() off the event loop: unlinking gigabytes is not instant."""
    await asyncio.to_thread(cleanup_upload_directory, path)


def _valid_storage_name(name: str) -> bool:
    """One filename component, never the manifest itself."""
    return (
        isinstance(name, str)
        and 0 < len(name.encode("utf-8")) <= 255
        and name not in (".", "..", STORAGE_MANIFEST_NAME)
        and "/" not in name
        and "\\" not in name
        and not any(ord(char) < 32 or ord(char) == 127 for char in name)
    )


def _sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        while True:
            chunk = source.read(1024 * 1024)
            if not chunk:
                break
            digest.update(chunk)
    return digest.hexdigest()


def _write_storage_manifest(
    directory: Path,
    name: str,
    size: int,
    sha256: str,
) -> bool:
    """Atomically publish a small, versioned storage identity file."""
    if (
        not _valid_storage_name(name)
        or isinstance(size, bool)
        or not isinstance(size, int)
        or size < 0
        or re.fullmatch(r"[a-f0-9]{64}", sha256 or "") is None
        or directory.is_symlink()
        or not directory.is_dir()
    ):
        return False
    payload = json.dumps(
        {"version": 1, "name": name, "size": size, "sha256": sha256},
        ensure_ascii=False,
        separators=(",", ":"),
    ) + "\n"
    temporary = directory / f".filehost-manifest-{secrets.token_hex(8)}.tmp"
    try:
        with temporary.open("x", encoding="utf-8", newline="\n") as handle:
            handle.write(payload)
            handle.flush()
            os.fsync(handle.fileno())
        os.replace(temporary, directory / STORAGE_MANIFEST_NAME)
        return True
    except OSError:
        return False
    finally:
        try:
            temporary.unlink(missing_ok=True)
        except OSError:
            pass


def _seal_staged_upload(directory: Path, name: str, size: int, sha256: str) -> bool:
    """Make the uploaded bytes durable, then publish their manifest (runs in a thread).

    The payload is flushed before its manifest so a crash can never leave a manifest that
    vouches for bytes still sitting in the page cache. Both fsyncs happen off the event loop:
    on ext4 the manifest's fsync alone could wait for a multi-gigabyte journal commit.
    """
    payload = directory / name
    try:
        # r+b, not rb: Windows refuses to flush a handle opened read-only.
        with payload.open("r+b") as handle:
            os.fsync(handle.fileno())
    except OSError:
        return False
    return _write_storage_manifest(directory, name, size, sha256)


def _resolve_storage_file_sync(
    file_id: str,
    expected_name: str,
    expected_size: int,
    *,
    verify_checksum: bool = False,
    repair_legacy: bool = True,
) -> Path | None:
    """Resolve exactly the DB-named object and validate its versioned manifest."""
    if (
        re.fullmatch(r"[A-Za-z0-9_-]{1,32}", file_id or "") is None
        or not _valid_storage_name(expected_name)
        or isinstance(expected_size, bool)
        or not isinstance(expected_size, int)
        or expected_size < 0
    ):
        return None
    try:
        root = UPLOADS_DIR.resolve(strict=True)
        directory_candidate = UPLOADS_DIR / file_id
        if directory_candidate.is_symlink() or not directory_candidate.is_dir():
            return None
        directory = directory_candidate.resolve(strict=True)
        if directory.parent != root:
            return None

        manifest_path = directory / STORAGE_MANIFEST_NAME
        if not manifest_path.exists():
            if manifest_path.is_symlink() or not repair_legacy:
                return None
            legacy = directory / expected_name
            if legacy.is_symlink() or not legacy.is_file():
                return None
            legacy = legacy.resolve(strict=True)
            if legacy.parent != directory or legacy.stat().st_size != expected_size:
                return None
            checksum = _sha256_file(legacy)
            if not _write_storage_manifest(
                directory, expected_name, expected_size, checksum
            ):
                if verify_checksum:
                    return None
                # Exact-name legacy access stays available on a read-only volume; the
                # integrity checker will continue to report the missing manifest.
                return legacy

        if manifest_path.is_symlink() or not manifest_path.is_file():
            return None
        if manifest_path.stat().st_size < 2 or manifest_path.stat().st_size > 4096:
            return None
        manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
        if (
            not isinstance(manifest, dict)
            or manifest.get("version") != 1
            or manifest.get("name") != expected_name
            or isinstance(manifest.get("size"), bool)
            or manifest.get("size") != expected_size
            or not isinstance(manifest.get("sha256"), str)
            or re.fullmatch(r"[a-f0-9]{64}", manifest["sha256"]) is None
        ):
            return None

        candidate = directory / manifest["name"]
        if candidate.is_symlink() or not candidate.is_file():
            return None
        candidate = candidate.resolve(strict=True)
        if candidate.parent != directory or candidate.stat().st_size != expected_size:
            return None
        if verify_checksum and not hmac.compare_digest(
            manifest["sha256"], _sha256_file(candidate)
        ):
            return None
        return candidate
    except (OSError, ValueError, TypeError, json.JSONDecodeError):
        return None


async def resolve_storage_file(
    file_id: str,
    expected_name: str,
    expected_size: int,
    *,
    verify_checksum: bool = False,
    repair_legacy: bool = True,
) -> Path | None:
    """Async boundary around legacy hashing and manifest filesystem I/O."""
    return await asyncio.to_thread(
        _resolve_storage_file_sync,
        file_id,
        expected_name,
        expected_size,
        verify_checksum=verify_checksum,
        repair_legacy=repair_legacy,
    )


async def commit_staged_upload(
    staging_dir: Path,
    final_dir: Path,
    file_id: str,
    filename: str,
    mime_type: str,
    received_size: int,
    hashed_token: str,
    client_ip: str,
    user_id: int | None,
    storage_sha256: str,
):
    """Publish filesystem bytes and their DB row as one externally visible operation."""
    pool = await require_storage_ready()
    prefix = db_config_cache.get("prefix", "") if db_config_cache else ""
    published = False
    try:
        if not await asyncio.to_thread(
            _seal_staged_upload,
            staging_dir,
            filename,
            received_size,
            storage_sha256,
        ):
            raise RuntimeError("storage manifest could not be written")
        async with pool.acquire() as conn:
            await conn.begin()
            try:
                async with conn.cursor() as cur:
                    await cur.execute(
                        f"INSERT INTO `{prefix}files` "
                        f"(`id`,`original_name`,`mime_type`,`size`,`delete_token`,`uploaded_at`,"
                        f"`uploaded_ip`,`downloads`,`user_id`) "
                        f"VALUES (%s,%s,%s,%s,%s,%s,%s,0,%s)",
                        (
                            file_id,
                            filename,
                            mime_type,
                            received_size,
                            hashed_token,
                            int(time.time()),
                            client_ip,
                            user_id,
                        ),
                    )
                    await log_traffic(
                        client_ip,
                        received_size,
                        "upload",
                        file_id,
                        user_id,
                        cursor=cur,
                    )
                await asyncio.to_thread(staging_dir.replace, final_dir)
                published = True
                await conn.commit()
            except Exception:
                await conn.rollback()
                raise
    except Exception as exc:
        await remove_upload_directory(final_dir if published else staging_dir)
        print(f"Upload publish failed: {type(exc).__name__}: {exc}")
        raise HTTPException(status_code=503, detail="Upload could not be committed")

# ============================================================================
# STARTUP & SHUTDOWN EVENTS (MERGED - was duplicated before)
# ============================================================================

_service_tasks = set()


def _start_service_task(coroutine):
    """Keep a strong reference: the event loop holds running tasks only weakly."""
    task = asyncio.create_task(coroutine)
    _service_tasks.add(task)
    task.add_done_callback(_service_tasks.discard)
    return task


async def startup():
    """Initialize background services and durable state."""
    # aiofiles, manifests and bcrypt share the loop's default executor. Its stock size is
    # cpu_count + 4 threads, which a few slow jobs could fill on a small VPS and stall
    # every transfer behind them.
    asyncio.get_running_loop().set_default_executor(
        ThreadPoolExecutor(max_workers=IO_THREADS, thread_name_prefix="filehost-io")
    )
    info_log("=" * 60)
    info_log(f"TryHackX Files Upload Server v{SERVER_VERSION}")
    info_log(f"Uploads directory: {UPLOADS_DIR}")
    info_log(f"Configuration file: {CONFIG_FILE}")
    info_log("=" * 60)

    if CONFIG_FILE.exists():
        pool = await get_db_pool()
        if pool:
            await reconcile_upload_storage()
            info_log("Database ready")
            
            info_log("Cleaning stale active downloads...")
            try:
                prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
                table = f"{prefix}active_downloads"
                async with pool.acquire() as conn:
                    async with conn.cursor() as cur:
                        now = int(time.time())
                        await cur.execute(
                            f"DELETE FROM `{table}` WHERE `heartbeat_at` < %s",
                            (now - 3600,),
                        )
                await reap_expired_download_reservations()
                info_log("Stale active downloads cleaned")
            except Exception as e:
                print(f"Stale-download cleanup failed: {e}")
        else:
            print("Could not connect to the database")
    else:
        print("config.local.php is missing; run the web installer")

    # Webhook delivery worker (Faza 4.1) — best-effort background loop.
    _start_service_task(webhook_worker())
    info_log("Webhook worker started")

    # Optional maintenance scheduler — see cleanup_scheduler(). The task always starts; it is
    # the setting that decides whether it does anything, so switching it on needs no restart.
    _start_service_task(cleanup_scheduler())
    info_log("Cleanup scheduler ready")

async def shutdown():
    """Close the database pool."""
    global db_pool, db_config_mtime
    readiness_cache["pool"] = None
    if db_pool:
        db_pool.close()
        await db_pool.wait_closed()
        # Drop the reference too. _get_db_pool_unlocked() only rebuilds when this is None or
        # the config mtime moved, so leaving a closed pool here means the next lifespan in the
        # same process hands out a dead pool. Clearing the mtime makes that startup re-read
        # config.local.php instead of trusting a value the previous cycle captured.
        db_pool = None
        db_config_mtime = 0
        info_log("Database pool closed")

# ============================================================================
# UPLOAD ENDPOINT
# ============================================================================

@app.post("/upload")
async def stream_upload(
    request: Request,
    x_filename: str = Header(None, alias="X-Filename"),
    content_length: int = Header(0, alias="Content-Length"),
    x_upload_token: str = Header(None, alias="X-Upload-Token"),
    x_upload_id: str = Header(None, alias="X-Upload-ID"),
):
    """
    Upload endpoint: streams request data directly to disk.
    """
    pool = await require_storage_ready()
    await update_settings()
    if not settings_cache.get("checked_at") or settings_cache.get("blocked_extensions") is None:
        raise HTTPException(status_code=503, detail="Upload settings unavailable")
    
    current_max_size = settings_cache['max_file_size']

    if not x_filename:
        raise HTTPException(status_code=400, detail="Missing filename header")

    client_ip = request_client_ip(request)
    token_valid, user_id, reservation = await authorize_upload_identity(
        x_upload_token,
        client_ip,
    )
    if not token_valid:
        raise HTTPException(status_code=403, detail="Invalid or missing upload token. Please verify reCAPTCHA.")

    # A8: a registered user's per-file cap comes from their group (never above the system max).
    # Guests keep the system-max behaviour. This is now enforced server-side, not just in the UI.
    user_limits = await get_user_group_limits(user_id)
    effective_max_size = current_max_size
    if user_limits and user_limits.get('max_file_size_mb', 0) > 0:
        effective_max_size = min(current_max_size, user_limits['max_file_size_mb'] * 1024 * 1024)

    x_filename = unquote(x_filename)

    if content_length > effective_max_size:
        raise HTTPException(status_code=413, detail=f"File too large (Max {effective_max_size/1024/1024:.0f} MiB)")

    if content_length <= 0:
        raise HTTPException(status_code=400, detail="Empty file")

    filename = await sanitize_filename_async(x_filename)
    file_id = generate_file_id()

    while (UPLOADS_DIR / file_id).exists() or any(STAGING_DIR.glob(file_id + "-*")):
        file_id = generate_file_id()

    file_dir = UPLOADS_DIR / file_id
    staging_dir = STAGING_DIR / f"{file_id}-{secrets.token_hex(8)}"
    storage_reservation = await reserve_upload_capacity(
        pool,
        user_id,
        user_limits,
        content_length,
        settings_cache.get('max_upload_folder_mb', 0),
    )
    try:
        staging_dir.mkdir(parents=False, exist_ok=False)
    except Exception:
        await release_upload_capacity(storage_reservation)
        raise
    try:
        slot_reserved = await reserve_upload_slot(reservation, user_limits)
    except Exception:
        await remove_upload_directory(staging_dir)
        await release_upload_capacity(storage_reservation)
        raise
    file_path = staging_dir / filename

    received_size = 0
    storage_digest = hashlib.sha256()
    
    # Determine bandwidth limit (already in bytes/s after update_settings conversion).
    # Group profiles are authoritative. Zero is an intentional unlimited value.
    limit_Bps = max(0, safe_int(user_limits.get('limit_upload'), 0))

    # Visible on the dashboard while it runs (pt 8). Cleared in the finally-block below, so a
    # refused or abandoned transfer does not leave a row claiming to be live.
    client_upload_id = (
        x_upload_id.lower()
        if x_upload_id and re.fullmatch(r"[a-fA-F0-9]{32}", x_upload_id)
        else None
    )
    upload_row = await register_upload_start(
        client_ip, filename, content_length, user_id, client_upload_id
    )
    last_progress = 0.0

    cancelled = False

    try:
        async with aiofiles.open(file_path, 'wb') as f:
            raw_stream = request.stream()
            stream_source = raw_stream

            if limit_Bps > 0:
                stream_source = throttled_iterator(stream_source, limit_Bps)

            async for chunk in stream_source:
                await f.write(chunk)
                storage_digest.update(chunk)
                received_size += len(chunk)

                if received_size > effective_max_size:
                    raise HTTPException(status_code=413, detail="File too large")

                # At most one write a second: the point is a progress bar a human is watching,
                # not a row updated per 64 KiB chunk. The same beat carries the kill check, so
                # an admin severing the transfer takes effect within a second.
                now = time.time()
                if upload_row and now - last_progress >= 1.0:
                    last_progress = now
                    await update_upload_progress(upload_row, received_size)
                    if await upload_was_killed(upload_row):
                        info_log(f"Upload {upload_row} severed by admin")
                        cancelled = True
                        break

    except HTTPException:
        await remove_upload_directory(staging_dir)
        if slot_reserved:
            await release_upload_slot(reservation)
        await release_upload_capacity(storage_reservation)
        raise

    except Exception as e:
        await remove_upload_directory(staging_dir)
        if slot_reserved:
            await release_upload_slot(reservation)
        await release_upload_capacity(storage_reservation)
        raise _upload_failure(e) from e

    finally:
        await register_upload_end(upload_row, cancelled, received_size)

    if cancelled:
        # Keep the cancelled state for the browser and stop consuming the request immediately.
        # The browser's status poll aborts the client-side XHR as soon as it observes this
        # state, so continuing to drain the request would waste the very bandwidth the
        # administrator intended to cut off.
        await remove_upload_directory(staging_dir)
        if slot_reserved:
            await release_upload_slot(reservation)
        await release_upload_capacity(storage_reservation)

        return JSONResponse(
            status_code=409,
            content={"detail": "Upload cancelled by an administrator", "code": "cancelled"},
        )

    if content_length > 0 and received_size != content_length:
        await remove_upload_directory(staging_dir)
        if slot_reserved:
            await release_upload_slot(reservation)
        await release_upload_capacity(storage_reservation)
        raise HTTPException(
            status_code=400, 
            detail=f"Size mismatch: expected {content_length}, got {received_size}"
        )

    try:
        delete_token = generate_delete_token()
        hashed_token = (
            await asyncio.to_thread(bcrypt.hashpw, delete_token.encode(), bcrypt.gensalt())
        ).decode()
        mime_type = mimetypes.guess_type(filename)[0] or 'application/octet-stream'

        await commit_staged_upload(
            staging_dir,
            file_dir,
            file_id,
            filename,
            mime_type,
            received_size,
            hashed_token,
            client_ip,
            user_id,
            storage_digest.hexdigest(),
        )
    except Exception:
        await remove_upload_directory(staging_dir)
        if slot_reserved:
            await release_upload_slot(reservation)
        await release_upload_capacity(storage_reservation)
        raise
    await release_upload_capacity(storage_reservation)

    await enqueue_webhook_event(
        user_id,
        'upload',
        {'file': {'id': file_id, 'name': filename, 'mime': mime_type, 'size': received_size}},
    )
    METRICS["uploads"] += 1
    return PlainTextResponse(
        content=f"{file_id}:{delete_token}",
        headers={"X-File-Id": file_id, "X-Delete-Token": delete_token},
    )

# ============================================================================
# SHAREX / PROGRAMMATIC UPLOAD (API-key auth)  — Faza 3.3
# ============================================================================

async def resolve_api_key(raw: str | None) -> int | None:
    """Map an API key ('Authorization: Bearer <key>' or a bare key) to its user_id and
    stamp last_used_at. Only the SHA-256 of the key is stored, so we hash what the client
    presents and look that up. Returns None for a missing/unknown key."""
    if not raw:
        return None
    key = raw.strip()
    if key.lower().startswith('bearer '):
        key = key[7:].strip()
    if not key:
        return None

    pool = await get_db_pool()
    if not pool:
        return None

    key_hash = hashlib.sha256(key.encode()).hexdigest()
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}api_keys"
        users_table = f"{prefix}users"
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"SELECT k.`id`, k.`user_id` FROM `{table}` k "
                    f"INNER JOIN `{users_table}` u ON u.`id` = k.`user_id` "
                    f"WHERE k.`key_hash` = %s AND u.`is_active` = 1",
                    (key_hash,)
                )
                row = await cur.fetchone()
                if not row:
                    return None
                await cur.execute(f"UPDATE `{table}` SET `last_used_at` = %s WHERE `id` = %s", (int(time.time()), row[0]))
                return int(row[1])
    except Exception as e:
        print(f"❌ API key lookup error: {e}")
        return None


@app.post("/sharex")
async def sharex_upload(
    request: Request,
    x_filename: str = Header(None, alias="X-Filename"),
    content_length: int = Header(0, alias="Content-Length"),
    authorization: str = Header(None, alias="Authorization"),
    x_api_key: str = Header(None, alias="X-API-Key"),
):
    """Programmatic / ShareX upload: raw file body + X-Filename header, authenticated by an
    API key. Mirrors /upload's streaming store (same limits/throttle) but returns JSON so a
    ShareX custom uploader can read $json:id$ / $json:delete_token$."""
    pool = await require_storage_ready()
    await update_settings()
    if not settings_cache.get("checked_at") or settings_cache.get("blocked_extensions") is None:
        raise HTTPException(status_code=503, detail="Upload settings unavailable")

    user_id = await resolve_api_key(authorization or x_api_key)
    if not user_id:
        raise HTTPException(status_code=401, detail="Invalid or missing API key")

    if not x_filename:
        raise HTTPException(status_code=400, detail="Missing X-Filename header")

    client_ip = request_client_ip(request)

    await enforce_ip_allowed(pool, client_ip)

    # Per-file cap: the user's group limit, never above the system max (same rule as /upload).
    current_max_size = settings_cache['max_file_size']
    user_limits = await get_user_group_limits(user_id)
    effective_max_size = current_max_size
    if user_limits and user_limits.get('max_file_size_mb', 0) > 0:
        effective_max_size = min(current_max_size, user_limits['max_file_size_mb'] * 1024 * 1024)

    x_filename = unquote(x_filename)
    if content_length > effective_max_size:
        raise HTTPException(status_code=413, detail=f"File too large (Max {effective_max_size/1024/1024:.0f} MiB)")
    if content_length <= 0:
        raise HTTPException(status_code=400, detail="Empty file")

    filename = await sanitize_filename_async(x_filename)
    file_id = generate_file_id()
    while (UPLOADS_DIR / file_id).exists() or any(STAGING_DIR.glob(file_id + "-*")):
        file_id = generate_file_id()

    file_dir = UPLOADS_DIR / file_id
    staging_dir = STAGING_DIR / f"{file_id}-{secrets.token_hex(8)}"
    storage_reservation = await reserve_upload_capacity(
        pool,
        user_id,
        user_limits,
        content_length,
        settings_cache.get('max_upload_folder_mb', 0),
    )
    try:
        staging_dir.mkdir(parents=False, exist_ok=False)
    except Exception:
        await release_upload_capacity(storage_reservation)
        raise
    file_path = staging_dir / filename

    received_size = 0
    storage_digest = hashlib.sha256()
    limit_Bps = max(0, safe_int(user_limits.get('limit_upload'), 0))

    try:
        async with aiofiles.open(file_path, 'wb') as f:
            stream_source = request.stream()
            if limit_Bps > 0:
                stream_source = throttled_iterator(stream_source, limit_Bps)
            async for chunk in stream_source:
                await f.write(chunk)
                storage_digest.update(chunk)
                received_size += len(chunk)
                if received_size > effective_max_size:
                    raise HTTPException(status_code=413, detail="File too large")
    except HTTPException:
        await remove_upload_directory(staging_dir)
        await release_upload_capacity(storage_reservation)
        raise
    except Exception as e:
        await remove_upload_directory(staging_dir)
        await release_upload_capacity(storage_reservation)
        raise _upload_failure(e) from e

    if content_length > 0 and received_size != content_length:
        await remove_upload_directory(staging_dir)
        await release_upload_capacity(storage_reservation)
        raise HTTPException(status_code=400, detail=f"Size mismatch: expected {content_length}, got {received_size}")

    try:
        delete_token = generate_delete_token()
        hashed_token = (
            await asyncio.to_thread(bcrypt.hashpw, delete_token.encode(), bcrypt.gensalt())
        ).decode()
        mime_type = mimetypes.guess_type(filename)[0] or 'application/octet-stream'

        await commit_staged_upload(
            staging_dir,
            file_dir,
            file_id,
            filename,
            mime_type,
            received_size,
            hashed_token,
            client_ip,
            user_id,
            storage_digest.hexdigest(),
        )
    except Exception:
        await remove_upload_directory(staging_dir)
        await release_upload_capacity(storage_reservation)
        raise
    await release_upload_capacity(storage_reservation)
    await enqueue_webhook_event(
        user_id,
        'upload',
        {'file': {'id': file_id, 'name': filename, 'mime': mime_type, 'size': received_size}},
    )
    METRICS["sharex_uploads"] += 1
    return JSONResponse({
        "success": True,
        "id": file_id,
        "delete_token": delete_token,
    })

# ============================================================================
# HEALTH & RELOAD ENDPOINTS
# ============================================================================

_qr_pending = 0
_qr_cache: OrderedDict = OrderedDict()


def _render_qr_svg(data: str, scale: int) -> bytes:
    """Draw one QR code as SVG. Runs on QR_EXECUTOR, never on the event loop."""
    import segno

    buf = io.BytesIO()
    segno.make(data, error='m').save(buf, kind='svg', scale=scale, border=2, dark='#111', light='#fff')
    return buf.getvalue()


def _qr_render_finished(future) -> None:
    """Free the render slot when the worker is really done, even if its caller went away."""
    global _qr_pending
    _qr_pending -= 1
    if not future.cancelled():
        future.exception()  # retrieved here, so an abandoned failure is not logged as lost


async def _render_qr(data: str, scale: int, cacheable: bool = True) -> Response:
    """SVG for a link or otpauth URI, drawn off the event loop.

    One render of the largest allowed payload holds the GIL for about 30 ms. On the event
    loop that was 30 ms in which no transfer moved, so about twenty anonymous requests a
    second stalled every upload and download. Links (`cacheable`) are kept in a small LRU;
    POSTed payloads carry TOTP secrets and are neither cached here nor by the browser.
    """
    global _qr_pending
    try:
        import segno
    except ImportError:
        raise HTTPException(status_code=501, detail="QR support not installed (pip install segno)")

    if not data or len(data.encode("utf-8", errors="replace")) > QR_DATA_MAX_BYTES:
        raise HTTPException(status_code=400, detail="Invalid data")

    scale = max(2, min(12, scale))
    key = (data, scale)
    body = _qr_cache.get(key) if cacheable else None
    if body is not None:
        _qr_cache.move_to_end(key)
    else:
        if _qr_pending >= QR_MAX_PENDING:
            raise HTTPException(
                status_code=503, detail="QR renderer is busy", headers={"Retry-After": "1"}
            )
        _qr_pending += 1
        future = asyncio.get_running_loop().run_in_executor(
            QR_EXECUTOR, _render_qr_svg, data, scale
        )
        future.add_done_callback(_qr_render_finished)
        try:
            body = await asyncio.shield(future)
        except segno.DataOverflowError:
            raise HTTPException(status_code=400, detail="Invalid data")
        if cacheable:
            _qr_cache[key] = body
            while len(_qr_cache) > QR_CACHE_ENTRIES:
                _qr_cache.popitem(last=False)
    return Response(
        content=body,
        media_type="image/svg+xml",
        headers={
            "Cache-Control": "public, max-age=86400" if cacheable else "no-store",
        },
    )


@app.get("/qr")
async def qr_code(data: str, scale: int = 6):
    """Render an SVG QR code for a link (used by the upload results UI)."""
    return await _render_qr(data, scale)


@app.post("/qr")
async def qr_code_post(request: Request):
    """Same renderer, payload in the body. Used for secrets (a TOTP `otpauth://` URI
    carries the shared secret) that must never land in a URL — query strings end up in
    access logs and browser history; request bodies do not."""
    raw = await read_bounded_body(request, QR_POST_MAX_BYTES)
    try:
        body = json.loads(raw)
    except (ValueError, UnicodeDecodeError):
        raise HTTPException(status_code=400, detail="Invalid JSON")
    if not isinstance(body, dict):
        raise HTTPException(status_code=400, detail="Invalid JSON")
    return await _render_qr(
        str(body.get("data") or ""), safe_int(body.get("scale"), 6), cacheable=False
    )


async def read_bounded_body(request: Request, limit: int) -> bytes:
    """Read a small request body, refusing anything larger before it is buffered.

    Starlette's request.body()/json() keep every chunk in memory with no ceiling, and Apache
    applies no body limit to the requests it proxies here, so this is the only one.
    """
    declared = request.headers.get("content-length")
    if declared is not None:
        try:
            declared_size = int(declared)
        except ValueError:
            raise HTTPException(status_code=400, detail="Invalid Content-Length")
        if declared_size > limit:
            raise HTTPException(status_code=413, detail="Request body too large")
    body = bytearray()
    async for chunk in request.stream():
        body += chunk
        if len(body) > limit:
            raise HTTPException(status_code=413, detail="Request body too large")
    return bytes(body)


@app.get("/live")
async def live_check():
    return {"status": "ok", "version": SERVER_VERSION}


async def _readiness_state() -> dict:
    await require_storage_ready(force=True)
    await update_settings()
    if not settings_cache.get("checked_at") or settings_cache.get("blocked_extensions") is None:
        raise HTTPException(status_code=503, detail="Upload settings unavailable")
    return {
        "status": "ready",
        "version": SERVER_VERSION,
        "schema_version": SUPPORTED_SCHEMA_VERSION,
    }


@app.get("/ready")
async def ready_check():
    """Operator readiness probe (loopback only, never proxied): always probes afresh.

    A failure says what failed. For storage that is the path, the error (EROFS, EACCES, …)
    and the usual fix: under the systemd sandbox a path PHP can write may still be read-only
    for this service, which the panel's own check cannot see.
    """
    try:
        state = await _readiness_state()
    except HTTPException as exc:
        body = {"status": "unavailable", "version": SERVER_VERSION, "detail": str(exc.detail)}
        problem = getattr(exc, "problem", None)
        if problem:
            body["storage"] = problem
        return JSONResponse(status_code=exc.status_code, content=body)
    return {**state, "uploads_dir": str(UPLOADS_DIR)}


@app.get("/health")
async def health_check():
    """Backward-compatible readiness endpoint: unhealthy dependencies produce HTTP 503.

    PHP relays a summary of this to the public `api.php?action=health`, so it carries no
    filesystem paths; /ready (loopback only) still reports the uploads directory.
    """
    try:
        ready = await _readiness_state()
        return {
            **ready,
            "status": "ok",
            "database_configured": bool(load_php_config().get("database")),
            "database_connected": True,
        }
    except HTTPException as exc:
        return JSONResponse(
            status_code=503,
            content={
                "status": "unavailable",
                "version": SERVER_VERSION,
                "detail": str(exc.detail),
                "database_configured": bool(load_php_config().get("database")),
                "database_connected": False,
            },
        )


@app.api_route("/reload", methods=["GET", "POST"])
async def reload_config(request: Request):
    """Re-read config.local.php after the web installer and refresh cached settings.

    Only a local caller may ask (Apache never proxies this path, and anything it did proxy
    would carry X-Forwarded-For). The pool is rebuilt only when the database configuration
    actually changed, so repeating the call costs a file read, not every live connection.
    """
    global db_config_mtime

    if not request_is_direct_loopback(request):
        raise HTTPException(status_code=403, detail="Reload is restricted to local callers")

    async with db_pool_lock:
        db_config_mtime = -1  # forces a re-read; the pool is replaced only if it differs
        settings_cache['checked_at'] = 0
        readiness_cache["pool"] = None
        pool = await _get_db_pool_unlocked()

    return {
        "status": "reloaded",
        "database_connected": pool is not None
    }

# ============================================================================
# DOWNLOAD LIMITS & TRACKING
# ============================================================================

async def _reserve_download_slot_with_cursor(
    cur,
    prefix: str,
    ip: str,
    user_id: int | None,
    file_id: str,
    group_limits: dict,
) -> int:
    active_table = f"{prefix}active_downloads"
    now = int(time.time())
    await cur.execute(
        f"DELETE FROM `{active_table}` WHERE `heartbeat_at` < %s",
        (now - 3600,),
    )
    group_files = safe_int(group_limits.get('concurrent_downloads'), 0)
    group_conn = safe_int(group_limits.get('concurrent_connections_per_file'), 0)
    limit_files = max(0, group_files)
    limit_conn = max(0, group_conn)
    # Counted per throttle key: every address of one IPv6 /64 shares one budget.
    same_client, client_params = _same_client_condition("ip_address", ip)

    if limit_conn > 0:
        await cur.execute(
            f"SELECT COUNT(*) FROM `{active_table}` "
            f"WHERE {same_client} AND `file_id` = %s AND `heartbeat_at` >= %s",
            (*client_params, file_id, now - 3600),
        )
        if safe_int((await cur.fetchone() or (0,))[0]) >= limit_conn:
            raise HTTPException(
                status_code=429,
                detail="Too many concurrent connections for this file.",
            )

    if limit_files > 0:
        await cur.execute(
            f"SELECT COUNT(DISTINCT `file_id`) FROM `{active_table}` "
            f"WHERE {same_client} AND `file_id` <> %s AND `heartbeat_at` >= %s",
            (*client_params, file_id, now - 3600),
        )
        if safe_int((await cur.fetchone() or (0,))[0]) >= limit_files:
            raise HTTPException(status_code=429, detail="Too many concurrent downloads.")

    await cur.execute(
        f"INSERT INTO `{active_table}` "
        f"(`ip_address`, `file_id`, `started_at`, `instance_id`, `heartbeat_at`) "
        f"VALUES (%s, %s, %s, %s, %s)",
        (ip, file_id, now, INSTANCE_ID, now),
    )
    active_id = safe_int(cur.lastrowid)
    if active_id < 1:
        raise HTTPException(status_code=503, detail="Could not reserve download slot")
    return active_id


async def _count_active_counter_reservations(
    cur,
    prefix: str,
    resource_type: str,
    resource_id: str,
) -> int:
    effects = f"{prefix}download_reservation_effects"
    reservations = f"{prefix}download_reservations"
    await cur.execute(
        f"SELECT COUNT(*) FROM `{effects}` e "
        f"JOIN `{reservations}` r ON r.`id` = e.`reservation_id` "
        f"WHERE e.`effect_type` = 'counter' AND e.`resource_type` = %s "
        f"AND e.`resource_id` = %s "
        f"AND r.`state` IN ('reserved', 'started')",
        (resource_type, resource_id),
    )
    return safe_int((await cur.fetchone() or (0,))[0])


def _quota_period_window(period: str, now: int | None = None) -> tuple[int, int]:
    """UTC calendar window used consistently by reservations and the PHP status view."""
    current = datetime.fromtimestamp(now or int(time.time()), UTC)
    if period == "day":
        start = current.replace(hour=0, minute=0, second=0, microsecond=0)
        end = start + timedelta(days=1)
    elif period == "month":
        start = current.replace(day=1, hour=0, minute=0, second=0, microsecond=0)
        end = (
            start.replace(year=start.year + 1, month=1)
            if start.month == 12
            else start.replace(month=start.month + 1)
        )
    elif period == "year":
        start = current.replace(month=1, day=1, hour=0, minute=0, second=0, microsecond=0)
        end = start.replace(year=start.year + 1)
    else:
        period = "week"
        midnight = current.replace(hour=0, minute=0, second=0, microsecond=0)
        start = midnight - timedelta(days=midnight.weekday())
        end = start + timedelta(days=7)
    return int(start.timestamp()), int(end.timestamp())


async def _reserve_transfer_quota_with_cursor(
    cur,
    prefix: str,
    user_id: int | None,
    ip: str,
    group_limits: dict,
    requested_bytes: int,
) -> dict | None:
    limit = max(0, safe_int(group_limits.get("transfer_quota_bytes"), 0))
    requested = max(0, safe_int(requested_bytes, 0))
    if limit <= 0 or requested <= 0:
        return None
    period = str(group_limits.get("transfer_quota_period") or "week")
    if period not in {"day", "week", "month", "year"}:
        period = "week"
    period_start, _ = _quota_period_window(period)
    # Guests are one subject per throttle key, the row TransferQuotaRepository reads as well.
    subject_type = "user" if user_id else "ip"
    subject_key = str(user_id) if user_id else throttle_key(ip)
    table = f"{prefix}transfer_quota_usage"
    now = int(time.time())
    await cur.execute(
        f"INSERT INTO `{table}` (`subject_type`, `subject_key`, `period`, "
        f"`period_start`, `used_bytes`, `reserved_bytes`, `updated_at`) "
        f"VALUES (%s, %s, %s, %s, 0, 0, %s) "
        f"ON DUPLICATE KEY UPDATE `updated_at` = VALUES(`updated_at`)",
        (subject_type, subject_key, period, period_start, now),
    )
    await cur.execute(
        f"SELECT `used_bytes`, `reserved_bytes` FROM `{table}` "
        f"WHERE `subject_type` = %s AND `subject_key` = %s AND `period` = %s "
        f"AND `period_start` = %s FOR UPDATE",
        (subject_type, subject_key, period, period_start),
    )
    used, reserved = await cur.fetchone() or (0, 0)
    if safe_int(used) + safe_int(reserved) + requested > limit:
        raise HTTPException(
            status_code=429,
            detail="Transfer allowance exceeded for the current period.",
        )
    await cur.execute(
        f"UPDATE `{table}` SET `reserved_bytes` = `reserved_bytes` + %s, "
        f"`updated_at` = %s WHERE `subject_type` = %s AND `subject_key` = %s "
        f"AND `period` = %s AND `period_start` = %s",
        (requested, now, subject_type, subject_key, period, period_start),
    )
    return {
        "subject_type": subject_type,
        "subject_key": subject_key,
        "period": period,
        "period_start": period_start,
        "reserved_bytes": requested,
    }


async def _create_download_reservation_with_cursor(
    cur,
    prefix: str,
    resource_type: str,
    resource_id: str,
    token: str,
    active_id: int,
    user_id: int | None,
    ip: str,
    effects_to_reserve: list[tuple[str, str, str]],
    quota_reservation: dict | None = None,
) -> str:
    if resource_type not in {"file", "collection"}:
        raise HTTPException(status_code=503, detail="Invalid download reservation type")
    reservation_id = secrets.token_hex(16)
    now = int(time.time())
    table = f"{prefix}download_reservations"
    token_table = f"{prefix}download_tokens"
    effects_table = f"{prefix}download_reservation_effects"

    await cur.execute(
        f"UPDATE `{token_table}` SET `used` = 1, `reservation_id` = %s, "
        f"`reserved_until` = %s, `used_at` = NULL "
        f"WHERE `token` = %s AND `used` = 0",
        (reservation_id, now + DOWNLOAD_LEASE_SECONDS, token),
    )
    if cur.rowcount != 1:
        raise HTTPException(status_code=403, detail="Invalid download token")

    await cur.execute(
        f"INSERT INTO `{table}` "
        f"(`id`, `resource_type`, `resource_id`, `token_fingerprint`, "
        f"`active_download_id`, `user_id`, `ip_address`, `state`, `bytes_sent`, "
        f"`lease_until`, `created_at`, `updated_at`, `quota_subject_type`, "
        f"`quota_subject_key`, `quota_period`, `quota_period_start`, "
        f"`quota_reserved_bytes`) "
        f"VALUES (%s, %s, %s, %s, %s, %s, %s, 'reserved', 0, %s, %s, %s, "
        f"%s, %s, %s, %s, %s)",
        (
            reservation_id,
            resource_type,
            resource_id,
            hashlib.sha256(token.encode()).hexdigest(),
            active_id,
            user_id,
            ip,
            now + DOWNLOAD_LEASE_SECONDS,
            now,
            now,
            quota_reservation.get("subject_type") if quota_reservation else None,
            quota_reservation.get("subject_key") if quota_reservation else None,
            quota_reservation.get("period") if quota_reservation else None,
            quota_reservation.get("period_start") if quota_reservation else None,
            quota_reservation.get("reserved_bytes", 0) if quota_reservation else 0,
        ),
    )
    if cur.rowcount != 1:
        raise HTTPException(status_code=503, detail="Could not create download reservation")

    unique_effects = sorted(set(effects_to_reserve))
    for effect_type, effect_resource_type, effect_resource_id in unique_effects:
        if (
            effect_type not in {"counter", "one_time"}
            or effect_resource_type not in {"file", "collection"}
        ):
            raise HTTPException(status_code=503, detail="Invalid download effect")
        if effect_type == "one_time":
            resource_table = (
                f"{prefix}files"
                if effect_resource_type == "file"
                else f"{prefix}collections"
            )
            await cur.execute(
                f"UPDATE `{resource_table}` SET `consume_reservation_id` = %s "
                f"WHERE `id` = %s AND `consumed_at` IS NULL "
                f"AND `consume_reservation_id` IS NULL",
                (reservation_id, effect_resource_id),
            )
            if cur.rowcount != 1:
                raise HTTPException(
                    status_code=410,
                    detail="A one-time link is already reserved or consumed",
                )
        await cur.execute(
            f"INSERT INTO `{effects_table}` "
            f"(`reservation_id`, `effect_type`, `resource_type`, `resource_id`, `applied_at`) "
            f"VALUES (%s, %s, %s, %s, NULL)",
            (
                reservation_id,
                effect_type,
                effect_resource_type,
                effect_resource_id,
            ),
        )
    return reservation_id


async def register_download_end(active_id: int):
    """Drop a finished download's live row (no-op if the finalization already did)."""
    if not active_id:
        return
        
    pool = await get_db_pool()
    if not pool:
        return
        
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}active_downloads"
        
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"DELETE FROM `{table}` WHERE `id` = %s AND `instance_id` = %s",
                    (active_id, INSTANCE_ID),
                )
    except Exception as e:
        print(f"Download deregistration failed: {e}")

async def notification_allowed(cur, prefix: str, user_id: int, notif_type: str) -> bool:
    """
    May this account be told about `notif_type` in the app?

    A faithful mirror of Notifications::allows() on the PHP side, which stays the source of
    truth: the operator's per-type `enabled` veto wins, then the account's own preference, then
    the operator's default, then the type's built-in default. Only the in-app channel is
    considered — e-mail belongs to PHP, which owns the templates and the SMTP settings.
    """
    try:
        await cur.execute(
            f"SELECT `setting_value` FROM `{prefix}settings` WHERE `setting_key` = 'notification_defaults'"
        )
        row = await cur.fetchone()
        defaults = json.loads(row[0]) if row and row[0] else {}
    except Exception:
        defaults = {}

    conf = defaults.get(notif_type) if isinstance(defaults, dict) else None
    if isinstance(conf, dict) and not conf.get('enabled', True):
        return False

    try:
        await cur.execute(
            f"SELECT `prefs` FROM `{prefix}notification_prefs` WHERE `user_id` = %s", (user_id,)
        )
        row = await cur.fetchone()
        prefs = json.loads(row[0]) if row and row[0] else {}
    except Exception:
        prefs = {}

    mine = prefs.get(notif_type) if isinstance(prefs, dict) else None
    if isinstance(mine, dict) and 'app' in mine:
        return bool(mine['app'])
    if isinstance(conf, dict) and 'app' in conf:
        return bool(conf['app'])
    return True  # every download-ish type ships on by default (see Notifications::TYPES)


async def push_notification(cur, prefix: str, user_id: int, notif_type: str,
                            subject: str, link: str, group_key: str):
    """
    Write one stacked notification, exactly as NotificationRepository::push() does.

    UPDATE first, INSERT only if it matched nothing: a popular link is one line whose counter
    rises, and reading that line closes the stack so the next fetch is news again. The 24-hour
    window and the `read_at IS NULL` clause are the same numbers as the PHP side — change one,
    change the other.
    """
    now = int(time.time())
    try:
        await cur.execute(
            f"UPDATE `{prefix}notifications` SET `count` = `count` + 1, `updated_at` = %s, "
            f"`subject` = %s, `link` = %s "
            f"WHERE `user_id` = %s AND `group_key` = %s AND `read_at` IS NULL AND `updated_at` >= %s",
            (now, subject[:255], link[:255], user_id, group_key[:80], now - 86400)
        )
        if cur.rowcount:
            return
        await cur.execute(
            f"INSERT INTO `{prefix}notifications` "
            f"(`user_id`, `type`, `group_key`, `subject`, `link`, `count`, `created_at`, `updated_at`) "
            f"VALUES (%s, %s, %s, %s, %s, 1, %s, %s)",
            (user_id, notif_type[:40], group_key[:80], subject[:255], link[:255], now, now)
        )
    except Exception:
        # A missing table means an installation that has not run the v27 migration yet; a
        # download must not fail over a notification either way.
        pass


async def register_upload_start(
    ip: str, filename: str, size: int, user_id=None, client_id: str | None = None
):
    """
    Announce an upload that has just begun, so the dashboard can show it while it runs.

    The counterpart of register_download_start(). Returns the row id (or None), which the
    streaming loop uses to report progress and the finally-block uses to clear the row.
    """
    pool = await get_db_pool()
    if not pool:
        return None
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        now = int(time.time())
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"INSERT INTO `{prefix}active_uploads` "
                    f"(`ip_address`, `user_id`, `filename`, `size`, `received`, "
                    f"`started_at`, `updated_at`, `client_id`, `status`) "
                    f"VALUES (%s, %s, %s, %s, 0, %s, %s, %s, 'active')",
                    (
                        ip,
                        user_id,
                        filename[:255],
                        max(0, safe_int(size)),
                        now,
                        now,
                        client_id,
                    )
                )
                return cur.lastrowid
    except Exception:
        # A missing table means the v29 migration has not run; an upload must not fail over
        # a progress row nobody is watching.
        return None


async def update_upload_progress(row_id, received: int):
    """How far along an upload is. Called at most once a second by the streaming loop."""
    if not row_id:
        return
    pool = await get_db_pool()
    if not pool:
        return
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"UPDATE `{prefix}active_uploads` SET `received` = %s, `updated_at` = %s "
                    f"WHERE `id` = %s AND `status` = 'active'",
                    (received, int(time.time()), row_id)
                )
    except Exception:
        pass


async def upload_was_killed(row_id) -> bool:
    """
    Has an admin severed this upload? (its `active_uploads` row deleted)

    The mirror of download_was_killed(). It works for the same reason: the row is the handle,
    and the loop that is moving the bytes is the thing that checks for it. Cutting an upload is
    not a matter of "telling the client to stop" — we simply stop reading its body and raise,
    which closes the connection, and the partial file is removed by the same cleanup that
    handles any other failed upload.
    """
    if not row_id:
        return False
    pool = await get_db_pool()
    if not pool:
        return False
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"SELECT `status` FROM `{prefix}active_uploads` WHERE `id` = %s",
                    (row_id,),
                )
                row = await cur.fetchone()
                return row is None or str(row[0]) != "active"
    except Exception:
        return False   # a DB hiccup must not abort a healthy upload


async def register_upload_end(row_id, cancelled: bool = False, received: int = 0):
    """The transfer is over — finished, refused or aborted. Either way it is no longer live."""
    if not row_id:
        return
    pool = await get_db_pool()
    if not pool:
        return
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                if cancelled:
                    await cur.execute(
                        f"UPDATE `{prefix}active_uploads` SET `status` = 'cancelled', "
                        f"`received` = %s, `updated_at` = %s WHERE `id` = %s",
                        (max(0, safe_int(received)), int(time.time()), row_id),
                    )
                else:
                    await cur.execute(
                        f"DELETE FROM `{prefix}active_uploads` WHERE `id` = %s",
                        (row_id,),
                    )
    except Exception:
        pass


async def notify_file_download(
    file_id: str,
    viewer_id=None,
    owner_id=None,
    original_name=None,
):
    """
    Notify the owner after the transaction has already reserved the download counter.

    `viewer_id` is whoever is doing the downloading (from the download token). It is used for
    one thing: not telling somebody that they themselves fetched their own file, which is both
    useless and the fastest way to make the bell worth ignoring. Mirrors the same rule in
    FileManager::notifyOwnerOfDownload().
    """
    pool = await get_db_pool()
    if not pool:
        return

    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}files"

        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                if owner_id is None:
                    await cur.execute(
                        f"SELECT `user_id`, `original_name` FROM `{table}` "
                        f"WHERE `id` = %s",
                        (file_id,),
                    )
                    row = await cur.fetchone()
                    if row:
                        owner_id, original_name = row
                if owner_id and int(owner_id) != safe_int(viewer_id):
                    user_id, name = int(owner_id), (original_name or file_id)
                    if await notification_allowed(cur, prefix, user_id, 'file.downloaded'):
                        # A root-relative link: this process has no idea what host the site is
                        # served under (PHP derives APP_URL from the request), and the browser
                        # resolves it against whatever page the bell is on either way.
                        await push_notification(
                            cur, prefix, user_id, 'file.downloaded', name,
                            f"/download.php?id={file_id}", f"file.downloaded:{file_id}"
                        )
    except Exception as exc:
        print(f"   Warning: download notification failed ({type(exc).__name__})")

QUARANTINE_MANIFEST_VERSION = 1


def _manifest_value(value):
    """A column value the way PHP's PDO hands it to json_encode() in a quarantine manifest."""
    if isinstance(value, (bytes, bytearray)):
        return bytes(value).decode("utf-8", errors="replace")
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d %H:%M:%S")
    if isinstance(value, date):
        return value.isoformat()
    return value


def _quarantine_manifest(
    file_row: dict,
    memberships: list,
    deleted_at: int,
    reason: str,
    actor_type: str,
    actor_id: str | None = None,
) -> str:
    """The manifest FileQuarantine::enqueue() writes, which FileQuarantine::restore() reads.

    tests/fixtures/sidecar-quarantine.json pins this exact output, and a PHP test restores a
    file from it, so the two sides cannot drift apart unnoticed.
    """
    return json.dumps(
        {
            "version": QUARANTINE_MANIFEST_VERSION,
            "file": {column: _manifest_value(value) for column, value in file_row.items()},
            "collections": [
                {column: _manifest_value(value) for column, value in membership.items()}
                for membership in memberships
            ],
            "deleted_at": deleted_at,
            "reason": reason,
            "actor": {"type": actor_type, "id": actor_id},
        },
        ensure_ascii=False,
        separators=(",", ":"),
    )


async def _enqueue_file_quarantine(cur, prefix: str, file_id: str, file_row: dict, now: int) -> bool:
    """Capture restore metadata in the caller's deletion transaction, as PHP does.

    The mirror of FileQuarantine::enqueue(): with `file_quarantine_days` above zero the PHP
    deletion worker then copies and verifies the bytes before it removes them, so the file
    can be brought back with `scripts/file-quarantine.php restore`. Zero keeps the immediate
    purge. Returns whether a quarantine row was written.
    """
    await cur.execute(
        f"SELECT `setting_value` FROM `{prefix}settings` "
        f"WHERE `setting_key` = 'file_quarantine_days'"
    )
    row = await cur.fetchone()
    days = max(0, min(3650, safe_int(row[0] if row else 0, 0)))
    if days < 1:
        return False
    await cur.execute(
        f"SELECT `collection_id`, `position` FROM `{prefix}collection_files` "
        f"WHERE `file_id` = %s ORDER BY `collection_id`, `position`",
        (file_id,),
    )
    memberships = [
        {"collection_id": collection_id, "position": position}
        for collection_id, position in await cur.fetchall()
    ]
    manifest = _quarantine_manifest(file_row, memberships, now, "download_limit", "system")
    values = (
        manifest, "download_limit", "system", None, safe_int(file_row.get("size"), 0),
        now + days * 86400,
    )
    await cur.execute(
        f"INSERT INTO `{prefix}file_quarantine` "
        f"(`file_id`, `manifest_json`, `reason`, `actor_type`, `actor_id`, `size`, "
        f"`checksum`, `state`, `quarantine_until`, `attempts`, `next_attempt_at`, "
        f"`last_error`, `created_at`, `updated_at`) "
        f"VALUES (%s, %s, %s, %s, %s, %s, NULL, 'pending', %s, 0, 0, NULL, %s, %s) "
        f"ON DUPLICATE KEY UPDATE `manifest_json` = %s, `reason` = %s, `actor_type` = %s, "
        f"`actor_id` = %s, `size` = %s, `checksum` = NULL, `state` = 'pending', "
        f"`quarantine_until` = %s, `attempts` = 0, `next_attempt_at` = 0, "
        f"`last_error` = NULL, `created_at` = %s, `updated_at` = %s",
        (file_id, *values, now, now, *values, now, now),
    )
    return True


async def delete_file_after_limit(file_id: str):
    """
    Remove a file whose download cap has just been spent, when its owner chose
    `on_limit_action = 'delete'`.

    Runs after the response finished streaming, under PHP's deletion contract
    (FileManager::deleteLockedFile): the file is captured for quarantine when retention is
    on, the owner's webhooks get a `delete` event, and the row and its relations disappear
    in one transaction while physical cleanup is handed to PHP's durable deletion outbox.
    """
    pool = await get_db_pool()
    if not pool:
        return
    quarantined = False
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        async with pool.acquire() as conn:
            try:
                async with conn.cursor() as cur:
                    await cur.execute("START TRANSACTION")
                    await cur.execute(
                        f"SELECT * FROM `{prefix}files` WHERE `id` = %s FOR UPDATE",
                        (file_id,),
                    )
                    row = await cur.fetchone()
                    if not row:
                        await cur.execute("ROLLBACK")
                        return
                    file_row = dict(zip((column[0] for column in cur.description), row, strict=True))
                    now = int(time.time())
                    quarantined = await _enqueue_file_quarantine(cur, prefix, file_id, file_row, now)
                    # Same transaction: the owner hears about the deletion only if it commits.
                    await enqueue_webhook_event(
                        file_row.get("user_id"),
                        "delete",
                        {"file": {
                            "id": file_id,
                            "name": file_row.get("original_name"),
                            "mime": file_row.get("mime_type"),
                            "size": safe_int(file_row.get("size"), 0),
                        }},
                        cursor=cur,
                    )
                    await cur.execute(
                        f"DELETE FROM `{prefix}collection_files` WHERE `file_id` = %s",
                        (file_id,),
                    )
                    await cur.execute(
                        f"DELETE FROM `{prefix}reports` WHERE `file_id` = %s",
                        (file_id,),
                    )
                    await cur.execute(
                        f"INSERT INTO `{prefix}file_deletion_queue` "
                        f"(`file_id`, `attempts`, `next_attempt_at`, `last_error`, `created_at`) "
                        f"VALUES (%s, 0, 0, NULL, %s) "
                        f"ON DUPLICATE KEY UPDATE `next_attempt_at` = 0, `last_error` = NULL",
                        (file_id, int(time.time())),
                    )
                    await cur.execute(
                        f"DELETE FROM `{prefix}files` WHERE `id` = %s",
                        (file_id,),
                    )
                    if cur.rowcount != 1:
                        raise RuntimeError("File changed during post-limit deletion")
                    await cur.execute("COMMIT")
            except Exception:
                await conn.rollback()
                raise
        info_log(
            f"{file_id} deleted after reaching its download limit"
            + (" (kept in quarantine)" if quarantined else "")
        )
    except Exception as e:
        print(f"⚠️  Post-limit delete failed for {file_id}: {e}")


async def log_traffic(
    ip: str,
    size: int,
    transfer_type: str,
    file_id: str = None,
    user_id: int = None,
    cursor=None,
):
    """Log bandwidth usage"""
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}traffic_logs"

        async def insert_row(active_cursor):
            await active_cursor.execute(
                f"INSERT INTO `{table}` (`ip_address`, `transfer_size`, `transfer_type`, `file_id`, `user_id`, `created_at`) VALUES (%s, %s, %s, %s, %s, %s)",
                (ip, size, transfer_type, file_id, user_id, int(time.time()))
            )

        # Upload endpoints already hold a connection while inserting the file row. Reusing
        # their cursor avoids a recursive acquire and the resulting pool-exhaustion deadlock.
        if cursor is not None:
            await insert_row(cursor)
            return

        pool = await get_db_pool()
        if not pool:
            return

        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await insert_row(cur)
    except Exception as e:
        print(f"⚠️  Traffic log error: {e}")

# ============================================================================
# DOWNLOAD ENDPOINT
# ============================================================================

@app.get("/download")
async def download_file(token: str, request: Request, background_tasks: BackgroundTasks):
    """
    Stream a full or Range download with bounded memory and an administrative kill check.
    """
    client_ip = request_client_ip(request)
    token_fingerprint = hashlib.sha256(token.encode()).hexdigest()[:12]

    # Ensure settings are up to date
    try:
        await update_settings()
    except Exception as e:
        print(f"❌ update_settings error: {e}")
        raise HTTPException(status_code=503, detail="Settings unavailable")
    
    pool = await require_storage_ready()

    file_id = None
    user_id = None
    original_name = "file"
    file_size = 0
    mime_type = "application/octet-stream"
    row = None  # Initialize to avoid UnboundLocalError
    actual_file_path = None
    active_id = 0
    download_lock_name = None
    conn = None
    committed = False
    limit_Bps = 0
    range_spec = None
    reservation_id = ""
    transfer_state = {"interrupted": False}

    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        token_table = f"{prefix}download_tokens"
        files_table = f"{prefix}files"

        conn = await pool.acquire()  # a connection, or HTTPException 503 after the deadline
        async with conn.cursor() as cur:
                await cur.execute("START TRANSACTION")
                # Single source of truth for the download-token lifetime, shared
                # with PHP (Database::createDownloadToken / verifyUseDownloadToken).
                # Default 900s (15 min); admin may override via `download_token_ttl`.
                settings_table = f"{prefix}settings"
                token_ttl = 900
                try:
                    await cur.execute(
                        f"SELECT `setting_value` FROM `{settings_table}` WHERE `setting_key` = %s",
                        ('download_token_ttl',)
                    )
                    ttl_row = await cur.fetchone()
                    if ttl_row and ttl_row[0] is not None and str(ttl_row[0]).strip() != '':
                        token_ttl = int(ttl_row[0])
                except Exception:
                    pass
                valid_time = int(time.time()) - token_ttl

                await cur.execute(
                    f"SELECT `file_id`, `user_id` FROM `{token_table}` WHERE `token` = %s "
                    f"AND `ip_address` = %s AND `used` = 0 AND `created_at` > %s FOR UPDATE",
                    (token, client_ip, valid_time)
                )
                row = await cur.fetchone()
                if row:
                    file_id = row[0]
                    user_id = row[1]

                if not row:
                    raise HTTPException(status_code=403, detail="Invalid token")
                
                # Sharing-control columns are mandatory. An outdated schema is a readiness
                # failure, never a reason to silently disable expiry/caps/one-time.
                owner_id = None
                await cur.execute(
                    f"SELECT `original_name`, `mime_type`, `size`, `expires_at`, "
                    f"`max_downloads`, `downloads`, `one_time`, `consumed_at`, "
                    f"`consume_reservation_id`, `user_id` "
                    f"FROM `{files_table}` WHERE `id` = %s FOR UPDATE",
                    (file_id,),
                )
                file_row = await cur.fetchone()
                expires_at = file_row[3] if file_row else None
                max_downloads = file_row[4] if file_row else None
                current_downloads = file_row[5] if file_row else 0
                one_time = file_row[6] if file_row else 0
                consumed_at = file_row[7] if file_row else None
                consume_reservation_id = file_row[8] if file_row else None
                owner_id = file_row[9] if file_row else None

                if not file_row:
                    raise HTTPException(status_code=404, detail="File info not found")

                # Enforce per-file expiry / download cap before any response bytes are sent.
                if expires_at and int(expires_at) < int(time.time()):
                    raise HTTPException(status_code=410, detail="This link has expired")
                if max_downloads:
                    active_counter_reservations = (
                        await _count_active_counter_reservations(
                            cur, prefix, "file", file_id
                        )
                    )
                    if (
                        int(current_downloads) + active_counter_reservations
                        >= int(max_downloads)
                    ):
                        # The cap was already spent. With on_limit_action = 'delete' the file was
                        # removed when it was reached, so getting here means 'keep': the row stays
                        # and simply stops serving.
                        raise HTTPException(status_code=410, detail="Download limit reached")

                original_name = file_row[0]
                mime_type = file_row[1]
                file_size = file_row[2]

                actual_file_path = await resolve_storage_file(
                    file_id,
                    str(original_name),
                    safe_int(file_size, -1),
                )
                if not actual_file_path:
                    raise HTTPException(status_code=404, detail="File content missing")

                # Same connection: a second acquire while this one is held could deadlock
                # the pool under a burst of signed-in downloads.
                group_limits = await get_user_group_limits(user_id, cur)
                group_dl = safe_int(group_limits.get('limit_download'), 0)
                limit_Bps = max(0, group_dl)
                range_spec = parse_single_range_header(
                    request.headers.get('range', ''),
                    safe_int(file_size),
                )
                download_lock_name = _download_lock_name(client_ip)
                await cur.execute("SELECT GET_LOCK(%s, 5)", (download_lock_name,))
                lock_row = await cur.fetchone()
                if not lock_row or safe_int(lock_row[0]) != 1:
                    raise HTTPException(status_code=503, detail="Download limiter is busy")
                active_id = await _reserve_download_slot_with_cursor(
                    cur, prefix, client_ip, user_id, file_id, group_limits
                )
                requested_bytes = safe_int(file_size)
                if range_spec is not None:
                    requested_bytes = range_spec[1] - range_spec[0] + 1
                quota_reservation = await _reserve_transfer_quota_with_cursor(
                    cur,
                    prefix,
                    user_id,
                    client_ip,
                    group_limits,
                    requested_bytes,
                )

                if one_time:
                    if consumed_at is not None or consume_reservation_id is not None:
                        raise HTTPException(
                            status_code=410,
                            detail="This one-time link has already been used",
                        )
                effects_to_reserve = [("counter", "file", str(file_id))]
                if one_time:
                    effects_to_reserve.append(("one_time", "file", str(file_id)))
                reservation_id = await _create_download_reservation_with_cursor(
                    cur,
                    prefix,
                    "file",
                    file_id,
                    token,
                    active_id,
                    user_id,
                    client_ip,
                    effects_to_reserve,
                    quota_reservation,
                )
                await cur.execute("COMMIT")
                committed = True
                # Registered the moment the reservation is durable, so the accounting
                # middleware settles it whatever happens after this point.
                request.scope["filehost.traffic"] = {
                    "ip": client_ip,
                    "file_id": file_id,
                    "user_id": user_id,
                    "active_id": active_id,
                    "reservation_id": reservation_id,
                    "transfer_state": transfer_state,
                    "completion": {
                        "kind": "file",
                        "resource_id": file_id,
                        "viewer_id": user_id,
                        "owner_id": owner_id,
                        "name": original_name,
                        "mime": mime_type,
                        "size": file_size,
                    },
                }

    except HTTPException:
        raise
    except Exception as e:
        print(f"❌ DL Error: {type(e).__name__}: {e}")
        import traceback
        traceback.print_exc()
        raise HTTPException(status_code=500, detail="Internal Error")
    finally:
        if conn is not None:
            try:
                if not committed:
                    await conn.rollback()
                if download_lock_name:
                    async with conn.cursor() as release_cur:
                        await release_cur.execute(
                            "SELECT RELEASE_LOCK(%s)", (download_lock_name,)
                        )
            except Exception:
                pass
            finally:
                pool.release(conn)

    # CI-only synchronization window for a real socket-close-before-body test. The branch is
    # impossible to activate unless the isolated container explicitly opts into CI smoke mode.
    if (
        CI_PREBODY_DELAY_SECONDS > 0
        and request.headers.get("x-filehost-ci-prebody") == "1"
    ):
        await asyncio.sleep(CI_PREBODY_DELAY_SECONDS)
    # Completion-only notifications, webhooks, metrics and post-limit deletion are finalized
    # by the accounting middleware after the complete response body has been accepted.
    # Clean up after download completes (or connection closes)
    background_tasks.add_task(register_download_end, active_id)

    # Prepare headers with proper Unicode filename encoding (RFC 5987)
    # Some filenames contain non-ASCII characters (Japanese, Chinese, etc.)
    # HTTP headers must be latin-1, so we need special encoding
    
    # ASCII-safe fallback filename (remove non-ASCII chars)
    ascii_filename = original_name.encode('ascii', 'ignore').decode('ascii').strip()
    if not ascii_filename:
        ascii_filename = 'file'
    # Remove any quotes that might break the header
    ascii_filename = ascii_filename.replace('"', "'")
    
    # UTF-8 encoded filename for modern browsers
    utf8_filename = quote(original_name, safe='')
    
    # Use both formats for maximum compatibility
    content_disposition = f"attachment; filename=\"{ascii_filename}\"; filename*=UTF-8''{utf8_filename}"
    
    headers = {
        "Content-Disposition": content_disposition,
        "Content-Length": str(file_size),
        "Accept-Ranges": "bytes",
        "X-Content-Type-Options": "nosniff",
    }

    # One line per started transfer, naming the token only by its fingerprint and never the
    # visitor's file name (Apache already logs who asked).
    info_log(f"Download {token_fingerprint}: file {file_id}")
    status_code = 200
    range_start = 0
    range_end = None
    if range_spec is not None:
        range_start, range_end = range_spec
        headers["Content-Range"] = f"bytes {range_start}-{range_end}/{file_size}"
        headers["Content-Length"] = str(range_end - range_start + 1)
        status_code = 206
    # The reader holds the file open for as long as it is suspended at a yield. A consumer
    # that stops early — a cancelled request, a client that closes once Content-Length is
    # satisfied — leaves the generator suspended, so `async with aiofiles.open(...)` inside it
    # never runs its exit and the descriptor stays open for the life of the process. That
    # leaks a descriptor per download, keeps deleted files occupying disk on Linux, and on
    # Windows makes the file undeletable outright, so purging it after `action=delete` fails
    # and the bytes are orphaned. Closing the generator explicitly once the response is done
    # is what guarantees the exit runs; on a reader that already finished it is a no-op.
    reader = throttled_file_reader(
        actual_file_path,
        limit_Bps,
        active_id,
        range_start,
        range_end,
        transfer_state,
    )
    background_tasks.add_task(close_stream_reader, reader)

    return StreamingResponse(
        reader,
        status_code=status_code,
        media_type=mime_type,
        headers=headers,
    )

# ============================================================================
# WEBHOOKS — enqueue user events + a delivery worker (Faza 4.1)
# ============================================================================

async def enqueue_webhook_event(user_id, event: str, payload: dict, cursor=None):
    """Queue a delivery row per active webhook of user_id subscribed to `event`.

    Cheap and non-blocking on the hot path — the worker does the actual POSTs. Given a
    `cursor`, the rows join the caller's transaction, as WebhookRepository::enqueueEvent()
    does on the PHP side: a `delete` event is only sent if the deletion commits.
    """
    if not user_id:
        return
    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
    wh_table = f"{prefix}webhooks"
    del_table = f"{prefix}webhook_deliveries"
    now = int(time.time())
    body = json.dumps({"event": event, "timestamp": now, **payload}, ensure_ascii=False, separators=(',', ':'))

    async def insert_rows(cur):
        await cur.execute(f"SELECT `id`, `events` FROM `{wh_table}` WHERE `user_id`=%s AND `is_active`=1", (user_id,))
        hooks = await cur.fetchall()
        for hid, events in hooks:
            subs = [e.strip() for e in (events or '').split(',')]
            if event not in subs:
                continue
            await cur.execute(
                f"INSERT INTO `{del_table}` "
                f"(`webhook_id`,`event_id`,`event`,`payload`,`attempts`,`status`,`next_attempt_at`,`created_at`) "
                f"VALUES (%s,%s,%s,%s,0,'pending',%s,%s)",
                (hid, secrets.token_hex(16), event, body, now, now)
            )

    try:
        if cursor is not None:
            await insert_rows(cursor)
            return
        pool = await get_db_pool()
        if not pool:
            return
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await insert_rows(cur)
    except Exception as e:
        print(f"⚠️  webhook enqueue error: {e}")


def _public_webhook_target(url: str):
    """
    Resolve and validate a public webhook destination.

    Every DNS answer must be globally routable. The returned peer IP is separate from the
    original host so delivery can pin TCP while retaining the correct Host and TLS identity.
    """
    if not isinstance(url, str) or len(url) > 500:
        raise ValueError("invalid webhook URL")
    parsed = urlsplit(url)
    scheme = parsed.scheme.lower()
    if scheme not in {"http", "https"} or not parsed.hostname:
        raise ValueError("unsupported webhook scheme")
    if parsed.username is not None or parsed.password is not None:
        raise ValueError("webhook credentials are forbidden")
    if "%" in parsed.hostname:
        raise ValueError("IPv6 zone identifiers are forbidden")

    try:
        port = parsed.port or (443 if scheme == "https" else 80)
    except ValueError as exc:
        raise ValueError("invalid webhook port") from exc
    if port != (443 if scheme == "https" else 80):
        raise ValueError("webhook port is not allowlisted")

    try:
        literal = ipaddress.ip_address(parsed.hostname)
        candidates = [str(literal)]
    except ValueError:
        records = socket.getaddrinfo(
            parsed.hostname,
            port,
            type=socket.SOCK_STREAM,
            proto=socket.IPPROTO_TCP,
        )
        candidates = sorted({record[4][0] for record in records})

    if not candidates:
        raise ValueError("webhook host did not resolve")
    for candidate in candidates:
        address = ipaddress.ip_address(candidate)
        if (
            not address.is_global
            or address.is_private
            or address.is_loopback
            or address.is_link_local
            or address.is_multicast
            or address.is_reserved
            or address.is_unspecified
        ):
            raise ValueError("webhook address is not public")

    path = parsed.path or "/"
    if parsed.query:
        path += "?" + parsed.query
    return scheme, parsed.hostname.rstrip("."), port, candidates[0], path


class _PinnedHTTPSConnection(http.client.HTTPSConnection):
    """Connect to one validated IP while authenticating TLS against the URL hostname."""

    def __init__(self, host: str, pinned_ip: str, port: int, timeout: float):
        self._pinned_ip = pinned_ip
        super().__init__(
            host=host,
            port=port,
            timeout=timeout,
            context=ssl.create_default_context(),
        )

    def connect(self):
        self.sock = self._create_connection(
            (self._pinned_ip, self.port),
            self.timeout,
            self.source_address,
        )
        if self._tunnel_host:
            self._tunnel()
        self.sock = self._context.wrap_socket(self.sock, server_hostname=self.host)


def _abort_webhook_connection(holder: dict, state: dict) -> None:
    """Watchdog: cut a delivery that outlived its deadline, from outside its thread.

    The socket timeout bounds each read, not the delivery: a receiver dribbling one byte
    every few seconds kept a worker thread busy for days. Shutting the socket down makes the
    blocked read return at once. The base-class shutdown is called on purpose so a TLS
    socket's state is not touched from this thread.
    """
    state["expired"] = True
    sock = getattr(holder.get("connection"), "sock", None)
    if sock is None:
        return
    try:
        socket.socket.shutdown(sock, socket.SHUT_RDWR)
    except (OSError, TypeError, ValueError):
        pass


def _post_webhook(
    url: str,
    secret: str,
    event_id: str,
    event: str,
    payload: str,
    deadline: float = WEBHOOK_DELIVERY_DEADLINE,
):
    """DNS-pinned webhook POST. Redirects and non-public destinations are refused.

    The deadline runs from the start, so the name lookup is inside it: a slow authoritative
    DNS server used to add its own time on top, and the connect timeout is cut to what is left.
    """
    started = time.monotonic()
    holder = {"connection": None}
    state = {"expired": False}
    watchdog = threading.Timer(deadline, _abort_webhook_connection, args=(holder, state))
    watchdog.daemon = True
    watchdog.start()
    try:
        scheme, host, port, pinned_ip, path = _public_webhook_target(url)
        remaining = deadline - (time.monotonic() - started)
        if state["expired"] or remaining <= 0:
            return (False, "deadline")
        timeout = max(0.5, min(10.0, remaining))
        data = payload.encode("utf-8")
        sig = hmac.new(secret.encode("utf-8"), data, hashlib.sha256).hexdigest()
        host_header = f"[{host}]" if ":" in host else host
        headers = {
            "Content-Type": "application/json",
            "Content-Length": str(len(data)),
            "User-Agent": "FileHost-Webhook/1",
            "X-FileHost-Delivery": event_id,
            "X-FileHost-Event": event,
            "X-FileHost-Signature": "sha256=" + sig,
            "Host": host_header,
            "Connection": "close",
        }
        if scheme == "https":
            connection = _PinnedHTTPSConnection(host, pinned_ip, port, timeout=timeout)
        else:
            connection = http.client.HTTPConnection(pinned_ip, port, timeout=timeout)
        holder["connection"] = connection
        connection.request("POST", path, body=data, headers=headers)
        response = connection.getresponse()
        response.read(4096)
        code = int(response.status)
        if state["expired"]:
            return (False, "deadline")
        return (200 <= code < 300, str(code))
    except Exception as e:
        return (False, "deadline" if state["expired"] else type(e).__name__)
    finally:
        watchdog.cancel()
        if holder["connection"] is not None:
            holder["connection"].close()


async def _claim_pending_webhooks(
    pool,
    prefix: str,
    limit: int,
    busy_hooks=frozenset(),
    account_load: dict | None = None,
):
    """Claim up to `limit` due deliveries fairly, under a short cross-worker advisory lock.

    Each active webhook offers only its oldest due delivery, a webhook that already has one
    in flight offers none, and an account takes at most WEBHOOK_PER_ACCOUNT of the slots
    (`account_load` counts what each account already holds). One global FIFO used to let a
    single account's backlog against a receiver that never answers (up to 15 s an attempt,
    five attempts an event) hold every other account's events back for hours.
    """
    wh_table = f"{prefix}webhooks"
    del_table = f"{prefix}webhook_deliveries"
    now = int(time.time())
    lease_until = now + 60
    lock_name = build_lock_name("webhook-claim")
    load = dict(account_load or {})
    conn = await pool.acquire()
    locked = False
    try:
        async with conn.cursor() as cur:
            await cur.execute("SELECT GET_LOCK(%s, 1)", (lock_name,))
            locked = safe_int((await cur.fetchone() or (0,))[0]) == 1
            if not locked:
                return []
            await cur.execute(
                f"SELECT d.`id`, d.`webhook_id`, d.`event_id`, d.`event`, d.`payload`, "
                f"d.`attempts`, w.`url`, w.`secret`, w.`user_id` "
                f"FROM ("
                f"SELECT d0.`id`, ROW_NUMBER() OVER "
                f"(PARTITION BY d0.`webhook_id` ORDER BY d0.`id`) AS `position` "
                f"FROM `{del_table}` d0 JOIN `{wh_table}` w0 ON w0.`id` = d0.`webhook_id` "
                f"WHERE w0.`is_active` = 1 AND ("
                f" (d0.`status` = 'pending' AND d0.`next_attempt_at` <= %s)"
                f" OR (d0.`status` = 'processing' AND d0.`lease_until` < %s))"
                f") due "
                f"JOIN `{del_table}` d ON d.`id` = due.`id` "
                f"JOIN `{wh_table}` w ON w.`id` = d.`webhook_id` "
                f"WHERE due.`position` = 1 "
                f"ORDER BY d.`id` LIMIT {WEBHOOK_CLAIM_CANDIDATES}",
                (now, now),
            )
            rows = []
            for row in await cur.fetchall():
                hook, account = safe_int(row[1]), safe_int(row[8])
                if hook in busy_hooks or load.get(account, 0) >= WEBHOOK_PER_ACCOUNT:
                    continue
                rows.append(row)
                load[account] = load.get(account, 0) + 1
                if len(rows) >= limit:
                    break
            if not rows:
                return []
            ids = [safe_int(row[0]) for row in rows]
            placeholders = ",".join(["%s"] * len(ids))
            await cur.execute(
                f"UPDATE `{del_table}` SET `status`='processing', `lease_owner`=%s, "
                f"`lease_until`=%s WHERE `id` IN ({placeholders})",
                (INSTANCE_ID, lease_until, *ids),
            )
            return rows
    finally:
        if locked:
            try:
                async with conn.cursor() as cur:
                    await cur.execute("SELECT RELEASE_LOCK(%s)", (lock_name,))
            except Exception:
                pass
        pool.release(conn)


# Consecutive failed attempts per webhook in this process: (count, time of the first one).
_webhook_failure_streaks: dict = {}


async def _deliver_claimed_webhook(pool, prefix: str, row):
    del_table = f"{prefix}webhook_deliveries"
    wh_table = f"{prefix}webhooks"
    did, wid, event_id, event, payload, attempts, url, secret = row[:8]
    # Own executor: a slow receiver can only ever occupy webhook threads, never the ones
    # every upload and download does its file I/O on.
    ok, info = await asyncio.get_running_loop().run_in_executor(
        WEBHOOK_EXECUTOR, _post_webhook, url, secret, event_id, event, payload
    )
    ts = int(time.time())
    disable = False
    if ok:
        _webhook_failure_streaks.pop(wid, None)
    else:
        failures, since = _webhook_failure_streaks.get(wid, (0, ts))
        failures += 1
        _webhook_failure_streaks[wid] = (failures, since)
        disable = (
            failures >= WEBHOOK_DISABLE_AFTER_FAILURES
            and ts - since >= WEBHOOK_DISABLE_AFTER_SECONDS
        )
    new_attempts = safe_int(attempts) + 1
    async with pool.acquire() as conn:
        async with conn.cursor() as cur:
            if ok:
                await cur.execute(
                    f"UPDATE `{del_table}` SET `status`='delivered', `attempts`=%s, "
                    f"`delivered_at`=%s, `lease_owner`=NULL, `lease_until`=NULL "
                    f"WHERE `id`=%s AND `status`='processing' AND `lease_owner`=%s",
                    (new_attempts, ts, did, INSTANCE_ID),
                )
            elif new_attempts >= 5:
                await cur.execute(
                    f"UPDATE `{del_table}` SET `status`='failed', `attempts`=%s, "
                    f"`lease_owner`=NULL, `lease_until`=NULL "
                    f"WHERE `id`=%s AND `status`='processing' AND `lease_owner`=%s",
                    (new_attempts, did, INSTANCE_ID),
                )
            else:
                backoff = min(3600, 30 * (2 ** (new_attempts - 1)))
                await cur.execute(
                    f"UPDATE `{del_table}` SET `status`='pending', `attempts`=%s, "
                    f"`next_attempt_at`=%s, `lease_owner`=NULL, `lease_until`=NULL "
                    f"WHERE `id`=%s AND `status`='processing' AND `lease_owner`=%s",
                    (new_attempts, ts + backoff, did, INSTANCE_ID),
                )
            if cur.rowcount == 1:
                label = f"{'ok' if ok else 'fail'} {info}"[:64]
                await cur.execute(
                    f"UPDATE `{wh_table}` SET `last_status`=%s, `last_delivery_at`=%s "
                    f"WHERE `id`=%s",
                    (label, ts, wid),
                )
            if disable:
                await cur.execute(
                    f"UPDATE `{wh_table}` SET `is_active`=0, `last_status`=%s WHERE `id`=%s",
                    ("disabled: no delivery succeeded for 24 h", wid),
                )
                _webhook_failure_streaks.pop(wid, None)
                print(f"Webhook {wid} disabled: every delivery has failed for 24 hours")


async def webhook_worker():
    """Background loop: deliver due webhook rows with retry/backoff, fairly and continuously.

    A freed slot is refilled at once instead of after the slowest delivery of a batch, and
    the claim keeps one account (or one webhook) from taking every slot.
    """
    await asyncio.sleep(WEBHOOK_START_DELAY_SECONDS)  # let startup settle
    inflight: dict = {}  # task -> (webhook id, account id)
    while True:
        try:
            free = WEBHOOK_CONCURRENCY - len(inflight)
            pool = await get_db_pool() if free > 0 else None
            if pool:
                prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
                load: dict = {}
                for _hook, account in inflight.values():
                    load[account] = load.get(account, 0) + 1
                rows = await _claim_pending_webhooks(
                    pool, prefix, free, {hook for hook, _account in inflight.values()}, load
                )
                for row in rows:
                    task = asyncio.create_task(_deliver_claimed_webhook(pool, prefix, row))
                    inflight[task] = (safe_int(row[1]), safe_int(row[8]))
        except Exception as e:
            print(f"⚠️  webhook worker error: {e}")
        if not inflight:
            await asyncio.sleep(WEBHOOK_IDLE_POLL_SECONDS)
            continue
        done, _pending = await asyncio.wait(
            inflight, timeout=WEBHOOK_IDLE_POLL_SECONDS, return_when=asyncio.FIRST_COMPLETED
        )
        for task in done:
            inflight.pop(task, None)
            if not task.cancelled() and task.exception() is not None:
                print(f"⚠️  webhook delivery error: {task.exception()}")


# ============================================================================
# MAINTENANCE SCHEDULER — running scripts/cleanup.php without a system cron
# ============================================================================
#
# The housekeeping (expired tokens, retention sweeps, storage limits, payment
# reconciliation, the "your files expire on Friday" warnings) lives in
# scripts/cleanup.php and has always needed cron or Task Scheduler. On Windows
# that is a dialog nobody remembers to open, so the job silently never ran —
# which for a *warning* system is the worst possible failure: quiet.
#
# This process is already running, already has the DB and already outlives any
# single request, so it can hold the timer. It does not reimplement the job:
# it runs the same PHP script a system cron would, so there is exactly one
# definition of "housekeeping" and it stays in the language the rest of the
# app is written in.
#
# Off by default. An installation that already has a real cron entry does not
# want two schedulers racing each other, so turning this on is a decision.

CLEANUP_SCRIPT = APP_DIR / "scripts" / "cleanup.php"


def _find_php_binary() -> str | None:
    """
    The PHP CLI to run the job with.

    An explicit setting wins (a WAMP/XAMPP box has several PHP versions and the
    one on PATH is often not the one the site runs). Otherwise fall back to
    whatever `php` resolves to, and give up if there is nothing.
    """
    configured = (settings_cache.get('cron_php_binary') or '').strip()
    if configured:
        return configured if Path(configured).exists() else None
    from shutil import which
    return which('php')


async def _run_cleanup_once() -> tuple[bool, str]:
    """
    One run of scripts/cleanup.php. Returns (ok, last line of output).

    Run in a thread so a slow sweep cannot block the event loop that is also
    serving uploads, and capped by a timeout so a wedged run cannot pin the
    scheduler forever.
    """
    php = _find_php_binary()
    if not php:
        return False, "no PHP binary (set cron_php_binary in Settings)"
    if not CLEANUP_SCRIPT.exists():
        return False, f"missing {CLEANUP_SCRIPT}"

    def _run():
        return subprocess.run(
            [php, str(CLEANUP_SCRIPT)],
            capture_output=True, text=True, timeout=300,
            cwd=str(APP_DIR),
        )

    try:
        proc = await asyncio.get_running_loop().run_in_executor(MAINTENANCE_EXECUTOR, _run)
    except subprocess.TimeoutExpired:
        return False, "timed out after 300 s"
    except Exception as e:
        return False, str(e)

    output = (proc.stdout or proc.stderr or "").strip().splitlines()
    return proc.returncode == 0, (output[-1] if output else "no output")


async def cleanup_scheduler():
    """
    Periodic maintenance, when the operator asked this process to take it on.

    The interval is re-read every tick rather than captured once, so switching
    the scheduler off — or changing how often it runs — takes effect without a
    restart. A failed run is logged and retried on the next tick; housekeeping
    that skips an hour is not an emergency.
    """
    await asyncio.sleep(20)  # never compete with startup
    while True:
        try:
            await update_settings()
            try:
                await reap_expired_download_reservations()
            except Exception as e:
                # Its own guard: a reaper failure must not cost the maintenance run below.
                print(f"⚠️  download reservation reaper error: {e}", flush=True)
            if settings_cache.get('cron_enabled'):
                minutes = max(1, int(settings_cache.get('cron_interval', 15) or 15))
                last = settings_cache.get('_cron_last_run', 0)
                if time.time() - last >= minutes * 60:
                    ok, line = await _run_cleanup_once()
                    if ok:
                        settings_cache['_cron_last_run'] = time.time()
                    # Flushed explicitly: this is the one line that tells an operator the
                    # scheduler is alive, and Python block-buffers stdout when the server is
                    # started with its output redirected to a log file — which is exactly the
                    # setup where nobody is watching the console to notice the delay.
                    if ok:
                        info_log(f"cleanup: {line}")
                    else:
                        print(f"Cleanup warning: {line}", flush=True)
        except Exception as e:
            print(f"⚠️  cleanup scheduler error: {e}", flush=True)
        await asyncio.sleep(60)


# ============================================================================
# COLLECTIONS — stream a ZIP of grouped files on the fly (Faza 3.2)
# ============================================================================

class _ZipSink:
    """Write-only, non-seekable sink for zipfile.

    It provides write()/tell()/flush() but deliberately NO seek(): that makes
    zipfile switch to its streaming mode (data descriptors, no seek-back), so we
    never buffer the archive to disk and memory stays ~one chunk. tell() returns
    the running byte offset, which zipfile needs for the central directory.
    """
    def __init__(self):
        self._buf = bytearray()
        self._offset = 0

    def write(self, data):
        self._buf += data
        self._offset += len(data)
        return len(data)

    def tell(self):
        return self._offset

    def flush(self):
        pass

    def drain(self) -> bytes:
        data = bytes(self._buf)
        del self._buf[:]
        return data


def _safe_arcname(name: str, seen: dict) -> str:
    """Basename only, stripped of path separators/control chars, de-duplicated."""
    name = (name or "file").replace("\\", "/").split("/")[-1]
    name = "".join(ch for ch in name if ord(ch) >= 32).strip().strip(".")
    if not name:
        name = "file"
    base = name
    n = seen.get(base, 0)
    if n:
        stem, dot, ext = base.rpartition(".")
        candidate = f"{stem} ({n}){dot}{ext}" if dot else f"{base} ({n})"
        while candidate in seen:
            n += 1
            candidate = f"{stem} ({n}){dot}{ext}" if dot else f"{base} ({n})"
        seen[base] = n + 1
        seen[candidate] = 1
        return candidate
    seen[base] = 1
    return base


def _zip_stream(entries):
    """Yield a ZIP (STORED, no compression) of (disk_path, arcname) entries.

    STORED avoids CPU on already-compressed media (images/video) and keeps the
    stream cheap. allowZip64 handles >4GiB archives/members.

    Each member's size is declared before it is opened. In this non-seekable streaming
    mode zipfile picks ZIP64 records for a member only from the size it is told up front;
    left at 0, any member over 2 GiB raised "File size too large" after it had been
    streamed in full, truncating the archive.
    """
    sink = _ZipSink()
    seen: dict = {}
    with zipfile.ZipFile(sink, mode="w", compression=zipfile.ZIP_STORED, allowZip64=True) as zf:
        for disk_path, raw_name in entries:
            arcname = _safe_arcname(raw_name, seen)
            zinfo = zipfile.ZipInfo(filename=arcname, date_time=time.localtime()[:6])
            zinfo.compress_type = zipfile.ZIP_STORED
            zinfo.external_attr = 0o644 << 16  # regular file, rw-r--r--
            with open(disk_path, "rb") as src:
                zinfo.file_size = os.fstat(src.fileno()).st_size
                with zf.open(zinfo, mode="w") as dest:
                    while True:
                        chunk = src.read(1024 * 1024)
                        if not chunk:
                            break
                        dest.write(chunk)
                        data = sink.drain()
                        if data:
                            yield data
            data = sink.drain()
            if data:
                yield data
    tail = sink.drain()
    if tail:
        yield tail


async def _throttled_sync_iterator(
    iterator,
    limit_Bps: int,
    active_id: int = 0,
    transfer_state: dict | None = None,
):
    """Stream a blocking iterator with optional throttling and an admin kill check.

    Closing this generator closes the blocking one as well. A visitor who left mid-archive
    used to leave the ZIP generator suspended in a reference cycle, so its member file stayed
    open until a full GC pass: one descriptor per abandoned ZIP, deleted members still holding
    disk space, purges failing on Windows, and ZipFile's finaliser printing a traceback.
    """
    async_iterator = iterate_in_threadpool(iterator)
    stream = throttled_iterator(async_iterator, limit_Bps) if limit_Bps > 0 else async_iterator
    last_kill_check = 0.0
    try:
        async for chunk in stream:
            now = time.monotonic()
            if active_id and now - last_kill_check >= KILL_CHECK_SECONDS:
                last_kill_check = now
                if await download_was_killed(active_id):
                    if transfer_state is not None:
                        transfer_state["interrupted"] = True
                    info_log(f"Download {active_id} severed by admin")
                    break
            yield chunk
    finally:
        for layer in dict.fromkeys((stream, async_iterator)):
            try:
                await layer.aclose()
            except Exception:
                pass  # closing must not mask why the stream ended
        close = getattr(iterator, "close", None)
        if close is not None:
            try:
                # No next() is in flight here, so the generator's own `with` blocks run now:
                # the member file, the member writer and the archive close in order.
                await asyncio.to_thread(close)
            except ValueError:
                pass  # still executing in a worker thread; it finishes and closes on its own


async def notify_collection_download(
    collection_id: str,
    viewer_id=None,
    owner_id=None,
    collection_name=None,
):
    """
    Send the owner notification after counters and capabilities committed atomically.
    """
    pool = await get_db_pool()
    if not pool:
        return
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        table = f"{prefix}collections"
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                # Same bell as a single file, keyed on the collection so a ZIP doing the rounds
                # is one line that counts up.
                if owner_id is None:
                    await cur.execute(
                        f"SELECT `user_id`, `name` FROM `{table}` WHERE `id` = %s",
                        (collection_id,),
                    )
                    crow = await cur.fetchone()
                    if crow:
                        owner_id, collection_name = crow
                # Same rule as single files: nobody is told about their own download. The viewer
                # is known only when PHP signed the link (see _collection_viewer_id).
                if owner_id and int(owner_id) != safe_int(viewer_id):
                    owner_user_id = int(owner_id)
                    coll_name = collection_name or collection_id
                    if await notification_allowed(
                        cur, prefix, owner_user_id, 'collection.downloaded'
                    ):
                        await push_notification(
                            cur, prefix, owner_user_id, 'collection.downloaded', coll_name,
                            f"/collection.php?id={collection_id}",
                            f"collection.downloaded:{collection_id}"
                        )

    except Exception:
        pass


async def delete_collection_after_limit(collection_id: str):
    """
    Remove a collection whose download cap has just been spent, when its owner chose
    `on_limit_action = 'delete'`.

    Only the collection and its membership links go: the files themselves are the user's own
    uploads and exist independently of any collection they happen to be in.
    """
    pool = await get_db_pool()
    if not pool:
        return
    try:
        prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
        async with pool.acquire() as conn:
            try:
                async with conn.cursor() as cur:
                    await cur.execute("START TRANSACTION")
                    await cur.execute(
                        f"SELECT `id` FROM `{prefix}collections` "
                        f"WHERE `id` = %s FOR UPDATE",
                        (collection_id,),
                    )
                    if not await cur.fetchone():
                        await cur.execute("ROLLBACK")
                        return
                    await cur.execute(
                        f"DELETE FROM `{prefix}collection_files` "
                        f"WHERE `collection_id` = %s",
                        (collection_id,),
                    )
                    await cur.execute(
                        f"DELETE FROM `{prefix}collections` WHERE `id` = %s",
                        (collection_id,),
                    )
                    if cur.rowcount != 1:
                        raise RuntimeError("Collection changed during post-limit deletion")
                    await cur.execute("COMMIT")
            except Exception:
                await conn.rollback()
                raise
        info_log(f"collection {collection_id} deleted after reaching its download limit")
    except Exception as e:
        print(f"⚠️  Post-limit collection delete failed for {collection_id}: {e}")


def _is_hex_mac(mac: str) -> bool:
    """PHP's hash_hmac('sha256', …) output: 64 lowercase hex digits.

    Checked before hmac.compare_digest(), which raises TypeError for a str holding anything
    but ASCII. A crafted `v`, `t` or `m` from the query string used to turn into a 500 that,
    for `v`, fired after the ZIP's reservation had been committed.
    """
    return isinstance(mac, str) and re.fullmatch(r"[0-9a-f]{64}", mac) is not None


def _collection_token_ok(coll_id: str, token: str, delete_token_hash: str) -> bool:
    """
    Validate the stateless download token issued by PHP for a password-protected
    collection (C2). Format: "<expiry>.<hmac>", where hmac = HMAC-SHA256 over
    "<id>|<expiry>" keyed by the collection's own delete_token hash — a per-collection
    secret both sides read from the DB, so no shared secret or token table is needed.
    """
    if not token or '.' not in token:
        return False
    raw_expiry, _, mac = token.partition('.')
    if not _is_hex_mac(mac):
        return False
    try:
        expiry = int(raw_expiry)
    except ValueError:
        return False
    if expiry < int(time.time()):
        return False
    expected = hmac.new(
        (delete_token_hash or '').encode(),
        f"{coll_id}|{expiry}".encode(),
        hashlib.sha256,
    ).hexdigest()
    return hmac.compare_digest(expected, mac)


def _collection_viewer_id(coll_id: str, tag: str, delete_token_hash: str):
    """
    Who is fetching this ZIP, when PHP was able to say so and sign it.

    A collection link is public and id-based, so this endpoint normally has no idea who is on
    the other end — which is why an owner downloading their own collection used to notify
    themselves. PHP appends `v=<userId>.<expiry>.<hmac>` to the links it builds for a signed-in
    visitor, keyed by the same per-collection secret as the password token above.

    Returns the user id, or None for anything unsigned, expired or tampered with. The only
    thing the answer is used for is *not* sending a notification, so an unverifiable tag simply
    means "treat this as a stranger" — which is the safe direction.
    """
    if not tag or tag.count('.') != 2:
        return None
    raw_user, raw_expiry, mac = tag.split('.')
    if not _is_hex_mac(mac):
        return None
    try:
        user_id, expiry = int(raw_user), int(raw_expiry)
    except ValueError:
        return None
    if expiry < int(time.time()):
        return None
    expected = hmac.new(
        (delete_token_hash or '').encode(),
        f"{user_id}|{coll_id}|{expiry}".encode(),
        hashlib.sha256,
    ).hexdigest()
    return user_id if hmac.compare_digest(expected, mac) else None


def _collection_member_token_ids(coll_id: str, token: str, delete_token_hash: str):
    """Validate PHP's signed, short-lived allow-list of ZIP member ids."""
    if not token or token.count('.') != 1:
        return None
    encoded, mac = token.split('.', 1)
    if not _is_hex_mac(mac):
        return None
    expected = hmac.new(
        (delete_token_hash or '').encode(),
        f"{coll_id}|{encoded}".encode(),
        hashlib.sha256,
    ).hexdigest()
    if not hmac.compare_digest(expected, mac):
        return None
    try:
        padded = encoded + ('=' * (-len(encoded) % 4))
        payload = json.loads(base64.urlsafe_b64decode(padded.encode()).decode())
        if int(payload.get('e', 0)) < int(time.time()):
            return None
        ids = payload.get('i', [])
        if not isinstance(ids, list) or len(ids) > 200:
            return None
        clean = {str(file_id) for file_id in ids if re.fullmatch(r'[A-Za-z0-9_-]{1,32}', str(file_id))}
        return clean
    except (ValueError, TypeError, UnicodeError, json.JSONDecodeError):
        return None


async def _authorize_collection_members(
    cur,
    prefix: str,
    link_table: str,
    files_table: str,
    collection_id: str,
    password_authorized: bool,
    protected_policy: str = 'prompt_skip',
    member_authorized_ids=None,
):
    """Authorize every member locked by the caller and return one-time ids to claim."""
    await cur.execute(
        f"SELECT f.`id`, f.`original_name`, f.`size`, f.`password_hash`, f.`expires_at`, "
        f"f.`max_downloads`, f.`downloads`, f.`one_time`, f.`consumed_at`, "
        f"f.`consume_reservation_id` "
        f"FROM `{link_table}` cf JOIN `{files_table}` f ON f.`id` = cf.`file_id` "
        f"WHERE cf.`collection_id` = %s ORDER BY cf.`position` ASC FOR UPDATE",
        (collection_id,),
    )
    rows = await cur.fetchall()
    now = int(time.time())
    one_time_ids = []
    members = []
    for row in rows:
        (
            file_id,
            original_name,
            file_size,
            password_hash,
            expires_at,
            max_downloads,
            downloads,
            one_time,
            consumed_at,
            consume_reservation_id,
        ) = row
        if password_hash and not password_authorized:
            if protected_policy == 'remember_access':
                pass
            elif protected_policy == 'prompt_skip':
                if member_authorized_ids is None:
                    raise HTTPException(status_code=403, detail="Protected member authorization required")
                if file_id not in member_authorized_ids:
                    continue
            else:
                raise HTTPException(status_code=403, detail="Collection password required for protected files")
        if expires_at and int(expires_at) < now:
            raise HTTPException(status_code=410, detail="Collection contains an expired file")
        if max_downloads:
            active_counter_reservations = await _count_active_counter_reservations(
                cur, prefix, "file", file_id
            )
            if (
                int(downloads or 0) + active_counter_reservations
                >= int(max_downloads)
            ):
                raise HTTPException(status_code=410, detail="Collection contains a file at its download limit")
        if one_time:
            if consumed_at is not None or consume_reservation_id is not None:
                raise HTTPException(status_code=410, detail="Collection contains a consumed one-time file")
            one_time_ids.append(file_id)
        members.append((file_id, original_name, safe_int(file_size, -1)))

    return members, one_time_ids


@app.get("/collection")
async def download_collection(id: str, request: Request, background_tasks: BackgroundTasks, t: str = "", m: str = "", v: str = "", dt: str = ""):
    """
    Stream a ZIP of every existing file in a collection.

    Honours the same per-link controls as single files (C2): expiry, download cap, one-time
    ("burn after reading") and password. A password-protected collection additionally
    requires `t`, the short-lived token PHP hands out once the visitor has entered the
    correct password on collection.php.

    Runda 10 — tokenised like single files: `dt` is a short-lived, single-use, IP-bound row
    from `download_tokens` issued by PHP (`collection_zip_token`). The bare id is no longer
    a download, and the token's user decides the throttle (their group's download limit;
    anonymous ZIPs ride the guest limit).
    """
    client_ip = request_client_ip(request)

    if not re.fullmatch(r'[A-Za-z0-9_-]{1,32}', id or ''):
        raise HTTPException(status_code=400, detail="Invalid collection id")

    try:
        await update_settings()
    except Exception as exc:
        print(f"Collection settings error: {type(exc).__name__}: {exc}")
        raise HTTPException(status_code=503, detail="Settings unavailable")

    pool = await require_storage_ready()

    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
    coll_table = f"{prefix}collections"
    link_table = f"{prefix}collection_files"
    files_table = f"{prefix}files"
    token_table = f"{prefix}download_tokens"
    dl_user_id = None
    coll_name = "collection"
    max_downloads = None
    cur_downloads = 0
    coll_on_limit = 'keep'
    coll_owner_id = None
    delete_token_hash = ''
    entries = []
    zipped_ids = []
    total_size = 0
    active_id = 0
    limit_Bps = 0
    conn = None
    committed = False
    download_lock_name = None
    now = int(time.time())
    reservation_id = ""
    transfer_state = {"interrupted": False}

    try:
        conn = await pool.acquire()
        async with conn.cursor() as cur:
                await cur.execute("START TRANSACTION")
                # The ZIP token gate (runda 10) — same table, TTL, IP-binding and single-use
                # semantics as download_file's. `file_id` carries the collection id.
                settings_table = f"{prefix}settings"
                token_ttl = 900
                try:
                    await cur.execute(
                        f"SELECT `setting_value` FROM `{settings_table}` WHERE `setting_key` = %s",
                        ('download_token_ttl',)
                    )
                    ttl_row = await cur.fetchone()
                    if ttl_row and ttl_row[0] is not None and str(ttl_row[0]).strip() != '':
                        token_ttl = int(ttl_row[0])
                except Exception:
                    pass
                await cur.execute(
                    f"SELECT `user_id` FROM `{token_table}` WHERE `token` = %s AND `file_id` = %s "
                    f"AND `ip_address` = %s AND `used` = 0 AND `created_at` > %s FOR UPDATE",
                    (dt or '', id, client_ip, now - token_ttl)
                )
                tok_row = await cur.fetchone()
                if not tok_row:
                    raise HTTPException(status_code=403, detail="Download token required")
                dl_user_id = tok_row[0]
                await cur.execute(
                    f"SELECT `name`, `password_hash`, `expires_at`, `max_downloads`, "
                    f"`downloads`, `one_time`, `consumed_at`, `consume_reservation_id`, "
                    f"`delete_token`, `on_limit_action`, `user_id` "
                    f"FROM `{coll_table}` WHERE `id` = %s FOR UPDATE", (id,)
                )
                row = await cur.fetchone()
                if not row:
                    raise HTTPException(status_code=404, detail="Collection not found")
                (
                    coll_name,
                    password_hash,
                    expires_at,
                    max_downloads,
                    cur_downloads,
                    one_time,
                    consumed_at,
                    consume_reservation_id,
                    delete_token_hash,
                    coll_on_limit,
                    coll_owner_id,
                ) = row
                coll_on_limit = coll_on_limit or 'keep'

                coll_name = coll_name or "collection"

                # Expiry / download cap.
                if expires_at and int(expires_at) < now:
                    raise HTTPException(status_code=410, detail="This link has expired")
                if max_downloads:
                    active_collection_reservations = (
                        await _count_active_counter_reservations(
                            cur, prefix, "collection", id
                        )
                    )
                    if (
                        int(cur_downloads or 0) + active_collection_reservations
                        >= int(max_downloads)
                    ):
                        raise HTTPException(status_code=410, detail="Download limit reached")

                # Password: the id alone must not be enough, or the protection is bypassable.
                collection_password_authorized = False
                if password_hash:
                    if not _collection_token_ok(id, t, delete_token_hash):
                        raise HTTPException(status_code=403, detail="Password required")
                    collection_password_authorized = True

                protected_policy = settings_cache.get('collection_protected_file_policy', 'prompt_skip')
                member_authorized_ids = _collection_member_token_ids(id, m, delete_token_hash)

                if one_time:
                    if consumed_at is not None or consume_reservation_id is not None:
                        raise HTTPException(status_code=410, detail="This one-time link has already been used")

                members, one_time_member_ids = await _authorize_collection_members(
                    cur,
                    prefix,
                    link_table,
                    files_table,
                    id,
                    collection_password_authorized,
                    protected_policy,
                    member_authorized_ids,
                )

                # Resolve storage before spending any capability or counter. Missing bytes are
                # omitted from the ZIP and therefore do not consume a member's one-time claim.
                for file_id, original_name, file_size in members:
                    if not re.fullmatch(r'[A-Za-z0-9_-]{1,32}', file_id or ''):
                        raise HTTPException(status_code=503, detail="Invalid file storage id")
                    disk_file = await resolve_storage_file(
                        file_id,
                        str(original_name),
                        file_size,
                    )
                    if disk_file is None:
                        continue
                    entries.append((disk_file, original_name))
                    zipped_ids.append(file_id)
                    total_size += file_size

                if not entries:
                    raise HTTPException(status_code=404, detail="Collection is empty")

                group_limits = await get_user_group_limits(dl_user_id, cur)
                group_dl = safe_int(group_limits.get('limit_download'), 0)
                limit_Bps = max(0, group_dl)

                download_lock_name = _download_lock_name(client_ip)
                await cur.execute("SELECT GET_LOCK(%s, 5)", (download_lock_name,))
                lock_row = await cur.fetchone()
                if not lock_row or safe_int(lock_row[0]) != 1:
                    raise HTTPException(status_code=503, detail="Download limiter is busy")
                active_id = await _reserve_download_slot_with_cursor(
                    cur,
                    prefix,
                    client_ip,
                    dl_user_id,
                    f"collection:{id}",
                    group_limits,
                )
                quota_reservation = await _reserve_transfer_quota_with_cursor(
                    cur,
                    prefix,
                    dl_user_id,
                    client_ip,
                    group_limits,
                    total_size + max(65536, len(zipped_ids) * 1024),
                )

                claimed_member_ids = [
                    file_id for file_id in zipped_ids if file_id in one_time_member_ids
                ]
                effects_to_reserve = [("counter", "collection", str(id))]
                if one_time:
                    effects_to_reserve.append(("one_time", "collection", str(id)))
                effects_to_reserve.extend(
                    ("one_time", "file", str(file_id))
                    for file_id in claimed_member_ids
                )
                if settings_cache['collection_counts_files']:
                    effects_to_reserve.extend(
                        ("counter", "file", str(file_id))
                        for file_id in zipped_ids
                    )
                reservation_id = await _create_download_reservation_with_cursor(
                    cur,
                    prefix,
                    "collection",
                    id,
                    dt or "",
                    active_id,
                    dl_user_id,
                    client_ip,
                    effects_to_reserve,
                    quota_reservation,
                )
                # Viewer identity: prefer the `dt` token's user — PHP stamps the session's user
                # id into `download_tokens` when issuing it, exactly like single files. The
                # signed `v` tag stays as a fallback for links built ahead of time (e.g. a
                # copied zipUrl from "My Files"), where the token might be fetched without a
                # session. Worked out before the commit: nothing may raise between committing
                # the reservation and handing it to the accounting middleware below.
                viewer_id = dl_user_id or _collection_viewer_id(id, v, delete_token_hash)
                await cur.execute("COMMIT")
                committed = True
                # From here on the middleware settles the reservation whatever happens next;
                # a reservation committed but never registered stayed "reserved" until the
                # reaper, and kept a one-time or capped collection unavailable meanwhile.
                request.scope["filehost.traffic"] = {
                    "ip": client_ip,
                    "file_id": None,
                    "user_id": dl_user_id,
                    "active_id": active_id,
                    "reservation_id": reservation_id,
                    "transfer_state": transfer_state,
                    "completion": {
                        "kind": "collection",
                        "resource_id": id,
                        "viewer_id": viewer_id,
                        "owner_id": coll_owner_id,
                        "name": coll_name,
                    },
                }
    except HTTPException:
        raise
    except Exception as e:
        print(f"❌ Collection error: {type(e).__name__}: {e}")
        raise HTTPException(status_code=500, detail="Internal Error")
    finally:
        if conn is not None:
            try:
                if not committed:
                    await conn.rollback()
                if download_lock_name:
                    async with conn.cursor() as release_cur:
                        await release_cur.execute(
                            "SELECT RELEASE_LOCK(%s)", (download_lock_name,)
                        )
            except Exception:
                pass
            finally:
                pool.release(conn)

    background_tasks.add_task(register_download_end, active_id)

    info_log(f"Collection ZIP {id[:8]}: {len(entries)} file(s)")

    # Same rule as single files: if this ZIP spent the last allowance and the owner asked for
    # deletion, remove the collection once the archive has finished streaming. Only the
    # collection (and its links) go — the member files belong to the user independently.
    # Filename: "<name>.zip" with an ASCII fallback + RFC 5987 UTF-8 form.
    safe_name = "".join(ch for ch in coll_name if ch not in '\\/:*?"<>|').strip() or "collection"
    ascii_name = safe_name.encode('ascii', 'ignore').decode('ascii').strip().replace('"', "'") or "collection"
    utf8_name = quote(f"{safe_name}.zip", safe='')
    headers = {
        "Content-Disposition": f"attachment; filename=\"{ascii_name}.zip\"; filename*=UTF-8''{utf8_name}",
        "X-Content-Type-Options": "nosniff",
    }
    stream = _zip_stream(entries)
    stream = _throttled_sync_iterator(
        stream, limit_Bps, active_id, transfer_state
    )
    # Closed once the response is over, however it ended — the same guarantee as the single
    # file reader in download_file(); a visitor leaving mid-archive no longer leaks its member.
    background_tasks.add_task(close_stream_reader, stream)
    # No Content-Length: the archive is produced on the fly, so we stream it chunked.
    return StreamingResponse(stream, media_type="application/zip", headers=headers)


# ============================================================================
# THUMBNAILS — lazy, disk-cached previews for images & video (Faza 3.4)
# ============================================================================

def _extract_video_frame(src: Path, max_edge: int):
    """Grab one frame (~1s in, falling back to the very start) via the bundled ffmpeg.

    ffmpeg stays in the worker's own process group (no new session), so the worker's
    deadline kill takes it down too instead of leaving an orphan without any timeout. Input
    is restricted to the local file protocol: a playlist-shaped upload cannot make ffmpeg
    open anything else.
    """
    exe = imageio_ffmpeg.get_ffmpeg_exe()
    for seek in ("1", "0"):
        proc = None
        try:
            creation_flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
            proc = subprocess.Popen(
                [exe, "-nostdin", "-loglevel", "error", "-threads", "1",
                 "-filter_threads", "1", "-filter_complex_threads", "1",
                 "-probesize", "5000000", "-analyzeduration", "5000000",
                 "-protocol_whitelist", "file",
                 "-ss", seek, "-i", str(src), "-frames:v", "1",
                 "-an", "-sn", "-dn",
                 "-vf", f"scale={max_edge}:{max_edge}:force_original_aspect_ratio=decrease",
                 "-f", "image2pipe", "-vcodec", "mjpeg", "-"],
                stdin=subprocess.DEVNULL,
                stdout=subprocess.PIPE,
                stderr=subprocess.DEVNULL,
                creationflags=creation_flags,
            )
            stdout, _ = proc.communicate(timeout=THUMB_FFMPEG_ATTEMPT_TIMEOUT)
            if 0 < len(stdout) <= THUMB_MAX_OUTPUT_BYTES:
                return Image.open(io.BytesIO(stdout))
        except subprocess.TimeoutExpired:
            if proc is not None:
                proc.kill()
                proc.communicate()
        except Exception:
            if proc is not None and proc.poll() is None:
                proc.kill()
                proc.communicate()
        finally:
            if proc is not None and proc.poll() is None:
                proc.kill()
                proc.communicate()
    return None


def _generate_thumbnail(src: Path, mime: str, dest: Path, max_edge: int = THUMB_MAX_DEFAULT) -> bool:
    """Write a JPEG thumbnail for an image or video to `dest`. Returns True on success."""
    try:
        if mime.startswith("image/"):
            im = Image.open(src)
        elif mime.startswith("video/"):
            im = _extract_video_frame(src, max_edge)
            if im is None:
                return False
        else:
            return False

        width, height = im.size
        if width < 1 or height < 1 or width * height > THUMB_MAX_SOURCE_PIXELS:
            return False

        # First frame of animations, then honour EXIF orientation.
        try:
            im.seek(0)
        except Exception:
            pass
        im = ImageOps.exif_transpose(im)
        im.thumbnail((max_edge, max_edge))

        # JPEG has no alpha — flatten transparency onto white.
        if im.mode in ("RGBA", "LA", "P"):
            im = im.convert("RGBA")
            bg = Image.new("RGB", im.size, (255, 255, 255))
            bg.paste(im, mask=im.split()[-1])
            im = bg
        else:
            im = im.convert("RGB")

        tmp = dest.with_suffix(".tmp")
        im.save(tmp, "JPEG", quality=80, optimize=True)
        tmp.replace(dest)  # never serve a half-written thumb
        return True
    except Exception as e:
        print(f"⚠️  Thumbnail gen failed for {src.name}: {type(e).__name__}: {e}")
        return False


def _kill_worker_tree(proc, posix: bool | None = None) -> None:
    """Kill a thumbnail worker together with any ffmpeg it started.

    The worker leads its own session (start_new_session), so its process group is exactly
    the worker plus its children. Windows has no process groups; there the worker is killed.
    """
    if posix is None:
        posix = os.name != "nt"
    pid = getattr(proc, "pid", None)
    if posix and isinstance(pid, int) and pid > 0:
        try:
            os.killpg(pid, signal.SIGKILL)
        except (ProcessLookupError, PermissionError, OSError):
            pass
    proc.kill()


def _run_thumbnail_worker(src: Path, mime: str, dest: Path, max_edge: int) -> bool:
    """Run all untrusted media parsing in a killable child process."""
    creation_flags = subprocess.CREATE_NO_WINDOW if os.name == "nt" else 0
    proc = subprocess.Popen(
        [
            sys.executable,
            str(Path(__file__).resolve()),
            "--thumbnail-worker",
            str(src),
            mime,
            str(dest),
            str(max_edge),
        ],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        creationflags=creation_flags,
        start_new_session=os.name != "nt",
    )
    try:
        proc.communicate(timeout=THUMB_GENERATION_TIMEOUT)
    except subprocess.TimeoutExpired:
        _kill_worker_tree(proc)
        proc.communicate()
        dest.with_suffix(".tmp").unlink(missing_ok=True)
        return False
    try:
        success = (
            proc.returncode == 0
            and dest.is_file()
            and 0 < dest.stat().st_size <= THUMB_MAX_OUTPUT_BYTES
        )
        if not success:
            dest.with_suffix(".tmp").unlink(missing_ok=True)
        return success
    except OSError:
        dest.with_suffix(".tmp").unlink(missing_ok=True)
        return False


async def _generate_thumbnail_bounded(
    src: Path,
    mime: str,
    dest: Path,
    max_edge: int,
) -> bool:
    """Keep the event loop responsive and cap global child-process concurrency."""
    async with thumb_semaphore:
        return await asyncio.get_running_loop().run_in_executor(
            THUMB_EXECUTOR,
            _run_thumbnail_worker,
            src,
            mime,
            dest,
            max_edge,
        )


def _remember_thumbnail_failure(file_id: str):
    """Bound the negative cache even during a spray of distinct invalid media IDs."""
    thumb_negative_cache[file_id] = time.monotonic() + THUMB_NEGATIVE_TTL
    if len(thumb_negative_cache) <= 1000:
        return
    remove_count = len(thumb_negative_cache) - 1000
    oldest = sorted(thumb_negative_cache, key=thumb_negative_cache.get)[:remove_count]
    for key in oldest:
        thumb_negative_cache.pop(key, None)


def _thumbnail_worker_main(args: list[str]) -> int:
    """Strict internal CLI invoked by _run_thumbnail_worker()."""
    if not THUMBS_AVAILABLE or len(args) != 4:
        return 64
    src = Path(args[0])
    mime = args[1].lower()
    dest = Path(args[2])
    try:
        max_edge = int(args[3])
        src_resolved = src.resolve(strict=True)
        uploads_resolved = UPLOADS_DIR.resolve(strict=True)
        thumbs_resolved = THUMBS_DIR.resolve(strict=True)
        dest_parent = dest.parent.resolve(strict=True)
        src_resolved.relative_to(uploads_resolved)
        if dest_parent != thumbs_resolved:
            return 65
        if not (64 <= max_edge <= 2000):
            return 65
        if not mime.startswith(("image/", "video/")):
            return 65
        if not src_resolved.is_file() or src_resolved.stat().st_size > THUMB_MAX_INPUT_BYTES:
            return 65
    except (OSError, ValueError):
        return 65
    return 0 if _generate_thumbnail(src_resolved, mime, dest, max_edge) else 1


@app.get("/thumb")
async def thumbnail(id: str):
    """Serve a cached JPEG thumbnail for an image/video, generating it on first hit.
    404 for non-media / unsupported / failure — the UI then falls back to an icon."""
    if not THUMBS_AVAILABLE:
        raise HTTPException(status_code=404, detail="Thumbnails unavailable")
    if not re.fullmatch(r'[A-Za-z0-9_-]{1,32}', id or ''):
        raise HTTPException(status_code=400, detail="Invalid id")

    # The configured size can change, so keep the cache honest about what it holds.
    try:
        await update_settings()
    except Exception as exc:
        # Like every other route: no database is a 503, not a 500 with a traceback.
        print(f"Thumbnail settings error: {type(exc).__name__}: {exc}")
        raise HTTPException(status_code=503, detail="Settings unavailable")
    max_edge = settings_cache.get('thumb_max', THUMB_MAX_DEFAULT)

    pool = await get_db_pool()
    if not pool:
        raise HTTPException(status_code=503, detail="Database unavailable")
    prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
    files_table = f"{prefix}files"
    try:
        async with pool.acquire() as conn:
            async with conn.cursor() as cur:
                await cur.execute(
                    f"SELECT `original_name`, `size`, `mime_type`, `password_hash`, `expires_at`, "
                    f"`max_downloads`, `downloads`, `one_time`, `consumed_at` "
                    f"FROM `{files_table}` WHERE `id` = %s",
                    (id,),
                )
                row = await cur.fetchone()
    except Exception:
        raise HTTPException(status_code=500, detail="DB error")

    if not row:
        raise HTTPException(status_code=404, detail="Not found")
    original_name, file_size = str(row[0]), safe_int(row[1], -1)
    mime = (row[2] or "").lower()
    password_hash, expires_at, max_downloads, downloads, one_time, consumed_at = row[3:]
    now = int(time.time())
    if password_hash:
        raise HTTPException(status_code=403, detail="Protected thumbnail requires authorization")
    if expires_at and int(expires_at) < now:
        raise HTTPException(status_code=410, detail="This link has expired")
    if max_downloads and int(downloads or 0) >= int(max_downloads):
        raise HTTPException(status_code=410, detail="Download limit reached")
    if one_time and consumed_at is not None:
        raise HTTPException(status_code=410, detail="This one-time link has already been used")
    if not mime.startswith(("image/", "video/")):
        raise HTTPException(status_code=404, detail="No thumbnail for this type")

    # Always re-evaluate policy before a cache hit. no-store prevents a thumbnail that was
    # public yesterday from surviving after the owner protects or expires the file.
    dest = THUMBS_DIR / f"{id}.jpg"
    cache_headers = {
        "Cache-Control": "private, no-store",
        "X-Content-Type-Options": "nosniff",
    }
    if dest.exists():
        return FileResponse(dest, media_type="image/jpeg", headers=cache_headers)

    failed_until = thumb_negative_cache.get(id, 0)
    if failed_until > time.monotonic():
        raise HTTPException(status_code=404, detail="Thumbnail generation recently failed")
    thumb_negative_cache.pop(id, None)

    src = await resolve_storage_file(id, original_name, file_size)
    if not src:
        raise HTTPException(status_code=404, detail="File content missing")
    try:
        if src.stat().st_size > THUMB_MAX_INPUT_BYTES:
            _remember_thumbnail_failure(id)
            raise HTTPException(status_code=413, detail="Media is too large for thumbnail generation")
    except OSError:
        raise HTTPException(status_code=404, detail="File content missing")

    entry = thumb_locks.get(id)
    if entry is None:
        entry = [asyncio.Lock(), 0]
        thumb_locks[id] = entry
    entry[1] += 1
    lock = entry[0]
    try:
        async with lock:
            if dest.exists():
                return FileResponse(dest, media_type="image/jpeg", headers=cache_headers)
            ok = await _generate_thumbnail_bounded(src, mime, dest, max_edge)
    finally:
        entry[1] -= 1
        if entry[1] == 0 and thumb_locks.get(id) is entry:
            thumb_locks.pop(id, None)
    if not ok or not dest.exists():
        _remember_thumbnail_failure(id)
        raise HTTPException(status_code=404, detail="Could not generate thumbnail")
    METRICS["thumbs_generated"] += 1

    return FileResponse(dest, media_type="image/jpeg", headers=cache_headers)


# ============================================================================
# METRICS — Prometheus text exposition (Faza 4.6)
# ============================================================================

# Process-level counters (reset on restart, as Prometheus expects for a process).
METRICS = {"uploads": 0, "downloads": 0, "sharex_uploads": 0, "collection_zips": 0, "thumbs_generated": 0}
_START_TIME = time.time()


def _metric(name: str, help_text: str, mtype: str, value, labels: str = "") -> str:
    return (f"# HELP {name} {help_text}\n"
            f"# TYPE {name} {mtype}\n"
            f"{name}{labels} {value}\n")


@app.get("/metrics")
async def metrics(request: Request):
    """Prometheus scrape endpoint.

    Not public: it exposes install-wide counts. Requires the `metrics_token` setting as a
    Bearer token; when that setting is empty, only loopback scrapes are allowed (the usual
    single-host setup). Returns text/plain in the standard exposition format.
    """
    # A scrape must survive a database outage: this is the endpoint monitoring reads to find
    # out something is wrong, so refreshing settings is best-effort and the cached values
    # carry it. Without this the whole endpoint 500s exactly when it is most needed.
    try:
        await update_settings()
    except Exception as exc:  # noqa: BLE001
        info_log(f"/metrics: settings refresh failed, serving cached values ({exc})")

    token = (settings_cache.get('metrics_token') or '').strip()

    if token:
        supplied = (request.headers.get('authorization') or '').strip()
        if supplied.lower().startswith('bearer '):
            supplied = supplied[7:].strip()
        if not hmac.compare_digest(supplied.encode(), token.encode()):
            raise HTTPException(status_code=401, detail="Invalid metrics token")
    elif not request_is_direct_loopback(request):
        raise HTTPException(status_code=403, detail="Metrics restricted to loopback (set metrics_token to scrape remotely)")

    out = [_metric("filehost_up", "1 when the upload server is serving", "gauge", 1)]
    out.append(_metric("filehost_uptime_seconds", "Seconds since the upload server started", "gauge", int(time.time() - _START_TIME)))

    for key, desc in (("uploads", "Files uploaded via the web uploader since start"),
                      ("sharex_uploads", "Files uploaded via the API/ShareX endpoint since start"),
                      ("downloads", "Downloads served since start"),
                      ("collection_zips", "Collection ZIPs streamed since start"),
                      ("thumbs_generated", "Thumbnails generated since start")):
        out.append(_metric(f"filehost_{key}_total", desc, "counter", METRICS[key]))

    # Install-wide gauges straight from the DB; skipped (rather than failing the scrape)
    # if the database is unreachable.
    pool = await get_db_pool()
    if pool:
        try:
            prefix = db_config_cache.get('prefix', '') if db_config_cache else ''
            async with pool.acquire() as conn:
                async with conn.cursor() as cur:
                    await cur.execute(f"SELECT COUNT(*), COALESCE(SUM(`size`),0), COALESCE(SUM(`downloads`),0) FROM `{prefix}files`")
                    files, size, dls = await cur.fetchone()
                    out.append(_metric("filehost_files", "Files currently stored", "gauge", int(files)))
                    out.append(_metric("filehost_storage_bytes", "Bytes currently stored", "gauge", int(size)))
                    out.append(_metric("filehost_downloads_all_time", "Lifetime download count across stored files", "gauge", int(dls)))

                    await cur.execute(f"SELECT COUNT(*) FROM `{prefix}users`")
                    out.append(_metric("filehost_users", "Registered users", "gauge", int((await cur.fetchone())[0])))

                    await cur.execute(f"SELECT COUNT(*) FROM `{prefix}active_downloads`")
                    out.append(_metric("filehost_active_downloads", "Downloads in flight right now", "gauge", int((await cur.fetchone())[0])))

                    try:
                        await cur.execute(f"SELECT `status`, COUNT(*) FROM `{prefix}webhook_deliveries` GROUP BY `status`")
                        rows = await cur.fetchall()
                        seen = {r[0]: int(r[1]) for r in rows}
                        # One HELP/TYPE per metric family, then a line per label value.
                        block = ("# HELP filehost_webhook_deliveries Webhook deliveries by status\n"
                                 "# TYPE filehost_webhook_deliveries gauge\n")
                        for st in ("pending", "delivered", "failed"):
                            block += f'filehost_webhook_deliveries{{status="{st}"}} {seen.get(st, 0)}\n'
                        out.append(block)
                    except Exception:
                        pass

                    try:
                        await cur.execute(
                            f"SELECT `status`, COUNT(*) FROM `{prefix}mail_outbox` GROUP BY `status`"
                        )
                        rows = await cur.fetchall()
                        seen = {r[0]: int(r[1]) for r in rows}
                        block = ("# HELP filehost_mail_outbox E-mail outbox rows by status\n"
                                 "# TYPE filehost_mail_outbox gauge\n")
                        for st in ("pending", "sending", "sent", "dead"):
                            block += f'filehost_mail_outbox{{status="{st}"}} {seen.get(st, 0)}\n'
                        out.append(block)
                        await cur.execute(
                            f"SELECT MIN(`created_at`) FROM `{prefix}mail_outbox` "
                            "WHERE `status` = 'pending'"
                        )
                        oldest = (await cur.fetchone())[0]
                        oldest_age = max(0, int(time.time()) - int(oldest)) if oldest else 0
                        out.append(_metric(
                            "filehost_mail_outbox_oldest_pending_seconds",
                            "Age of the oldest pending e-mail",
                            "gauge",
                            oldest_age,
                        ))
                    except Exception:
                        pass
        except Exception as e:
            print(f"⚠️  metrics DB error: {e}")

    return PlainTextResponse("".join(out), media_type="text/plain; version=0.0.4; charset=utf-8")


# ============================================================================
# MAIN ENTRY POINT
# ============================================================================

if __name__ == "__main__":
    if len(sys.argv) > 1 and sys.argv[1] == "--thumbnail-worker":
        raise SystemExit(_thumbnail_worker_main(sys.argv[2:]))
    import uvicorn
    uvicorn.run(
        app,
        host=HOST,
        port=PORT,
        log_level="warning" if MINIMAL_LOGS else "info",
        # Apache already logs every request with the real client. uvicorn's own access log
        # only ever saw 127.0.0.1 and wrote full single-use tokens out of the query strings.
        access_log=False,
        # request_client_ip() resolves the visitor with PHP's rules (TRUSTED_PROXIES) from
        # the entry Apache appends to X-Forwarded-For; uvicorn must not rewrite it first.
        proxy_headers=False,
    )
