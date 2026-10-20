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
import os
import re
import socket
import sys
import threading
import time
import zipfile
from pathlib import Path

import pytest

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


class TestAdvisoryLockNames:
    """`GET_LOCK()` names are server-wide, so they must identify the installation."""

    def test_name_is_scoped_to_database_and_prefix(self, monkeypatch):
        monkeypatch.setattr(us, "db_config_cache", {"database": "files_a", "prefix": "fh_"})
        first = us.build_lock_name("upload-storage")
        monkeypatch.setattr(us, "db_config_cache", {"database": "files_b", "prefix": "fh_"})
        second = us.build_lock_name("upload-storage")
        monkeypatch.setattr(us, "db_config_cache", {"database": "files_a", "prefix": "other_"})
        third = us.build_lock_name("upload-storage")

        assert first != second, "two installs on one server would block each other"
        assert first != third, "two prefixes in one database would block each other"
        assert first.startswith("fh:") and first.endswith(":upload-storage")

    def test_name_is_stable_for_the_same_installation(self, monkeypatch):
        monkeypatch.setattr(us, "db_config_cache", {"database": "files", "prefix": "fh_"})
        assert us.build_lock_name("webhook-claim") == us.build_lock_name("webhook-claim")

    def test_every_name_fits_the_mysql_limit(self, monkeypatch):
        monkeypatch.setattr(
            us, "db_config_cache", {"database": "a" * 64, "prefix": "b" * 24}
        )
        suffixes = [
            "upload-storage",
            "webhook-claim",
            "download:" + hashlib.sha256(b"203.0.113.9").hexdigest()[:24],
        ]
        for suffix in suffixes:
            assert len(us.build_lock_name(suffix)) <= 64

    def test_missing_config_still_produces_a_usable_name(self, monkeypatch):
        monkeypatch.setattr(us, "db_config_cache", None)
        name = us.build_lock_name("upload-storage")
        assert name.startswith("fh:") and name.endswith(":upload-storage")


class TestPhpConfigParsing:
    def test_commented_storage_examples_are_not_configuration(self, monkeypatch, tmp_path):
        config = tmp_path / "config.local.php"
        config.write_text(
            "<?php\n"
            "// define('UPLOADS_PATH', 'D:/filehost-uploads');\n"
            "// define('UPLOADS_PATH', '/mnt/storage/uploads');\n",
            encoding="utf-8",
        )
        monkeypatch.setattr(us, "CONFIG_FILE", config)

        assert us._php_constant("UPLOADS_PATH") == ""

    def test_active_php_constant_is_read(self, monkeypatch, tmp_path):
        config = tmp_path / "config.local.php"
        config.write_text(
            "<?php\n  define('UPLOADS_PATH', '/srv/tryhackx-files/uploads');\n",
            encoding="utf-8",
        )
        monkeypatch.setattr(us, "CONFIG_FILE", config)

        assert us._php_constant("UPLOADS_PATH") == "/srv/tryhackx-files/uploads"

    def test_plain_double_quoted_constants_are_read_but_interpolation_is_not(self, monkeypatch, tmp_path):
        config = tmp_path / "config.local.php"
        config.write_text(
            '<?php\ndefine("TRUSTED_PROXIES", "172.18.0.5");\n'
            'define(\'TRUSTED_PROXY_HEADER\', "$header");\n',
            encoding="utf-8",
        )
        monkeypatch.setattr(us, "CONFIG_FILE", config)

        assert us._php_constant("TRUSTED_PROXIES") == "172.18.0.5"
        assert us._php_constant("TRUSTED_PROXY_HEADER") == ""


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


CLIENT_IP_VECTORS = json.loads(
    (Path(__file__).resolve().parents[1] / "fixtures" / "client-ip.json").read_text(encoding="utf-8")
)


