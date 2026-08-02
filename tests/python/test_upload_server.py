"""
Unit tests for the pure helper functions in upload_server.py (Faza 5 · #4).

These exercise the deterministic, side-effect-free helpers only — importing the module is
safe (the server starts under `if __name__ == "__main__"`, and the DB pool is lazy), so no
FastAPI TestClient or MySQL is required here. Endpoint/integration tests can be layered on
later with httpx + a test DB.
"""
import asyncio
import hashlib
import io
import json
import re
import sys
import time
import zipfile
from pathlib import Path

# Import the server module from the project root.
sys.path.insert(0, str(Path(__file__).resolve().parents[2]))
import upload_server as us  # noqa: E402


class TestLoggingMode:
    def test_info_log_is_silent_only_in_minimal_mode(self, monkeypatch, capsys):
        monkeypatch.setattr(us, "MINIMAL_LOGS", False)
        us.info_log("routine message")
        assert capsys.readouterr().out == "routine message\n"

        monkeypatch.setattr(us, "MINIMAL_LOGS", True)
        us.info_log("suppressed message")
        assert capsys.readouterr().out == ""


class TestStorageManifest:
    def test_legacy_repair_resolves_database_name_not_first_entry(self, monkeypatch, tmp_path):
        uploads = tmp_path / "uploads"
        directory = uploads / "file1"
        directory.mkdir(parents=True)
        (directory / "000-decoy.bin").write_bytes(b"decoy!!")
        payload = directory / "payload.bin"
        payload.write_bytes(b"payload")
        monkeypatch.setattr(us, "UPLOADS_DIR", uploads)

        resolved = us._resolve_storage_file_sync("file1", "payload.bin", 7)

        assert resolved == payload.resolve()
        manifest = json.loads(
            (directory / us.STORAGE_MANIFEST_NAME).read_text(encoding="utf-8")
        )
        assert manifest["name"] == "payload.bin"
        assert manifest["sha256"] == hashlib.sha256(b"payload").hexdigest()

    def test_manifest_cannot_redirect_to_another_directory_entry(self, monkeypatch, tmp_path):
        uploads = tmp_path / "uploads"
        directory = uploads / "file2"
        directory.mkdir(parents=True)
        (directory / "payload.bin").write_bytes(b"payload")
        (directory / "decoy.bin").write_bytes(b"payload")
        (directory / us.STORAGE_MANIFEST_NAME).write_text(
            json.dumps({
                "version": 1,
                "name": "decoy.bin",
                "size": 7,
                "sha256": hashlib.sha256(b"payload").hexdigest(),
            }),
            encoding="utf-8",
        )
        monkeypatch.setattr(us, "UPLOADS_DIR", uploads)

        assert us._resolve_storage_file_sync("file2", "payload.bin", 7) is None

    def test_full_integrity_mode_detects_same_size_corruption(self, monkeypatch, tmp_path):
        uploads = tmp_path / "uploads"
        directory = uploads / "file3"
        directory.mkdir(parents=True)
        payload = directory / "payload.bin"
        payload.write_bytes(b"payload")
        checksum = hashlib.sha256(b"payload").hexdigest()
        assert us._write_storage_manifest(directory, "payload.bin", 7, checksum)
        payload.write_bytes(b"PAYLOAD")
        monkeypatch.setattr(us, "UPLOADS_DIR", uploads)

        assert us._resolve_storage_file_sync("file3", "payload.bin", 7) == payload.resolve()
        assert us._resolve_storage_file_sync(
            "file3", "payload.bin", 7, verify_checksum=True
        ) is None


class TestClientIp:
    @staticmethod
    def _request(peer, forwarded=""):
        class Client:
            host = peer

        class Request:
            client = Client()
            headers = {"x-forwarded-for": forwarded} if forwarded else {}

        return Request()

    def test_loopback_proxy_uses_first_forwarded_address(self):
        request = self._request("127.0.0.1", "198.51.100.27, 10.0.0.2")
        assert us.request_client_ip(request) == "198.51.100.27"

    def test_direct_client_cannot_spoof_forwarded_address(self):
        request = self._request("203.0.113.8", "198.51.100.27")
        assert us.request_client_ip(request) == "203.0.113.8"

    def test_invalid_forwarded_address_falls_back_to_proxy_peer(self):
        request = self._request("::1", "not-an-ip")
        assert us.request_client_ip(request) == "::1"


class TestSafeInt:
    def test_valid(self):
        assert us.safe_int("42") == 42
        assert us.safe_int(42) == 42

    def test_invalid_returns_default(self):
        assert us.safe_int("abc") == 0
        assert us.safe_int(None) == 0
        assert us.safe_int("", default=7) == 7
        assert us.safe_int("nope", default=-1) == -1


class TestIdAndToken:
    def test_file_id_shape(self):
        fid = us.generate_file_id()
        # hex timestamp + 8 hex chars of randomness, all lowercase hex.
        assert re.fullmatch(r"[0-9a-f]+", fid)
        assert len(fid) >= 12

    def test_file_ids_are_unique(self):
        assert us.generate_file_id() != us.generate_file_id()

    def test_delete_token_is_32_hex(self):
        tok = us.generate_delete_token()
        assert re.fullmatch(r"[0-9a-f]{32}", tok)


