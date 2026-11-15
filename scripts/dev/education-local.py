"""Start/seed a separate Education environment; never targets the working stack."""

import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import subprocess

ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / "runtime/education-local"
ENV_FILE = RUNTIME / "local.env"
COMPOSE = ROOT / "compose.education-local.yaml"


def run(*args: str, capture: bool = False) -> str:
    result = subprocess.run(list(args), cwd=ROOT, check=True, text=True,
                            encoding="utf-8", capture_output=capture)
    return result.stdout if capture else ""


def compose(*args: str, capture: bool = False) -> str:
    # Explicit file/project/env excludes all working Compose services and secrets.
    return run("docker", "compose", "--env-file", str(ENV_FILE), "-p",
               "platform-education-local", "-f", str(COMPOSE), *args, capture=capture)


def ensure_environment() -> None:
    RUNTIME.mkdir(parents=True, exist_ok=True)
    for directory in ["backend", "data"]:
        (RUNTIME / directory).mkdir(exist_ok=True)
    if not ENV_FILE.exists():
        values = {
            "EDUCATION_LOCAL_DB_PASSWORD": secrets.token_hex(32),
            "EDUCATION_LOCAL_PROOF": secrets.token_hex(32),
            "EDUCATION_LOCAL_HMAC_KEY": secrets.token_hex(32),
            "EDUCATION_LOCAL_TOTP_KEY": base64.b64encode(secrets.token_bytes(32)).decode(),
        }
        ENV_FILE.write_text("".join(f"{k}={v}\n" for k, v in values.items()), encoding="utf-8")
        if os.name != "nt":
            ENV_FILE.chmod(0o600)


def assert_destination() -> None:
    container = compose("ps", "-q", "backend", capture=True).strip()
    if not container:
        raise RuntimeError("Start the isolated Education environment first.")
    info = json.loads(run("docker", "inspect", container, capture=True))[0]
    labels: dict[str, str] = info["Config"].get("Labels") or {}
    if labels.get("com.docker.compose.project") != "platform-education-local":
        raise RuntimeError("Unexpected Compose project.")
    # No mount may refer to a working PostgreSQL volume or Docker socket.
    if any("docker.sock" in m.get("Destination", "") for m in info.get("Mounts", [])):
        raise RuntimeError("Unexpected privileged mount.")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["start", "seed", "status", "stop"])
    args = parser.parse_args()
    ensure_environment()
    if args.command == "stop":
        compose("stop")  # Retains containers, volumes, files and identities.
        return
    if args.command == "status":
        compose("ps")
        return
    if args.command == "start":
        compose("up", "-d", "--wait", "--wait-timeout", "90")
        assert_destination()
        compose("exec", "-T", "backend", "php", "/education-tools/migrate.php")
    else:
        assert_destination()
    compose("exec", "-T", "backend", "php", "/education-tools/seed.php")
    compose("exec", "-T", "backend", "php", "/education-tools/seed-homework.php")
    compose("exec", "-T", "backend", "php", "/education-tools/seed-parent.php")
    print("Isolated Education API: http://localhost:8088")
    print("Credentials: runtime/education-local/data/access.json (local, ignored by Git)")
    print("Frontend: VITE_EDUCATION_ENABLED=1 VITE_CORE_PROXY_TARGET=http://localhost:8088 npm run dev -- --host 127.0.0.1 --port 5178")


if __name__ == "__main__":
    main()