class TestClientIp:
    @staticmethod
    def _request(peer, forwarded="", **headers):
        sent = {name.replace("_", "-"): value for name, value in headers.items()}
        if forwarded:
            sent["X-Forwarded-For"] = forwarded
        return _http_request("/download", headers=sent, client=(peer, 50000))

    @pytest.fixture(autouse=True)
    def _no_trusted_proxies(self, monkeypatch, tmp_path):
        monkeypatch.setattr(us, "CONFIG_FILE", tmp_path / "absent.php")

    def test_loopback_proxy_uses_the_hop_apache_appended(self):
        # mod_proxy APPENDS the peer it accepted; everything left of it came from the client.
        request = self._request("127.0.0.1", "198.51.100.27, 203.0.113.44")
        assert us.request_client_ip(request) == "203.0.113.44"

    def test_spoofed_forwarded_prefix_is_ignored(self):
        request = self._request("127.0.0.1", "6.6.6.6,10.0.0.1, 192.0.2.10")
        assert us.request_client_ip(request) == "192.0.2.10"

    def test_direct_loopback_means_no_proxy_hop(self):
        assert us.request_is_direct_loopback(self._request("127.0.0.1"))
        assert us.request_is_direct_loopback(self._request("::1"))
        assert not us.request_is_direct_loopback(self._request("127.0.0.1", "192.0.2.10"))
        assert not us.request_is_direct_loopback(self._request("203.0.113.8"))

    def test_direct_client_cannot_spoof_forwarded_address(self):
        request = self._request("203.0.113.8", "198.51.100.27")
        assert us.request_client_ip(request) == "203.0.113.8"

    def test_invalid_forwarded_address_falls_back_to_proxy_peer(self):
        request = self._request("::1", "not-an-ip")
        assert us.request_client_ip(request) == "::1"

    @pytest.mark.parametrize(
        "case", CLIENT_IP_VECTORS["resolve"], ids=lambda case: case["case"]
    )
    def test_resolution_matches_php_on_the_shared_vectors(self, case):
        assert us.resolve_client_ip(case["server"], case["trusted"], case["header"]) == case["expected"]

    @pytest.mark.parametrize(
        "case",
        [case for case in CLIENT_IP_VECTORS["resolve"] if not case.get("php_only")],
        ids=lambda case: case["case"],
    )
    def test_the_apache_hop_yields_what_php_sees(self, monkeypatch, case):
        # What reaches the sidecar for the request PHP saw: Apache appended its peer (PHP's
        # REMOTE_ADDR) to the X-Forwarded-For it received and passed every other header on.
        server = case["server"]
        forwarded = server["REMOTE_ADDR"]
        if "HTTP_X_FORWARDED_FOR" in server:
            forwarded = server["HTTP_X_FORWARDED_FOR"] + ", " + forwarded
        headers = {
            name: server[key]
            for name, key in (("CF-Connecting-IP", "HTTP_CF_CONNECTING_IP"),
                              ("X-Real-IP", "HTTP_X_REAL_IP"))
            if key in server
        }
        monkeypatch.setattr(
            us, "_trusted_proxy_settings", lambda: (tuple(case["trusted"]), case["header"])
        )
        request = _http_request(
            "/download", headers={"X-Forwarded-For": forwarded, **headers}, client=("127.0.0.1", 1)
        )
        assert us.request_client_ip(request) == case["expected"]

    def test_a_front_proxy_listed_in_the_config_is_skipped_like_php_does(self, monkeypatch, tmp_path):
        # The audit's Docker case: client 198.51.100.7 -> Traefik 172.18.0.5 -> Apache -> sidecar.
        config = tmp_path / "config.local.php"
        config.write_text('<?php\ndefine("TRUSTED_PROXIES", "172.18.0.5, 10.0.0.9");\n', encoding="utf-8")
        monkeypatch.setattr(us, "CONFIG_FILE", config)

        request = self._request("127.0.0.1", "198.51.100.7, 172.18.0.5")
        assert us.request_client_ip(request) == "198.51.100.7"

        # A visitor talking to Apache directly still cannot pick an address.
        direct = self._request("127.0.0.1", "6.6.6.6, 198.51.100.9")
        assert us.request_client_ip(direct) == "198.51.100.9"

    def test_a_named_header_is_believed_only_from_a_configured_proxy(self, monkeypatch, tmp_path):
        config = tmp_path / "config.local.php"
        config.write_text(
            "<?php\ndefine('TRUSTED_PROXIES', '172.18.0.5');\n"
            "define('TRUSTED_PROXY_HEADER', 'CF-Connecting-IP');\n",
            encoding="utf-8",
        )
        monkeypatch.setattr(us, "CONFIG_FILE", config)

        via_proxy = self._request("127.0.0.1", "172.18.0.5", CF_Connecting_IP="198.51.100.8")
        assert us.request_client_ip(via_proxy) == "198.51.100.8"
        direct = self._request("127.0.0.1", "203.0.113.9", CF_Connecting_IP="198.51.100.8")
        assert us.request_client_ip(direct) == "203.0.113.9"

    def test_trusted_proxies_follow_the_config_file(self, monkeypatch, tmp_path):
        config = tmp_path / "config.local.php"
        monkeypatch.setattr(us, "CONFIG_FILE", config)
        assert us._trusted_proxy_settings() == ((), "")

        config.write_text("<?php\ndefine('TRUSTED_PROXIES', '10.0.0.1,10.0.0.2');\n", encoding="utf-8")
        assert us._trusted_proxy_settings() == (("10.0.0.1", "10.0.0.2"), "")

        config.write_text("<?php\n// define('TRUSTED_PROXIES', '10.0.0.1');\n", encoding="utf-8")
        later = time.time() + 5
        os.utime(config, (later, later))
        assert us._trusted_proxy_settings() == ((), "")


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

    @staticmethod
    def _record_finalisation(monkeypatch, events):
        async def no_op(*args, **kwargs):
            return None

        async def finish(reservation_id, state, size):
            events.append((reservation_id, state, size))

        monkeypatch.setattr(us, "mark_download_reservation_started", no_op)
        monkeypatch.setattr(us, "heartbeat_download", no_op)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", no_op)
        return no_op

    def test_every_promised_byte_sent_counts_even_without_the_closing_message(
        self, monkeypatch
    ):
        # Apache proxies these routes without persistent connections and hangs up as soon as
        # Content-Length is satisfied; the disconnect cancels the stream before its closing
        # empty message. The download is still complete.
        events = []
        no_op = self._record_finalisation(monkeypatch, events)

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.16", "file_id": "file5", "active_id": 8,
                "reservation_id": "a" * 32,
            }
            await send({"type": "http.response.start", "status": 200,
                        "headers": [(b"content-length", b"6")]})
            await send({"type": "http.response.body", "body": b"abc", "more_body": True})
            await send({"type": "http.response.body", "body": b"def", "more_body": True})
            # ... and the stream is cancelled here, as StreamingResponse is on disconnect.

        asyncio.run(us.ActualTransferAccountingMiddleware(app)(
            {"type": "http"}, no_op, lambda _message: no_op()
        ))

        assert events == [("a" * 32, "completed", 6)]

    def test_a_disconnect_raised_after_the_last_byte_still_counts(self, monkeypatch):
        events = []
        no_op = self._record_finalisation(monkeypatch, events)

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.17", "file_id": "file6", "active_id": 9,
                "reservation_id": "b" * 32,
            }
            await send({"type": "http.response.start", "status": 200,
                        "headers": [(b"content-length", b"3")]})
            await send({"type": "http.response.body", "body": b"xyz", "more_body": True})
            await send({"type": "http.response.body", "body": b"", "more_body": False})

        async def send(message):
            if message["type"] == "http.response.body" and not message.get("more_body"):
                raise OSError("peer closed after reading Content-Length bytes")

        try:
            asyncio.run(us.ActualTransferAccountingMiddleware(app)({"type": "http"}, no_op, send))
        except OSError:
            pass

        assert events == [("b" * 32, "completed", 3)]

    def test_chunked_body_without_its_terminating_message_is_not_complete(self, monkeypatch):
        # No Content-Length (the collection ZIP): only the closing message ends the body.
        events = []
        no_op = self._record_finalisation(monkeypatch, events)

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.18", "file_id": None, "active_id": 10,
                "reservation_id": "c" * 32,
            }
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send({"type": "http.response.body", "body": b"zip-part", "more_body": True})

        asyncio.run(us.ActualTransferAccountingMiddleware(app)(
            {"type": "http"}, no_op, lambda _message: no_op()
        ))

        assert events == [("c" * 32, "released", 8)]


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
class TestStreamingResponseFinalisation:
    """The contract the download path depends on, asserted against the installed Starlette.

    A streaming download hands its reader to a background task so the generator is finalised
    and its file descriptor released. Starlette classifies a background task with an
    ``iscoroutinefunction()`` check, which is False for an async generator's built-in
    ``aclose`` - handing that in directly would run it in a worker thread and drop the
    coroutine unawaited, leaking a descriptor per download. These tests fail if a dependency
    update ever changes either half of that.
    """

    def test_a_bare_aclose_is_not_recognised_as_an_async_task(self):
        from starlette.background import BackgroundTask

        async def generator():
            yield b"chunk"

        reader = generator()
        assert not BackgroundTask(reader.aclose).is_async
        assert BackgroundTask(us.close_stream_reader, reader).is_async
        asyncio.run(us.close_stream_reader(reader))

    def test_the_background_task_finalises_the_reader_after_the_body(self):
        from starlette.background import BackgroundTask
        from starlette.responses import StreamingResponse

        finalised = []

        async def generator():
            try:
                yield b"first"
                yield b"second"
            finally:
                finalised.append(True)

        reader = generator()
        response = StreamingResponse(
            reader,
            media_type="application/octet-stream",
            background=BackgroundTask(us.close_stream_reader, reader),
        )

        body = bytearray()
        status = []

        async def receive():
            # Starlette watches for a client disconnect while it streams. Returning a message
            # every time would spin that watcher and starve the sender, so this reports the
            # (empty) request once and then waits to be cancelled with the response.
            if not receive.sent:
                receive.sent = True
                return {"type": "http.request", "body": b"", "more_body": False}
            await asyncio.Event().wait()
            raise AssertionError("unreachable")

        receive.sent = False

        async def send(message):
            if message["type"] == "http.response.start":
                status.append(message["status"])
            elif message["type"] == "http.response.body":
                body.extend(message.get("body", b""))

        scope = {
            "type": "http",
            "asgi": {"version": "3.0"},
            "http_version": "1.1",
            "method": "GET",
            "scheme": "http",
            "path": "/",
            "raw_path": b"/",
            "query_string": b"",
            "root_path": "",
            "headers": [],
            "client": ("127.0.0.1", 1234),
            "server": ("127.0.0.1", 8001),
        }
        async def drive():
            await asyncio.wait_for(response(scope, receive, send), timeout=10)

        asyncio.run(drive())

        assert status == [200]
        assert bytes(body) == b"firstsecond"
        assert finalised == [True]
class TestGuestTransferLimits:
    """Where a guest's throttle actually comes from.

    `limit_download_guest` in the settings table looks like the knob and is not: the migration
    that introduced groups copied it into the guest group's `limit_download` once, and the
    download path has read the group row ever since. Writing the setting alone changes nothing,
    which is what silently disabled throttling in the Docker transfer smoke.
    """

    GROUPS = {
        7: {'slug': 'guest', 'limit_download': 131072, 'limit_upload': 0,
            'concurrent_downloads': 2, 'transfer_quota_bytes': 0},
        8: {'slug': 'user', 'limit_download': 999, 'limit_upload': 0,
            'concurrent_downloads': 2, 'transfer_quota_bytes': 0},
    }

    def test_a_guest_resolves_to_the_guest_group_row(self, monkeypatch):
        monkeypatch.setitem(us.settings_cache, 'groups', self.GROUPS)
        # A stale flat setting must not win, in either direction.
        monkeypatch.setitem(us.settings_cache, 'limit_download_guest', 4242)

        limits = asyncio.run(us.get_user_group_limits(None))

        assert limits['slug'] == 'guest'
        assert limits['limit_download'] == 131072

    def test_no_guest_group_is_an_error_not_an_unthrottled_download(self, monkeypatch):
        monkeypatch.setitem(us.settings_cache, 'groups', {8: self.GROUPS[8]})
        monkeypatch.setitem(us.settings_cache, 'limit_download_guest', 131072)

        try:
            asyncio.run(us.get_user_group_limits(None))
        except us.HTTPException as exc:
            assert exc.status_code == 503
        else:
            raise AssertionError('a missing guest group must not fall back to no limit')


