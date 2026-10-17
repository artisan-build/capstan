from __future__ import annotations

import contextlib
import http.server
import importlib.util
import json
import os
import sys
import tempfile
import threading
import unittest
from pathlib import Path
from unittest import mock


ROOT = Path(__file__).resolve().parents[2]
MODULE_PATH = ROOT / "drain" / "capstan_drain.py"
SPEC = importlib.util.spec_from_file_location("capstan_drain", MODULE_PATH)
assert SPEC and SPEC.loader
drain = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = drain
SPEC.loader.exec_module(drain)

ITEM_ID = "0199f000-0000-7000-8000-000000000001"
TASK_ID = "0199f000-0000-7000-8000-000000000002"


def links(suffix: str = "one", orchestrator: bool = False) -> dict[str, str]:
    result = {
        "brief": f"https://capstan.test/brief?signature={suffix}",
        "milestone": f"https://capstan.test/milestone?signature={suffix}",
        "steers": f"https://capstan.test/steers?signature={suffix}",
    }
    if orchestrator:
        result.update({
            "orchestration": f"https://capstan.test/orchestration?signature={suffix}",
            "orchestrate": f"https://capstan.test/orchestrate?signature={suffix}",
        })
    return result


class FakeClient:
    def __init__(self, claimed: list[dict] | None = None, renewed: list[dict] | None = None) -> None:
        self.claimed = [] if claimed is None else claimed
        self.renewed = [] if renewed is None else renewed
        self.calls: list[tuple] = []

    def claim(self, capacity, roles, capabilities):
        self.calls.append(("claim", capacity, tuple(roles), tuple(capabilities)))
        return self.claimed

    def renew(self, items):
        self.calls.append(("renew", items))
        return self.renewed

    def transition(self, item_id, fence, state):
        self.calls.append(("transition", item_id, fence, state))
        return {"item": {"id": item_id, "state": state}}


class FakeLauncher:
    def __init__(self, pid: int = 4321, failure: Exception | None = None) -> None:
        self.pid = pid
        self.failure = failure
        self.calls: list[tuple] = []

    def launch(self, argv, cwd, prompt):
        self.calls.append((list(argv), Path(cwd), prompt))
        if self.failure:
            raise self.failure
        return self.pid


class DrainTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.base = Path(self.temporary.name)
        (self.base / "token").write_text("secret-bearer\n", encoding="utf-8")
        os.chmod(self.base / "token", 0o600)
        (self.base / "worker.md").write_text((ROOT / "drain/worker-prompt.md").read_text(), encoding="utf-8")
        (self.base / "orchestrator.md").write_text((ROOT / "drain/orchestrator-prompt.md").read_text(), encoding="utf-8")
        self.config_path = self.base / "config.json"
        self.write_config()
        self.config = drain.load_config(self.config_path)

    def write_config(self, **changes) -> None:
        payload = {
            "version": 1,
            "capstan_url": "https://capstan.test",
            "team": 7,
            "actor_id": "12",
            "credential_file": "token",
            "max_concurrent": 1,
            "max_load_1m": 10.0,
            "max_memory_percent": 90.0,
            "capabilities": ["php"],
            "roles": ["builder"],
            "state_file": "state/state.json",
            "work_dir": "work",
            "lock_file": "state/drain.lock",
            "prompt_template": "worker.md",
            "orchestrator_prompt_template": "orchestrator.md",
            "request_timeout_seconds": 5,
            "role_commands": {"builder": ["worker", "--item", "{item_id}", "--prompt", "{prompt_file}"]},
        }
        payload.update(changes)
        self.config_path.write_text(json.dumps(payload), encoding="utf-8")

    def claim(self, role: str = "builder") -> dict:
        return {"id": ITEM_ID, "task_id": TASK_ID, "role": role, "fence": 1, "links": links(orchestrator=role == "orchestrator")}

    def write_state(self, *, phase: str = "routed") -> dict:
        item_dir = self.base / "work" / f"{ITEM_ID}-f1"
        item_dir.mkdir(parents=True)
        (item_dir / "prompt.md").write_text("secret prompt", encoding="utf-8")
        (item_dir / "links.json").write_text(json.dumps(links()), encoding="utf-8")
        entry = {"task_id": TASK_ID, "role": "builder", "fence": 1, "pid": 999, "phase": phase, "work_dir": str(item_dir)}
        drain.StateStore(self.config.state_file).save({ITEM_ID: entry})
        return entry