class TestSafeArcname:
    def test_strips_path_and_control_chars(self):
        seen = {}
        assert us._safe_arcname("../../etc/passwd", seen) == "passwd"
        assert us._safe_arcname("C:\\Windows\\evil.exe", seen) == "evil.exe"

    def test_empty_becomes_file(self):
        assert us._safe_arcname("", {}) == "file"
        assert us._safe_arcname("...", {}) == "file"

    def test_deduplicates_collisions(self):
        seen = {}
        a = us._safe_arcname("doc.pdf", seen)
        b = us._safe_arcname("doc.pdf", seen)
        c = us._safe_arcname("doc.pdf", seen)
        assert a == "doc.pdf"
        assert b == "doc (1).pdf"
        assert c == "doc (2).pdf"


class TestBlockedExtensions:
    def test_dangerous_extensions_blocked(self):
        for ext in (
            "php", "exe", "sh", "phtml",
            "html", "htm", "xhtml", "svg", "shtml", "xml",
        ):
            assert ext in us.BLOCKED_EXTENSIONS, f"{ext} should be blocked"

    def test_normal_extensions_not_blocked(self):
        for ext in ("jpg", "png", "pdf", "zip", "mp4"):
            assert ext not in us.BLOCKED_EXTENSIONS


class TestMetricFormat:
    def test_prometheus_exposition_shape(self):
        out = us._metric("fh_uploads_total", "Total uploads", "counter", 5)
        assert "# HELP fh_uploads_total Total uploads" in out
        assert "# TYPE fh_uploads_total counter" in out
        assert out.strip().endswith("fh_uploads_total 5")

    def test_labels_are_appended_to_series(self):
        out = us._metric("fh_x", "h", "gauge", 3, labels='{k="v"}')
        assert 'fh_x{k="v"} 3' in out


class TestThumbnailSizeClamp:
    """
    The thumbnail size is admin-configurable, so a bad value in the settings table must not
    reach Pillow. update_settings() applies the clamp; this covers the rule it uses.
    """

    @staticmethod
    def _clamp(px):
        # Mirrors the expression in update_settings(): out-of-range falls back to the default.
        return px if 64 <= px <= 2000 else us.THUMB_MAX_DEFAULT

    def test_in_range_value_is_kept(self):
        assert self._clamp(64) == 64
        assert self._clamp(800) == 800
        assert self._clamp(2000) == 2000

    def test_out_of_range_falls_back_to_default(self):
        assert self._clamp(0) == us.THUMB_MAX_DEFAULT
        assert self._clamp(1) == us.THUMB_MAX_DEFAULT
        assert self._clamp(63) == us.THUMB_MAX_DEFAULT
        assert self._clamp(2001) == us.THUMB_MAX_DEFAULT
        assert self._clamp(-100) == us.THUMB_MAX_DEFAULT

    def test_default_is_itself_in_range(self):
        assert 64 <= us.THUMB_MAX_DEFAULT <= 2000


class TestThumbnailResourceLimits:
    def test_negative_cache_is_strictly_bounded(self, monkeypatch):
        cache = {}
        monkeypatch.setattr(us, "thumb_negative_cache", cache)
        monkeypatch.setattr(us, "THUMB_NEGATIVE_TTL", 300)
        for index in range(1100):
            us._remember_thumbnail_failure(f"file-{index}")
        assert len(cache) == 1000
        assert "file-1099" in cache

    def test_killable_worker_is_terminated_on_timeout(self, monkeypatch, tmp_path):
        state = {"killed": False, "calls": 0}

        class Process:
            returncode = None

            def communicate(self, timeout=None):
                state["calls"] += 1
                if state["calls"] == 1:
                    raise us.subprocess.TimeoutExpired("thumbnail", timeout)
                self.returncode = -1
                return (b"", b"")

            def kill(self):
                state["killed"] = True

        monkeypatch.setattr(us.subprocess, "Popen", lambda *args, **kwargs: Process())
        source = tmp_path / "source.bin"
        source.write_bytes(b"x")
        dest = tmp_path / "dest.jpg"

        assert not us._run_thumbnail_worker(source, "image/jpeg", dest, 400)
        assert state["killed"] is True
        assert state["calls"] == 2

    def test_generation_does_not_block_the_event_loop(self, monkeypatch, tmp_path):
        def expensive(*args):
            time.sleep(0.08)
            return True

        monkeypatch.setattr(us, "_run_thumbnail_worker", expensive)

        async def scenario():
            monkeypatch.setattr(us, "thumb_semaphore", asyncio.Semaphore(1))
            task = asyncio.create_task(us._generate_thumbnail_bounded(
                tmp_path / "source",
                "image/jpeg",
                tmp_path / "dest",
                400,
            ))
            ticks = 0
            while not task.done():
                ticks += 1
                await asyncio.sleep(0.005)
            assert await task is True
            return ticks

        assert asyncio.run(scenario()) >= 5

    def test_internal_worker_rejects_oversized_input_before_parsing(self, monkeypatch, tmp_path):
        uploads = tmp_path / "uploads"
        thumbs = tmp_path / "thumbs"
        source_dir = uploads / "file1"
        source_dir.mkdir(parents=True)
        thumbs.mkdir()
        source = source_dir / "large.jpg"
        source.write_bytes(b"01234567890")
        dest = thumbs / "file1.jpg"
        monkeypatch.setattr(us, "UPLOADS_DIR", uploads)
        monkeypatch.setattr(us, "THUMBS_DIR", thumbs)
        monkeypatch.setattr(us, "THUMBS_AVAILABLE", True)
        monkeypatch.setattr(us, "THUMB_MAX_INPUT_BYTES", 10)
        monkeypatch.setattr(
            us,
            "_generate_thumbnail",
            lambda *args: (_ for _ in ()).throw(AssertionError("parser must not run")),
        )

        assert us._thumbnail_worker_main([
            str(source),
            "image/jpeg",
            str(dest),
            "400",
        ]) == 65

    def test_image_dimensions_are_checked_before_decode(self, monkeypatch, tmp_path):
        class HugeImage:
            size = (100_000, 100_000)

        monkeypatch.setattr(us.Image, "open", lambda src: HugeImage())
        monkeypatch.setattr(us, "THUMB_MAX_SOURCE_PIXELS", 40_000_000)
        source = tmp_path / "huge.jpg"
        source.write_bytes(b"fake")

        assert not us._generate_thumbnail(
            source,
            "image/jpeg",
            tmp_path / "dest.jpg",
            400,
        )


