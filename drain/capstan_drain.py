#!/usr/bin/env python3
"""Small, cron-oriented reference drain for Capstan Work."""

from __future__ import annotations

import argparse
import contextlib
import dataclasses
import fcntl
import ipaddress
import json
import os
import re
import signal
import stat
import string
import subprocess
import sys
import tempfile
import time
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path
from typing import Any, Callable, Iterator, Mapping, Sequence


MAX_BATCH = 25
STATE_VERSION = 1
KNOWN_PLACEHOLDERS = frozenset(
    {
        "brief_url",
        "config_dir",
        "item_id",
        "links_file",
        "milestone_url",
        "orchestrate_url",
        "orchestration_url",
        "prompt",
        "prompt_file",
        "seat_fence",
        "session_ref",
        "steers_url",
        "task_id",
        "work_dir",
    }
)
REQUIRED_LINKS = frozenset({"brief", "milestone", "steers"})
ORCHESTRATOR_LINKS = frozenset({"orchestration", "orchestrate"})
UUID_RE = re.compile(r"\A[0-9a-fA-F]{8}-[0-9a-fA-F-]{27,28}\Z")
SIGNED_URL_RE = re.compile(r"https?://[^\s'\"<>]+\?[^\s'\"<>]+")


class DrainError(RuntimeError):
    """An expected, safe-to-report drain failure."""


class ConfigError(DrainError):
    pass


class ApiError(DrainError):
    def __init__(self, status: int | None, code: str = "request_failed") -> None:
        self.status = status
        self.code = code
        super().__init__(f"API request failed ({status or 'network'} {code})")


@dataclasses.dataclass(frozen=True)
class Config:
    path: Path
    capstan_url: str
    team: str
    actor_id: str
    credential_file: Path
    max_concurrent: int
    max_load_1m: float
    max_memory_percent: float
    capabilities: tuple[str, ...]
    roles: tuple[str, ...]
    state_file: Path
    work_dir: Path
    lock_file: Path
    prompt_template: Path
    orchestrator_prompt_template: Path
    role_commands: Mapping[str, tuple[str, ...]]
    request_timeout_seconds: float