class TemplateAndLaunchTests(DrainTestCase):
    def test_unknown_placeholder_is_rejected(self) -> None:
        with self.assertRaisesRegex(drain.ConfigError, "unknown placeholder"):
            drain.substitute(["worker", "{not_allowed}"], {}, "command")

    def test_launcher_uses_argv_cwd_stdin_and_never_a_shell(self) -> None:
        stream = mock.Mock()
        process = mock.Mock(pid=44, stdin=stream)
        with mock.patch.object(drain.subprocess, "Popen", return_value=process) as popen:
            pid = drain.Launcher().launch(["worker", "--flag"], self.base, "the prompt")
        self.assertEqual(pid, 44)
        stream.write.assert_called_once_with("the prompt")
        stream.close.assert_called_once()
        self.assertEqual(popen.call_args.args[0][-3:], ["--", "worker", "--flag"])
        self.assertEqual(popen.call_args.kwargs["cwd"], self.base)
        self.assertEqual(popen.call_args.kwargs["env"]["PWD"], str(self.base))
        self.assertIs(popen.call_args.kwargs["shell"], False)

    def test_opencode_example_attaches_the_private_prompt_file_without_prompt_content_in_argv(self) -> None:
        config = drain.load_config(ROOT / "drain/examples/opencode.json")
        prompt_path = "/private/item/prompt.md"
        signed_prompt = f"Read {links()['brief']}"
        argv = drain.substitute(
            config.role_commands["builder"],
            {"prompt_file": prompt_path, "prompt": signed_prompt},
            "OpenCode example",
        )

        self.assertEqual(
            argv,
            ["opencode", "run", "--standalone", "-f", prompt_path, "Follow the attached work brief exactly."],
        )
        self.assertNotIn(signed_prompt, argv)
        self.assertFalse(any("signature=" in argument for argument in argv))

    def test_terminate_process_waits_for_a_cooperative_term_exit(self) -> None:
        process = drain.subprocess.Popen(
            [
                sys.executable,
                "-c",
                "import signal, time\n"
                "def stop(*_):\n"
                "    raise SystemExit(0)\n"
                "signal.signal(signal.SIGTERM, stop)\n"
                "print('ready', flush=True)\n"
                "time.sleep(60)\n",
            ],
            stdout=drain.subprocess.PIPE,
            text=True,
            start_new_session=True,
        )
        self.addCleanup(self._stop_test_process, process)
        self.assertIsNotNone(process.stdout)
        self.assertEqual(process.stdout.readline().strip(), "ready")
        reaper = threading.Thread(target=process.wait)
        reaper.start()

        drain.terminate_process(process.pid)

        reaper.join(timeout=1)
        self.assertFalse(reaper.is_alive())
        self.assertEqual(process.returncode, 0)

    def test_terminate_process_kills_and_waits_when_term_is_ignored(self) -> None:
        process = drain.subprocess.Popen(
            [
                sys.executable,
                "-c",
                "import signal, time\n"
                "signal.signal(signal.SIGTERM, signal.SIG_IGN)\n"
                "print('ready', flush=True)\n"
                "time.sleep(60)\n",
            ],
            stdout=drain.subprocess.PIPE,
            text=True,
            start_new_session=True,
        )
        self.addCleanup(self._stop_test_process, process)
        self.assertIsNotNone(process.stdout)
        self.assertEqual(process.stdout.readline().strip(), "ready")
        reaper = threading.Thread(target=process.wait)
        reaper.start()

        with mock.patch.object(drain, "TERM_WAIT_SECONDS", 0.05):
            drain.terminate_process(process.pid)

        reaper.join(timeout=1)
        self.assertFalse(reaper.is_alive())
        self.assertEqual(process.returncode, -drain.signal.SIGKILL)

    def test_terminate_process_kills_a_term_resistant_descendant_after_the_leader_exits(self) -> None:
        leader = drain.subprocess.Popen(
            [
                sys.executable,
                "-c",
                "import os, signal, time\n"
                "read_fd, write_fd = os.pipe()\n"
                "child = os.fork()\n"
                "if child == 0:\n"
                "    os.close(read_fd)\n"
                "    signal.signal(signal.SIGTERM, signal.SIG_IGN)\n"
                "    os.write(write_fd, b'ready')\n"
                "    os.close(write_fd)\n"
                "    time.sleep(60)\n"
                "    os._exit(0)\n"
                "os.close(write_fd)\n"
                "os.read(read_fd, 5)\n"
                "os.close(read_fd)\n"
                "def stop(*_):\n"
                "    raise SystemExit(0)\n"
                "signal.signal(signal.SIGTERM, stop)\n"
                "print(child, flush=True)\n"
                "time.sleep(60)\n",
            ],
            stdout=drain.subprocess.PIPE,
            text=True,
            start_new_session=True,
        )
        self.addCleanup(self._stop_test_process, leader)
        self.assertIsNotNone(leader.stdout)
        descendant_pid = int(leader.stdout.readline().strip())
        self.assertTrue(drain.process_alive(descendant_pid))
        reaper = threading.Thread(target=leader.wait)
        reaper.start()

        with (
            mock.patch.object(drain, "TERM_WAIT_SECONDS", 0.05),
            mock.patch.object(drain.os, "killpg", wraps=os.killpg) as killpg,
        ):
            drain.terminate_process(leader.pid)

        reaper.join(timeout=1)
        self.assertFalse(reaper.is_alive())
        self.assertEqual(leader.returncode, 0)
        self.assertIn(mock.call(leader.pid, drain.signal.SIGTERM), killpg.call_args_list)
        self.assertIn(mock.call(leader.pid, drain.signal.SIGKILL), killpg.call_args_list)
        self.assertFalse(drain.process_group_alive(leader.pid))

    def test_terminate_process_raises_when_kill_does_not_establish_exit(self) -> None:
        with (
            mock.patch.object(drain.os, "killpg") as killpg,
            mock.patch.object(drain, "_wait_for_process_group_exit", side_effect=(False, False)),
        ):
            with self.assertRaisesRegex(OSError, "process group remained alive"):
                drain.terminate_process(44)

        self.assertEqual(killpg.call_args_list, [mock.call(44, drain.signal.SIGTERM), mock.call(44, drain.signal.SIGKILL)])

    def _stop_test_process(self, process) -> None:
        with contextlib.suppress(ProcessLookupError):
            os.killpg(process.pid, drain.signal.SIGKILL)
        if process.poll() is None:
            process.wait(timeout=1)
        if process.stdout is not None:
            process.stdout.close()

    def test_role_prompts_contain_required_instructions(self) -> None:
        worker = (ROOT / "drain/worker-prompt.md").read_text()
        for value in ("{brief_url}", "started", "blocked", "pr_opened", "done", "failed", "{steers_url}", "capstan/{item_id}-<slug>"):
            self.assertIn(value, worker)
        orchestrator = (ROOT / "drain/orchestrator-prompt.md").read_text()
        for value in ("{orchestration_url}", "{orchestrate_url}", "Finish every turn explicitly", "state `acted`", "state `failed`"):
            self.assertIn(value, orchestrator)


