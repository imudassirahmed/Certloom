#!/usr/bin/env python3
from importlib.util import spec_from_file_location, module_from_spec
from pathlib import Path
import numpy as np
from PIL import Image

here=Path(__file__).resolve().parent
spec=spec_from_file_location('certloom_verify_bundle',here/'verify-bundle.py')
mod=module_from_spec(spec); spec.loader.exec_module(mod)
golden=np.asarray(Image.open(here.parent/'fixtures/rendering/reference/english/reference.png').convert('RGB'))
metric=mod.golden_structural_metric(golden.copy(),golden,'identical fixture')
assert metric['foreground_precision']==1.0 and metric['foreground_recall']==1.0
blank=np.full_like(golden,255)
try:
    mod.golden_structural_metric(blank,golden,'blank candidate')
except RuntimeError:
    pass
else:
    raise AssertionError('blank candidate unexpectedly satisfied frozen-golden structural parity')
# A one-pixel rasterization offset is intentionally tolerated by the 2px structural dilation.
shift=np.full_like(golden,255); shift[:,1:]=golden[:,:-1]
metric=mod.golden_structural_metric(shift,golden,'one-pixel shift')
assert metric['foreground_precision']>=mod.GOLDEN_MIN_PRECISION and metric['foreground_recall']>=mod.GOLDEN_MIN_RECALL
print('PASS  structural golden metric rejects blank/wrong rendering while tolerating bounded rasterization drift')