# ---------------------------------------------------------------------------------------------
# Shared fakes for the request-path tests below.
# ---------------------------------------------------------------------------------------------

class _AsyncContext:
    def __init__(self, value):
        self.value = value

    async def __aenter__(self):
        return self.value

    async def __aexit__(self, exc_type, exc, tb):
        return False


class _ScriptedCursor:
    """Answers fetchone()/fetchall() by the first needle found in the most recent query."""

    def __init__(self, answers=(), all_answers=()):
        self.answers = list(answers)
        self.all_answers = list(all_answers)
        self.queries = []
        self.rowcount = 1
        self.lastrowid = 5

    async def execute(self, query, params=None):
        self.queries.append((query, params))

    def _match(self, table):
        last = self.queries[-1][0] if self.queries else ""
        for needle, value in table:
            if needle in last:
                return value
        return None

    async def fetchone(self):
        return self._match(self.answers)

    async def fetchall(self):
        return self._match(self.all_answers) or []


class _Connection:
    def __init__(self, cursor):
        self._cursor = cursor

    def cursor(self):
        return _AsyncContext(self._cursor)

    async def begin(self):
        return None

    async def commit(self):
        return None

    async def rollback(self):
        return None


class _OneConnectionPool:
    """A pool of exactly one connection that fails loudly on a nested acquire."""

    def __init__(self, cursor):
        self.connection = _Connection(cursor)
        self.in_use = 0
        self.max_in_use = 0

    async def _take(self):
        if self.in_use:
            raise AssertionError("a second connection was requested while one is held")
        self.in_use += 1
        self.max_in_use = max(self.max_in_use, self.in_use)
        return self.connection

    def acquire(self):
        pool = self

        class Acquire:
            def __await__(self):
                return pool._take().__await__()

            async def __aenter__(self):
                return await pool._take()

            async def __aexit__(self, exc_type, exc, tb):
                pool.release(pool.connection)
                return False

        return Acquire()

    def release(self, _conn):
        self.in_use -= 1


def _http_request(path="/", query=b"", headers=None, client=("203.0.113.9", 50000),
                  receive=None, method="GET"):
    from starlette.requests import Request

    scope = {
        "type": "http",
        "asgi": {"version": "3.0"},
        "http_version": "1.1",
        "method": method,
        "scheme": "http",
        "path": path,
        "raw_path": path.encode(),
        "query_string": query,
        "root_path": "",
        "headers": [
            (name.lower().encode("latin-1"), value.encode("latin-1"))
            for name, value in (headers or {}).items()
        ],
        "client": client,
        "server": ("127.0.0.1", 8001),
    }
    return Request(scope) if receive is None else Request(scope, receive)


def _receive_chunks(chunks):
    state = {"calls": 0}

    async def receive():
        index = state["calls"]
        state["calls"] += 1
        if index < len(chunks):
            return {
                "type": "http.request",
                "body": chunks[index],
                "more_body": index < len(chunks) - 1,
            }
        return {"type": "http.disconnect"}

    return receive, state


USER_GROUPS = {
    1: {"slug": "user", "limit_download": 0, "concurrent_downloads": 0,
        "concurrent_connections_per_file": 0, "transfer_quota_bytes": 0},
    2: {"slug": "guest", "limit_download": 0, "concurrent_downloads": 0,
        "concurrent_connections_per_file": 0, "transfer_quota_bytes": 0},
}


class TestBoundedPoolAcquire:
    """A saturated pool answers 503 instead of parking every request behind it."""

    def test_saturated_pool_answers_503_instead_of_waiting_forever(self):
        class NeverFreePool:
            def acquire(self):
                return asyncio.Event().wait()

            def release(self, _conn):
                raise AssertionError("nothing was acquired")

        async def scenario():
            try:
                await us.acquire_bounded(NeverFreePool(), wait_seconds=0.05)
            except us.HTTPException as exc:
                return exc.status_code
            return None

        assert asyncio.run(scenario()) == 503

    def test_connection_granted_after_the_deadline_goes_back_to_the_pool(self):
        released = []

        class LatePool:
            def acquire(self):
                async def late():
                    try:
                        await asyncio.sleep(0.2)
                    except asyncio.CancelledError:
                        pass  # the pool handed the connection over just as the waiter gave up
                    return "connection"

                return late()

            def release(self, conn):
                released.append(conn)

        async def scenario():
            try:
                await us.acquire_bounded(LatePool(), wait_seconds=0.02)
            except us.HTTPException:
                pass
            await asyncio.sleep(0.05)

        asyncio.run(scenario())
        assert released == ["connection"]


class TestDownloadUsesOneConnection:
    """A signed-in download used to hold one pooled connection while asking for a second;
    twenty of them at once deadlocked the whole pool."""

    def test_group_lookup_runs_on_the_callers_cursor(self, monkeypatch):
        async def forbidden():
            raise AssertionError("nested pool acquire")

        monkeypatch.setattr(us, "get_db_pool", forbidden)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})
        monkeypatch.setitem(us.settings_cache, "groups", {
            1: {"slug": "user", "limit_download": 7},
            2: {"slug": "guest", "limit_download": 0},
        })
        monkeypatch.setitem(us.settings_cache, "default_group_id", 1)
        cursor = _ScriptedCursor([("FROM `fh_users`", (None, None, "user"))])

        limits = asyncio.run(us.get_user_group_limits(42, cursor))

        assert limits["limit_download"] == 7
        assert "FROM `fh_users`" in cursor.queries[0][0]

    def test_signed_in_download_never_asks_for_a_second_connection(self, monkeypatch, tmp_path):
        payload = tmp_path / "a.bin"
        payload.write_bytes(b"abc")
        cursor = _ScriptedCursor([
            ("GET_LOCK", (1,)),
            ("COUNT(", (0,)),
            ("FROM `fh_download_tokens` WHERE `token`", ("file1", 42)),
            ("FROM `fh_files` WHERE `id`",
             ("a.bin", "application/octet-stream", 3, None, None, 0, 0, None, None, 9)),
            ("FROM `fh_users`", (None, None, "user")),
        ])
        pool = _OneConnectionPool(cursor)

        async def no_settings():
            return None

        async def same_pool(*_args, **_kwargs):
            return pool

        async def resolved(*_args, **_kwargs):
            return payload

        monkeypatch.setattr(us, "update_settings", no_settings)
        monkeypatch.setattr(us, "require_storage_ready", same_pool)
        monkeypatch.setattr(us, "get_db_pool", same_pool)
        monkeypatch.setattr(us, "resolve_storage_file", resolved)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_", "database": "files"})
        monkeypatch.setitem(us.settings_cache, "groups", USER_GROUPS)
        monkeypatch.setitem(us.settings_cache, "default_group_id", 1)
        request = _http_request("/download", b"token=t")

        response = asyncio.run(us.download_file("t", request, us.BackgroundTasks()))

        assert response.status_code == 200
        assert pool.max_in_use == 1 and pool.in_use == 0
        assert any("FROM `fh_users`" in query for query, _ in cursor.queries)
        assert re.fullmatch(r"[a-f0-9]{32}", request.scope["filehost.traffic"]["reservation_id"])


