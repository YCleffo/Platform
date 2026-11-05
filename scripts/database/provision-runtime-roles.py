"""Provision isolated HTTP and scheduler database logins on existing Platform databases."""
import json
import subprocess
from collections.abc import Mapping
from typing import TypedDict
from urllib.parse import urlsplit, unquote
from migrate import ROOT, container


class ComposeService(TypedDict):
    environment: dict[str, str]


def resolved_credentials(services: Mapping[str, ComposeService], plane: str) -> tuple[str, str, str, str]:
    """Use exactly the interpolation and environment precedence used by Compose."""
    if plane == 'core':
        runtime = services['backend']['environment']
        maintenance = services['backend-scheduler']['environment']
        return runtime['DB_USER'], runtime['DB_PASSWORD'], maintenance['DB_USER'], maintenance['DB_PASSWORD']
    def login(service: str) -> tuple[str, str]:
        url = urlsplit(services[service]['environment']['CONTROL_DATABASE_URL'])
        if url.scheme not in ('postgres', 'postgresql') or not url.username or not url.password:
            raise RuntimeError('Invalid Commercial database connection configuration')
        return unquote(url.username), unquote(url.password)
    return (*login('control-plane'), *login('control-scheduler'))


def provision(plane: str, services: Mapping[str, ComposeService]) -> None:
    service = 'postgres' if plane == 'core' else 'control-postgres'
    database = 'platform' if plane == 'core' else 'platform_commercial'
    schemas = 'core,platform_runtime,public' if plane == 'core' else 'commercial,public'
    name = container(service)
    meta = json.loads(subprocess.check_output(['docker', 'inspect', name]))[0]
    admin = dict(item.split('=', 1) for item in meta['Config']['Env'] if '=' in item)
    if admin.get('POSTGRES_DB') != database:
        raise RuntimeError('Unexpected target database')
    role, password, maintenance, maintenance_password = resolved_credentials(services, plane)
    env = {'PGHOST': '127.0.0.1', 'PGPORT': '5432', 'PGDATABASE': database, 'SCHEMAS': schemas,
           'PGADMIN_USER': admin['POSTGRES_USER'], 'PGADMIN_PASSWORD': admin['POSTGRES_PASSWORD'],
           'MIGRATION_ROLE': admin['POSTGRES_USER'], 'RUNTIME_ROLE': role,
           'RUNTIME_PASSWORD': password,
           'MAINTENANCE_ROLE': maintenance, 'MAINTENANCE_PASSWORD': maintenance_password}
    if not env['RUNTIME_PASSWORD'] or not env['MAINTENANCE_PASSWORD']:
        raise RuntimeError('Run scripts/init-env.ps1 before provisioning database roles')
    command = ['docker', 'exec', '-i']
    for key, value in env.items():
        command.extend(['-e', key + '=' + value])
    command.extend([name, 'bash', '-s'])
    result = subprocess.run(command, input=(ROOT / 'scripts/operations/provision-db-role.sh').read_bytes().replace(b'\r\n', b'\n'), capture_output=True)
    if result.returncode:
        raise RuntimeError('Role provisioning failed; credentials and SQL diagnostics are suppressed')
    print(plane + ': runtime and scheduler roles provisioned; migration owner preserved')


if __name__ == '__main__':
    # Never print config: it contains the resolved private credentials.
    result = subprocess.run(['docker', 'compose', '--profile', 'control', 'config', '--format', 'json'],
                            cwd=ROOT, capture_output=True)
    if result.returncode:
        raise RuntimeError('Compose configuration failed; private diagnostics are suppressed')
    services = json.loads(result.stdout)['services']
    for current in ('core', 'commercial'):
        provision(current, services)
