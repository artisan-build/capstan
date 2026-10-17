#!/usr/bin/env python3
"""Run fresh drain processes repeatedly for coordinator-owned driven checks."""

from __future__ import annotations

import argparse
import subprocess
import sys
import time
from pathlib import Path


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--config", required=True, type=Path)
    parser.add_argument("--iterations", type=int, default=6)
    parser.add_argument("--interval", type=float, default=1.0)
    args = parser.parse_args()
    if args.iterations < 1 or args.interval < 0:
        parser.error("iterations must be positive and interval must not be negative")
    drain = Path(__file__).resolve().parents[1] / "capstan_drain.py"
    for index in range(args.iterations):
        result = subprocess.run([sys.executable, str(drain), "--config", str(args.config)], check=False)
        if result.returncode != 0:
            return result.returncode
        if index + 1 < args.iterations:
            time.sleep(args.interval)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
