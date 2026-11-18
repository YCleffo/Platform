"""Start/seed a separate Education environment; never targets the working stack."""

import argparse
import base64
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
from typing import TypedDict, cast
from urllib.error import HTTPError, URLError
from urllib.request import ProxyHandler, build_opener

ROOT = Path(__file__).resolve().parents[2]
RUNTIME = ROOT / "runtime/education-local"
ENV_FILE = RUNTIME / "local.env"
S3_DIRECTORY = RUNTIME / "s3"
COMPOSE = ROOT / "compose.education-local.yaml"
PROJECT = "platform-education-local"
HTTP = build_opener(ProxyHandler({}))
S3_ERRORS = {
    "s3_configuration_missing": "application S3 settings are missing",
    "s3_configuration_invalid": "application S3 settings are invalid",
    "s3_https_required": "S3 endpoint is not allowed by the HTTP policy",
    "s3_credentials_rejected": "SeaweedFS rejected the application credentials",
    "s3_access_denied": "the application identity cannot access its test buckets",
    "s3_bucket_missing": "the test buckets have not been initialized",
    "s3_signature_invalid": "SeaweedFS rejected the request signature",
    "s3_unavailable": "SeaweedFS did not answer the application request",
}


def run(*args: str, capture: bool = False, compose_environment: bool = False) -> str:
    env = None
    if compose_environment:
        # PowerShell/CMD environment takes precedence over Compose env files.
        # Use the isolated files even when the developer has working S3 vars exported.
        env = {key: value for key, value in os.environ.items()
               if not key.upper().startswith(("COMPOSE_", "EDUCATION_LOCAL_", "S3_"))}
    result = subprocess.run(list(args), cwd=ROOT, check=True, text=True,
                            encoding="utf-8", capture_output=capture, env=env)
    return result.stdout if capture else ""


def compose(*args: str, capture: bool = False) -> str:
    # Explicit file/project/env excludes all working Compose services and secrets.
    return run("docker", "compose", "--env-file", str(ENV_FILE), "--env-file", str(S3_DIRECTORY / "s3.env"),
               "-p", PROJECT, "-f", str(COMPOSE), *args, capture=capture, compose_environment=True)


class Ports(TypedDict):
    api: int
    s3: int
    vite: int


def ensure_environment() -> Ports:
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
        descriptor = os.open(ENV_FILE, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, "w", encoding="utf-8", newline="\n") as stream:
            stream.writelines(f"{key}={value}\n" for key, value in values.items())

    settings = dict(line.split("=", 1) for line in ENV_FILE.read_text(encoding="utf-8-sig").splitlines()
                    if "=" in line)

    def port(name: str, default: int) -> int:
        variable = f"EDUCATION_LOCAL_{name}_PORT"
        value = settings.get(variable, str(default))
        if not value.isascii() or not value.isdecimal() or not 1024 <= int(value) <= 65535:
            raise RuntimeError(f"{variable} must be a decimal port between 1024 and 65535.")
        return int(value)

    ports = Ports(api=port("API", 8088), s3=port("S3", 18338), vite=port("VITE", 5178))
    if len(set(ports.values())) != 3:
        raise RuntimeError("Education API, S3 and Vite require distinct loopback ports.")
    run(sys.executable, str(ROOT / "scripts/dev/storage02/s3-config.py"),
        "--directory", str(S3_DIRECTORY), "--test-suite", "education-local",
        "--port", str(ports["s3"]), "--public-endpoint", f"http://127.0.0.1:{ports['s3']}",
        "--cors-origin", f"http://127.0.0.1:{ports['vite']}", "--cors-origin", f"http://localhost:{ports['vite']}")
    return ports


class Mount(TypedDict):
    Type: str
    Name: str
    Destination: str


class ContainerConfig(TypedDict):
    Labels: dict[str, str]


class NetworkSettings(TypedDict):
    Networks: dict[str, object]


class ContainerInfo(TypedDict):
    Config: ContainerConfig
    Mounts: list[Mount]
    NetworkSettings: NetworkSettings


