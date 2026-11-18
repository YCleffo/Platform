"""Verify the local Docker fixture and bind HTTP smoke requests to it."""

from __future__ import annotations

from collections.abc import Generator
from contextlib import contextmanager
from dataclasses import dataclass
import hashlib
import hmac
from http.client import HTTPResponse, IncompleteRead
import json
from pathlib import Path
import subprocess
import time
from typing import IO, TypedDict, cast
from urllib.error import HTTPError, URLError
from urllib.parse import SplitResult, urlsplit
from urllib.request import HTTPRedirectHandler, ProxyHandler, Request, build_opener


LOCAL_PROJECT = "platform-education-local"
DEFAULT_RUNTIME = Path(__file__).resolve().parents[3] / "runtime/education-local"
ISOLATION_PATH = "/api/health/isolation"
BUCKETS = ("education-local-test-storage", "education-local-test-education")
REQUIRED_ENV = {
    "APP_ENV": "test",
    "TEST_ISOLATED_RUNTIME": "1",
    "EDUCATION_LOCAL_FIXTURE": "1",
    "DB_HOST": "postgres-test",
    "DB_NAME": "platform_test",
    "DB_USER": "education_fixture",
    "REDIS_HOST": "redis-test",
}


class SmokeEnvironmentError(RuntimeError):
    """The test destination did not satisfy the fixture contract."""


class NoRedirects(HTTPRedirectHandler):
    def redirect_request(
        self, req: Request, fp: IO[bytes], code: int, msg: str, headers: object, newurl: str,
    ) -> None:
        return None


opener = build_opener(ProxyHandler({}), NoRedirects())


class Binding(TypedDict):
    HostIp: str
    HostPort: str


class Configuration(TypedDict):
    Env: list[str]


class Mount(TypedDict, total=False):
    Type: str
    Name: str
    Destination: str


class NetworkSettings(TypedDict):
    Ports: dict[str, list[Binding] | None]
    Networks: dict[str, object]


class Container(TypedDict):
    Id: str
    Config: Configuration
    Mounts: list[Mount]
    NetworkSettings: NetworkSettings


class SignedRequest(TypedDict):
    url: str
    method: str
    headers: dict[str, str]


class ProofEnvelope(TypedDict):
    nonce: object


class ProofResponse(TypedDict):
    data: ProofEnvelope


def docker(*arguments: str) -> str:
    result = subprocess.run(
        ["docker", *arguments], check=False, capture_output=True, encoding="utf-8", timeout=20,
    )
    if result.returncode:
        raise SmokeEnvironmentError(f"Docker {arguments[0]} failed (exit {result.returncode}).")
    return result.stdout


def service(project: str, name: str) -> Container:
    ids = docker(
        "ps", "-q", "--filter", f"label=com.docker.compose.project={project}",
        "--filter", f"label=com.docker.compose.service={name}",
    ).split()
    if len(ids) != 1:
        raise SmokeEnvironmentError(f"Expected one running {project}/{name} container. Run education-local.py start.")
    containers = cast(list[Container], json.loads(docker("inspect", "--type", "container", ids[0])))
    return containers[0]


def loopback(url: str, *, origin_only: bool) -> SplitResult:
    try:
        parsed = urlsplit(url)
        port = parsed.port
    except ValueError:
        raise SmokeEnvironmentError("Expected an explicit local HTTP loopback endpoint.") from None
    if (
        parsed.scheme != "http" or parsed.hostname not in ("127.0.0.1", "localhost")
        or parsed.username is not None or parsed.password is not None or parsed.fragment
        or port is None or (origin_only and (parsed.path not in ("", "/") or parsed.query))
    ):
        raise SmokeEnvironmentError("Expected an explicit local HTTP loopback endpoint.")
    return parsed


def published_port(container: Container, port: str) -> str:
    bindings = container["NetworkSettings"]["Ports"].get(port)
    if not bindings or len(bindings) != 1 or bindings[0]["HostIp"] != "127.0.0.1":
        raise SmokeEnvironmentError(f"Expected a single IPv4 loopback publication for {port}.")
    return bindings[0]["HostPort"]


def require_owned_volume(container: Container, project: str, destination: str) -> None:
    name = next(
        (mount.get("Name") for mount in container["Mounts"]
         if mount.get("Type") == "volume" and mount.get("Destination") == destination),
        None,
    )
    if not name:
        raise SmokeEnvironmentError(f"Expected a dedicated volume at {destination}.")
    metadata = cast(list[dict[str, object]], json.loads(docker("volume", "inspect", name)))
    labels = cast(dict[str, str] | None, metadata[0].get("Labels"))
    if not labels or labels.get("com.docker.compose.project") != project:
        raise SmokeEnvironmentError(f"The volume at {destination} belongs to another project.")


def require_owned_network(project: str, *containers: Container) -> None:
    common = set(containers[0]["NetworkSettings"]["Networks"])
    for container in containers[1:]:
        common.intersection_update(container["NetworkSettings"]["Networks"])
    for network in sorted(common):
        # Obtain labels via JSON so Windows does not reinterpret nested quotes.
        metadata = cast(list[dict[str, object]], json.loads(docker("network", "inspect", network)))
        labels = cast(dict[str, str] | None, metadata[0].get("Labels"))
        if metadata[0].get("Internal") is True and labels and labels.get("com.docker.compose.project") == project:
            return
    raise SmokeEnvironmentError("Test backend and dependencies lack a shared project-owned internal network.")


def read_proof(runtime: Path) -> str:
    values = dict(
        line.split("=", 1) for line in (runtime / "local.env").read_text(encoding="utf-8").splitlines()
        if "=" in line and not line.lstrip().startswith("#")
    )
    proof = values.get("EDUCATION_LOCAL_PROOF", "")
    if len(proof) < 32:
        raise SmokeEnvironmentError("The fixture nonce is missing in local.env.")
    return proof


