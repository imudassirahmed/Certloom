"""Run the standalone PHP/JS suites without shell interpolation or source copies."""
from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parent.parent
EXCLUDED = {
    "run-authorized-release-packaging.php": "Creates full plugin copies; run separately in an explicitly permitted packaging workspace.",
    "run-domain-authorized-packaging.php": "Creates full plugin copies; run separately in an explicitly permitted packaging workspace.",
}


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("suites", nargs="*", help="Exact filenames under tests; default: all standalone PHP and JS suites")
    parser.add_argument("--php", action="append", help="PHP executable; repeat for each supported runtime")
    parser.add_argument("--ini", action="append", help="CLI ini path; one shared path or one per --php")
    parser.add_argument("--node", default="node", help="Node executable for standalone JavaScript suites")
    parser.add_argument("--output", type=Path, help="Fresh evidence directory; default: output/test-runs/<UTC timestamp>")
    parser.add_argument("--timeout", type=int, default=120, help="Maximum seconds per suite")
    args = parser.parse_args()
    if args.timeout < 1:
        parser.error("--timeout must be positive")
    php = args.php or ["php"]
    ini = args.ini or []
    if len(ini) not in (0, 1, len(php)):
        parser.error("provide one --ini or one per --php")
    known = {p.name: p for suffix in ("php", "js") for p in (ROOT / "tests").glob("run-*." + suffix)}
    suites = args.suites or sorted(known)
    if len(set(suites)) != len(suites) or any(name not in known for name in suites):
        parser.error("suite names must be unique exact standalone filenames under tests")
    if any(name in EXCLUDED for name in args.suites):
        parser.error("source-copy packaging suites must run separately; see tests/README.md")
    runtimes = []
    for index, executable in enumerate(php):
        resolved = shutil.which(executable)
        if not resolved:
            parser.error("PHP executable unavailable: " + executable)
        command = [resolved]
        if ini:
            config = Path(ini[0 if len(ini) == 1 else index]).resolve()
            if not config.is_file():
                parser.error("CLI ini unavailable: " + str(config))
            command += ["-c", str(config)]
        version = subprocess.run(command + ["-r", "echo PHP_VERSION;"], cwd=ROOT, capture_output=True, timeout=15)
        if version.returncode:
            parser.error("PHP preflight failed: " + executable)
        runtimes.append(("php-" + str(index + 1), version.stdout.decode(errors="replace").strip(), command))
    node = shutil.which(args.node)
    if any(name.endswith(".js") for name in suites) and not node:
        parser.error("Node executable unavailable: " + args.node)
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    output = (args.output or ROOT / "output" / "test-runs" / stamp).resolve()
    if output.exists():
        parser.error("evidence directory must be fresh: " + str(output))
    output.mkdir(parents=True)
    environment = os.environ.copy()
    environment.setdefault("CERTLOOM_TEST_PYTHON", sys.executable)
    environment.setdefault("SOURCE_DATE_EPOCH", "1787356800")
    # Standalone fixtures must not inherit access to a real WordPress database.
    environment.pop("CERTLOOM_TEST_WP_ROOT", None)
    environment.pop("CERTLOOM_TEST_DB_BACKUP", None)
    results = []
    for runtime_id, version, command in runtimes:
        for name in suites:
            if name.endswith(".js") and runtime_id != "php-1":
                continue
            row = {"suite": name, "runtime": version if name.endswith(".php") else "Node"}
            if name in EXCLUDED:
                row.update(status="excluded", reason=EXCLUDED[name])
                results.append(row)
                continue
            process_command = command + [str(known[name])] if name.endswith(".php") else [node, str(known[name])]
            process_environment = environment.copy()
            process_environment["PATH"] = str(Path(command[0]).parent) + os.pathsep + environment.get("PATH", "")
            if len(command) > 1:
                process_environment["PHPRC"] = command[2]
            started = time.monotonic()
            try:
                done = subprocess.run(process_command, cwd=ROOT, env=process_environment, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=args.timeout)
                code, body = done.returncode, done.stdout
            except subprocess.TimeoutExpired as error:
                code, body = 124, (error.stdout or b"") + b"\nSUITE TIMEOUT\n"
            except OSError as error:
                code, body = 126, str(error).encode()
            transcript = body.decode("utf-8", errors="replace")
            log = runtime_id + "-" + name + ".log"
            (output / log).write_text(transcript, encoding="utf-8")
            row.update(status="passed" if code == 0 else "failed", exit=code, seconds=round(time.monotonic() - started, 2), log=log,
                       has_skips=bool(re.search(r"(?m)^\s*\[?SKIP(?:PED)?(?:\]|:|\s)|\b[1-9]\d* (?:platform )?(?:checks )?skips?\b", transcript, re.I)),
                       has_diagnostics=bool(re.search(r"(?m)^(?:PHP )?(?:Warning|Deprecated|Notice):", transcript)))
            results.append(row)
            (output / "results.json").write_text(json.dumps(results, indent=2) + "\n", encoding="utf-8")
            if code or len(results) % 40 == 0:
                print(row["status"].upper(), row["runtime"], name, flush=True)
    totals = {status: sum(row["status"] == status for row in results) for status in ("passed", "failed", "excluded")}
    totals.update(skip_bearing_runs=sum(row.get("has_skips", False) for row in results), diagnostic_runs=sum(row.get("has_diagnostics", False) for row in results))
    (output / "totals.json").write_text(json.dumps(totals, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(totals), "\nEvidence:", output, flush=True)
    return 1 if totals["failed"] else 0


if __name__ == "__main__":
    raise SystemExit(main())