class TestQuotaArithmetic:
    """
    Storage quota enforcement (get_user_quota + the check in /upload). The DB lookup needs a
    live pool, so what is covered here is the decision the endpoint makes from its result.
    """

    @staticmethod
    def _over(used, limit, incoming):
        # Mirrors the guard in stream_upload/sharex_upload.
        return limit > 0 and (used + incoming) > limit

    def test_zero_limit_means_unlimited(self):
        assert not self._over(10**12, 0, 10**12)

    def test_upload_fitting_in_the_allowance_is_accepted(self):
        assert not self._over(100, 1000, 900)

    def test_upload_exceeding_the_allowance_is_refused(self):
        assert self._over(100, 1000, 901)

    def test_exactly_full_is_accepted(self):
        # Landing exactly on the quota is within it, not over it.
        assert not self._over(0, 1000, 1000)
        assert self._over(0, 1000, 1001)

    def test_already_over_quota_refuses_even_an_empty_upload(self):
        assert self._over(2000, 1000, 1)


class TestTrafficLogging:
    def test_existing_cursor_does_not_acquire_another_connection(self, monkeypatch):
        class Cursor:
            def __init__(self):
                self.calls = []

            async def execute(self, query, params):
                self.calls.append((query, params))

        async def forbidden_pool_acquire():
            raise AssertionError("log_traffic attempted a nested pool acquire")

        cursor = Cursor()
        monkeypatch.setattr(us, "get_db_pool", forbidden_pool_acquire)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        asyncio.run(
            us.log_traffic(
                "127.0.0.1",
                123,
                "upload",
                "file-id",
                7,
                cursor=cursor,
            )
        )

        assert len(cursor.calls) == 1
        query, params = cursor.calls[0]
        assert "`fh_traffic_logs`" in query
        assert params[:5] == ("127.0.0.1", 123, "upload", "file-id", 7)


