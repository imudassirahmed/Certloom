<?php
/** 1.6.24 behavioral regression for candidate-to-frozen-golden comparison. */
$script=__DIR__.'/renderer-candidate/test-structural-parity.py';
if(!is_file($script)){throw new RuntimeException('Structural parity regression script is missing.');}
$cmd='python3 '.escapeshellarg($script).' 2>&1';
$output=array();$status=0;exec($cmd,$output,$status);
if(0!==$status){throw new RuntimeException("Structural parity regression failed:\n".implode("\n",$output));}
$verifier=file_get_contents(__DIR__.'/renderer-candidate/verify-bundle.py');
$checks=0;function rgp($c,$m){global $checks;$checks++;if(!$c){throw new RuntimeException($m);}}
rgp(false!==strpos($verifier,'GOLDEN_MIN_PRECISION = 0.92')&&false!==strpos($verifier,'GOLDEN_MIN_RECALL = 0.92'),'Structural thresholds must stay explicit and conservative.');
rgp(false!==strpos($verifier,"'pdf_png': structural_metric(pdf_rgb, png_rgb")&&false!==strpos($verifier,"'pdf': structural_metric(pdf_rgb, golden_rgb[name]['png']")&&false!==strpos($verifier,'current-oracle-v2'),'Verifier must require PDF/PNG foreground structure and compare every candidate format to the versioned current oracle.');
rgp(false!==strpos(implode("\n",$output),'PASS'),'Behavioral structural-parity script must prove rejection/tolerance semantics.');
echo "PASS  Candidate output must satisfy current-oracle structure and PDF/PNG foreground parity\n";
echo "\n{$checks} checks, 0 failures\n";
