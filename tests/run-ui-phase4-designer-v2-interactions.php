<?php
/** Phase 4 Gamma-02R Studio interaction contract. */

if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
$root=dirname(__DIR__);$php=file_get_contents($root.'/src/Infrastructure/Admin/TemplateAdmin.php');$js=file_get_contents($root.'/admin/assets/designer.js');$css=file_get_contents($root.'/admin/assets/designer.css');
$c=0;function si($ok,$m){global$c;$c++;if(!$ok){fwrite(STDERR,"FAIL  $m\n");exit(1);}}function sih($h,$n,$m){si(false!==strpos($h,$n),$m);}function si_not($h,$n,$m){si(false===strpos($h,$n),$m);}
sih($php,'Undo (Ctrl/Cmd+Z)','Undo tooltip documents shortcut');sih($php,'Redo (Ctrl/Cmd+Shift+Z)','Redo tooltip documents shortcut');sih($php,'Restore last saved state','Restore is explicit and separate from history');
sih($js,'restoreConfirm','Restore warns before discarding unsaved work');
sih($js,"'+' === key || '=' === key",'keyboard zoom in is implemented');sih($js,"'-' === key",'keyboard zoom out is implemented');sih($js,"'0' === key",'keyboard fitted-100% is implemented');si_not($js,"'1' === key",'legacy physical 1:1 shortcut is removed from revised fit semantics');
sih($js,'relativeZoomPercent()','zoom percentage is relative to fitted 100%');sih($js,'setZoomLevel( relativeZoomPercent() + 10','toolbar/keyboard zoom share relative scale');
sih($js,"( 'Delete' === key || 'Backspace' === key )",'Delete/Backspace removes selected unlocked layers');sih($js,"'d' === lower",'Ctrl/Cmd+D duplicate remains');sih($js,"'c' === lower",'Ctrl/Cmd+C internal copy remains');sih($js,"'x' === lower",'Ctrl/Cmd+X internal cut remains');sih($js,"'v' === lower",'Ctrl/Cmd+V internal paste remains');
sih($js,'nudgeSelected','arrow-key nudge remains');sih($js,'event.shiftKey ? 5','Shift+Arrow uses 5 mm step');sih($js,'event.altKey ? 0.1','Alt/Option+Arrow uses 0.1 mm step');sih($js,'spacePanHeld','Space+drag pan remains');
sih($js,'renderSelectionToolbar','selection toolbar is rendered directly over canvas');foreach(array("'duplicate'","'lock'","'delete'","'more'" ) as $a){sih($js,$a,'selection quick action '.$a.' remains');}
sih($js,'startMarqueeSelection','marquee multi-select remains');sih($js,'distributeSelection','multi-selection distribute engine remains');
sih($php,'Test print','Test print is the only proof action');sih($js,'window.print()','Test print uses browser print surface without creating a production artifact');sih($css,'.certloom-studio-selection-toolbar','on-canvas toolbar has dedicated polished styling');
echo "PASS  Phase 4 Gamma-02R Studio interactions\n\n$c checks, 0 failures\n";