class TestDownloadReservationLifecycle:
    def test_transfer_quota_reserves_requested_bytes_atomically(self):
        class Cursor:
            def __init__(self):
                self.calls = []

            async def execute(self, query, params=None):
                self.calls.append((query, params))

            async def fetchone(self):
                return (400, 100)

        cursor = Cursor()
        reservation = asyncio.run(
            us._reserve_transfer_quota_with_cursor(
                cursor,
                "fh_",
                7,
                "203.0.113.20",
                {
                    "transfer_quota_bytes": 1000,
                    "transfer_quota_period": "week",
                },
                500,
            )
        )

        assert reservation["subject_type"] == "user"
        assert reservation["subject_key"] == "7"
        assert reservation["reserved_bytes"] == 500
        assert any(
            "`reserved_bytes` = `reserved_bytes` + %s" in query
            for query, _params in cursor.calls
        )

    def test_transfer_quota_rejects_before_reservation_when_total_would_exceed(self):
        class Cursor:
            async def execute(self, _query, _params=None):
                pass

            async def fetchone(self):
                return (700, 200)

        try:
            asyncio.run(
                us._reserve_transfer_quota_with_cursor(
                    Cursor(),
                    "fh_",
                    None,
                    "203.0.113.20",
                    {
                        "transfer_quota_bytes": 1000,
                        "transfer_quota_period": "week",
                    },
                    101,
                )
            )
        except us.HTTPException as exc:
            assert exc.status_code == 429
        else:
            raise AssertionError("oversold transfer allowance was accepted")

    def test_reservation_claims_token_and_persists_explicit_effects(
        self, monkeypatch
    ):
        class Cursor:
            def __init__(self):
                self.calls = []
                self.rowcount = 1

            async def execute(self, query, params=None):
                self.calls.append((query, params))
                self.rowcount = 1

        cursor = Cursor()
        monkeypatch.setattr(us.secrets, "token_hex", lambda _size: "e" * 32)

        reservation_id = asyncio.run(
            us._create_download_reservation_with_cursor(
                cursor,
                "fh_",
                "file",
                "file1",
                "a" * 64,
                9,
                7,
                "203.0.113.20",
                [
                    ("counter", "file", "file1"),
                    ("one_time", "file", "file1"),
                ],
            )
        )

        assert reservation_id == "e" * 32
        queries = [query for query, _params in cursor.calls]
        assert any(
            "UPDATE `fh_download_tokens`" in query
            and "`reservation_id`" in query
            for query in queries
        )
        assert any(
            "UPDATE `fh_files` SET `consume_reservation_id`" in query
            for query in queries
        )
        effect_inserts = [
            params
            for query, params in cursor.calls
            if "INSERT INTO `fh_download_reservation_effects`" in query
        ]
        assert len(effect_inserts) == 2
        assert {params[1] for params in effect_inserts} == {"counter", "one_time"}

    def test_unstarted_release_only_returns_owned_unapplied_claims(self):
        class Cursor:
            def __init__(self):
                self.calls = []
                self.rowcount = 1

            async def execute(self, query, params=None):
                self.calls.append((query, params))

            async def fetchall(self):
                return [("file", "file1")]

        cursor = Cursor()
        asyncio.run(
            us._release_unstarted_download_effects(
                cursor,
                "fh_",
                "f" * 32,
            )
        )

        queries = [query for query, _params in cursor.calls]
        assert any(
            "`consume_reservation_id` = %s" in query
            and "`consumed_at` IS NULL" in query
            for query in queries
        )
        assert any(
            "UPDATE `fh_download_tokens`" in query
            and "`used_at` IS NULL" in query
            for query in queries
        )

    def test_successful_stream_moves_started_to_completed_with_actual_bytes(
        self, monkeypatch
    ):
        events = []

        async def mark(reservation_id):
            events.append(("started", reservation_id))

        async def heartbeat(active_id, reservation_id, bytes_sent):
            events.append(("heartbeat", active_id, reservation_id, bytes_sent))
            return True

        async def finish(reservation_id, state, size):
            events.append(("finished", reservation_id, state, size))

        async def traffic(*args, **kwargs):
            events.append(("traffic", args[1]))

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.10",
                "file_id": "file1",
                "user_id": 7,
                "active_id": 4,
                "reservation_id": "a" * 32,
            }
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send({"type": "http.response.body", "body": b"abc", "more_body": True})
            await send({"type": "http.response.body", "body": b"def", "more_body": False})

        async def receive():
            return {"type": "http.request"}

        async def send(_message):
            return None

        monkeypatch.setattr(us, "mark_download_reservation_started", mark)
        monkeypatch.setattr(us, "heartbeat_download", heartbeat)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", traffic)

        middleware = us.ActualTransferAccountingMiddleware(app)
        asyncio.run(middleware({"type": "http"}, receive, send))

        assert events.count(("started", "a" * 32)) == 1
        assert ("finished", "a" * 32, "completed", 6) in events
        assert ("traffic", 6) in events

    def test_completion_side_effects_run_only_after_durable_transition(
        self, monkeypatch
    ):
        events = []

        async def no_op(*args, **kwargs):
            return None

        async def finish(_reservation_id, state, _size):
            events.append(("finish", state))
            return state == "completed"

        async def notify(file_id, viewer_id, owner_id, name):
            events.append(("notify", file_id, viewer_id, owner_id, name))

        async def webhook(owner_id, event, payload):
            events.append(("webhook", owner_id, event, payload["file"]["id"]))

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.15",
                "file_id": "file-complete",
                "active_id": 9,
                "reservation_id": "f" * 32,
                "completion": {
                    "kind": "file",
                    "resource_id": "file-complete",
                    "viewer_id": 3,
                    "owner_id": 7,
                    "name": "complete.bin",
                    "mime": "application/octet-stream",
                    "size": 4,
                },
            }
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send(
                {"type": "http.response.body", "body": b"done", "more_body": False}
            )

        monkeypatch.setattr(us, "mark_download_reservation_started", no_op)
        monkeypatch.setattr(us, "heartbeat_download", no_op)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", no_op)
        monkeypatch.setattr(us, "notify_file_download", notify)
        monkeypatch.setattr(us, "enqueue_webhook_event", webhook)
        previous_metric = us.METRICS["downloads"]

        middleware = us.ActualTransferAccountingMiddleware(app)
        asyncio.run(middleware({"type": "http"}, no_op, no_op))

        assert events == [
            ("finish", "completed"),
            ("notify", "file-complete", 3, 7, "complete.bin"),
            ("webhook", 7, "download", "file-complete"),
        ]
        assert us.METRICS["downloads"] == previous_metric + 1

    def test_interrupted_stream_is_released_not_completed(self, monkeypatch):
        events = []

        async def no_op(*args, **kwargs):
            return None

        async def finish(reservation_id, state, size):
            events.append((reservation_id, state, size))

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.11",
                "file_id": "file2",
                "active_id": 5,
                "reservation_id": "b" * 32,
            }
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send({"type": "http.response.body", "body": b"partial", "more_body": True})
            raise RuntimeError("client disconnected")

        async def receive():
            return {"type": "http.request"}

        async def send(_message):
            return None

        monkeypatch.setattr(us, "mark_download_reservation_started", no_op)
        monkeypatch.setattr(us, "heartbeat_download", no_op)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", no_op)

        middleware = us.ActualTransferAccountingMiddleware(app)
        try:
            asyncio.run(middleware({"type": "http"}, receive, send))
        except RuntimeError as exc:
            assert str(exc) == "client disconnected"
        else:
            raise AssertionError("stream interruption must reach the caller")

        assert events == [("b" * 32, "released", 7)]

    def test_socket_failure_before_first_body_releases_zero_bytes(self, monkeypatch):
        events = []

        async def no_op(*args, **kwargs):
            return None

        async def mark(_reservation_id):
            raise AssertionError("a body rejected by send must not mark the reservation started")

        async def finish(reservation_id, state, size):
            events.append((reservation_id, state, size))

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.14",
                "file_id": "file-prebody",
                "active_id": 8,
                "reservation_id": "e" * 32,
            }
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send(
                {"type": "http.response.body", "body": b"first", "more_body": True}
            )

        async def send(message):
            if message["type"] == "http.response.body":
                raise ConnectionResetError("socket closed before body")

        monkeypatch.setattr(us, "mark_download_reservation_started", mark)
        monkeypatch.setattr(us, "heartbeat_download", no_op)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", no_op)

        middleware = us.ActualTransferAccountingMiddleware(app)
        try:
            asyncio.run(middleware({"type": "http"}, no_op, send))
        except ConnectionResetError:
            pass
        else:
            raise AssertionError("socket reset must reach the ASGI server")

        assert events == [("e" * 32, "released", 0)]

    def test_short_body_is_released_even_when_application_returns_normally(
        self, monkeypatch
    ):
        events = []

        async def no_op(*args, **kwargs):
            return None

        async def finish(reservation_id, state, size):
            events.append((reservation_id, state, size))

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.12",
                "file_id": "file3",
                "active_id": 6,
                "reservation_id": "c" * 32,
            }
            await send(
                {
                    "type": "http.response.start",
                    "status": 200,
                    "headers": [(b"content-length", b"10")],
                }
            )
            await send(
                {"type": "http.response.body", "body": b"partial", "more_body": False}
            )

        monkeypatch.setattr(us, "mark_download_reservation_started", no_op)
        monkeypatch.setattr(us, "heartbeat_download", no_op)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", no_op)

        middleware = us.ActualTransferAccountingMiddleware(app)
        asyncio.run(
            middleware(
                {"type": "http"},
                lambda: None,
                lambda _message: no_op(),
            )
        )

        assert events == [("c" * 32, "released", 7)]

    def test_complete_range_counts_as_completed_representation(self, monkeypatch):
        events = []

        async def no_op(*args, **kwargs):
            return None

        async def finish(reservation_id, state, size):
            events.append((reservation_id, state, size))

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.13",
                "file_id": "file4",
                "active_id": 7,
                "reservation_id": "d" * 32,
            }
            await send(
                {
                    "type": "http.response.start",
                    "status": 206,
                    "headers": [(b"content-length", b"3")],
                }
            )
            await send(
                {"type": "http.response.body", "body": b"xyz", "more_body": False}
            )

        monkeypatch.setattr(us, "mark_download_reservation_started", no_op)
        monkeypatch.setattr(us, "heartbeat_download", no_op)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", no_op)

        middleware = us.ActualTransferAccountingMiddleware(app)
        asyncio.run(
            middleware(
                {"type": "http"},
                lambda: None,
                lambda _message: no_op(),
            )
        )

        assert events == [("d" * 32, "completed", 3)]


