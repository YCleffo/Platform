"""Exercise the actual console service's named pipe; never enable native Windows operations."""
import json
import os
from pathlib import Path
import subprocess
import tempfile
import time
import uuid


def main():
    agent = Path(__file__).resolve().parents[1]
    service = agent / "Platform.Agent.Service/bin/Debug/net10.0-windows/platform-agent.dll"
    if not service.is_file():
        raise RuntimeError("Build Platform.Agent.sln before running this smoke check.")
    with tempfile.TemporaryDirectory(prefix="platform-ipc-smoke-") as directory:
        env = dict(os.environ)
        env.update(PLATFORM_AGENT_STATE_DIR=directory,
                   PLATFORM_AGENT_BASE_URL="http://127.0.0.1:9",
                   PLATFORM_AGENT_ALLOW_INSECURE_HTTP="1",
                   PLATFORM_AGENT_DANGEROUS_WINDOWS_MODE="0",
                   PLATFORM_AGENT_DANGEROUS_MODE="false")
        process = subprocess.Popen(["dotnet", str(service), "--console"], env=env,
                                   stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
                                   creationflags=subprocess.CREATE_NO_WINDOW)
        try:
            def exchange(payload):
                deadline = time.monotonic() + 10
                while True:
                    try:
                        with open(r"\\.\pipe\platform-agent", "r+b", buffering=0) as pipe:
                            pipe.write(payload.encode("utf-8") + b"\n")
                            data = bytearray()
                            while len(data) < 16384:
                                byte = pipe.read(1)
                                if byte == b"\n":
                                    return json.loads(data)
                                if not byte:
                                    raise RuntimeError("Service disconnected without a response.")
                                data.extend(byte)
                            raise RuntimeError("Oversized service response.")
                    except OSError:
                        if process.poll() is not None or time.monotonic() >= deadline:
                            raise
                        time.sleep(0.05)

            def request(command, **values):
                request_id = str(uuid.uuid4())
                response = exchange(json.dumps(dict(version=1, requestId=request_id, command=command, **values)))
                assert response["requestId"] == request_id
                return response

            state = request("status")
            assert state["ok"] and state["state"]["testAdapter"]
            assert state["state"]["sessionStatus"] == "CLOSED"
            assert not state["state"]["serverConnected"]
            assert request("run_powershell")["error"] == "unknown_command"
            assert request("end_session", sessionId=str(uuid.uuid4()))["error"] == "session_mismatch"
            assert exchange("not-json")["error"] == "invalid_request"
            assert exchange(json.dumps(dict(version=1, requestId=str(uuid.uuid4()), command="enroll",
                                            isAdministrator=True)))["error"] == "invalid_request"
            print("PASS actual service IPC: status, unknown command, session binding, malformed JSON, identity injection")
        finally:
            process.terminate()
            output, _ = process.communicate(timeout=10)
            if process.returncode not in (0, 1):
                # Terminating a disposable console process is expected; report only startup failure if relevant.
                if b"console mode" not in output:
                    raise RuntimeError(output.decode("utf-8", errors="replace"))


if __name__ == "__main__":
    main()