class AdmissionAndLockTests(DrainTestCase):
    def test_lock_contention_exits_successfully_before_http_client_exists(self) -> None:
        first = drain.FileLock(self.config.lock_file)
        self.assertTrue(first.acquire())
        self.addCleanup(first.close)
        output: list[str] = []
        with mock.patch.object(drain, "ApiClient") as client:
            result = drain.execute(self.config_path, logger=output.append)
        self.assertEqual(result, 0)
        client.assert_not_called()
        self.assertIn("holds the lock", output[0])

    def test_each_admission_ceiling_makes_no_claim_and_logs_not_this_round(self) -> None:
        cases = [
            ({"max_concurrent": 1}, (0.0, 0.0, 0.0), 10.0, True),
            ({"max_load_1m": 1.0}, (1.1, 0.0, 0.0), 10.0, False),
            ({"max_memory_percent": 50.0}, (0.0, 0.0, 0.0), 50.1, False),
        ]
        for changes, load, memory, with_state in cases:
            with self.subTest(changes=changes):
                self.write_config(**changes)
                config = drain.load_config(self.config_path)
                if with_state:
                    self.config = config
                    self.write_state()
                    renewed = [{"item_id": ITEM_ID, "status": "renewed", "item": {"id": ITEM_ID, "links": links("new")}}]
                else:
                    with mock.patch.object(drain.StateStore, "load", return_value={}):
                        pass
                    renewed = []
                client = FakeClient(renewed=renewed)
                output: list[str] = []
                runner = drain.DrainRunner(config, client, logger=output.append, alive=lambda _: True, load=lambda: load, memory=lambda: memory)
                runner.run()
                self.assertFalse(any(call[0] == "claim" for call in client.calls))
                self.assertTrue(any("not this round" in line for line in output))
                if config.state_file.exists():
                    config.state_file.unlink()

    def test_empty_claim_starts_nothing(self) -> None:
        client = FakeClient(claimed=[])
        launcher = FakeLauncher()
        output: list[str] = []
        result = drain.DrainRunner(self.config, client, logger=output.append, launcher=launcher, load=lambda: (0, 0, 0), memory=lambda: 1).run()
        self.assertEqual(result, 0)
        self.assertEqual(launcher.calls, [])
        self.assertIn("queue empty", output)

    def test_dry_claim_authenticates_with_zero_capacity_and_starts_nothing(self) -> None:
        client = mock.Mock()
        client.claim.return_value = []
        output: list[str] = []
        with mock.patch.object(drain, "ApiClient", return_value=client):
            result = drain.execute(self.config_path, dry_claim=True, logger=output.append)
        self.assertEqual(result, 0)
        client.claim.assert_called_once_with(0, self.config.roles, self.config.capabilities)
        self.assertIn("no item was claimed", output[0])