class TestCollectionTagsFromTheQueryString:
    """`v`, `t` and `m` come straight from the URL; none of them may turn into a 500."""

    FUTURE = int(time.time()) + 3600

    @pytest.mark.parametrize("mac", ["é", "é" * 64, "A" * 64, "g" * 64, "a" * 63, ""])
    def test_a_malformed_mac_is_simply_not_valid(self, mac):
        assert us._collection_viewer_id("abc", f"1.{self.FUTURE}.{mac}", "h") is None
        assert us._collection_token_ok("abc", f"{self.FUTURE}.{mac}", "h") is False
        assert us._collection_member_token_ids("abc", f"e30.{mac}", "h") is None

    def test_a_correct_tag_still_identifies_the_viewer(self):
        mac = us.hmac.new(b"h", f"7|abc|{self.FUTURE}".encode(), hashlib.sha256).hexdigest()
        assert us._collection_viewer_id("abc", f"7.{self.FUTURE}.{mac}", "h") == 7

    @staticmethod
    def _guest_one_time_collection(monkeypatch, tmp_path):
        payload = tmp_path / "member.txt"
        payload.write_bytes(b"hello")
        cursor = _ScriptedCursor(
            answers=[
                ("GET_LOCK", (1,)),
                ("COUNT(", (0,)),
                ("FROM `fh_download_tokens` WHERE `token`", (None,)),  # a guest's `dt`
                ("FROM `fh_collections` WHERE `id`",
                 ("Holiday", None, None, None, 0, 1, None, None, "hash", "keep", 9)),
            ],
            all_answers=[
                ("FROM `fh_collection_files`",
                 [("file1", "member.txt", 5, None, None, None, 0, 0, None, None)]),
            ],
        )
        pool = _OneConnectionPool(cursor)

        async def none(*_args, **_kwargs):
            return None

        async def same_pool(*_args, **_kwargs):
            return pool

        async def resolved(*_args, **_kwargs):
            return payload

        monkeypatch.setattr(us, "update_settings", none)
        monkeypatch.setattr(us, "require_storage_ready", same_pool)
        monkeypatch.setattr(us, "get_db_pool", same_pool)
        monkeypatch.setattr(us, "resolve_storage_file", resolved)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_", "database": "files"})
        monkeypatch.setitem(us.settings_cache, "groups", USER_GROUPS)
        monkeypatch.setitem(us.settings_cache, "default_group_id", 1)
        monkeypatch.setitem(us.settings_cache, "collection_counts_files", False)
        return cursor

    def test_a_crafted_viewer_tag_cannot_strand_a_committed_reservation(self, monkeypatch, tmp_path):
        # The audit's probe: guest token, one-time collection, non-ASCII MAC in `v`.
        cursor = self._guest_one_time_collection(monkeypatch, tmp_path)
        request = _http_request("/collection", b"id=c1&dt=x")

        response = asyncio.run(us.download_collection(
            "c1", request, us.BackgroundTasks(), dt="x", v=f"1.{self.FUTURE}.é"
        ))

        assert response.status_code == 200
        assert any(query.strip() == "COMMIT" for query, _ in cursor.queries)
        context = request.scope["filehost.traffic"]
        assert re.fullmatch(r"[a-f0-9]{32}", context["reservation_id"])
        assert context["completion"]["viewer_id"] is None

    def test_a_failure_after_the_commit_still_hands_the_reservation_over(self, monkeypatch, tmp_path):
        self._guest_one_time_collection(monkeypatch, tmp_path)

        def broken_log(_message):
            raise RuntimeError("stdout is gone")

        monkeypatch.setattr(us, "info_log", broken_log)
        request = _http_request("/collection", b"id=c1&dt=x")

        with pytest.raises(RuntimeError):
            asyncio.run(us.download_collection("c1", request, us.BackgroundTasks(), dt="x"))

        # The accounting middleware finds it and releases it instead of the reaper, minutes later.
        assert re.fullmatch(r"[a-f0-9]{32}", request.scope["filehost.traffic"]["reservation_id"])


class TestAbandonedZipClosesItsMember:
    """Real uvicorn and the real /collection handler; the visitor leaves mid-archive."""

    def test_the_member_file_is_closed_as_soon_as_the_visitor_leaves(self, monkeypatch, tmp_path):
        import uvicorn

        member = tmp_path / "member.bin"
        member.write_bytes(b"Z" * (16 * 1024 * 1024))
        cursor = _ScriptedCursor(
            answers=[
                ("GET_LOCK", (1,)),
                ("COUNT(", (0,)),
                ("FROM `fh_download_tokens` WHERE `token`", (None,)),
                ("FROM `fh_collections` WHERE `id`",
                 ("Holiday", None, None, None, 0, 0, None, None, "hash", "keep", 9)),
            ],
            all_answers=[
                ("FROM `fh_collection_files`",
                 [("file1", "member.bin", member.stat().st_size, None, None, None, 0, 0, None, None)]),
            ],
        )
        pool = _OneConnectionPool(cursor)
        opened, finalised = [], []
        real_open = open

        def tracking_open(path, *args, **kwargs):
            handle = real_open(path, *args, **kwargs)
            if Path(path) == member:
                opened.append(handle)
            return handle

        async def none(*_args, **_kwargs):
            return None

        async def same_pool(*_args, **_kwargs):
            return pool

        async def resolved(*_args, **_kwargs):
            return member

        async def not_killed(_active_id):
            await asyncio.sleep(0.002)
            return False

        async def finalize(_context, state, sent):
            finalised.append((state, sent))

        for name, value in (
            ("update_settings", none), ("require_storage_ready", same_pool),
            ("get_db_pool", same_pool), ("resolve_storage_file", resolved),
            ("mark_download_reservation_started", none), ("heartbeat_download", none),
            ("download_was_killed", not_killed), ("log_traffic", none),
            ("register_download_end", none), ("_finalize_download_context", finalize),
        ):
            monkeypatch.setattr(us, name, value)
        monkeypatch.setattr(us, "open", tracking_open, raising=False)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_", "database": "files"})
        # Throttled, so the archive is still being written when the visitor leaves.
        monkeypatch.setitem(us.settings_cache, "groups", {
            1: USER_GROUPS[1], 2: {**USER_GROUPS[2], "limit_download": 2 * 1024 * 1024},
        })
        monkeypatch.setitem(us.settings_cache, "default_group_id", 1)
        monkeypatch.setitem(us.settings_cache, "collection_counts_files", False)

        def visitor(port):
            sock = socket.create_connection(("127.0.0.1", port), timeout=20)
            sock.sendall(b"GET /collection?id=c1&dt=x HTTP/1.1\r\nHost: files\r\n\r\n")
            got = 0
            while got < 1_500_000:
                chunk = sock.recv(65536)
                if not chunk:
                    break
                got += len(chunk)
            sock.close()  # gives up mid-archive
            return got

        async def scenario():
            probe = socket.socket()
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
            probe.close()
            server = uvicorn.Server(uvicorn.Config(
                us.app, host="127.0.0.1", port=port, log_level="warning", access_log=False,
                lifespan="off",
            ))
            task = asyncio.create_task(server.serve())
            for _ in range(500):
                if server.started:
                    break
                await asyncio.sleep(0.02)
            assert server.started, "uvicorn did not start"
            try:
                got = await asyncio.to_thread(visitor, port)
                left_at = time.monotonic()
                for _ in range(100):  # bounded: two seconds at most
                    if opened and opened[0].closed:
                        break
                    await asyncio.sleep(0.02)
                closed_after = time.monotonic() - left_at
            finally:
                server.should_exit = True
                await task
            return got, closed_after

        got, closed_after = asyncio.run(scenario())

        assert got >= 1_500_000
        assert opened, "the member was never opened"
        assert opened[0].closed, "the member file stayed open after the visitor left"
        assert closed_after < 1.0
        assert finalised and finalised[0][0] == "released"


class TestQrBodyIsBounded:
    def test_oversized_body_is_refused_while_streaming(self):
        receive, state = _receive_chunks([b"x" * 4096] * 10)
        request = _http_request("/qr", receive=receive, method="POST")

        try:
            asyncio.run(us.qr_code_post(request))
        except us.HTTPException as exc:
            assert exc.status_code == 413
        else:
            raise AssertionError("an unbounded body was buffered")
        assert state["calls"] < 10

    def test_declared_oversized_body_is_refused_unread(self):
        receive, state = _receive_chunks([b"{}"])
        request = _http_request(
            "/qr", headers={"Content-Length": str(10 ** 9)}, receive=receive, method="POST"
        )

        try:
            asyncio.run(us.qr_code_post(request))
        except us.HTTPException as exc:
            assert exc.status_code == 413
        else:
            raise AssertionError("a declared 1 GB body was accepted")
        assert state["calls"] == 0

    def test_small_payload_still_renders_and_non_objects_are_rejected(self):
        body = json.dumps({"data": "otpauth://totp/files?secret=ABCDEF"}).encode()
        receive, _ = _receive_chunks([body])
        request = _http_request(
            "/qr", headers={"Content-Length": str(len(body))}, receive=receive, method="POST"
        )
        response = asyncio.run(us.qr_code_post(request))
        assert response.media_type == "image/svg+xml"

        receive, _ = _receive_chunks([b"[1, 2]"])
        try:
            asyncio.run(us.qr_code_post(_http_request("/qr", receive=receive, method="POST")))
        except us.HTTPException as exc:
            assert exc.status_code == 400
        else:
            raise AssertionError("a JSON list was treated as an object")


