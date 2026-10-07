<?php
/** Behavioral public profile layout, status, privacy and uploaded-byte contracts. */
if ( PHP_SAPI !== 'cli' ) { http_response_code( 404 ); exit; }
require __DIR__ . '/bootstrap-autoload.php';
function __( $v ) { return $v; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $v ) ); }
function sanitize_html_class( $v ) { return sanitize_key( $v ); }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }
function absint( $v ) { return abs( (int) $v ); }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html( $v ) { return esc_attr( $v ); }
function esc_attr__( $v ) { return esc_attr( $v ); }
function esc_html__( $v ) { return esc_html( $v ); }
function esc_url( $v ) { return (string) $v; }
function wp_unique_id( $p = '' ) { static $n=0; return $p . ++$n; }
function get_option( $k, $d=false ) { return 'permalink_structure' === $k ? '/%postname%/' : $d; }
function home_url( $p='' ) { return 'https://example.test' . $p; }
function current_time( $v ) { return '2026-10-01 00:00:00'; }
function wp_date( $format, $timestamp ) { return (new DateTimeImmutable('@'.$timestamp))->setTimezone(new DateTimeZone('Asia/Karachi'))->format('Y-m-d H:i'); }
function is_rtl() { return ! empty( $GLOBALS['profile_test_rtl'] ); }
function is_wp_error( $v ) { return $v instanceof WP_Error; }
class Ecapla_CertVerify_Settings { public static $values=array('certificate_layout'=>'right','certificate_center_width'=>960); public static function get($k,$d=''){return self::$values[$k]??$d;} }
class Ecapla_CertVerify_Fields { public static function get_field_map(){return array();} }
$image_path=tempnam(sys_get_temp_dir(),'certloom-profile-image-');
function get_attached_file($id,$raw){global $image_path; return $image_path;}
function get_post_mime_type($id){return 'image/png';}
require dirname(__DIR__) . '/includes/class-ecapla-certverify-render.php';
use Certloom\Application\VerificationResult;
use Certloom\Domain\Credential\StatusResult;
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Application\Rendering\LayoutValidator;
use Certloom\Application\Rendering\LayoutAssetManifest;
use Certloom\Infrastructure\Rendering\WordPressLocalAssetResolver;
$checks=0;
function layout_check($ok,$label){global $checks;$checks++;if(!$ok){throw new RuntimeException($label);}echo "PASS  $label\n";}
$json=new CanonicalJson();$layout=(new LayoutValidator())->normalize(json_decode(file_get_contents(__DIR__.'/fixtures/rendering/english.json'),true));
$merge=array('recipient.name'=>'Public learner');
$snapshot=array('recipient'=>array('display_name'=>'Public learner'),'credential'=>array('title'=>'Public credential','verification_url'=>'https://example.test/credential'),'template'=>array('layout'=>$layout,'layout_sha256'=>$json->hash($layout)),'merge'=>array('values'=>$merge,'sha256'=>$json->hash($merge)));
$id=(new Certloom\Domain\Credential\PublicCredentialId())->generate();
$row=array('visibility'=>'public','display_number'=>'CERT-QA','issued_at'=>'2026-09-07','snapshot'=>$snapshot);
$status=new StatusResult(true,'valid','all_requirements_met','issued','active','current','not_applicable');
function profile_projection($row,$status){global $id;return Ecapla_CertVerify_Render::native_profile_presentation(new VerificationResult(true,$id,'found',$row,$status));}
$verified_at=profile_projection($row,$status)['verified_at'];
layout_check(preg_match('/T\d{2}:\d{2}:\d{2}\+00:00$/',$verified_at)&&abs(strtotime($verified_at)-time())<=2,'native verification timestamps identify the actual UTC instant');
$format_verified=new ReflectionMethod(Ecapla_CertVerify_Render::class,'format_verified_at');
layout_check($format_verified->invoke(null,$verified_at)===gmdate('Y-m-d H:i',strtotime($verified_at)+5*3600),'site timezone is applied once when displaying verification time');
foreach(array('right','left','center') as $position){
 Ecapla_CertVerify_Settings::$values['certificate_layout']=$position;
 $html=Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($row,$status));
 layout_check(strpos($html,'cvp-profile-layout--'.$position)!==false,'global '.$position.' applies to a verified designed certificate');
 $art=strpos($html,'class="cvp-profile-certificate"');$info=strpos($html,'class="cvp-profile-information"');
 layout_check($position==='right'?$info<$art:$art<$info,$position.' keeps the DOM reading order aligned with its visible order');
}
$GLOBALS['profile_test_rtl']=true;
$html=Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($row,$status));
layout_check(strpos($html,'class="cvp-profile-information" dir="rtl"')!==false,'RTL interface copy keeps its reading direction within the chosen physical certificate layout');
$GLOBALS['profile_test_rtl']=false;
$cached=array('profile_html'=>Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($row,$status)),'presentation_mode'=>'design');
$inline=new ReflectionMethod(Ecapla_CertVerify_Render::class,'result_card_html');
$first=$inline->invoke(null,$cached,'cv');$second=$inline->invoke(null,$cached,'cvx');
preg_match_all('/\sid="([^"]+)"/',$first,$first_ids);preg_match_all('/\sid="([^"]+)"/',$second,$second_ids);
layout_check(count($first_ids[1])>0&&count($first_ids[1])===count($second_ids[1])&&!array_intersect($first_ids[1],$second_ids[1]),'cached server-rendered profile results receive distinct accessible IDs without JavaScript');
$references_valid=true;foreach(array($first,$second) as $card){preg_match_all('/aria-labelledby="([^"]+)"/',$card,$references);foreach($references[1] as $reference){foreach(explode(' ',$reference) as $id_reference){$references_valid=$references_valid&&strpos($card,'id="'.$id_reference.'"')!==false;}}}
layout_check($references_valid,'server-rendered certificate headings remain correctly referenced after cached result reuse');
$restricted=$row;$restricted['visibility']='verification_summary';$p=profile_projection($restricted,$status);$html=Ecapla_CertVerify_Render::normalized_profile_card_html($p);
layout_check(empty($p['snapshot'])&&strpos($html,'Public learner')===false&&strpos($html,'certloom-template-preview-page')===false,'summary view cannot acquire recipient or certificate artwork');
foreach(array('private','recipient_only','organization_member') as $visibility){$restricted['visibility']=$visibility;layout_check(!profile_projection($restricted,$status)['found'],$visibility.' stays non-enumerating in the shared profile projection');}
$revoked=new StatusResult(false,'revoked','issuer_revoked','issued','revoked','current','not_applicable');
$html=Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($row,$revoked));
layout_check(strpos($html,'Revoked credential')!==false&&strpos($html,'cvp-status-revoked')!==false&&strpos($html,'Valid credential')===false,'new layout keeps authoritative revoked status instead of inferring validity from artwork');
$details=$row;$details['snapshot']['template']=array();$html=Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($details,$status));
layout_check(strpos($html,'cvp-profile-layout--details')!==false&&strpos($html,'certloom-template-preview-page')===false,'details-only record has a readable information layout without empty certificate space');
try{
 $image=imagecreatetruecolor(2,2);imagefill($image,0,0,0x112233);imagepng($image,$image_path);
 $uploaded=$row;$uploaded['snapshot']['presentation']=array('mode'=>'image','certificate_image_id'=>7);
 $uploaded['snapshot']['media']=(new LayoutAssetManifest())->capture_image('attachment:7',new WordPressLocalAssetResolver());
 $html=Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($uploaded,$status));
 layout_check(strpos($html,'data:image/png;base64,')!==false&&strpos($html,'cvp-certificate-image')!==false,'public uploaded image uses the proven issued bytes rather than a mutable attachment URL');
 imagefill($image,0,0,0x445566);imagepng($image,$image_path);clearstatcache(true,$image_path);
 $html=Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($uploaded,$status));
 layout_check(strpos($html,'data:image/png;base64,')===false&&strpos($html,'Certificate image unavailable')!==false,'replacement uploaded bytes are refused while credential details remain available');
 unset($uploaded['snapshot']['media']);$html=Ecapla_CertVerify_Render::normalized_profile_card_html(profile_projection($uploaded,$status));
 layout_check(strpos($html,'data:image/png;base64,')===false,'historical image ID without byte provenance cannot authorize current media');
}finally{if(is_file($image_path)){unlink($image_path);}}
$snippet='<div id="lookup"><label for="number">Number</label><input id="number" aria-controls="result" /><div id="result" aria-labelledby="lookup"></div></div>';
$scoped=Ecapla_CertVerify_Render::scoped_markup_html($snippet,'preview-unique');
layout_check(strpos($scoped,'id="preview-unique-number"')!==false&&strpos($scoped,'for="preview-unique-number"')!==false&&strpos($scoped,'aria-controls="preview-unique-result"')!==false&&strpos($scoped,'aria-labelledby="preview-unique-lookup"')!==false,'independent editor preview markup scopes IDs together with labels and accessible references');
layout_check(Ecapla_CertVerify_Render::scoped_markup_html('<div>No identifiers</div>','preview')==='<div>No identifiers</div>','scoping leaves markup without identifiers unchanged');
$svg='<svg><defs><clipPath id="clip"><rect /></clipPath></defs><use href="#clip" xlink:href="#clip" clip-path="url(#clip)" /><a href="#external">Other</a></svg>';
$svg_scoped=Ecapla_CertVerify_Render::scoped_markup_html($svg,'preview-art');
layout_check(strpos($svg_scoped,'id="preview-art-clip"')!==false&&strpos($svg_scoped,'href="#preview-art-clip"')!==false&&strpos($svg_scoped,'xlink:href="#preview-art-clip"')!==false&&strpos($svg_scoped,'url(#preview-art-clip)')!==false&&strpos($svg_scoped,'href="#external"')!==false,'scoped certificate artwork preserves SVG references and leaves outside fragments unchanged');
echo "$checks checks, 0 failures\n";