class ApiTransportTests(DrainTestCase):
    def test_redirect_response_is_closed_without_reading_body(self) -> None:
        response = mock.Mock()
        redirect = drain.urllib.error.HTTPError(
            "https://capstan.test/api/v1/work/7/claim",
            302,
            "Found",
            {"Location": "https://redirect.test/secret"},
            response,
        )
        client = drain.ApiClient(self.config, "secret-bearer")
        client.opener = mock.Mock()
        client.opener.open.side_effect = redirect

        with self.assertRaises(drain.ApiError) as failure:
            client.claim(0, ["builder"], ["php"])

        response.close.assert_called_once_with()
        response.read.assert_not_called()
        self.assertEqual((failure.exception.status, failure.exception.code), (302, "request_failed"))

    def test_redirect_is_refused_without_forwarding_bound_credentials(self) -> None:
        source_credentials: list[tuple[str | None, str | None]] = []
        target_requests: list[dict[str, str]] = []

        class TargetHandler(http.server.BaseHTTPRequestHandler):
            def record(self) -> None:
                target_requests.append(dict(self.headers))
                self.send_response(204)
                self.end_headers()

            do_GET = record
            do_POST = record

            def log_message(self, *_: object) -> None:
                pass

        target = http.server.ThreadingHTTPServer(("127.0.0.1", 0), TargetHandler)
        target_thread = threading.Thread(target=target.serve_forever, daemon=True)
        target_thread.start()
        target_url = f"http://127.0.0.1:{target.server_port}/location-secret?signature=redirect-secret"

        class RedirectHandler(http.server.BaseHTTPRequestHandler):
            def do_POST(self) -> None:
                source_credentials.append((self.headers.get("Authorization"), self.headers.get("X-Capstan-Actor-ID")))
                body = b'{"error":{"code":"redirect_body_secret"}}'
                self.send_response(302)
                self.send_header("Location", target_url)
                self.send_header("Content-Type", "application/json")
                self.send_header("Content-Length", str(len(body)))
                self.end_headers()
                self.wfile.write(body)

            def log_message(self, *_: object) -> None:
                pass

        source = http.server.ThreadingHTTPServer(("127.0.0.1", 0), RedirectHandler)
        source_thread = threading.Thread(target=source.serve_forever, daemon=True)
        source_thread.start()
        try:
            self.write_config(capstan_url=f"http://127.0.0.1:{source.server_port}", actor_id="918273645")
            client = drain.ApiClient(drain.load_config(self.config_path), "secret-bearer")
            with self.assertRaises(drain.ApiError) as failure:
                client.claim(0, ["builder"], ["php"])
        finally:
            source.shutdown()
            target.shutdown()
            source.server_close()
            target.server_close()
            source_thread.join()
            target_thread.join()

        self.assertEqual(source_credentials, [("Bearer secret-bearer", "918273645")])
        self.assertEqual(target_requests, [])
        self.assertEqual((failure.exception.status, failure.exception.code), (302, "request_failed"))
        self.assertEqual(str(failure.exception), "API request failed (302 request_failed)")
        for sensitive in ("secret-bearer", "918273645", "location-secret", "redirect-secret", "redirect_body_secret"):
            self.assertNotIn(sensitive, str(failure.exception))

    def test_capstan_url_requires_https_except_for_local_loopback_http(self) -> None:
        accepted = (
            "https://capstan.test",
            "https://192.0.2.1",
            "http://localhost:8000",
            "http://127.0.0.1:8000",
            "http://[::1]:8000",
        )
        for url in accepted:
            with self.subTest(accepted=url):
                self.write_config(capstan_url=url)
                self.assertEqual(drain.load_config(self.config_path).capstan_url, url)

        for url in ("http://capstan.test", "http://192.0.2.1"):
            with self.subTest(rejected=url):
                self.write_config(capstan_url=url)
                with self.assertRaisesRegex(drain.ConfigError, "must use HTTPS"):
                    drain.load_config(self.config_path)