class TestQrRenderingStaysOffTheEventLoop:
    """One render holds the GIL for tens of milliseconds; on the loop it froze every transfer."""

    @staticmethod
    def _status(coroutine):
        try:
            asyncio.run(coroutine)
        except us.HTTPException as exc:
            return exc.status_code, exc.headers
        return 200, None

    def test_payloads_over_the_byte_cap_are_refused(self, monkeypatch):
        monkeypatch.setattr(us, "_qr_cache", us.OrderedDict())
        assert self._status(us.qr_code("A" * us.QR_DATA_MAX_BYTES))[0] == 200
        assert self._status(us.qr_code("A" * (us.QR_DATA_MAX_BYTES + 1)))[0] == 400
        # Counted in UTF-8: 257 two-byte characters are 514 bytes.
        assert self._status(us.qr_code("é" * 257))[0] == 400

    def test_a_real_otpauth_uri_with_a_long_site_name_still_renders(self, monkeypatch):
        monkeypatch.setattr(us, "_qr_cache", us.OrderedDict())
        site = "Moj%20bardzo%20dlugi%20serwer%20plikow%20%E2%80%94%20TryHackX%20Files"
        uri = (
            f"otpauth://totp/{site}:{'u' * 50}?secret={'A' * 32}&issuer={site}"
            "&algorithm=SHA1&digits=6&period=30"
        )
        assert len(uri) < us.QR_DATA_MAX_BYTES
        assert self._status(us.qr_code(uri))[0] == 200

    def test_render_runs_on_the_worker_thread(self, monkeypatch):
        import segno

        seen = []
        real_make = segno.make

        def recording_make(*args, **kwargs):
            seen.append(threading.current_thread())
            return real_make(*args, **kwargs)

        monkeypatch.setattr(segno, "make", recording_make)
        monkeypatch.setattr(us, "_qr_cache", us.OrderedDict())

        async def render():
            loop_thread = threading.current_thread()
            response = await us.qr_code("https://files.example.org/download.php?id=abc")
            return loop_thread, response

        loop_thread, response = asyncio.run(render())

        assert response.media_type == "image/svg+xml"
        assert len(seen) == 1
        assert seen[0] is not loop_thread
        assert seen[0].name.startswith("filehost-qr")

    def test_a_saturated_renderer_answers_503_instead_of_queueing(self, monkeypatch):
        monkeypatch.setattr(us, "_qr_cache", us.OrderedDict())
        monkeypatch.setattr(us, "_qr_pending", us.QR_MAX_PENDING)

        status, headers = self._status(us.qr_code("https://files.example.org/x"))

        assert status == 503
        assert headers == {"Retry-After": "1"}

    def test_links_are_cached_but_posted_secrets_are_not(self, monkeypatch):
        import segno

        calls = []
        real_make = segno.make

        def counting_make(*args, **kwargs):
            calls.append(args[0])
            return real_make(*args, **kwargs)

        monkeypatch.setattr(segno, "make", counting_make)
        monkeypatch.setattr(us, "_qr_cache", us.OrderedDict())

        link = "https://files.example.org/collection.php?id=abc"
        first = asyncio.run(us.qr_code(link))
        second = asyncio.run(us.qr_code(link))
        assert first.body == second.body and calls == [link]
        assert first.headers["cache-control"] == "public, max-age=86400"

        body = json.dumps({"data": "otpauth://totp/files:me?secret=ABCDEF"}).encode()
        for _ in range(2):
            receive, _state = _receive_chunks([body])
            response = asyncio.run(us.qr_code_post(_http_request(
                "/qr", headers={"Content-Length": str(len(body))}, receive=receive, method="POST"
            )))
            assert response.headers["cache-control"] == "no-store"
        assert calls.count("otpauth://totp/files:me?secret=ABCDEF") == 2
        assert us._qr_pending == 0


class TestWebhookDeliveryDeadline:
    def test_slow_receiver_is_cut_at_the_delivery_deadline(self, monkeypatch):
        server = socket.socket()
        server.bind(("127.0.0.1", 0))
        server.listen(1)
        port = server.getsockname()[1]
        stop = threading.Event()

        def dribble():
            try:
                connection, _ = server.accept()
            except OSError:
                return
            try:
                connection.recv(65536)
                while not stop.is_set():
                    connection.sendall(b"H")  # one byte at a time, never a full status line
                    stop.wait(0.05)
            except OSError:
                pass
            finally:
                connection.close()

        thread = threading.Thread(target=dribble, daemon=True)
        thread.start()
        monkeypatch.setattr(
            us,
            "_public_webhook_target",
            lambda url: ("http", "127.0.0.1", port, "127.0.0.1", "/hook"),
        )

        started = time.monotonic()
        try:
            ok, info = us._post_webhook(
                "http://hooks.example.test/hook", "secret", "event", "upload", "{}",
                deadline=0.4,
            )
        finally:
            stop.set()
            server.close()
            thread.join(timeout=5)

        assert not ok and info == "deadline"
        assert time.monotonic() - started < 5

    def test_deliveries_run_on_their_own_threads(self):
        assert us.WEBHOOK_EXECUTOR._max_workers == us.WEBHOOK_CONCURRENCY
        assert us.WEBHOOK_EXECUTOR is not us.THUMB_EXECUTOR


class TestZip64Members:
    def test_member_over_the_zip64_limit_streams_into_a_valid_archive(self, monkeypatch, tmp_path):
        # The real limit is 2 GiB; lowering it reproduces the same code path with 3 MiB.
        monkeypatch.setattr(us.zipfile, "ZIP64_LIMIT", 1024 * 1024)
        big = tmp_path / "big.bin"
        big.write_bytes(b"B" * (3 * 1024 * 1024))
        small = tmp_path / "small.txt"
        small.write_bytes(b"small")

        archive = b"".join(us._zip_stream([(big, "big.bin"), (small, "small.txt")]))

        with zipfile.ZipFile(io.BytesIO(archive)) as zf:
            assert zf.namelist() == ["big.bin", "small.txt"]
            assert zf.read("big.bin") == big.read_bytes()
            assert zf.read("small.txt") == b"small"
            assert zf.testzip() is None


class TestThrottleKey:
    @pytest.mark.parametrize(
        "ip, key", CLIENT_IP_VECTORS["throttle_keys"], ids=lambda value: repr(value)
    )
    def test_matches_php_on_the_shared_vectors(self, ip, key):
        assert us.throttle_key(ip) == key

    def test_one_network_is_one_client_and_its_neighbours_are_not(self):
        assert us.throttle_key("2001:db8:1:2::1") == us.throttle_key("2001:db8:1:2:ffff::ffff")
        assert us.throttle_key("2001:db8:1:2::1") != us.throttle_key("2001:db8:1:3::1")
        assert us.throttle_key("203.0.113.9") != us.throttle_key("203.0.113.10")
        assert len(us.throttle_key("ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff")) <= 45


