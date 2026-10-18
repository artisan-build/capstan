#!/usr/bin/env python3
"""AC10 stub worker: read a drain prompt and post started, then done."""

from __future__ import annotations

import json
import re
import sys
import urllib.request


def post(url: str, kind: str) -> None:
    body = json.dumps({"kind": kind, "payload": {"summary": f"AC10 stub posted {kind}"}}).encode()
    request = urllib.request.Request(url, data=body, headers={"Content-Type": "application/json", "User-Agent": "capstan-drain/1"}, method="POST")
    with urllib.request.urlopen(request, timeout=15) as response:
        if response.status != 201:
            raise RuntimeError(f"milestone returned HTTP {response.status}")


def main() -> int:
    prompt = sys.stdin.read()
    match = re.search(r"Post a `started` milestone to\s*`([^`]+)`", prompt)
    if match is None:
        print("stub prompt did not contain a milestone link", file=sys.stderr)
        return 2
    post(match.group(1), "started")
    post(match.group(1), "done")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