def assert_destination() -> None:
    volumes = {
        "postgres-test": {f"{PROJECT}_education_local_postgres"},
        "seaweedfs-test": {f"{PROJECT}_education_local_s3"},
        "redis-test": set[str](),
        "backend": set[str](),
    }
    networks = {f"{PROJECT}_education_local", f"{PROJECT}_education_browser"}
    for service, expected_volumes in volumes.items():
        container = compose("ps", "-q", service, capture=True).strip()
        if not container:
            raise RuntimeError(f"Start the isolated Education environment first. Missing service: {service}.")
        info = cast(list[ContainerInfo], json.loads(run("docker", "inspect", container, capture=True)))[0]
        labels = info["Config"]["Labels"]
        if labels.get("com.docker.compose.project") != PROJECT or labels.get("com.docker.compose.service") != service:
            raise RuntimeError(f"Unexpected Compose ownership for {service}.")
        attached = set(info["NetworkSettings"]["Networks"])
        if f"{PROJECT}_education_local" not in attached or not attached <= networks:
            raise RuntimeError(f"Unexpected Docker networks for {service}.")
        mounts = info["Mounts"]
        if any("docker.sock" in mount["Destination"] for mount in mounts):
            raise RuntimeError(f"Unexpected Docker socket mount for {service}.")
        actual_volumes = {mount["Name"] for mount in mounts if mount["Type"] == "volume"}
        if actual_volumes != expected_volumes:
            raise RuntimeError(f"Unexpected Docker volumes for {service}.")


def wait_http(api: int, path: str, component: str, timeout: int = 30) -> None:
    last_failure = "connection did not answer"
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            with HTTP.open(f"http://127.0.0.1:{api}{path}", timeout=3) as response:
                if response.getcode() == 200:
                    return
                last_failure = f"HTTP {response.getcode()}"
        except HTTPError as error:
            with error:
                last_failure = f"HTTP {error.code}"
                if path == "/health/storage":
                    try:
                        result = cast(dict[str, object], json.loads(error.read(4096)))
                        reason = result.get("reason")
                        if isinstance(reason, str):
                            last_failure = S3_ERRORS.get(reason, last_failure)
                    except (AttributeError, ValueError):
                        pass
        except (TimeoutError, URLError):
            pass
        time.sleep(1)
    raise RuntimeError(f"{component} failed at {path}: {last_failure}.")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("command", choices=["start", "seed", "status", "stop"])
    args = parser.parse_args()
    ports = ensure_environment()
    if args.command == "stop":
        compose("stop")  # Retains containers, volumes, files and identities.
        return
    if args.command == "status":
        compose("ps", "-a")
        return
    if args.command == "start":
        compose("config", "--quiet")
        try:
            compose("up", "-d", "--wait", "--wait-timeout", "110", "backend")
        except subprocess.CalledProcessError as error:
            compose("ps", "-a")
            logs = compose("logs", "--no-log-prefix", "--tail", "8", "s3-init-test", capture=True)
            for line in logs.splitlines():
                if line.startswith("S3 initialization failed:"):
                    print(line, file=sys.stderr)
            raise RuntimeError("Education startup failed. Check the service status above and logs for s3-init-test.") from error
        assert_destination()
        wait_http(ports["api"], "/health/storage", "Isolated S3")
        compose("exec", "-T", "backend", "php", "/education-tools/migrate.php")
    else:
        assert_destination()
        wait_http(ports["api"], "/health/storage", "Isolated S3")
    wait_http(ports["api"], "/health/ready", "Education backend")
    compose("exec", "-T", "backend", "php", "/education-tools/seed.php")
    compose("exec", "-T", "backend", "php", "/education-tools/seed-homework.php")
    compose("exec", "-T", "backend", "php", "/education-tools/seed-parent.php")
    print(f"Isolated Education API: http://127.0.0.1:{ports['api']}")
    print(f"Isolated S3 endpoint: http://127.0.0.1:{ports['s3']}")
    print("Credentials: runtime/education-local/data/access.json (local, ignored by Git)")
    print(f"Frontend: set VITE_EDUCATION_ENABLED=1 and VITE_CORE_PROXY_TARGET=http://127.0.0.1:{ports['api']}, "
          f"then run npm run dev -- --host 127.0.0.1 --port {ports['vite']} --strictPort from apps/frontend.")


if __name__ == "__main__":
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError) as error:
        sys.exit(str(error))