class TestUploadIdentity:
    """Uploads need a token PHP minted; bans and caps follow the client's throttle key."""

    POLICY = [
        ("recaptcha_token_lifetime", "120"),
        ("recaptcha_max_files_per_session_guest", "5"),
        ("recaptcha_max_files_per_session_auth", "0"),
    ]

    def _run(self, monkeypatch, token, ip, banned=False, token_row=(None, 0)):
        cursor = _ScriptedCursor(
            [
                ("FROM `fh_blacklists`", (1,) if banned else None),
                ("FROM `fh_upload_tokens` t", token_row),
            ],
            [("FROM `fh_settings`", self.POLICY)],
        )
        pool = _OneConnectionPool(cursor)

        async def ready(*_args, **_kwargs):
            return pool

        monkeypatch.setattr(us, "require_storage_ready", ready)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})
        return asyncio.run(us.authorize_upload_identity(token, ip)), cursor

    def test_an_upload_without_a_token_is_refused_even_with_the_captcha_off(self, monkeypatch):
        # Each fresh address of a /64 used to get its own implicit guest session here.
        async def unreachable(*_args, **_kwargs):
            raise AssertionError("a token-less upload must not reach the database")

        monkeypatch.setattr(us, "require_storage_ready", unreachable)

        assert asyncio.run(us.authorize_upload_identity(None, "2001:db8:1:2::77")) == (
            False, None, None,
        )
        assert asyncio.run(us.authorize_upload_identity("", "198.51.100.4")) == (False, None, None)

    def test_a_token_opens_the_session_bound_to_the_exact_address(self, monkeypatch):
        (allowed, user_id, reservation), cursor = self._run(monkeypatch, "c" * 64, "2001:db8:1:2::77")

        assert allowed is True and user_id is None
        assert reservation == {"token": "c" * 64, "ip": "2001:db8:1:2::77", "default_limit": 5}
        token_params = next(p for q, p in cursor.queries if "FROM `fh_upload_tokens` t" in q)
        assert token_params[:2] == ("c" * 64, "2001:db8:1:2::77")
        assert not any("INSERT INTO" in query for query, _ in cursor.queries)

    def test_a_ban_on_the_network_refuses_every_address_in_it(self, monkeypatch):
        (allowed, _user, reservation), cursor = self._run(
            monkeypatch, "c" * 64, "2001:db8:1:2::77", banned=True
        )

        assert allowed is False and reservation is None
        ban_query, ban_params = next((q, p) for q, p in cursor.queries if "fh_blacklists" in q)
        assert "`value` IN (%s, %s)" in ban_query
        assert ban_params[:2] == ("2001:db8:1:2::77", "2001:db8:1:2::/64")

    def test_an_unknown_token_is_never_downgraded_to_a_guest_session(self, monkeypatch):
        (allowed, _user, reservation), _cursor = self._run(
            monkeypatch, "d" * 64, "198.51.100.4", token_row=None
        )

        assert allowed is False and reservation is None

    def test_sharex_bans_cover_the_network_too(self, monkeypatch):
        cursor = _ScriptedCursor([("FROM `fh_blacklists`", (1,))])
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})

        with pytest.raises(us.HTTPException) as refused:
            asyncio.run(us.enforce_ip_allowed(_OneConnectionPool(cursor), "2001:db8:1:2::9"))

        assert refused.value.status_code == 403
        assert cursor.queries[0][1][:2] == ("2001:db8:1:2::9", "2001:db8:1:2::/64")


class TestUploadSessionCap:
    """The cap is the group's when it sets one, else the flat setting; 0 means no cap."""

    def _reserve(self, monkeypatch, default_limit, group_limit, rowcount=1):
        cursor = _ScriptedCursor()
        cursor.rowcount = rowcount
        pool = _OneConnectionPool(cursor)

        async def ready(*_args, **_kwargs):
            return pool

        monkeypatch.setattr(us, "require_storage_ready", ready)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})
        reservation = {"token": "c" * 64, "ip": "198.51.100.4", "default_limit": default_limit}
        result = asyncio.run(
            us.reserve_upload_slot(reservation, {"max_files_per_session": group_limit})
        )
        return result, cursor.queries[-1]

    def test_group_zero_and_flat_zero_means_no_cap(self, monkeypatch):
        result, (query, params) = self._reserve(monkeypatch, 0, 0)

        assert result is True
        assert "`files_count` < %s" not in query
        assert params == ("c" * 64, "198.51.100.4")

    def test_the_flat_setting_applies_when_the_group_sets_none(self, monkeypatch):
        _result, (query, params) = self._reserve(monkeypatch, 3, 0)

        assert "`files_count` < %s" in query and params[-1] == 3

    def test_the_group_cap_wins_over_the_flat_setting(self, monkeypatch):
        _result, (_query, params) = self._reserve(monkeypatch, 3, 5)

        assert params[-1] == 5

    def test_a_full_session_answers_429(self, monkeypatch):
        with pytest.raises(us.HTTPException) as full:
            self._reserve(monkeypatch, 5, 0, rowcount=0)

        assert full.value.status_code == 429


class TestPerNetworkDownloadLimits:
    """Two addresses of one IPv6 /64 share one concurrency count and one guest quota."""

    LIMITS = {"concurrent_downloads": 1, "concurrent_connections_per_file": 8}

    def _reserve(self, monkeypatch, ip, counts=(0,)):
        cursor = _ScriptedCursor([("COUNT(", counts)])
        slot = asyncio.run(us._reserve_download_slot_with_cursor(
            cursor, "fh_", ip, None, "file1", self.LIMITS
        ))
        return slot, cursor

    def test_ipv6_counts_match_the_whole_network(self, monkeypatch):
        slot, cursor = self._reserve(monkeypatch, "2001:db8:1:2::5")

        counts = [(q, p) for q, p in cursor.queries if "COUNT(" in q]
        assert slot == 5 and len(counts) == 2
        for query, params in counts:
            assert "WHEN IS_IPV6(`ip_address`) THEN HEX(LEFT(INET6_ATON(`ip_address`), 8)) END = %s" in query
            assert params[:3] == ("2001:db8:1:2::5", "2001:db8:1:2::/64", "20010DB800010002")
        insert = next(p for q, p in cursor.queries if q.startswith("INSERT INTO `fh_active_downloads`"))
        assert insert[0] == "2001:db8:1:2::5", "the live list keeps the exact address"

    def test_ipv4_counts_stay_per_address(self, monkeypatch):
        _slot, cursor = self._reserve(monkeypatch, "203.0.113.9")

        for query, params in [(q, p) for q, p in cursor.queries if "COUNT(" in q]:
            assert "`ip_address` IN (%s)" in query and "INET6_ATON" not in query
            assert params[0] == "203.0.113.9"

    def test_a_network_at_its_limit_gets_429(self, monkeypatch):
        with pytest.raises(us.HTTPException) as refused:
            self._reserve(monkeypatch, "2001:db8:1:2:ffff::1", counts=(8,))

        assert refused.value.status_code == 429

    def test_one_lock_serializes_the_whole_network(self, monkeypatch):
        monkeypatch.setattr(us, "db_config_cache", {"database": "files", "prefix": "fh_"})

        assert us._download_lock_name("2001:db8:1:2::5") == us._download_lock_name("2001:db8:1:2:ffff::1")
        assert us._download_lock_name("2001:db8:1:2::5") != us._download_lock_name("2001:db8:1:3::5")
        assert us._download_lock_name("203.0.113.9") != us._download_lock_name("203.0.113.10")

    def test_guests_of_one_network_share_one_transfer_quota_row(self):
        subjects = []
        for ip in ("2001:db8:1:2::5", "2001:db8:1:2:ffff::1"):
            cursor = _ScriptedCursor([("FOR UPDATE", (0, 0))])
            reservation = asyncio.run(us._reserve_transfer_quota_with_cursor(
                cursor, "fh_", None, ip,
                {"transfer_quota_bytes": 1000, "transfer_quota_period": "week"}, 10,
            ))
            subjects.append((reservation["subject_type"], reservation["subject_key"]))

        assert subjects == [("ip", "2001:db8:1:2::/64")] * 2


