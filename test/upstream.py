#!/usr/bin/env python3
"""apigate 开发用 mock 上游：回显路径/请求头，/sse 返回 SSE 流。"""

import json
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer


class Handler(BaseHTTPRequestHandler):
    def _json(self, code, obj):
        data = json.dumps(obj).encode()
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if self.path == "/sse":
            self.send_response(200)
            self.send_header("Content-Type", "text/event-stream")
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            for i in range(3):
                self.wfile.write(f"data: event {i}\n\n".encode())
                self.wfile.flush()
                time.sleep(0.2)
            return
        self._json(200, {
            "path": self.path,
            "headers": dict(self.headers),
            "body": "upstream-ok",
        })

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length).decode("utf-8", "replace") if length else ""
        self._json(200, {"method": "POST", "path": self.path, "body": body})

    def log_message(self, *args):
        pass


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 9000), Handler).serve_forever()
