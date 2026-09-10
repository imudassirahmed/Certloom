<?php
/** Static regression guard: semantic restore review must independently use the bounded reader and reverify integrity. */
$root=dirname(__DIR__);
$source=file_get_contents($root.'/src/Application/Restore/DomainRestorePreflightService.php');
if(false===$source)throw new RuntimeException('Could not read restore preflight source.');
if(false===strpos($source,'$this->format->read_line( $handle, $line_no )'))throw new RuntimeException('Semantic restore review no longer uses the bounded backup-line reader.');
if(false!==strpos($source,'fgets('))throw new RuntimeException('Semantic restore review reintroduced an unbounded raw fgets path.');
if(false===strpos($source,"'certloom_domain_restore_preflight_changed'"))throw new RuntimeException('Semantic restore review no longer compares its independently recomputed chain to the first integrity pass.');
if(false===strpos($source,"'certloom_domain_backup_integrity_failed'"))throw new RuntimeException('Semantic restore review no longer verifies the integrity footer itself.');
echo "PASS  semantic restore preflight is independently bounded and integrity-bound on its second pass\n\n4 checks, 0 failures\n";