class TestApiKeyResolution:
    def test_key_requires_an_active_existing_user(self, monkeypatch):
        class Cursor:
            def __init__(self):
                self.calls = []

            async def execute(self, query, params):
                self.calls.append((query, params))

            async def fetchone(self):
                return (11, 7)

        class AsyncContext:
            def __init__(self, value):
                self.value = value

            async def __aenter__(self):
                return self.value

            async def __aexit__(self, exc_type, exc, tb):
                return False

        cursor = Cursor()

        class Connection:
            def cursor(self):
                return AsyncContext(cursor)

        class Pool:
            def acquire(self):
                return AsyncContext(Connection())

        async def fake_get_db_pool():
            return Pool()

        monkeypatch.setattr(us, "get_db_pool", fake_get_db_pool)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        user_id = asyncio.run(us.resolve_api_key("secret-key"))

        assert user_id == 7
        lookup_query, _ = cursor.calls[0]
        assert "INNER JOIN `fh_users`" in lookup_query
        assert "u.`is_active` = 1" in lookup_query


class TestThrottledZip:
    def test_sync_zip_generator_is_consumed_asynchronously(self, tmp_path):
        source = tmp_path / "payload.txt"
        source.write_bytes(b"zip payload")

        async def collect():
            chunks = []
            stream = us._zip_stream([(source, "payload.txt")])
            async for chunk in us._throttled_sync_iterator(stream, 100 * 1024 * 1024):
                chunks.append(chunk)
            return b"".join(chunks)

        archive = asyncio.run(collect())
        with zipfile.ZipFile(io.BytesIO(archive)) as zf:
            assert zf.namelist() == ["payload.txt"]
            assert zf.read("payload.txt") == b"zip payload"