class TestStoredFilenames:
    def test_long_cjk_name_fits_one_path_component(self):
        name = us.sanitize_filename("漢" * 120 + ".mp4", us.BLOCKED_EXTENSIONS)

        assert name.endswith(".mp4")
        assert len(name.encode("utf-8")) <= us.FILENAME_MAX_BYTES
        assert us._valid_storage_name(name)
        name.encode("utf-8").decode("utf-8")  # whole characters only

    def test_dot_names_and_empty_names_become_file(self):
        for raw in ("", ".", "..", "a/..", "../.."):
            assert us.sanitize_filename(raw, us.BLOCKED_EXTENSIONS) == "file"

    def test_blocked_extension_survives_truncation(self):
        name = us.sanitize_filename("x" * 300 + ".php", us.BLOCKED_EXTENSIONS)

        assert name.endswith(".blocked")
        assert len(name.encode("utf-8")) <= us.FILENAME_MAX_BYTES

    @pytest.mark.parametrize("ext", sorted(us.BLOCKED_EXTENSIONS))
    def test_truncation_cannot_expose_a_blocked_extension(self, ext):
        # The over-long last extension is cut away and leaves ".<ext>" at the end of the name.
        raw = "A" * (us.FILENAME_MAX_BYTES - len(ext) - 1) + "." + ext + "B" * 150

        name = us.sanitize_filename(raw, us.BLOCKED_EXTENSIONS)

        assert not name.lower().endswith("." + ext)
        assert name.endswith(us.BLOCKED_SUFFIX)
        assert len(name.encode("utf-8")) <= us.FILENAME_MAX_BYTES
        assert us._valid_storage_name(name)
        assert us.mimetypes.guess_type(name)[0] is None

    @pytest.mark.parametrize("raw", [
        "invoice.exe.", "invoice.exe ", "invoice.exe. . ", "invoice.EXE　",
        "invoice.exe ", "invoice.php﻿",
    ])
    def test_trailing_dots_and_spaces_cannot_hide_a_blocked_extension(self, raw):
        name = us.sanitize_filename(raw, us.BLOCKED_EXTENSIONS)

        assert name.endswith(us.BLOCKED_SUFFIX)
        assert name.rsplit(".", 2)[-2].lower() in {"exe", "php"}

    def test_the_check_uses_the_configured_list_on_the_final_name(self):
        blocked = {"zip"}
        raw = "A" * 196 + ".zip" + "B" * 150

        assert us.sanitize_filename(raw, blocked).endswith(".zip" + us.BLOCKED_SUFFIX)
        assert us.sanitize_filename("report.pdf. ", blocked) == "report.pdf"

    def test_control_characters_never_reach_the_manifest(self):
        name = us.sanitize_filename("a\x7fb\x01c.txt", us.BLOCKED_EXTENSIONS)

        assert name == "a_b_c.txt"
        assert us._valid_storage_name(name)

    def test_upload_failure_never_echoes_the_server_path(self, capsys):
        error = OSError(36, "File name too long", "/srv/files/uploads/.staging/abc/x")
        response = us._upload_failure(error)

        assert response.status_code == 500
        assert response.detail == "Upload failed"
        assert "/srv/files" in capsys.readouterr().out  # the operator still sees it


class TestLeaseRenewal:
    def test_lease_is_renewed_while_a_slow_client_blocks_a_send(self, monkeypatch):
        beats = []

        async def heartbeat(active_id, reservation_id, sent):
            beats.append((active_id, reservation_id))
            return True

        async def no_op(*_args, **_kwargs):
            return None

        async def finish(*_args):
            return False

        async def app(scope, receive, send):
            scope["filehost.traffic"] = {
                "ip": "203.0.113.30",
                "file_id": "slow",
                "active_id": 3,
                "reservation_id": "a" * 32,
            }
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send({"type": "http.response.body", "body": b"slow", "more_body": False})

        async def slow_send(message):
            if message["type"] == "http.response.body":
                await asyncio.sleep(0.4)  # one send held far longer than a renewal period

        monkeypatch.setattr(us, "heartbeat_download", heartbeat)
        monkeypatch.setattr(us, "mark_download_reservation_started", no_op)
        monkeypatch.setattr(us, "finish_download_reservation", finish)
        monkeypatch.setattr(us, "log_traffic", no_op)
        monkeypatch.setattr(us, "DOWNLOAD_HEARTBEAT_SECONDS", 0.05)

        asyncio.run(us.ActualTransferAccountingMiddleware(app)({"type": "http"}, no_op, slow_send))

        assert len(beats) >= 4
        assert set(beats) == {(3, "a" * 32)}

    def test_renewal_period_is_well_inside_the_lease(self):
        assert us.DOWNLOAD_HEARTBEAT_SECONDS * 3 <= max(3.0, us.DOWNLOAD_LEASE_SECONDS)


class TestReadinessProbeCache:
    def test_probe_is_reused_briefly_but_ready_always_probes(self, monkeypatch, tmp_path):
        cursor = _ScriptedCursor(
            all_answers=[("'schema_ready'", [("schema_version", "64"), ("schema_ready", "1")])]
        )
        pool = _OneConnectionPool(cursor)

        async def same_pool():
            return pool

        monkeypatch.setattr(us, "get_db_pool", same_pool)
        monkeypatch.setattr(us, "db_config_cache", {"prefix": "fh_"})
        monkeypatch.setattr(us, "STAGING_DIR", tmp_path / "staging")
        monkeypatch.setattr(us, "THUMBS_DIR", tmp_path / "thumbs")
        monkeypatch.setitem(us.readiness_cache, "pool", None)
        monkeypatch.setitem(us.readiness_cache, "checked_at", 0.0)

        async def scenario():
            await us.require_storage_ready()
            await us.require_storage_ready()
            cached = len(cursor.queries)
            await us.require_storage_ready(force=True)
            return cached, len(cursor.queries)

        cached, total = asyncio.run(scenario())

        assert cached == 3
        assert total == 6
        assert not list((tmp_path / "staging").iterdir())


class TestLocalOnlyEndpoints:
    def test_reload_refuses_anything_that_came_through_the_proxy(self):
        request = _http_request(
            "/reload", client=("127.0.0.1", 1), headers={"X-Forwarded-For": "203.0.113.5"}
        )
        try:
            asyncio.run(us.reload_config(request))
        except us.HTTPException as exc:
            assert exc.status_code == 403
        else:
            raise AssertionError("a proxied caller reloaded the server")

    def test_reload_keeps_the_pool_while_the_configuration_is_unchanged(self, monkeypatch, tmp_path):
        config_file = tmp_path / "config.local.php"
        config_file.write_text("<?php\n", encoding="utf-8")
        settings = {"host": "db", "user": "u", "password": "p", "database": "files", "prefix": "fh_"}

        class Pool:
            closed = False

            def close(self):
                self.closed = True

            async def wait_closed(self):
                return None

        pool = Pool()
        created = []

        async def create_pool(**kwargs):
            created.append(kwargs)
            return object()

        monkeypatch.setattr(us, "CONFIG_FILE", config_file)
        monkeypatch.setattr(us, "load_php_config", lambda: dict(settings))
        monkeypatch.setattr(us, "db_config_cache", dict(settings))
        monkeypatch.setattr(us, "db_pool", pool)
        monkeypatch.setattr(us, "db_config_mtime", 0)
        monkeypatch.setattr(us.aiomysql, "create_pool", create_pool)
        local = _http_request("/reload", client=("127.0.0.1", 1))

        result = asyncio.run(us.reload_config(local))
        assert result["database_connected"] is True
        assert pool.closed is False and us.db_pool is pool and created == []

        settings["database"] = "files_moved"
        asyncio.run(us.reload_config(local))
        assert pool.closed is True
        assert created and created[0]["db"] == "files_moved"
        assert isinstance(us.db_pool, us.BoundedPool)

    def test_metrics_refuse_proxied_requests_without_a_token(self, monkeypatch):
        async def no_settings():
            return None

        async def no_pool():
            return None

        monkeypatch.setattr(us, "update_settings", no_settings)
        monkeypatch.setattr(us, "get_db_pool", no_pool)
        monkeypatch.setitem(us.settings_cache, "metrics_token", "")
        proxied = _http_request(
            "/metrics", client=("127.0.0.1", 1), headers={"X-Forwarded-For": "203.0.113.5"}
        )
        try:
            asyncio.run(us.metrics(proxied))
        except us.HTTPException as exc:
            assert exc.status_code == 403
        else:
            raise AssertionError("metrics answered a proxied visitor")

        response = asyncio.run(us.metrics(_http_request("/metrics", client=("127.0.0.1", 1))))
        assert b"filehost_downloads_total " in response.body

    def test_no_cross_origin_policy_is_installed(self):
        assert not any(
            "CORSMiddleware" in getattr(middleware.cls, "__name__", "")
            for middleware in us.app.user_middleware
        )


