---
name: capstan-work-drain
description: Onboard a machine to Capstan Work with the standard-library reference drain, local JSON config, a bound credential file, and an optional cron or launchd schedule.
---

# Onboard a Capstan Work drain

Use this skill when a user asks to connect a machine to Capstan Work or install the reference drain. Work
inside the user's chosen private config directory. Do not put a bearer in the repository, a command line,
an environment variable, terminal output, or this report.

Do not install or modify cron or launchd during repository development, tests, a config check, or a dry
claim. Scheduler installation is the final onboarding action when the user has asked to install the drain
on this machine. Show the exact entry or plist and its paths before applying it. Never touch another
project's scheduler entry.

## 1. Inspect the machine

Find the repository root and confirm `drain/capstan_drain.py` exists. Run `python3 --version`, then detect
the supported harnesses with `command -v claude`, `command -v opencode`, and any generic command the user
named. Do not run a harness yet. Record only command names and whether they were found; do not dump its
configuration or environment.

Use the matching checked-in example.

- `drain/examples/claude.json` for `claude -p`;
- `drain/examples/opencode.json` for `opencode run --standalone -f {prompt_file}`;
- `drain/examples/generic.json` for an argv-based local worker or the AC10 stub.

Do not use `opencode mini`; it needs a TTY and cannot run under a detached drain.

If several harnesses are available, ask which roles should use each one. Keep `role_commands` values as
JSON argv lists. Reject shell strings, redirections, pipes, `sh -c`, and unknown placeholders.

## 2. Write the private config

Create a private directory with mode `0700`, copy the selected example to `config.json`, and edit its
Capstan URL, team, numeric actor id, capacity, load and memory ceilings, capabilities, roles, paths, and
role commands. Use absolute paths for anything a scheduler will read. Keep a separate work directory,
state file, and lock file for each config.

Relative paths resolve from the config file's directory. The examples point back into the repository
relative to `drain/examples`, so a copy in the private directory must repoint these to absolute paths:

- `prompt_template` -> `/absolute/path/to/drain/worker-prompt.md`;
- `orchestrator_prompt_template` -> `/absolute/path/to/drain/orchestrator-prompt.md`;
- `generic.json` only: the `role_commands.builder` script `{config_dir}/../harness/stub_worker.py` ->
  `/absolute/path/to/drain/harness/stub_worker.py`.

`--check-config` reports a missing prompt template but does not check a script path inside a role command;
a wrong one fails only when a worker launches. The `./var` paths are meant to resolve inside the private
directory and need no change.

Use the machine identity installed from `/postmaster` under **Connect a local agent**. If it is missing,
stop and ask the user to generate the installer in a signed-in browser, run it on this target machine, and
approve the waiting request in that browser. The approving person becomes the machine owner. Do not create
a separate Work credential or send the user to a credential page.

Set `credential_file` to `~/.config/capstan/<server-id>/token`. Read the adjacent `actor-id` file without
printing it into the report, and copy its numeric contents as the quoted JSON scalar `actor_id` in the config.
Do not copy the bearer itself. Verify both installed files exist and inspect their permission bits without
printing their contents; both must be mode `0600` or stricter. The server rejects an actor id that does not
belong to the installed `capstan.machine` credential.

## 3. Validate without HTTP

Run:

```sh
python3 /absolute/path/to/drain/capstan_drain.py \
  --config /absolute/path/to/config.json \
  --check-config
```

This checks the JSON schema, argv placeholders, prompt templates, local paths, bearer shape, and
credential permissions. It makes no HTTP request and starts no worker. Fix every reported error before
continuing.

## 4. Make one dry claim

Run:

```sh
python3 /absolute/path/to/drain/capstan_drain.py \
  --config /absolute/path/to/config.json \
  --dry-claim
```

The command calls the real team-scoped claim endpoint with capacity `0`. It authenticates the bearer and
actor header and validates the URL, team, and endpoint without taking an item. A 404 usually
means the URL or team is wrong. A 401 means the credential, purpose, actor binding, or product
admission is wrong. Report the status and safe error code only. Never include the bearer or a signed URL.

## 5. Prepare the scheduler

Use one scheduler, not both. Redirect the reference script's secret-free operational messages to a file
inside the private config directory, and arrange normal log rotation outside this skill.

For cron, preserve the existing crontab before changing it and add one tagged line like this, with all
paths made absolute:

```cron
* * * * * /usr/bin/python3 /absolute/path/to/drain/capstan_drain.py --config /absolute/private/config.json >> /absolute/private/drain.log 2>&1 # capstan-work-drain
```

Read the current crontab first. If it cannot be read for a reason other than "no crontab", stop without
writing. Refuse to add a second line with the same tag. Save the readable prior crontab beside the config
before installing the combined file.

On macOS, prefer a user launch agent at `~/Library/LaunchAgents/build.artisan.capstan-work-drain.plist`.
Its `ProgramArguments` array contains the Python path, script path, `--config`, and config path as separate
strings. Set `StartInterval` to `60`, set `StandardOutPath` and `StandardErrorPath` inside the private
directory, and do not put the bearer in the plist. Validate it with `plutil -lint`, then bootstrap it into
the current user's `gui/<uid>` domain. Do not unload, overwrite, or rename an unrelated agent.

Show the cron line or complete plist and the backup path before installation. If the user asked only for
validation or a dry claim, stop here and do not install anything.

## 6. Verify and report

After installation, trigger one normal run or wait for one scheduled interval. An empty queue should log
`queue empty`. A busy host should log `not this round` and make no claim. Do not create a real task unless
the user asked for an end-to-end check; if they did, use a test-created task and item.

Report the config path, selected harness for each role, detected capabilities, credential-file path and
permission result, local validation result, dry-claim result, scheduler type and installed path, and the
first run result. State clearly when the scheduler was not installed. Redact bearer values and every URL
containing a signature or other capability query.

For operational recovery, point the user to `docs/work.md`. Its optional session-reuse section covers the
existing Solo MCP, Amp/Puck, and opaque `session_ref` wiring pattern. Do not delete a state file or PID
entry until the corresponding process, item, and fence have been checked.