class TestDownloadRanges:
    def test_unchanged_heartbeat_does_not_look_like_admin_kill(self, monkeypatch):
        class Cursor:
            def __init__(self):
                self.rowcount = 0
                self.queries = []

            async def execute(self, query, params):
                self.queries.append((query, params))

            async def fetchone(self):
                return (1,)

        class AsyncContext:
            def __init__(self, value):
                self.value = value

            async def __aenter__(self):
                return self.value

            async def __aexit__(self, exc_type, exc, tb):
                return False

        cursor = Cursor()

        class Connection:
            def cursor(self):
                return AsyncContext(cursor)

        class Pool:
            def acquire(self):
                return AsyncContext(Connection())

        async def fake_get_db_pool():
            return Pool()

        monkeypatch.setattr(us, "get_db_pool", fake_get_db_pool)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        assert asyncio.run(us.download_was_killed(7)) is False
        assert len(cursor.queries) == 2
        assert cursor.queries[1][0].startswith("SELECT 1")

    def test_heartbeat_accepts_an_existing_row_when_timestamp_is_unchanged(self, monkeypatch):
        class Cursor:
            def __init__(self):
                self.rowcount = 0
                self.queries = []

            async def execute(self, query, params):
                self.queries.append((query, params))

            async def fetchone(self):
                return (1,)

        class AsyncContext:
            def __init__(self, value):
                self.value = value

            async def __aenter__(self):
                return self.value

            async def __aexit__(self, exc_type, exc, tb):
                return False

        cursor = Cursor()

        class Connection:
            def cursor(self):
                return AsyncContext(cursor)

        class Pool:
            def acquire(self):
                return AsyncContext(Connection())

        async def fake_get_db_pool():
            return Pool()

        monkeypatch.setattr(us, "get_db_pool", fake_get_db_pool)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        assert asyncio.run(us.heartbeat_download(7)) is True
        assert len(cursor.queries) == 2
        assert cursor.queries[1][0].startswith("SELECT 1")

    def test_open_and_suffix_ranges(self):
        assert us.parse_single_range_header("bytes=10-", 100) == (10, 99)
        assert us.parse_single_range_header("bytes=-12", 100) == (88, 99)
        assert us.parse_single_range_header("bytes=10-19", 100) == (10, 19)

    def test_multi_range_is_ignored_instead_of_misreported_as_partial(self):
        assert us.parse_single_range_header("bytes=0-1,4-5", 100) is None

    def test_invalid_range_returns_416_with_total_size(self):
        try:
            us.parse_single_range_header("bytes=100-", 100)
        except us.HTTPException as exc:
            assert exc.status_code == 416
            assert exc.headers == {"Content-Range": "bytes */100"}
        else:
            raise AssertionError("out-of-bounds range was accepted")

    def test_throttled_reader_seeks_and_stops_at_inclusive_end(self, tmp_path):
        source = tmp_path / "range.bin"
        source.write_bytes(bytes(range(100)))

        async def collect():
            chunks = []
            async for chunk in us.throttled_file_reader(
                source,
                100 * 1024 * 1024,
                start=10,
                end=19,
            ):
                chunks.append(chunk)
            return b"".join(chunks)

        assert asyncio.run(collect()) == bytes(range(10, 20))

    def test_unlimited_reader_is_still_streamed(self, tmp_path):
        source = tmp_path / "unlimited.bin"
        source.write_bytes(b"x" * (1024 * 1024 + 17))

        async def collect():
            return b"".join([
                chunk async for chunk in us.throttled_file_reader(source, 0)
            ])

        assert asyncio.run(collect()) == source.read_bytes()

    def test_admin_kill_stops_unlimited_reader_before_bytes(self, monkeypatch, tmp_path):
        source = tmp_path / "killed.bin"
        source.write_bytes(b"payload")
        state = {"interrupted": False}

        async def killed(_active_id):
            return True

        monkeypatch.setattr(us, "download_was_killed", killed)

        async def collect():
            return b"".join([
                chunk async for chunk in us.throttled_file_reader(
                    source, 0, active_id=7, transfer_state=state
                )
            ])

        assert asyncio.run(collect()) == b""
        assert state["interrupted"] is True


class TestTransferQuotaWindows:
    def test_plan_and_moderator_limits_merge_in_the_users_favour(self):
        plan = {
            "max_file_size_mb": 100,
            "max_files_per_session": 5,
            "storage_quota_mb": 1000,
            "limit_upload": 1024,
            "limit_download": 2048,
            "concurrent_downloads": 2,
            "concurrent_connections_per_file": 1,
            "transfer_quota_bytes": 500,
            "transfer_quota_period": "week",
        }
        moderator = {
            "max_file_size_mb": 250,
            "max_files_per_session": 20,
            "storage_quota_mb": 500,
            "limit_upload": 4096,
            "limit_download": 1024,
            "concurrent_downloads": 4,
            "concurrent_connections_per_file": 3,
            "transfer_quota_bytes": 100,
            "transfer_quota_period": "day",
        }

        merged = us._merge_group_limits(plan, moderator)

        assert merged["max_file_size_mb"] == 250
        assert merged["max_files_per_session"] == 20
        assert merged["storage_quota_mb"] == 1000
        assert merged["limit_upload"] == 4096
        assert merged["limit_download"] == 2048
        assert merged["transfer_quota_bytes"] == 100
        assert merged["transfer_quota_period"] == "day"

    def test_zero_limit_remains_unlimited_when_profiles_are_merged(self):
        merged = us._merge_group_limits(
            {"limit_download": 0, "transfer_quota_bytes": 0},
            {"limit_download": 1024, "transfer_quota_bytes": 500},
        )

        assert merged["limit_download"] == 0
        assert merged["transfer_quota_bytes"] == 0

    def test_week_starts_on_monday_utc(self):
        now = int(time.mktime((2026, 7, 29, 12, 0, 0, 0, 0, 0)))
        start, end = us._quota_period_window("week", now)
        assert end - start == 7 * 86400
        assert time.gmtime(start)[:6] == (2026, 7, 27, 0, 0, 0)