class TestThumbnailWorkerKill:
    def test_timeout_kills_the_workers_whole_process_group(self, monkeypatch):
        killed = []

        class Process:
            pid = 4242

            def kill(self):
                killed.append("worker")

        monkeypatch.setattr(us.os, "killpg", lambda pid, sig: killed.append(("group", pid, sig)), raising=False)
        monkeypatch.setattr(us.signal, "SIGKILL", 9, raising=False)

        us._kill_worker_tree(Process(), posix=True)

        assert killed == [("group", 4242, 9), "worker"]

    def test_ffmpeg_stays_in_the_workers_group_and_reads_only_local_files(self, monkeypatch, tmp_path):
        launches = []
        timeouts = []

        class Process:
            returncode = 0

            def communicate(self, timeout=None):
                timeouts.append(timeout)
                return (b"", None)

            def poll(self):
                return 0

            def kill(self):
                return None

        def popen(args, **kwargs):
            launches.append((args, kwargs))
            return Process()

        monkeypatch.setattr(us.subprocess, "Popen", popen)
        monkeypatch.setattr(us.imageio_ffmpeg, "get_ffmpeg_exe", lambda: "ffmpeg")

        assert us._extract_video_frame(tmp_path / "clip.mp4", 400) is None
        assert len(launches) == 2
        for args, kwargs in launches:
            assert "start_new_session" not in kwargs
            assert args[args.index("-protocol_whitelist") + 1] == "file"
            assert args.index("-protocol_whitelist") < args.index("-i")
        assert timeouts == [us.THUMB_FFMPEG_ATTEMPT_TIMEOUT] * 2
        assert 2 * us.THUMB_FFMPEG_ATTEMPT_TIMEOUT < us.THUMB_GENERATION_TIMEOUT


class TestStagedUploadSealing:
    def test_missing_payload_is_never_vouched_for(self, tmp_path):
        assert not us._seal_staged_upload(tmp_path, "absent.bin", 3, "a" * 64)
        assert not (tmp_path / us.STORAGE_MANIFEST_NAME).exists()

    def test_payload_is_flushed_then_described(self, tmp_path):
        (tmp_path / "payload.bin").write_bytes(b"abc")
        digest = hashlib.sha256(b"abc").hexdigest()

        assert us._seal_staged_upload(tmp_path, "payload.bin", 3, digest)
        manifest = json.loads((tmp_path / us.STORAGE_MANIFEST_NAME).read_text(encoding="utf-8"))
        assert manifest["sha256"] == digest


class TestDownloadsBehindAProxyThatHangsUp:
    """Real uvicorn, real sockets, a client that behaves like Apache's [P] proxy.

    mod_rewrite's [P] runs mod_proxy without persistent connections: once Content-Length bytes
    have arrived, Apache ends the request and closes the backend socket. The upload server
    sees that close while its reader is still looking for end-of-file and cancels the stream
    before the closing message. Every download that reached the client in full was recorded
    as "released", so counters never moved and max_downloads was never enforced.
    """

    @staticmethod
    def _serve_and_fetch(monkeypatch, tmp_path, rate, byte_range=None, stop_after=None):
        from fastapi import BackgroundTasks, FastAPI, Request
        from fastapi.responses import StreamingResponse
        import uvicorn

        payload = tmp_path / "payload.bin"
        payload.write_bytes(b"FH-CI-" * 87382)
        size = payload.stat().st_size
        finalised = []

        async def no_op(*_args, **_kwargs):
            return None

        async def not_killed(_active_id):
            await asyncio.sleep(0.002)  # stands in for the database round trip
            return False

        async def finalize(_context, state, sent):
            finalised.append((state, sent))

        monkeypatch.setattr(us, "mark_download_reservation_started", no_op)
        monkeypatch.setattr(us, "heartbeat_download", no_op)
        monkeypatch.setattr(us, "download_was_killed", not_killed)
        monkeypatch.setattr(us, "log_traffic", no_op)
        monkeypatch.setattr(us, "_finalize_download_context", finalize)

        app = FastAPI()
        app.add_middleware(us.ActualTransferAccountingMiddleware)

        @app.get("/download")
        async def download(request: Request, background_tasks: BackgroundTasks):
            request.scope["filehost.traffic"] = {
                "ip": "203.0.113.40", "file_id": "f", "user_id": None, "active_id": 7,
                "reservation_id": "a" * 32, "transfer_state": {"interrupted": False},
                "completion": {},
            }
            start, end, status = 0, None, 200
            headers = {"Content-Length": str(size)}
            if byte_range:
                start, end, status = byte_range[0], byte_range[1], 206
                headers = {
                    "Content-Length": str(end - start + 1),
                    "Content-Range": f"bytes {start}-{end}/{size}",
                }
            reader = us.throttled_file_reader(payload, rate, 7, start, end)
            background_tasks.add_task(us.close_stream_reader, reader)
            return StreamingResponse(reader, status_code=status, headers=headers,
                                     media_type="application/octet-stream")

        def proxy_like_client(port):
            sock = socket.create_connection(("127.0.0.1", port), timeout=20)
            sock.sendall(b"GET /download HTTP/1.1\r\nHost: files\r\n\r\n")
            buffer = b""
            while b"\r\n\r\n" not in buffer:
                buffer += sock.recv(65536)
            head, body = buffer.split(b"\r\n\r\n", 1)
            length = int(next(
                line.split(b":", 1)[1] for line in head.split(b"\r\n")
                if line.lower().startswith(b"content-length:")
            ))
            target = min(length, stop_after) if stop_after else length
            while len(body) < target:
                chunk = sock.recv(65536)
                if not chunk:
                    break
                body += chunk
            sock.close()  # exactly what mod_proxy does once the body is complete
            return len(body), length

        async def scenario():
            probe = socket.socket()
            probe.bind(("127.0.0.1", 0))
            port = probe.getsockname()[1]
            probe.close()
            server = uvicorn.Server(uvicorn.Config(
                app, host="127.0.0.1", port=port, log_level="warning", access_log=False,
                lifespan="off",
            ))
            task = asyncio.create_task(server.serve())
            for _ in range(500):  # bounded: a server that never starts fails the test
                if server.started:
                    break
                await asyncio.sleep(0.02)
            assert server.started, "uvicorn did not start"
            try:
                received = await asyncio.to_thread(proxy_like_client, port)
                for _ in range(100):
                    if finalised:
                        break
                    await asyncio.sleep(0.02)
            finally:
                server.should_exit = True
                await task
            return received

        received = asyncio.run(scenario())
        return received, finalised

    def test_full_download_is_completed(self, monkeypatch, tmp_path):
        (got, length), finalised = self._serve_and_fetch(monkeypatch, tmp_path, rate=0)

        assert got == length == 524292
        assert finalised == [("completed", 524292)]

    def test_throttled_download_is_completed(self, monkeypatch, tmp_path):
        # The Docker smoke's case: the throttled reader sleeps after its last chunk.
        (got, length), finalised = self._serve_and_fetch(
            monkeypatch, tmp_path, rate=4 * 1024 * 1024
        )

        assert got == length
        assert finalised == [("completed", length)]

    def test_complete_range_is_completed(self, monkeypatch, tmp_path):
        (got, length), finalised = self._serve_and_fetch(
            monkeypatch, tmp_path, rate=4 * 1024 * 1024, byte_range=(0, 262143)
        )

        assert got == length == 262144
        assert finalised == [("completed", 262144)]

    def test_client_gone_mid_body_is_released(self, monkeypatch, tmp_path):
        (got, _length), finalised = self._serve_and_fetch(
            monkeypatch, tmp_path, rate=1024 * 1024, stop_after=100_000
        )

        assert got >= 100_000
        assert len(finalised) == 1
        state, sent = finalised[0]
        assert state == "released"
        assert sent < 524292
