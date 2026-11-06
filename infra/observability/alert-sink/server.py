"""Local Alertmanager webhook sink used to prove delivery in the observability stack."""

from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import threading


alerts: list[dict[str, object]] = []
lock = threading.Lock()


class Handler(BaseHTTPRequestHandler):
    def do_GET(self) -> None:
        if self.path == "/health":
            self._json({"status": "ok"})
            return
        if self.path == "/alerts":
            with lock:
                self._json({"alerts": list(alerts)})
            return
        self.send_error(404)

    def do_POST(self) -> None:
        if self.path != "/alerts":
            self.send_error(404)
            return
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        try:
            payload = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            self.send_error(400)
            return
        if not isinstance(payload, dict):
            self.send_error(400)
            return
        with lock:
            alerts.append(payload)
        print(json.dumps({"received": True, "alerts": len(payload.get("alerts", []))}), flush=True)
        self._json({"status": "accepted"})

    def _json(self, payload: dict[str, object]) -> None:
        body = json.dumps(payload).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format: str, *args: object) -> None:
        return


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