class LifecycleTests(DrainTestCase):
    def test_claim_launch_deliver_route_uses_item_cwd_and_recoverable_secret_free_state(self) -> None:
        client = FakeClient(claimed=[self.claim()])
        launcher = FakeLauncher()
        runner = drain.DrainRunner(self.config, client, launcher=launcher, logger=lambda _: None, load=lambda: (0, 0, 0), memory=lambda: 1)
        self.assertEqual(runner.run(), 0)
        self.assertEqual([call[3] for call in client.calls if call[0] == "transition"], ["delivered", "routed"])
        argv, cwd, prompt = launcher.calls[0]
        self.assertEqual(cwd.name, f"{ITEM_ID}-f1")
        self.assertEqual(argv[:3], ["worker", "--item", ITEM_ID])
        self.assertIn(links()["brief"], prompt)
        state_text = self.config.state_file.read_text()
        self.assertNotIn("secret-bearer", state_text)
        self.assertNotIn("signature=", state_text)
        state = json.loads(state_text)["items"][ITEM_ID]
        self.assertEqual(state["phase"], "routed")
        self.assertEqual(state["pid"], 4321)
        self.assertTrue((cwd / "prompt.md").is_file())
        self.assertTrue((cwd / "links.json").is_file())
        self.assertTrue((cwd / "launch.ready").is_file())

    def test_alive_worker_renews_and_persists_replacement_links_and_session_metadata(self) -> None:
        self.write_state()
        renewed = [{
            "item_id": ITEM_ID,
            "status": "renewed",
            "item": {"id": ITEM_ID, "links": links("replacement"), "session_ref": "opaque-ref", "seat_fence": 9},
        }]
        client = FakeClient(renewed=renewed)
        drain.DrainRunner(self.config, client, logger=lambda _: None, alive=lambda _: True, load=lambda: (0, 0, 0), memory=lambda: 1).run()
        self.assertEqual(client.calls[0][0], "renew")
        state = json.loads(self.config.state_file.read_text())["items"][ITEM_ID]
        self.assertEqual(state["session_ref"], "opaque-ref")
        self.assertEqual(state["seat_fence"], 9)
        refreshed = json.loads((Path(state["work_dir"]) / "links.json").read_text())
        self.assertEqual(refreshed, links("replacement"))
        self.assertNotIn("signature=", self.config.state_file.read_text())

    def test_terminal_exit_is_forgotten_without_release(self) -> None:
        entry = self.write_state()
        client = FakeClient(renewed=[{"item_id": ITEM_ID, "status": "lost"}])
        drain.DrainRunner(self.config, client, logger=lambda _: None, alive=lambda _: False, load=lambda: (0, 0, 0), memory=lambda: 1).run()
        self.assertFalse(any(call[0] == "transition" for call in client.calls))
        self.assertEqual(json.loads(self.config.state_file.read_text())["items"], {})
        self.assertFalse((Path(entry["work_dir"]) / "prompt.md").exists())

    def test_lost_live_worker_is_stopped_as_an_orphan_without_release(self) -> None:
        self.write_state()
        stopped: list[int] = []
        client = FakeClient(renewed=[{"item_id": ITEM_ID, "status": "lost"}])
        drain.DrainRunner(
            self.config,
            client,
            logger=lambda _: None,
            alive=lambda _: True,
            terminate=stopped.append,
            load=lambda: (0, 0, 0),
            memory=lambda: 1,
        ).run()
        self.assertEqual(stopped, [999])
        self.assertFalse(any(call[0] == "transition" for call in client.calls))
        self.assertEqual(json.loads(self.config.state_file.read_text())["items"], {})

    def test_lost_live_worker_record_is_retained_when_shutdown_cannot_be_established(self) -> None:
        self.write_state()
        client = FakeClient(renewed=[{"item_id": ITEM_ID, "status": "lost"}])
        output: list[str] = []

        drain.DrainRunner(
            self.config,
            client,
            logger=output.append,
            alive=lambda _: True,
            terminate=mock.Mock(side_effect=OSError("still alive")),
            load=lambda: (0, 0, 0),
            memory=lambda: 1,
        ).run()

        self.assertIn(ITEM_ID, json.loads(self.config.state_file.read_text())["items"])
        self.assertTrue(any("orphan stop failed" in line for line in output))

    def test_nonterminal_exit_is_renewed_then_released(self) -> None:
        self.write_state()
        client = FakeClient(renewed=[{"item_id": ITEM_ID, "status": "renewed", "item": {"id": ITEM_ID, "links": links("new")}}])
        output: list[str] = []
        drain.DrainRunner(self.config, client, logger=output.append, alive=lambda _: False, load=lambda: (0, 0, 0), memory=lambda: 1).run()
        self.assertIn(("transition", ITEM_ID, 1, "released"), client.calls)
        self.assertEqual(json.loads(self.config.state_file.read_text())["items"], {})
        self.assertTrue(any("released item_id" in line for line in output))

    def test_renewal_recovers_a_committed_delivered_transition_and_finishes_routing(self) -> None:
        self.write_state(phase="launched")
        renewed = [{
            "item_id": ITEM_ID,
            "status": "renewed",
            "item": {"id": ITEM_ID, "state": "delivered", "links": links("new")},
        }]
        client = FakeClient(renewed=renewed)

        drain.DrainRunner(self.config, client, logger=lambda _: None, alive=lambda _: True, load=lambda: (0, 0, 0), memory=lambda: 1).run()

        self.assertEqual([call[3] for call in client.calls if call[0] == "transition"], ["routed"])
        state = json.loads(self.config.state_file.read_text())["items"][ITEM_ID]
        self.assertEqual(state["phase"], "routed")
        self.assertTrue((Path(state["work_dir"]) / "launch.ready").is_file())

    def test_renewal_recovers_a_committed_routed_transition_and_opens_the_gate(self) -> None:
        self.write_state(phase="delivered")
        renewed = [{
            "item_id": ITEM_ID,
            "status": "renewed",
            "item": {"id": ITEM_ID, "state": "routed", "links": links("new")},
        }]
        client = FakeClient(renewed=renewed)

        drain.DrainRunner(self.config, client, logger=lambda _: None, alive=lambda _: True, load=lambda: (0, 0, 0), memory=lambda: 1).run()

        self.assertFalse(any(call[0] == "transition" for call in client.calls))
        state = json.loads(self.config.state_file.read_text())["items"][ITEM_ID]
        self.assertEqual(state["phase"], "routed")
        self.assertTrue((Path(state["work_dir"]) / "launch.ready").is_file())

    def test_launch_failure_releases_claim_and_redacts_signed_url(self) -> None:
        client = FakeClient(claimed=[self.claim()])
        launcher = FakeLauncher(failure=drain.DrainError(f"bad {links()['brief']}"))
        output: list[str] = []
        drain.DrainRunner(self.config, client, launcher=launcher, logger=output.append, load=lambda: (0, 0, 0), memory=lambda: 1).run()
        self.assertIn(("transition", ITEM_ID, 1, "released"), client.calls)
        combined = "\n".join(output)
        self.assertNotIn("signature=", combined)
        self.assertNotIn("secret-bearer", combined)
        self.assertIn("<redacted-url>", combined)

    def test_one_launch_failure_does_not_prevent_an_unrelated_claim_from_starting(self) -> None:
        self.write_config(max_concurrent=2)
        config = drain.load_config(self.config_path)
        other_id = "0199f000-0000-7000-8000-000000000003"
        second = {**self.claim(), "id": other_id, "links": links("two")}

        class MixedLauncher:
            def __init__(self):
                self.calls = 0

            def launch(self, argv, cwd, prompt):
                self.calls += 1
                if self.calls == 1:
                    raise drain.DrainError("first launch failed")
                return 4444

        client = FakeClient(claimed=[self.claim(), second])
        launcher = MixedLauncher()
        drain.DrainRunner(config, client, launcher=launcher, logger=lambda _: None, load=lambda: (0, 0, 0), memory=lambda: 1).run()
        self.assertIn(("transition", ITEM_ID, 1, "released"), client.calls)
        self.assertIn(("transition", other_id, 1, "delivered"), client.calls)
        state = json.loads(config.state_file.read_text())["items"]
        self.assertEqual(set(state), {other_id})

    def test_transition_and_renew_failures_are_bounded_and_keep_unrelated_state(self) -> None:
        self.write_state(phase="delivered")

        class FailingClient(FakeClient):
            def renew(self, items):
                raise drain.ApiError(503, "unavailable")

        output: list[str] = []
        result = drain.DrainRunner(self.config, FailingClient(), logger=output.append, alive=lambda _: True, load=lambda: (0, 0, 0), memory=lambda: 1).run()
        self.assertEqual(result, 0)
        self.assertIn(ITEM_ID, json.loads(self.config.state_file.read_text())["items"])
        self.assertTrue(any("renew batch failed" in line for line in output))


