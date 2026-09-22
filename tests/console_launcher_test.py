"""Exercise the host launcher with a fake Docker client and no real services."""
import json
import os
from pathlib import Path
import pty
import shutil
import subprocess
import tempfile
import unittest

SOURCE = Path(__file__).resolve().parents[1]


class LauncherTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="naf console ")
        self.root = Path(self.temp.name)
        for relative in ("bin/naf", "app/bin/naf", "app/bin/naf-runtime"):
            target = self.root / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(SOURCE / relative, target)
        (self.root / "compose.yaml").write_text("services: {}\n")
        self.tools = self.root / "tools"
        self.tools.mkdir()
        self.log = self.root / "calls.jsonl"
        docker = self.tools / "docker"
        docker.write_text("""#!/usr/bin/env python3
import json, os, sys
with open(os.environ['CALL_LOG'], 'a') as log:
    log.write(json.dumps(sys.argv[1:]) + '\\n')
if 'ps' in sys.argv:
    print(os.environ.get('RUNNING', 'container-id'))
    sys.exit(int(os.environ.get('PROBE_EXIT', '0')))
if os.environ.get('READ_INPUT'):
    print(sys.stdin.read())
sys.exit(int(os.environ.get('COMMAND_EXIT', '0')))
""")
        docker.chmod(0o755)
        self.env = {**os.environ, "NAF_CLI_RUNTIME": "compose", "CALL_LOG": str(self.log), "PATH": str(self.tools) + os.pathsep + os.environ["PATH"]}

    def tearDown(self):
        self.temp.cleanup()

    def invoke(self, *args, **env):
        return subprocess.run([str(self.root / "bin/naf"), *args], cwd="/tmp", env={**self.env, **env}, input="input with spaces\n", text=True, capture_output=True)

    def calls(self):
        return [json.loads(line) for line in self.log.read_text().splitlines()]

    def test_running_container_preserves_arguments_and_exit_status(self):
        args = ["command:list", "a b", "$(touch nope)", "'quote", "", "--value=x y"]
        result = self.invoke(*args, COMMAND_EXIT="23")
        self.assertEqual(23, result.returncode, result.stderr)
        call = self.calls()[-1]
        self.assertEqual(args, call[-len(args):])
        self.assertIn("exec", call)
        self.assertIn("-T", call)
        self.assertEqual(str(self.root), call[call.index("--project-directory") + 1])

    def test_stopped_service_uses_disposable_container(self):
        result = self.invoke("command:list", RUNNING="")
        self.assertEqual(0, result.returncode, result.stderr)
        call = self.calls()[-1]
        self.assertIn("run", call)
        self.assertIn("--rm", call)
        self.assertIn("--no-deps", call)
        self.assertIn("-T", call)

    def test_compose_failure_is_not_a_local_fallback(self):
        result = self.invoke("command:list", PROBE_EXIT="19")
        self.assertEqual(19, result.returncode)
        self.assertEqual(1, len(self.calls()))

    def test_piped_input_reaches_the_command(self):
        result = self.invoke("command:list", READ_INPUT="1")
        self.assertEqual(0, result.returncode)
        self.assertIn("input with spaces", result.stdout)

    def test_service_is_explicitly_selectable(self):
        result = self.invoke("command:list", NAF_CLI_SERVICE="app-test")
        self.assertEqual(0, result.returncode)
        self.assertTrue(all("app-test" in call for call in self.calls()))

    def test_local_mode_avoids_docker(self):
        vendor = self.root / "app/vendor/bin"
        vendor.mkdir(parents=True)
        (vendor / "naf").write_text("placeholder")
        php = self.tools / "php"
        php.write_text("#!/bin/sh\nprintf '%s\\n' \"$PWD\" \"$@\"\nexit 27\n")
        php.chmod(0o755)
        result = self.invoke("command:list", "a b", NAF_CLI_RUNTIME="local")
        self.assertEqual(27, result.returncode)
        self.assertEqual([str(self.root / "app"), "vendor/bin/naf", "command:list", "a b"], result.stdout.splitlines())
        self.assertFalse(self.log.exists())

    def test_invalid_runtime_is_rejected(self):
        result = self.invoke(NAF_CLI_RUNTIME="typo")
        self.assertEqual(1, result.returncode)
        self.assertIn("NAF_CLI_RUNTIME", result.stderr)
        self.assertFalse(self.log.exists())

    def test_missing_shortcut_has_installation_hint(self):
        (self.root / "app/bin/naf").unlink()
        result = self.invoke()
        self.assertEqual(1, result.returncode)
        self.assertIn("make composer-install", result.stderr)

    def test_interactive_terminal_keeps_tty_enabled(self):
        master, slave = pty.openpty()
        try:
            process = subprocess.Popen([str(self.root / "bin/naf"), "command:list"], cwd="/tmp", env=self.env, stdin=slave, stdout=slave, stderr=slave)
            os.close(slave)
            self.assertEqual(0, process.wait(timeout=10))
            self.assertNotIn("-T", self.calls()[-1])
        finally:
            os.close(master)


if __name__ == "__main__":
    unittest.main()
