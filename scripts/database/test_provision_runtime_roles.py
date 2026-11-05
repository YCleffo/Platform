"""Credential resolution must match Compose, including quotes and percent-encoded URLs."""
import importlib.util
import unittest
from pathlib import Path

spec = importlib.util.spec_from_file_location('roles', Path(__file__).with_name('provision-runtime-roles.py'))
assert spec is not None and spec.loader is not None
roles = importlib.util.module_from_spec(spec)
spec.loader.exec_module(roles)


class ComposeCredentialsTest(unittest.TestCase):
    def test_core_uses_resolved_values_without_a_second_env_parser(self):
        services = {'backend': {'environment': {'DB_USER': 'shell_override', 'DB_PASSWORD': 'space # $ quote\"'}},
                    'backend-scheduler': {'environment': {'DB_USER': 'maintenance', 'DB_PASSWORD': "quoted'password"}}}
        self.assertEqual(('shell_override', 'space # $ quote\"', 'maintenance', "quoted'password"),
                         roles.resolved_credentials(services, 'core'))

    def test_commercial_decodes_uri_credentials_once(self):
        services = {name: {'environment': {'CONTROL_DATABASE_URL': url}} for name, url in (
            ('control-plane', 'postgresql://runtime:quote%27%40%25@db/platform_commercial'),
            ('control-scheduler', 'postgresql://scheduler:space%20%23@db/platform_commercial'))}
        self.assertEqual(('runtime', "quote'@%", 'scheduler', 'space #'),
                         roles.resolved_credentials(services, 'commercial'))

    def test_missing_password_fails_closed(self):
        with self.assertRaises(RuntimeError):
            roles.resolved_credentials({'control-plane': {'environment': {'CONTROL_DATABASE_URL': 'postgresql://owner@db/test'}}}, 'commercial')


if __name__ == '__main__':
    unittest.main()
