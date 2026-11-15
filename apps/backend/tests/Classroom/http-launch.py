"""Start only the isolated classroom agent HTTP scenario; reuse local images.

python tests/Classroom/http-launch.py --runtime C:/temporary/classroom-http
Then pass the printed fixturePath to Platform.Agent.HttpScenario.
Teacher setup/control is CLI-only; the HTTP server exposes production agent
routes, credential middleware and actions. This is not the full app pipeline.
"""

from __future__ import annotations

import argparse
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile


def run(args: list[str]) -> str:
    result = subprocess.run(args, check=True, capture_output=True, text=True)
    return result.stdout.strip()


def metadata(name: str) -> dict:
    return json.loads(run(["docker", "inspect", name]))[0]


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--runtime", type=Path)
    parser.add_argument("--backend-root", type=Path, default=Path(__file__).resolve().parents[2])
    parser.add_argument("--vendor-root", type=Path)
    parser.add_argument("--source-container", default="platform-backend")
    parser.add_argument("--postgres-container", default="platform-postgres")
    parser.add_argument("--name", default="platform-classroom02-http")
    options = parser.parse_args()
    backend = options.backend_root.resolve()
    vendor = (options.vendor_root or backend / "vendor").resolve()
    if not (vendor / "autoload.php").is_file():
        raise RuntimeError("An installed Composer vendor tree is required; use --vendor-root.")
    runtime = (options.runtime or Path(tempfile.mkdtemp(prefix="classroom02-http-"))).resolve()
    if runtime.is_relative_to(backend):
        raise RuntimeError("Place the temporary credential/runtime directory outside the repository.")
    runtime.mkdir(parents=True, exist_ok=True)
    if (runtime / "fixture.json").exists():
        raise RuntimeError("Choose a fresh runtime directory; an existing fixture must be preserved.")
    # The read-only source mount needs these mountpoints to exist beforehand.
    (backend / "runtime").mkdir(exist_ok=True)
    (backend / "vendor").mkdir(exist_ok=True)
    service = metadata(options.source_container)
    env = dict(value.split("=", 1) for value in service["Config"]["Env"])
    postgres = metadata(options.postgres_container)
    postgres_env = dict(value.split("=", 1) for value in postgres["Config"]["Env"])
    postgres_user = postgres_env.get("POSTGRES_USER", "postgres")
    database_owner = env["DB_USER"]
    if re.fullmatch(r"[A-Za-z_][A-Za-z0-9_]*", database_owner) is None:
        raise RuntimeError("Unsupported test database owner identifier.")
    exists = run(["docker", "exec", options.postgres_container, "psql", "-U", postgres_user,
                  "-d", "postgres", "-tAc", "SELECT 1 FROM pg_database WHERE datname='classroom02_test'"])
    if exists != "1":
        run(["docker", "exec", options.postgres_container, "psql", "-U", postgres_user, "-d", "postgres",
             "-v", "ON_ERROR_STOP=1", "-c", f'CREATE DATABASE classroom02_test OWNER "{database_owner}"'])
    env.update({"DB_NAME": "classroom02_test", "CLASSROOM_HTTP_TEST": "1",
                "CLASSROOM_HTTP_RUNTIME": "/classroom-runtime", "APP_ENV": "dev", "APP_DEBUG": "false",
                "STORAGE_FILE_DIRECTORY": "/classroom-runtime/binaries", "CORE_SCHEDULER_ENABLED": "false",
                "CORE_EMAIL_WORKER_ENABLED": "false"})
    env_file = runtime / "docker.env"
    # Existing service credentials never appear in stdout, command arguments or source files.
    env_file.write_text("".join(key + "=" + value + "\n" for key, value in env.items()
                                if "\n" not in value and "\r" not in value), encoding="utf-8")
    os.chmod(env_file, 0o600)
    networks = list(service["NetworkSettings"]["Networks"])
    if len(networks) != 1:
        raise RuntimeError("The source backend must have exactly one selected test network.")
    args = ["docker", "run", "--detach", "--pull=never", "--name", options.name,
            "--network", networks[0], "--publish", "127.0.0.1:18762:18762", "--env-file", str(env_file),
            "--volumes-from", options.source_container + ":ro", "--workdir", "/classroom",
            "--mount", f"type=bind,source={backend},target=/classroom,readonly",
            "--mount", f"type=bind,source={vendor},target=/classroom/vendor,readonly",
            "--mount", f"type=bind,source={runtime},target=/classroom-runtime",
            "--mount", f"type=bind,source={runtime},target=/classroom/runtime", "--entrypoint", "php",
            service["Config"]["Image"], "-S", "0.0.0.0:18762", "/classroom/tests/Classroom/http-router.php"]
    try:
        run(args)
    finally:
        # Docker has copied the environment into the disposable container. Do not
        # retain an additional plaintext copy of backend credentials on the host.
        env_file.unlink(missing_ok=True)
    result = json.loads(run(["docker", "exec", options.name, "php", "/classroom/tests/Classroom/http-fixture.php", "init"]))
    print(json.dumps({"container": options.name, "baseUrl": "http://127.0.0.1:18762",
                      "fixturePath": str(runtime / "fixture.json"), "deviceId": result["deviceId"]}))


if __name__ == "__main__":
    main()