def _expect_object(value: Any, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ConfigError(f"{label} must be a JSON object")
    return value


def _scalar_list(value: Any, label: str, *, nonempty: bool = False) -> tuple[str, ...]:
    if not isinstance(value, list) or (nonempty and not value):
        raise ConfigError(f"{label} must be {'a non-empty ' if nonempty else 'a '}JSON list")
    if any(not isinstance(item, str) or not item or len(item) > 64 for item in value):
        raise ConfigError(f"{label} entries must be non-empty strings of at most 64 characters")
    if len(set(value)) != len(value):
        raise ConfigError(f"{label} entries must be distinct")
    if len(value) > MAX_BATCH:
        raise ConfigError(f"{label} must contain at most {MAX_BATCH} entries")
    return tuple(value)


def _resolve_path(config_dir: Path, value: Any, label: str) -> Path:
    if not isinstance(value, str) or not value:
        raise ConfigError(f"{label} must be a path string")
    path = Path(value).expanduser()
    return (config_dir / path).resolve() if not path.is_absolute() else path.resolve()


def load_config(path: Path) -> Config:
    config_path = path.expanduser().resolve()
    try:
        raw = _expect_object(json.loads(config_path.read_text(encoding="utf-8")), "config")
    except (OSError, json.JSONDecodeError) as exception:
        raise ConfigError("config could not be read as JSON") from exception

    allowed = {
        "version", "capstan_url", "team", "actor_id", "credential_file", "max_concurrent",
        "max_load_1m", "max_memory_percent", "capabilities", "roles", "state_file", "work_dir",
        "lock_file", "prompt_template", "orchestrator_prompt_template", "role_commands",
        "request_timeout_seconds",
    }
    unknown = set(raw) - allowed
    if unknown:
        raise ConfigError("config contains unknown keys")
    if raw.get("version") != 1:
        raise ConfigError("config.version must be 1")

    capstan_url = raw.get("capstan_url")
    if not isinstance(capstan_url, str):
        raise ConfigError("capstan_url must be a URL string")
    try:
        parsed = urllib.parse.urlsplit(capstan_url)
        hostname = parsed.hostname
    except ValueError as exception:
        raise ConfigError("capstan_url must be a valid URL") from exception
    if parsed.scheme not in {"http", "https"} or not parsed.netloc or not hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
        raise ConfigError("capstan_url must be an http(s) URL without credentials, query, or fragment")
    if parsed.scheme == "http":
        is_loopback = hostname == "localhost"
        with contextlib.suppress(ValueError):
            is_loopback = is_loopback or ipaddress.ip_address(hostname).is_loopback
        if not is_loopback:
            raise ConfigError("capstan_url must use HTTPS except for localhost or a loopback IP address")

    team_value = raw.get("team")
    if not isinstance(team_value, (str, int)) or isinstance(team_value, bool) or str(team_value).strip() == "":
        raise ConfigError("team must be a non-empty id or route key")
    team = str(team_value)
    actor_id = raw.get("actor_id")
    if not isinstance(actor_id, str) or re.fullmatch(r"[1-9][0-9]{0,18}", actor_id) is None:
        raise ConfigError("actor_id must be the numeric Capstan actor id")

    max_concurrent = raw.get("max_concurrent")
    if not isinstance(max_concurrent, int) or isinstance(max_concurrent, bool) or not 1 <= max_concurrent <= MAX_BATCH:
        raise ConfigError(f"max_concurrent must be an integer from 1 to {MAX_BATCH}")
    max_load = raw.get("max_load_1m")
    if not isinstance(max_load, (int, float)) or isinstance(max_load, bool) or max_load <= 0:
        raise ConfigError("max_load_1m must be greater than zero")
    max_memory = raw.get("max_memory_percent")
    if not isinstance(max_memory, (int, float)) or isinstance(max_memory, bool) or not 0 < max_memory <= 100:
        raise ConfigError("max_memory_percent must be greater than zero and at most 100")
    timeout = raw.get("request_timeout_seconds", 15)
    if not isinstance(timeout, (int, float)) or isinstance(timeout, bool) or not 0 < timeout <= 120:
        raise ConfigError("request_timeout_seconds must be greater than zero and at most 120")

    roles = _scalar_list(raw.get("roles"), "roles", nonempty=True)
    capabilities = _scalar_list(raw.get("capabilities"), "capabilities")
    command_object = _expect_object(raw.get("role_commands"), "role_commands")
    if set(command_object) != set(roles):
        raise ConfigError("role_commands keys must exactly match roles")
    commands: dict[str, tuple[str, ...]] = {}
    for role, command in command_object.items():
        if not isinstance(command, list) or not command or any(not isinstance(part, str) or not part for part in command):
            raise ConfigError(f"role command for {role!r} must be a non-empty argv string list")
        validate_template_fields(command, f"role command for {role!r}")
        commands[role] = tuple(command)

    config_dir = config_path.parent
    return Config(
        path=config_path,
        capstan_url=capstan_url.rstrip("/"),
        team=team,
        actor_id=actor_id,
        credential_file=_resolve_path(config_dir, raw.get("credential_file"), "credential_file"),
        max_concurrent=max_concurrent,
        max_load_1m=float(max_load),
        max_memory_percent=float(max_memory),
        capabilities=capabilities,
        roles=roles,
        state_file=_resolve_path(config_dir, raw.get("state_file"), "state_file"),
        work_dir=_resolve_path(config_dir, raw.get("work_dir"), "work_dir"),
        lock_file=_resolve_path(config_dir, raw.get("lock_file"), "lock_file"),
        prompt_template=_resolve_path(config_dir, raw.get("prompt_template"), "prompt_template"),
        orchestrator_prompt_template=_resolve_path(config_dir, raw.get("orchestrator_prompt_template"), "orchestrator_prompt_template"),
        role_commands=commands,
        request_timeout_seconds=float(timeout),
    )


def validate_template_fields(parts: Sequence[str], label: str) -> None:
    formatter = string.Formatter()
    for part in parts:
        try:
            fields = [field for _, field, _, _ in formatter.parse(part) if field is not None]
        except ValueError as exception:
            raise ConfigError(f"{label} contains an invalid placeholder") from exception
        if any(field not in KNOWN_PLACEHOLDERS for field in fields):
            raise ConfigError(f"{label} contains an unknown placeholder")


def substitute(parts: Sequence[str], values: Mapping[str, str], label: str = "template") -> list[str]:
    validate_template_fields(parts, label)
    try:
        rendered = [part.format_map(values) for part in parts]
    except KeyError as exception:
        raise ConfigError(f"{label} requires an unavailable placeholder") from exception
    if any("\0" in part for part in rendered):
        raise ConfigError(f"{label} rendered a NUL byte")
    return rendered


def read_credential(path: Path) -> str:
    try:
        mode = stat.S_IMODE(path.stat().st_mode)
        if mode & 0o077:
            raise ConfigError("credential file must not be readable or writable by group or others")
        token = path.read_text(encoding="utf-8").strip()
    except ConfigError:
        raise
    except OSError as exception:
        raise ConfigError("credential file could not be read") from exception
    if not token or len(token) > 16384 or any(character.isspace() for character in token):
        raise ConfigError("credential file must contain one non-empty bearer without whitespace")
    return token


class FileLock:
    def __init__(self, path: Path) -> None:
        self.path = path
        self.handle: Any = None

    def acquire(self) -> bool:
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.handle = self.path.open("a+", encoding="utf-8")
        try:
            fcntl.flock(self.handle.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
            return True
        except BlockingIOError:
            self.handle.close()
            self.handle = None
            return False

    def close(self) -> None:
        if self.handle is not None:
            fcntl.flock(self.handle.fileno(), fcntl.LOCK_UN)
            self.handle.close()
            self.handle = None

    def __enter__(self) -> "FileLock":
        return self

    def __exit__(self, *_: object) -> None:
        self.close()


class NoRedirectHandler(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, request, file_pointer, code, message, headers, new_url):
        return None


class ApiClient:
    def __init__(self, config: Config, credential: str) -> None:
        self.base = f"{config.capstan_url}/api/v1/work/{urllib.parse.quote(config.team, safe='')}"
        self.headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {credential}",
            "Content-Type": "application/json",
            "X-Capstan-Actor-ID": config.actor_id,
        }
        self.timeout = config.request_timeout_seconds
        self.opener = urllib.request.build_opener(NoRedirectHandler())

    def request(self, action: str, payload: Mapping[str, Any]) -> dict[str, Any]:
        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
        request = urllib.request.Request(f"{self.base}/{action}", data=body, headers=self.headers, method="POST")
        try:
            with self.opener.open(request, timeout=self.timeout) as response:
                decoded = json.loads(response.read().decode("utf-8"))
        except urllib.error.HTTPError as exception:
            if 300 <= exception.code < 400:
                exception.close()
                raise ApiError(exception.code) from None
            code = "request_failed"
            with contextlib.suppress(Exception):
                error_body = json.loads(exception.read().decode("utf-8"))
                candidate = error_body.get("error", {}).get("code")
                if isinstance(candidate, str) and re.fullmatch(r"[a-z0-9_.-]{1,80}", candidate):
                    code = candidate
            raise ApiError(exception.code, code) from None
        except (OSError, ValueError, json.JSONDecodeError):
            raise ApiError(None) from None
        if not isinstance(decoded, dict):
            raise ApiError(None, "invalid_response")
        return decoded

    def claim(self, capacity: int, roles: Sequence[str], capabilities: Sequence[str]) -> list[dict[str, Any]]:
        response = self.request("claim", {"capacity": capacity, "roles": list(roles), "capabilities": list(capabilities)})
        items = response.get("items")
        if not isinstance(items, list):
            raise ApiError(None, "invalid_claim_response")
        return items

    def renew(self, items: Sequence[Mapping[str, Any]]) -> list[dict[str, Any]]:
        response = self.request("renew", {"items": list(items)})
        results = response.get("results")
        if not isinstance(results, list):
            raise ApiError(None, "invalid_renew_response")
        return results

    def transition(self, item_id: str, fence: int, state: str) -> dict[str, Any]:
        return self.request("transition", {"item_id": item_id, "fence": fence, "state": state})


class StateStore:
    def __init__(self, path: Path) -> None:
        self.path = path

    def load(self) -> dict[str, dict[str, Any]]:
        if not self.path.exists():
            return {}
        try:
            raw = json.loads(self.path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exception:
            raise DrainError("state file could not be read as JSON; it was left unchanged") from exception
        if not isinstance(raw, dict) or raw.get("version") != STATE_VERSION or not isinstance(raw.get("items"), dict):
            raise DrainError("state file has an unsupported shape; it was left unchanged")
        return raw["items"]

    def save(self, items: Mapping[str, Mapping[str, Any]]) -> None:
        self.path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
        payload = json.dumps({"version": STATE_VERSION, "items": items}, indent=2, sort_keys=True) + "\n"
        descriptor, temporary = tempfile.mkstemp(prefix=f".{self.path.name}.", dir=self.path.parent)
        try:
            os.fchmod(descriptor, 0o600)
            with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
                handle.write(payload)
                handle.flush()
                os.fsync(handle.fileno())
            os.replace(temporary, self.path)
        except Exception as exception:
            with contextlib.suppress(OSError):
                os.close(descriptor)
            with contextlib.suppress(OSError):
                os.unlink(temporary)
            if isinstance(exception, OSError):
                raise DrainError("state file could not be saved; the prior file was left unchanged") from exception
            raise


def process_alive(pid: int) -> bool:
    if pid <= 0:
        return False
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        return False
    except PermissionError:
        return True


def terminate_process(pid: int) -> None:
    try:
        os.killpg(pid, signal.SIGTERM)
    except ProcessLookupError:
        return


def memory_percent() -> float:
    meminfo = Path("/proc/meminfo")
    if meminfo.exists():
        values: dict[str, int] = {}
        for line in meminfo.read_text(encoding="ascii").splitlines():
            name, _, rest = line.partition(":")
            with contextlib.suppress(ValueError):
                values[name] = int(rest.strip().split()[0])
        if values.get("MemTotal") and values.get("MemAvailable") is not None:
            return 100.0 * (values["MemTotal"] - values["MemAvailable"]) / values["MemTotal"]

    try:
        total = int(subprocess.run(["sysctl", "-n", "hw.memsize"], check=True, capture_output=True, text=True).stdout.strip())
        output = subprocess.run(["vm_stat"], check=True, capture_output=True, text=True).stdout
        page_match = re.search(r"page size of (\d+) bytes", output)
        if not page_match:
            raise ValueError
        pages = 0
        for name in ("Pages free", "Pages inactive", "Pages speculative", "Pages purgeable"):
            match = re.search(rf"^{re.escape(name)}:\s+(\d+)\.", output, re.MULTILINE)
            if match:
                pages += int(match.group(1))
        return 100.0 * (total - pages * int(page_match.group(1))) / total
    except (OSError, subprocess.SubprocessError, ValueError, ZeroDivisionError):
        raise DrainError("memory admission could not be measured") from None


def safe_message(value: object, secrets: Sequence[str] = ()) -> str:
    message = str(value).replace("\n", " ").replace("\r", " ")
    for secret in secrets:
        if secret:
            message = message.replace(secret, "<redacted>")
    return SIGNED_URL_RE.sub("<redacted-url>", message)[:500]


class Launcher:
    def launch(self, argv: Sequence[str], cwd: Path, prompt: str) -> int:
        gate = cwd / "launch.ready"
        with contextlib.suppress(FileNotFoundError):
            gate.unlink()
        process = subprocess.Popen(
            [sys.executable, str(Path(__file__).resolve()), "--gated-exec", str(gate), "--", *argv],
            cwd=cwd,
            stdin=subprocess.PIPE,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            text=True,
            start_new_session=True,
            shell=False,
        )
        if process.stdin is None:
            raise DrainError("worker process did not accept stdin")
        try:
            process.stdin.write(prompt)
            process.stdin.close()
        except (BrokenPipeError, OSError) as exception:
            raise DrainError("worker process closed before receiving its prompt") from exception
        return process.pid


class DrainRunner:
    def __init__(
        self,
        config: Config,
        client: Any,
        *,
        logger: Callable[[str], None] = print,
        launcher: Any | None = None,
        alive: Callable[[int], bool] = process_alive,
        load: Callable[[], tuple[float, float, float]] = os.getloadavg,
        memory: Callable[[], float] = memory_percent,
        terminate: Callable[[int], None] = terminate_process,
    ) -> None:
        self.config = config
        self.client = client
        self.log = logger
        self.launcher = launcher or Launcher()
        self.alive = alive
        self.load = load
        self.memory = memory
        self.terminate = terminate
        self.store = StateStore(config.state_file)

    def run(self) -> int:
        try:
            items = self.store.load()
        except DrainError as exception:
            self.log(f"drain stopped: {safe_message(exception)}")
            return 1

        try:
            self._reconcile(items)
            self.store.save(items)
        except DrainError as exception:
            self.log(f"drain stopped: {safe_message(exception)}")
            return 1

        free_slots = self.config.max_concurrent - len(items)
        if free_slots <= 0:
            self.log(f"not this round: capacity full active={len(items)} max={self.config.max_concurrent}")
            return 0
        try:
            current_load = float(self.load()[0])
        except (OSError, IndexError, TypeError, ValueError):
            self.log("not this round: load admission could not be measured")
            return 0
        if current_load > self.config.max_load_1m:
            self.log(f"not this round: load_1m={current_load:.2f} ceiling={self.config.max_load_1m:.2f}")
            return 0
        try:
            current_memory = float(self.memory())
        except (DrainError, OSError, TypeError, ValueError):
            self.log("not this round: memory admission could not be measured")
            return 0
        if current_memory > self.config.max_memory_percent:
            self.log(f"not this round: memory_percent={current_memory:.1f} ceiling={self.config.max_memory_percent:.1f}")
            return 0

        try:
            claimed = self.client.claim(min(free_slots, MAX_BATCH), self.config.roles, self.config.capabilities)
        except DrainError as exception:
            self.log(f"claim failed: {safe_message(exception)}")
            return 1
        if not claimed:
            self.log("queue empty")
            return 0

        for claim in claimed[:free_slots]:
            self._start_claim(claim, items)
        try:
            self.store.save(items)
        except DrainError as exception:
            self.log(f"drain stopped: {safe_message(exception)}")
            return 1
        return 0

    def _reconcile(self, items: dict[str, dict[str, Any]]) -> None:
        valid: list[tuple[str, dict[str, Any], bool]] = []
        for item_id, entry in list(items.items()):
            if not self._valid_state_entry(item_id, entry):
                self.log(f"state entry ignored: item_id={safe_message(item_id)} invalid")
                continue
            valid.append((item_id, entry, self.alive(entry["pid"])))

        for offset in range(0, len(valid), MAX_BATCH):
            batch = valid[offset:offset + MAX_BATCH]
            requests = [{"item_id": item_id, "fence": entry["fence"]} for item_id, entry, _ in batch]
            try:
                results = self.client.renew(requests)
            except DrainError as exception:
                self.log(f"renew batch failed: {safe_message(exception)}")
                continue
            by_id = {result.get("item_id"): result for result in results if isinstance(result, dict)}
            for item_id, entry, is_alive in batch:
                result = by_id.get(item_id)
                if not isinstance(result, dict):
                    self.log(f"renew failed: item_id={item_id} missing result")
                    continue
                if result.get("status") == "lost":
                    if is_alive:
                        try:
                            self.terminate(entry["pid"])
                        except OSError as exception:
                            self.log(f"orphan stop failed: item_id={item_id} error={safe_message(exception)}")
                            continue
                    self._forget(item_id, entry, items)
                    continue
                if result.get("status") != "renewed" or not isinstance(result.get("item"), dict):
                    self.log(f"renew failed: item_id={item_id} invalid result")
                    continue
                self._apply_renewal(entry, result["item"])
                self.store.save(items)
                if is_alive:
                    self._ensure_routed(item_id, entry, items)
                else:
                    self._release_exited(item_id, entry, items)

    def _start_claim(self, claim: Any, items: dict[str, dict[str, Any]]) -> None:
        launched_pid: int | None = None
        try:
            item_id, task_id, role, fence, links = self._claim_fields(claim)
            item_dir = self.config.work_dir / f"{item_id}-f{fence}"
            item_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
            os.chmod(item_dir, 0o700)
            prompt_path = item_dir / "prompt.md"
            links_path = item_dir / "links.json"
            values = self._values(item_id, task_id, claim, links, item_dir, prompt_path, links_path)
            template_path = self.config.orchestrator_prompt_template if role == "orchestrator" else self.config.prompt_template
            template = template_path.read_text(encoding="utf-8")
            prompt = substitute([template], values, "prompt template")[0]
            self._write_private(links_path, json.dumps(links, indent=2, sort_keys=True) + "\n")
            self._write_private(prompt_path, prompt)
            values = {**values, "prompt": prompt}
            argv = substitute(self.config.role_commands[role], values, f"role command for {role!r}")
            pid = self.launcher.launch(argv, item_dir, prompt)
            launched_pid = pid
            entry: dict[str, Any] = {
                "task_id": task_id,
                "role": role,
                "fence": fence,
                "pid": pid,
                "phase": "launched",
                "work_dir": str(item_dir),
            }
            for key in ("session_ref", "seat_fence"):
                if key in claim:
                    entry[key] = claim[key]
            items[item_id] = entry
            self.store.save(items)
            self._ensure_routed(item_id, entry, items)
            self.log(f"launched item_id={item_id} task_id={task_id} role={role} pid={pid}")
        except (DrainError, ConfigError, OSError, KeyError, ValueError) as exception:
            item_id = claim.get("id") if isinstance(claim, dict) else None
            fence = claim.get("fence") if isinstance(claim, dict) else None
            self.log(f"launch failed: item_id={safe_message(item_id or 'unknown')} error={safe_message(exception)}")
            if launched_pid is not None:
                with contextlib.suppress(OSError):
                    self.terminate(launched_pid)
            if isinstance(item_id, str) and isinstance(fence, int):
                item_dir = self.config.work_dir / f"{item_id}-f{fence}"
                for name in ("prompt.md", "links.json", "launch.ready"):
                    with contextlib.suppress(OSError):
                        (item_dir / name).unlink()
                try:
                    self.client.transition(item_id, fence, "released")
                    items.pop(item_id, None)
                except DrainError as release_error:
                    self.log(f"release failed: item_id={safe_message(item_id)} error={safe_message(release_error)}")

    def _ensure_routed(self, item_id: str, entry: dict[str, Any], items: dict[str, dict[str, Any]]) -> None:
        for before, target in (("launched", "delivered"), ("delivered", "routed")):
            if entry.get("phase") != before:
                continue
            try:
                self.client.transition(item_id, entry["fence"], target)
            except DrainError as exception:
                self.log(f"transition failed: item_id={item_id} state={target} error={safe_message(exception)}")
                return
            entry["phase"] = target
            self.store.save(items)
        if entry.get("phase") == "routed":
            gate = Path(entry["work_dir"]) / "launch.ready"
            self._write_private(gate, "ready\n")

    def _release_exited(self, item_id: str, entry: dict[str, Any], items: dict[str, dict[str, Any]]) -> None:
        try:
            self.client.transition(item_id, entry["fence"], "released")
        except ApiError as exception:
            if exception.status == 409:
                self._forget(item_id, entry, items)
                return
            self.log(f"release failed: item_id={item_id} error={safe_message(exception)}")
            return
        except DrainError as exception:
            self.log(f"release failed: item_id={item_id} error={safe_message(exception)}")
            return
        self._forget(item_id, entry, items)
        self.log(f"released item_id={item_id} after worker exit")

    def _apply_renewal(self, entry: dict[str, Any], item: Mapping[str, Any]) -> None:
        for key in ("session_ref", "seat_fence"):
            if key in item:
                entry[key] = item[key]
        links = item.get("links")
        if isinstance(links, dict) and self._links_valid(entry.get("role"), links):
            links_path = Path(entry["work_dir"]) / "links.json"
            self._write_private(links_path, json.dumps(links, indent=2, sort_keys=True) + "\n")

    def _forget(self, item_id: str, entry: Mapping[str, Any], items: dict[str, dict[str, Any]]) -> None:
        work_dir = Path(str(entry.get("work_dir", "")))
        for name in ("prompt.md", "links.json", "launch.ready"):
            with contextlib.suppress(OSError):
                (work_dir / name).unlink()
        items.pop(item_id, None)
        self.store.save(items)

    def _claim_fields(self, claim: Any) -> tuple[str, str, str, int, dict[str, str]]:
        if not isinstance(claim, dict):
            raise DrainError("claim record is not an object")
        item_id, task_id, role, fence, links = (claim.get(key) for key in ("id", "task_id", "role", "fence", "links"))
        if not isinstance(item_id, str) or UUID_RE.fullmatch(item_id) is None:
            raise DrainError("claim has an invalid item id")
        if not isinstance(task_id, str) or UUID_RE.fullmatch(task_id) is None:
            raise DrainError("claim has an invalid task id")
        if role not in self.config.role_commands or not isinstance(fence, int) or isinstance(fence, bool) or fence < 1:
            raise DrainError("claim has an unsupported role or fence")
        if not isinstance(links, dict) or not self._links_valid(role, links):
            raise DrainError("claim has incomplete signed links")
        return item_id, task_id, role, fence, links

    def _links_valid(self, role: Any, links: Mapping[str, Any]) -> bool:
        required = REQUIRED_LINKS | (ORCHESTRATOR_LINKS if role == "orchestrator" else frozenset())
        return required <= set(links) and all(isinstance(links[key], str) and links[key].startswith(("http://", "https://")) for key in required)

    def _values(
        self,
        item_id: str,
        task_id: str,
        claim: Mapping[str, Any],
        links: Mapping[str, str],
        item_dir: Path,
        prompt_path: Path,
        links_path: Path,
    ) -> dict[str, str]:
        return {
            "brief_url": links.get("brief", ""),
            "config_dir": str(self.config.path.parent),
            "item_id": item_id,
            "links_file": str(links_path),
            "milestone_url": links.get("milestone", ""),
            "orchestrate_url": links.get("orchestrate", ""),
            "orchestration_url": links.get("orchestration", ""),
            "prompt": "",
            "prompt_file": str(prompt_path),
            "seat_fence": str(claim.get("seat_fence", "")),
            "session_ref": str(claim.get("session_ref", "")),
            "steers_url": links.get("steers", ""),
            "task_id": task_id,
            "work_dir": str(item_dir),
        }

    def _valid_state_entry(self, item_id: Any, entry: Any) -> bool:
        valid = (
            isinstance(item_id, str) and UUID_RE.fullmatch(item_id) is not None and isinstance(entry, dict)
            and isinstance(entry.get("fence"), int) and entry["fence"] > 0
            and isinstance(entry.get("pid"), int) and entry["pid"] > 0
            and entry.get("phase") in {"launched", "delivered", "routed"}
            and isinstance(entry.get("work_dir"), str) and isinstance(entry.get("role"), str)
            and isinstance(entry.get("task_id"), str)
        )
        if not valid:
            return False
        work_dir = Path(entry["work_dir"]).resolve()
        return work_dir.parent == self.config.work_dir.resolve() and work_dir.name == f"{item_id}-f{entry['fence']}"

    @staticmethod
    def _write_private(path: Path, content: str) -> None:
        descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(descriptor, "w", encoding="utf-8") as handle:
            handle.write(content)


def validate_setup(config: Config) -> None:
    read_credential(config.credential_file)
    for template in (config.prompt_template, config.orchestrator_prompt_template):
        try:
            content = template.read_text(encoding="utf-8")
        except OSError as exception:
            raise ConfigError("prompt template could not be read") from exception
        validate_template_fields([content], "prompt template")
    config.state_file.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    config.work_dir.mkdir(parents=True, exist_ok=True, mode=0o700)
    config.lock_file.parent.mkdir(parents=True, exist_ok=True, mode=0o700)


def execute(config_path: Path, *, dry_claim: bool = False, logger: Callable[[str], None] = print) -> int:
    try:
        config = load_config(config_path)
    except DrainError as exception:
        logger(f"configuration failed: {safe_message(exception)}")
        return 2
    with FileLock(config.lock_file) as lock:
        if not lock.acquire():
            logger("another drain run holds the lock; exiting successfully")
            return 0
        try:
            validate_setup(config)
            credential = read_credential(config.credential_file)
            client = ApiClient(config, credential)
            if dry_claim:
                client.claim(0, config.roles, config.capabilities)
                logger("dry claim succeeded with capacity=0; no item was claimed")
                return 0
            return DrainRunner(config, client, logger=logger).run()
        except DrainError as exception:
            logger(f"drain stopped: {safe_message(exception)}")
            return 1


def parse_args(argv: Sequence[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--config", type=Path, required=True, help="path to the local JSON config")
    parser.add_argument("--check-config", action="store_true", help="validate local files without making an HTTP request")
    parser.add_argument("--dry-claim", action="store_true", help="authenticate with a capacity-zero claim")
    return parser.parse_args(argv)


def gated_exec(argv: Sequence[str]) -> int:
    if len(argv) < 3 or argv[1] != "--":
        return 2
    gate = Path(argv[0])
    command = list(argv[2:])
    while not gate.exists():
        time.sleep(0.05)
    try:
        os.execvp(command[0], command)
    except OSError:
        return 127


def main(argv: Sequence[str] | None = None) -> int:
    arguments = list(sys.argv[1:] if argv is None else argv)
    if arguments[:1] == ["--gated-exec"]:
        return gated_exec(arguments[1:])
    args = parse_args(arguments)
    if args.check_config:
        try:
            config = load_config(args.config)
            validate_setup(config)
            print("configuration is valid; no HTTP request was made")
            return 0
        except DrainError as exception:
            print(f"configuration failed: {safe_message(exception)}", file=sys.stderr)
            return 2
    return execute(args.config, dry_claim=args.dry_claim)


if __name__ == "__main__":
    raise SystemExit(main())