class TestWebhookSsrf:
    def test_public_target_is_resolved_and_pinned(self, monkeypatch):
        monkeypatch.setattr(
            us.socket,
            "getaddrinfo",
            lambda *args, **kwargs: [
                (us.socket.AF_INET, us.socket.SOCK_STREAM, us.socket.IPPROTO_TCP, "", ("93.184.216.34", 443))
            ],
        )

        target = us._public_webhook_target(
            "https://hooks.example.test/events?kind=upload"
        )

        assert target == (
            "https",
            "hooks.example.test",
            443,
            "93.184.216.34",
            "/events?kind=upload",
        )

    def test_any_private_dns_answer_rejects_the_whole_host(self, monkeypatch):
        monkeypatch.setattr(
            us.socket,
            "getaddrinfo",
            lambda *args, **kwargs: [
                (us.socket.AF_INET, us.socket.SOCK_STREAM, us.socket.IPPROTO_TCP, "", ("93.184.216.34", 443)),
                (us.socket.AF_INET, us.socket.SOCK_STREAM, us.socket.IPPROTO_TCP, "", ("127.0.0.1", 443)),
            ],
        )

        try:
            us._public_webhook_target("https://hooks.example.test/")
        except ValueError as exc:
            assert "not public" in str(exc)
        else:
            raise AssertionError("mixed public/private DNS answers must be rejected")

    def test_literals_credentials_and_nonstandard_ports_are_rejected(self):
        for url in (
            "http://127.0.0.1/hook",
            "http://169.254.169.254/latest/meta-data",
            "https://[::1]/hook",
            "https://user:pass@8.8.8.8/hook",
            "https://8.8.8.8:8443/hook",
        ):
            try:
                us._public_webhook_target(url)
            except ValueError:
                pass
            else:
                raise AssertionError(f"unsafe webhook accepted: {url}")

    def test_delivery_does_not_follow_redirects_and_uses_pinned_peer(self, monkeypatch):
        calls = {}

        class Response:
            status = 302

            def read(self, limit):
                calls["read_limit"] = limit
                return b""

        class Connection:
            def __init__(self, host, port, timeout):
                calls["peer"] = (host, port, timeout)

            def request(self, method, path, body, headers):
                calls["request"] = (method, path, body, headers)

            def getresponse(self):
                return Response()

            def close(self):
                calls["closed"] = True

        monkeypatch.setattr(
            us,
            "_public_webhook_target",
            lambda url: ("http", "hooks.example.test", 80, "93.184.216.34", "/hook"),
        )
        monkeypatch.setattr(us.http.client, "HTTPConnection", Connection)

        ok, info = us._post_webhook(
            "http://hooks.example.test/hook",
            "secret",
            "delivery-event-id",
            "upload",
            '{"event":"upload"}',
        )

        assert not ok and info == "302"
        assert calls["peer"] == ("93.184.216.34", 80, 10)
        assert calls["request"][3]["Host"] == "hooks.example.test"
        assert calls["request"][3]["X-FileHost-Delivery"] == "delivery-event-id"
        assert calls["read_limit"] == 4096
        assert calls["closed"] is True


class TestThumbnailAuthorization:
    def test_cached_thumbnail_is_not_served_before_database_policy(self, monkeypatch, tmp_path):
        class Cursor:
            def __init__(self):
                self.queries = []

            async def execute(self, query, params):
                self.queries.append((query, params))

            async def fetchone(self):
                return (
                    "image.jpg",
                    21,
                    "image/jpeg",
                    "protected-hash",
                    None,
                    None,
                    0,
                    0,
                    None,
                )

        class AsyncContext:
            def __init__(self, value):
                self.value = value

            async def __aenter__(self):
                return self.value

            async def __aexit__(self, exc_type, exc, tb):
                return False

        cursor = Cursor()

        class Connection:
            def cursor(self):
                return AsyncContext(cursor)

        class Pool:
            def acquire(self):
                return AsyncContext(Connection())

        async def fake_settings():
            return None

        async def fake_pool():
            return Pool()

        thumb_dir = tmp_path / "thumbs"
        thumb_dir.mkdir()
        (thumb_dir / "abc123.jpg").write_bytes(b"cached-secret-preview")

        monkeypatch.setattr(us, "THUMBS_AVAILABLE", True)
        monkeypatch.setattr(us, "THUMBS_DIR", thumb_dir)
        monkeypatch.setattr(us, "update_settings", fake_settings)
        monkeypatch.setattr(us, "get_db_pool", fake_pool)
        monkeypatch.setattr(us, "settings_cache", {"thumb_max": 400})
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        try:
            asyncio.run(us.thumbnail("abc123"))
        except us.HTTPException as exc:
            assert exc.status_code == 403
        else:
            raise AssertionError("cached thumbnail bypassed password policy")

        assert cursor.queries
        assert "`password_hash`" in cursor.queries[0][0]


class TestCollectionMemberAuthorization:
    def test_one_time_members_are_returned_for_caller_transaction(self):
        class Cursor:
            def __init__(self):
                self.calls = []
                self.rowcount = 0

            async def execute(self, query, params=None):
                self.calls.append((query, params))
            async def fetchall(self):
                return [
                    ("file1", "one.txt", 11, None, None, None, 0, 1, None, None),
                    ("file2", "two.txt", 22, None, None, 5, 2, 0, None, None),
                ]

            async def fetchone(self):
                return (0,)

        cursor = Cursor()
        members, one_time_ids = asyncio.run(
            us._authorize_collection_members(
                cursor,
                "fh_",
                "fh_collection_files",
                "fh_files",
                "collection1",
                False,
            )
        )

        assert members == [("file1", "one.txt", 11), ("file2", "two.txt", 22)]
        assert one_time_ids == ["file1"]
        assert len(cursor.calls) == 2
        assert "FOR UPDATE" in cursor.calls[0][0]
        assert all(
            "download_reservation_effects" in query
            for query, _params in cursor.calls[1:]
        )

    def test_protected_member_without_collection_password_is_rejected(self):
        class Cursor:
            def __init__(self):
                self.calls = []
                self.rowcount = 0

            async def execute(self, query, params=None):
                self.calls.append((query, params))

            async def fetchall(self):
                return [
                    (
                        "file1",
                        "secret.jpg",
                        123,
                        "hash",
                        None,
                        None,
                        0,
                        0,
                        None,
                        None,
                    )
                ]

            async def fetchone(self):
                return (0,)

        cursor = Cursor()
        try:
            asyncio.run(
                us._authorize_collection_members(
                    cursor,
                    "fh_",
                    "fh_collection_files",
                    "fh_files",
                    "collection1",
                    False,
                )
            )
        except us.HTTPException as exc:
            assert exc.status_code == 403
        else:
            raise AssertionError("unprotected collection exposed protected member")

        assert len(cursor.calls) == 1
        assert "FOR UPDATE" in cursor.calls[0][0]

    def test_signed_member_allow_list_round_trips_and_rejects_tampering(self):
        collection_id = "collection1"
        secret = "stored-delete-token-hash"
        payload = json.dumps(
            {"e": int(time.time()) + 60, "i": ["file1"]},
            separators=(",", ":"),
        ).encode()
        encoded = us.base64.urlsafe_b64encode(payload).decode().rstrip("=")
        mac = us.hmac.new(
            secret.encode(),
            f"{collection_id}|{encoded}".encode(),
            hashlib.sha256,
        ).hexdigest()

        assert us._collection_member_token_ids(
            collection_id, f"{encoded}.{mac}", secret
        ) == {"file1"}
        tampered_last = "0" if mac[-1] != "0" else "1"
        assert us._collection_member_token_ids(
            collection_id, f"{encoded}.{mac[:-1]}{tampered_last}", secret
        ) is None

    def test_prompt_policy_skips_only_protected_members_not_in_allow_list(self):
        class Cursor:
            async def execute(self, query, params=None):
                pass

            async def fetchall(self):
                return [
                    ("file1", "secret.jpg", 123, "hash", None, None, 0, 0, None, None),
                    ("file2", "public.txt", 45, None, None, None, 0, 0, None, None),
                ]

        members, one_time_ids = asyncio.run(
            us._authorize_collection_members(
                Cursor(),
                "fh_",
                "fh_collection_files",
                "fh_files",
                "collection1",
                False,
                "prompt_skip",
                set(),
            )
        )

        assert members == [("file2", "public.txt", 45)]
        assert one_time_ids == []


