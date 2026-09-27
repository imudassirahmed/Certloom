#!/usr/bin/env python3
"""Regression proof for cross-extraction Certloom POT determinism.

The test intentionally replays the exact source tree through an adversarial
`os.walk()` that yields directories/files in reverse encounter order. Generated
POT bytes must still equal the normal walk and the committed catalog.
"""
from __future__ import annotations

import importlib.util
import os
import sys
import tempfile
from pathlib import Path

EPOCH = "1787356800"


def load_generator(path: Path):
    spec = importlib.util.spec_from_file_location("certloom_make_pot", path)
    if spec is None or spec.loader is None:
        raise RuntimeError("Unable to import make-pot.py")
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def invoke(module, root: Path, output: Path, walk_override=None) -> None:
    old_argv = sys.argv[:]
    old_epoch = os.environ.get("SOURCE_DATE_EPOCH")
    old_walk = module.os.walk
    try:
        os.environ["SOURCE_DATE_EPOCH"] = EPOCH
        if walk_override is not None:
            module.os.walk = walk_override
        sys.argv = [str(Path(module.__file__)), str(root), "-o", str(output)]
        rc = module.main()
        if rc != 0:
            raise RuntimeError(f"make-pot.py returned {rc}")
    finally:
        sys.argv = old_argv
        module.os.walk = old_walk
        if old_epoch is None:
            os.environ.pop("SOURCE_DATE_EPOCH", None)
        else:
            os.environ["SOURCE_DATE_EPOCH"] = old_epoch


def main() -> int:
    if len(sys.argv) != 2:
        print("usage: check_make_pot_determinism.py /path/to/certloom", file=sys.stderr)
        return 2

    root = Path(sys.argv[1]).resolve()
    generator = root / "tests" / "i18n" / "make-pot.py"
    committed = root / "languages" / "certloom.pot"
    if not generator.is_file() or not committed.is_file():
        print("required generator/catalog missing", file=sys.stderr)
        return 2

    module = load_generator(generator)
    native_walk = module.os.walk

    # Pre-materialize then reverse traversal to simulate a filesystem/extractor
    # whose directory encounter order differs from the release host. Because
    # this wrapper yields a completed sequence, in-place dirnames sorting alone
    # cannot save the output; references and final entry ordering must also be
    # canonicalized by the generator.
    def adversarial_walk(top):
        rows = []
        for dirpath, dirnames, filenames in native_walk(top):
            # Mirror production pruning before materializing the traversal,
            # then deliberately scramble the yielded encounter order.
            dirnames[:] = [d for d in dirnames if d not in module.EXCLUDED_DIRS]
            rows.append((dirpath, list(reversed(dirnames)), list(reversed(filenames))))
        for row in reversed(rows):
            yield row

    with tempfile.TemporaryDirectory(prefix="certloom-pot-det-") as temp:
        normal = Path(temp) / "normal.pot"
        reverse = Path(temp) / "reverse.pot"
        invoke(module, root, normal)
        invoke(module, root, reverse, adversarial_walk)
        normal_bytes = normal.read_bytes()
        reverse_bytes = reverse.read_bytes()
        committed_bytes = committed.read_bytes()

        # Generated local evidence and dependency/build trees are not shipped
        # source. A checkout's ignored files must not change its catalog.
        fixture = Path(temp) / "catalog-fixture"
        fixture.mkdir()
        (fixture / "source.php").write_text("<?php __('Production catalog marker', 'certloom');", encoding="utf-8")
        for ignored in ("output", "build", "vendor", "qa-evidence", "reports", ".playwright-cli"):
            directory = fixture / ignored
            directory.mkdir()
            (directory / "artifact.php").write_text("<?php __('Ignored artifact marker', 'certloom');", encoding="utf-8")
        fixture_pot = Path(temp) / "fixture.pot"
        invoke(module, fixture, fixture_pot)
        fixture_bytes = fixture_pot.read_bytes()

        checks = [
            (normal_bytes == reverse_bytes, "normal/adversarial traversal POT bytes differ"),
            (normal_bytes == committed_bytes, "committed POT is not reproducible from the exact source tree"),
            (b"POT-Creation-Date: 2026-08-22 00:00+0000" in normal_bytes, "fixed release epoch missing"),
            (b"\r\n" not in normal_bytes, "catalog line endings depend on the host operating system"),
            (b"Production catalog marker" in fixture_bytes and b"Ignored artifact marker" not in fixture_bytes, "ignored build/evidence/dependency files leaked into the catalog"),
        ]
        for ok, message in checks:
            if not ok:
                print(message, file=sys.stderr)
                return 1

    print("POT cross-extraction determinism: 5/5 checks passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
