#!/usr/bin/env python3
from importlib.util import spec_from_file_location, module_from_spec
from pathlib import Path
import numpy as np
from PIL import Image

here = Path(__file__).resolve().parent
spec = spec_from_file_location('certloom_verify_bundle', here / 'verify-bundle.py')
mod = module_from_spec(spec)
spec.loader.exec_module(mod)
golden = np.asarray(Image.open(here.parent / 'fixtures/rendering/reference/english/reference.png').convert('RGB'))

metric = mod.structural_metric(golden.copy(), golden, 'identical fixture')
assert metric['foreground_precision'] == 1.0 and metric['foreground_recall'] == 1.0

blank = np.full_like(golden, 255)
try:
    mod.structural_metric(blank, golden, 'blank candidate')
except RuntimeError:
    pass
else:
    raise AssertionError('blank candidate unexpectedly satisfied structural parity')

# A one-pixel rasterization offset is intentionally tolerated by the 2px structural dilation.
shift = np.full_like(golden, 255)
shift[:, 1:] = golden[:, :-1]
metric = mod.structural_metric(shift, golden, 'one-pixel shift')
assert metric['foreground_precision'] >= mod.GOLDEN_MIN_PRECISION
assert metric['foreground_recall'] >= mod.GOLDEN_MIN_RECALL

# Whole-page MAE is intentionally retained as one signal, but mostly-white pages can
# hide a gross foreground displacement. Prove the added foreground gate catches it.
displaced = np.full_like(golden, 255)
displaced[:, 40:] = golden[:, :-40]
mod.cross_format_metric(displaced, golden, 'mostly-white whole-page blind-spot fixture')
try:
    mod.structural_metric(displaced, golden, 'foreground-displaced fixture')
except RuntimeError:
    pass
else:
    raise AssertionError('foreground structural parity failed to catch a gross displacement that whole-page MAE accepted')

print('PASS  structural metric rejects blank/displaced rendering, tolerates bounded drift, and closes the whole-page MAE blind spot')
