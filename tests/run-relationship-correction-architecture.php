<?php
/** Structural acceptance for 1.6.5 relationship correction + privacy contract. */
$root=dirname(__DIR__);
$files=array(
 'correction'=>'src/Application/CredentialRelationshipCorrectionService.php',
 'bulk'=>'src/Application/CredentialRelationshipBulkService.php',
 'contract'=>'src/Application/Contract/CredentialRelationshipRepositoryInterface.php',
 'repo'=>'src/Infrastructure/Repository/CredentialRepository.php',
 'event'=>'src/Infrastructure/Repository/EventRepository.php',
 'wp'=>'tests/wp-integration/run-domain-schema.php',
 'privacy'=>'docs/privacy-data-lifecycle-contract.md',
 'bootstrap'=>'certloom.php',
);
$s=array();foreach($files as $k=>$f){$s[$k]=file_get_contents($root.'/'.$f);if(!is_string($s[$k])||$s[$k]==='')throw new RuntimeException('missing '.$f);} $checks=0;
function rca($c,$m){global $checks;if(!$c)throw new RuntimeException($m);$checks++;} function rch($s,$n,$m){rca(strpos($s,$n)!==false,$m);}
rch($s['correction'],'find_series_for_update','Correction must lock Series.');
rch($s['correction'],'expected_updated_at','Correction must reject stale reviews.');
rch($s['correction'],'find_version_for_update','Current Version must be locked/validated.');
rch($s['correction'],"'previous_version_id' => absint( \$current['id'] )",'Correction must preserve Version lineage.');
rch($s['correction'],"'display_number'      => (string) \$current['display_number']",'Correction must preserve human certificate number.');
rch($s['correction'],"'source_type'         => 'relationship_correction'",'Correction Version must identify its source.');
rch($s['correction'],"['__certloom_relationship']",'Correction evidence must use a reserved internal snapshot namespace.');
rch($s['correction'],'set_relationships(','Series relationship update must be narrow repository command.');
rch($s['correction'],'set_current_version(','Current Version pointer must advance atomically.');
rch($s['correction'],"'credential.relationship_corrected'",'Correction must append dedicated audit Event.');
rch($s['correction'],'return $this->transaction->run','Correction must be transaction-backed.');
rch($s['repo'],'validate_relationship_target','Repository must re-prove target relationships.');
rch($s['repo'],'certloom_relationship_batch_program_mismatch','Batch/Program mismatch must fail closed.');
rch($s['repo'],'certloom_relationship_conflict','Series relationship write must compare-and-swap.');
rch($s['repo'],'program_id IS NULL','Nullable relationship CAS must use SQL NULL semantics.');
rch($s['repo'],'batch_id IS NULL','Nullable Batch CAS must use SQL NULL semantics.');
rch($s['bulk'],'const MAX_SELECTED = 100','Bulk correction must be bounded.');
rch($s['bulk'],'expected_updated_at','Bulk correction must carry reviewed concurrency state.');
rch($s['bulk'],'CredentialRelationshipCorrectionService','Bulk must delegate to single-record correction command.');
rca(strpos($s['bulk'],'UPDATE credential_series')===false,'Bulk service must not raw-update credential_series.');
rch($s['bulk'],'hash_hmac','Bulk review must use keyed authenticity protection.');
rch($s['bulk'],"wp_salt( 'auth' )",'Bulk review HMAC must use a WordPress secret.');
rch($s['bulk'],'const REVIEW_TTL   = 900','Bulk reviews must expire.');
rch($s['bulk'],'owner_user_id','Bulk review may bind to initiating administrator.');
rch($s['wp'],'CredentialRelationshipCorrectionService','Real DB gate must exercise correction service.');
rch($s['wp'],'certloom_relationship_review_stale','Real DB gate must prove stale review rejection.');
rch($s['privacy'],'Never silently rewrite/delete through a generic erasure callback','Privacy contract must protect immutable Versions.');
rch($s['privacy'],'items_retained','Privacy contract must require truthful retention reporting.');
rch($s['privacy'],'PRV-02','Privacy status must remain explicit.');
rch($s['bootstrap'],"CERTLOOM_DOMAIN_WRITE_PATHS_READY', false",'Production domain writes must remain gated.');
rch($s['bootstrap'],"CERTLOOM_DOMAIN_ADMIN_PREVIEW', false",'Production domain admin must remain gated.');
rch($s['bootstrap'],"CERTLOOM_DOMAIN_DATA_TOOLS_READY', false",'Production domain tools must remain gated.');
echo "PASS  $checks relationship-correction/privacy architecture checks\n";