class ContractTests(DrainTestCase):
    def test_example_configs_match_schema_and_cover_three_harnesses(self) -> None:
        examples = ROOT / "drain/examples"
        loaded = {path.name: drain.load_config(path) for path in examples.glob("*.json")}
        self.assertEqual(set(loaded), {"claude.json", "opencode.json", "generic.json"})
        self.assertEqual(loaded["claude.json"].role_commands["builder"], ("claude", "-p"))
        self.assertEqual(
            loaded["opencode.json"].role_commands["builder"],
            ("opencode", "run", "--standalone", "-f", "{prompt_file}", "Follow the attached work brief exactly."),
        )
        self.assertIn("{config_dir}/../harness/stub_worker.py", loaded["generic.json"].role_commands["builder"])
        self.assertTrue(all(config.capstan_url.startswith("http://127.0.0.1:") for config in loaded.values()))

    def test_config_requires_role_map_argv_and_rejects_shell_strings(self) -> None:
        self.write_config(role_commands={"builder": "worker --unsafe"})
        with self.assertRaisesRegex(drain.ConfigError, "argv string list"):
            drain.load_config(self.config_path)

    def test_credential_permissions_and_value_are_validated_without_echoing_value(self) -> None:
        os.chmod(self.base / "token", 0o644)
        with self.assertRaisesRegex(drain.ConfigError, "group or others") as failure:
            drain.read_credential(self.base / "token")
        self.assertNotIn("secret-bearer", str(failure.exception))

    def test_docs_skill_and_ci_publish_required_contracts(self) -> None:
        docs = (ROOT / "docs/work.md").read_text()
        skill = (ROOT / "drain/SKILL.md").read_text()
        workflow = (ROOT / ".github/workflows/tests.yml").read_text()
        for phrase in ("session_ref", "Solo MCP", "Puck", "feature is off", "cold"):
            self.assertIn(phrase, docs)
        for phrase in ("--dry-claim", "cron", "launchd", "Do not install", "credential"):
            self.assertIn(phrase, skill)
        self.assertIn("python3 -m unittest discover -s tests/python", workflow)
        postgres = workflow.split("tests-postgres:", 1)[1]
        self.assertNotIn("python3 -m unittest", postgres)


if __name__ == "__main__":
    unittest.main()