class TestUploadStaging:
    def test_publish_commits_row_and_atomic_directory_before_success(self, monkeypatch, tmp_path):
        uploads = tmp_path / "uploads"
        staging_root = uploads / ".staging"
        uploads.mkdir()
        staging_root.mkdir()
        staging = staging_root / "file1-nonce"
        staging.mkdir()
        (staging / "payload.bin").write_bytes(b"payload")
        final = uploads / "file1"

        class Cursor:
            async def execute(self, query, params):
                assert "INSERT INTO" in query

        class AsyncContext:
            async def __aenter__(self):
                return Cursor()

            async def __aexit__(self, exc_type, exc, tb):
                return False

        class Connection:
            def __init__(self):
                self.events = []

            async def begin(self):
                self.events.append("begin")

            def cursor(self):
                return AsyncContext()

            async def commit(self):
                self.events.append("commit")

            async def rollback(self):
                self.events.append("rollback")

        connection = Connection()

        class PoolContext:
            async def __aenter__(self):
                return connection

            async def __aexit__(self, exc_type, exc, tb):
                return False

        class Pool:
            def acquire(self):
                return PoolContext()

        async def ready():
            return Pool()

        async def traffic(*args, **kwargs):
            return None

        monkeypatch.setattr(us, "UPLOADS_DIR", uploads)
        monkeypatch.setattr(us, "STAGING_DIR", staging_root)
        monkeypatch.setattr(us, "require_storage_ready", ready)
        monkeypatch.setattr(us, "log_traffic", traffic)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        asyncio.run(
            us.commit_staged_upload(
                staging,
                final,
                "file1",
                "payload.bin",
                "application/octet-stream",
                7,
                "hash",
                "203.0.113.1",
                5,
                hashlib.sha256(b"payload").hexdigest(),
            )
        )

        assert connection.events == ["begin", "commit"]
        assert not staging.exists()
        assert (final / "payload.bin").read_bytes() == b"payload"
        manifest = json.loads(
            (final / us.STORAGE_MANIFEST_NAME).read_text(encoding="utf-8")
        )
        assert manifest == {
            "version": 1,
            "name": "payload.bin",
            "size": 7,
            "sha256": hashlib.sha256(b"payload").hexdigest(),
        }

    def test_database_failure_removes_staging_and_never_publishes(self, monkeypatch, tmp_path):
        uploads = tmp_path / "uploads"
        staging_root = uploads / ".staging"
        uploads.mkdir()
        staging_root.mkdir()
        staging = staging_root / "file2-nonce"
        staging.mkdir()
        (staging / "payload.bin").write_bytes(b"payload")
        final = uploads / "file2"

        class Cursor:
            async def execute(self, query, params):
                raise RuntimeError("database write failed")

        class AsyncContext:
            async def __aenter__(self):
                return Cursor()

            async def __aexit__(self, exc_type, exc, tb):
                return False

        class Connection:
            def __init__(self):
                self.rolled_back = False

            async def begin(self):
                return None

            def cursor(self):
                return AsyncContext()

            async def commit(self):
                raise AssertionError("failed write must not commit")

            async def rollback(self):
                self.rolled_back = True

        connection = Connection()

        class PoolContext:
            async def __aenter__(self):
                return connection

            async def __aexit__(self, exc_type, exc, tb):
                return False

        class Pool:
            def acquire(self):
                return PoolContext()

        async def ready():
            return Pool()

        monkeypatch.setattr(us, "UPLOADS_DIR", uploads)
        monkeypatch.setattr(us, "STAGING_DIR", staging_root)
        monkeypatch.setattr(us, "require_storage_ready", ready)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        try:
            asyncio.run(
                us.commit_staged_upload(
                    staging,
                    final,
                    "file2",
                    "payload.bin",
                    "application/octet-stream",
                    7,
                    "hash",
                    "203.0.113.1",
                    5,
                    hashlib.sha256(b"payload").hexdigest(),
                )
            )
        except us.HTTPException as exc:
            assert exc.status_code == 503
        else:
            raise AssertionError("failed DB write reported upload success")

        assert connection.rolled_back
        assert not staging.exists()
        assert not final.exists()
