#!/usr/bin/env python3
"""Send one HTTP request, read nothing, then close the socket with an RST."""

import socket
import struct
import sys
import time
from urllib.parse import urlsplit


def main() -> int:
    if len(sys.argv) != 3:
        return 64
    parsed = urlsplit(sys.argv[1])
    if parsed.scheme != "http" or not parsed.hostname:
        return 64
    hold_seconds = max(0.1, min(4.0, float(sys.argv[2])))
    port = parsed.port or 80
    target = parsed.path or "/"
    if parsed.query:
        target += "?" + parsed.query
    host = parsed.hostname
    host_header = host if port == 80 else f"{host}:{port}"

    connection = socket.create_connection((host, port), timeout=5)
    try:
        request = (
            f"GET {target} HTTP/1.1\r\n"
            f"Host: {host_header}\r\n"
            "X-FileHost-CI-Prebody: 1\r\n"
            "Connection: close\r\n\r\n"
        ).encode("ascii")
        connection.sendall(request)
        time.sleep(hold_seconds)
        connection.setsockopt(
            socket.SOL_SOCKET,
            socket.SO_LINGER,
            struct.pack("ii", 1, 0),
        )
    finally:
        connection.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