def verify_http_origin(api_origin: str, proof: str) -> None:
    try:
        with cast(HTTPResponse, opener.open(api_origin + ISOLATION_PATH, timeout=5)) as response:
            payload = cast(ProofResponse, json.load(response))
            nonce = payload["data"]["nonce"]
    except (HTTPError, URLError, TimeoutError, ValueError, KeyError, TypeError):
        raise SmokeEnvironmentError("The local API did not prove its isolated fixture identity.") from None
    if not isinstance(nonce, str) or not hmac.compare_digest(nonce, hashlib.sha256(proof.encode()).hexdigest()):
        raise SmokeEnvironmentError("The local API nonce does not match this fixture.")


@dataclass(frozen=True)
class FixtureRuntime:
    api_origin: str
    s3_origin: str | None
    s3_id: str | None = None

    @contextmanager
    def s3_offline(self) -> Generator[None, None, None]:
        if self.s3_id is None:
            raise SmokeEnvironmentError("The isolated S3 service has not been verified.")
        current = service(LOCAL_PROJECT, "seaweedfs-test")
        if current["Id"] != self.s3_id:
            raise SmokeEnvironmentError("The isolated S3 service changed during the smoke.")
        require_owned_volume(current, LOCAL_PROJECT, "/data")
        docker("stop", "--timeout", "10", self.s3_id)
        try:
            yield
        finally:
            docker("start", self.s3_id)
            deadline = time.monotonic() + 45
            while time.monotonic() < deadline:
                try:
                    with cast(HTTPResponse, opener.open(self.api_origin + "/health/storage", timeout=4)) as response:
                        if response.status == 200:
                            break
                except (URLError, TimeoutError):
                    pass
                time.sleep(1)
            else:
                raise SmokeEnvironmentError("The isolated S3 service did not recover after restart.")

    def signed(
        self, descriptor: SignedRequest, data: bytes | None = None,
        extra_headers: dict[str, str] | None = None,
    ) -> tuple[int, bytes, dict[str, str]]:
        target = loopback(descriptor["url"], origin_only=False)
        if self.s3_origin is None or f"{target.scheme}://{target.netloc}" != self.s3_origin:
            raise SmokeEnvironmentError("The signed request points outside the proven test S3 origin.")
        if not target.path.startswith(tuple(f"/{bucket}/" for bucket in BUCKETS)):
            raise SmokeEnvironmentError("The signed request points outside the test buckets.")
        if descriptor["method"] not in ("GET", "PUT"):
            raise SmokeEnvironmentError("The smoke only sends signed GET/PUT requests.")
        headers = {**descriptor["headers"], **(extra_headers or {})}
        if any(name.lower() in ("authorization", "cookie", "host") for name in headers):
            raise SmokeEnvironmentError("Unexpected credential or host header in a signed request.")
        req = Request(descriptor["url"], data=data, method=descriptor["method"], headers=headers)
        attempts = 3 if descriptor["method"] == "GET" else 1
        for attempt in range(attempts):
            try:
                with cast(HTTPResponse, opener.open(req, timeout=35)) as response:
                    return response.status, response.read(), {name.lower(): value for name, value in response.headers.items()}
            except HTTPError as error:
                with error:
                    return error.code, b"", {}
            except (URLError, TimeoutError, ConnectionError, IncompleteRead):
                if attempt + 1 == attempts:
                    break
                time.sleep(1)
        raise SmokeEnvironmentError("The isolated signed object request could not transfer its bytes.")


def verify_fixture(runtime: Path, *, with_s3: bool = False) -> FixtureRuntime:
    proof = read_proof(runtime)
    backend = service(LOCAL_PROJECT, "backend")
    environment = dict(value.split("=", 1) for value in backend["Config"]["Env"])
    for key, expected in REQUIRED_ENV.items():
        if environment.get(key) != expected:
            raise SmokeEnvironmentError(f"The isolated backend does not declare the expected {key}.")
    if not hmac.compare_digest(environment.get("E2E_ISOLATION_PROOF", ""), proof):
        raise SmokeEnvironmentError("The running backend does not match the nonce in local.env.")

    postgres = service(LOCAL_PROJECT, "postgres-test")
    redis = service(LOCAL_PROJECT, "redis-test")
    require_owned_volume(postgres, LOCAL_PROJECT, "/var/lib/postgresql")
    require_owned_network(LOCAL_PROJECT, backend, postgres, redis)
    api_origin = f"http://127.0.0.1:{published_port(backend, '80/tcp')}"
    verify_http_origin(api_origin, proof)

    if not with_s3:
        return FixtureRuntime(api_origin, None)

    if (environment.get("S3_BUCKET_STORAGE"), environment.get("S3_BUCKET_EDUCATION")) != BUCKETS:
        raise SmokeEnvironmentError("The isolated backend does not declare the test S3 buckets.")
    if environment.get("S3_ENDPOINT_INTERNAL") != "http://seaweedfs-test:8333":
        raise SmokeEnvironmentError("The isolated backend does not point to its own S3 service.")
    s3 = service(LOCAL_PROJECT, "seaweedfs-test")
    require_owned_volume(s3, LOCAL_PROJECT, "/data")
    require_owned_network(LOCAL_PROJECT, backend, s3)
    s3_origin = loopback(environment.get("S3_PUBLIC_ENDPOINT", ""), origin_only=True)
    if str(s3_origin.port) != published_port(s3, "8333/tcp"):
        raise SmokeEnvironmentError("The signed S3 port does not match the test service publication.")
    return FixtureRuntime(api_origin, f"{s3_origin.scheme}://{s3_origin.netloc}", s3["Id"])
