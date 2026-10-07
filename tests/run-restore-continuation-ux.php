<?php
/** UX-C03 — restore continuation must never be an invisible/queued dead end. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root=dirname(__DIR__);$view=file_get_contents($root.'/admin/views/backup-progress.php');$js=file_get_contents($root.'/admin/assets/admin.js');$css=file_get_contents($root.'/admin/assets/admin.css');$pass=0;$fail=0;
function rcx($ok,$label){global $pass,$fail;if($ok){$pass++;echo "PASS  $label\n";}else{$fail++;echo "FAIL  $label\n";}}
rcx(false!==strpos($view,'data-certverify-continue-button')&&false!==strpos($view,"esc_html_e( 'Continue restore', 'certloom' )"),'restore progress always exposes a manual Continue restore action');
rcx(false!==strpos($view,'data-certverify-auto-continue-status')&&false!==strpos($view,'If this page does not move forward'),'restore progress explains the automatic/manual fallback');
rcx(false===strpos($view,'<noscript>'),'manual recovery is not hidden behind noscript-only markup');
rcx(false!==strpos($js,'function initRestoreAutoContinue()')&&false!==strpos($js,'window.setTimeout( submitRestore, 700 )'),'restore automatically advances after the progress screen loads');
rcx(false!==strpos($js,"This restore is still on this step. Use Continue restore to retry safely."),'stalled browser continuation becomes an actionable manual state');
rcx(false!==strpos($js,"form.addEventListener( 'submit'")&&false!==strpos($js,'button.disabled = true'),'double-submit is guarded while one continuation is in flight');
rcx(false!==strpos($css,'.certloom-restore-continuation')&&false!==strpos($css,'min-height:44px'),'restore fallback action remains accessible and responsive');
echo "\n$pass UX-C03 checks, $fail failures\n";exit($fail?1:0);
