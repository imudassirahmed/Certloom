<?php
namespace Certloom\Application\Email;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Rendering\CanonicalJson;
use Certloom\Infrastructure\Email\EmailMergeTokenCatalog;

/**
 * Deterministic credential-email composition from an immutable Version snapshot.
 * Templates never query current Recipient/Program/Template rows after issuance.
 */
final class EmailTemplateService {
	const TEMPLATE_SCHEMA = 'certloom-email-template';
	const TEMPLATE_VERSION = 1;
	const MAX_STATIC_ATTACHMENTS = 5;
	const MAX_ATTACHMENT_BYTES = 10485760; // 10 MiB each.
	const MAX_ATTACHMENTS_TOTAL_BYTES = 20971520; // 20 MiB total.

	/** @var CanonicalJson */
	private $json;

	public function __construct( ?CanonicalJson $json = null ) { $this->json = $json ?: new CanonicalJson(); }

	/** @return array<string,mixed> */
	public static function defaults() {
		return array(
			'schema' => self::TEMPLATE_SCHEMA,
			'schema_version' => self::TEMPLATE_VERSION,
			'subject' => __( 'Your {{program_title}} credential', 'certloom' ),
			'preheader' => __( 'Your credential is ready to view and verify.', 'certloom' ),
			'html_body' => '<p>' . esc_html__( 'Hello {{recipient_name}},', 'certloom' ) . '</p>'
				. '<p>' . esc_html__( 'Your credential for {{program_title}} has been issued.', 'certloom' ) . '</p>'
				. '<p><strong>' . esc_html__( 'Certificate number:', 'certloom' ) . '</strong> {{credential_number}}</p>'
				. '<p>{{verification_link}}</p>',
			'text_body' => __( "Hello {{recipient_name}},\n\nYour credential for {{program_title}} has been issued.\nCertificate number: {{credential_number}}\n{{verification_url}}", 'certloom' ),
		);
	}

	/** @return array<int,string> */
	public static function tokens() {
		if ( class_exists( EmailMergeTokenCatalog::class ) ) { return array_keys( ( new EmailMergeTokenCatalog() )->flat() ); }
		return array( 'recipient_name', 'program_title', 'issuer_name', 'credential_number', 'issue_date', 'expiry_date', 'grade', 'verification_url', 'verification_link' );
	}

	/** @return array<string,mixed>|\WP_Error */
	public function compose( array $snapshot, array $template, $verification_authoritative ) {
		$valid = $this->validate_snapshot( $snapshot );
		if ( is_wp_error( $valid ) ) { return $valid; }
		$template = $this->normalize_template( $template );
		if ( is_wp_error( $template ) ) { return $template; }

		$credential = (array) $snapshot['credential'];
		$plain = $this->scalar_merge_values( $snapshot );
		$plain = $this->merge_legacy_fallbacks( $plain, $snapshot );

		$url = $verification_authoritative
			? trim( (string) ( $plain['verification.url'] ?? $plain['verification_url'] ?? $credential['verification_url'] ?? '' ) )
			: '';
		$plain['verification.url'] = $url;
		$plain['verification_url'] = $url;
		$plain['verification_link'] = $url;

		$link_html = '' !== $url
			? '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Verify this credential', 'certloom' ) . '</a>'
			: '<span>' . esc_html__( 'Online verification is not available for this credential yet.', 'certloom' ) . '</span>';

		$html_values = array();
		foreach ( $plain as $key => $value ) { $html_values[ $key ] = esc_html( (string) $value ); }
		// A URL token must remain a URL string so it works inside href attributes.
		$html_values['verification.url'] = esc_url( $url );
		$html_values['verification_url'] = esc_url( $url );
		$html_values['verification_link'] = $link_html;

		$subject = sanitize_text_field( wp_strip_all_tags( $this->replace( (string) $template['subject'], $plain ) ) );
		$preheader = sanitize_text_field( wp_strip_all_tags( $this->replace( (string) ( $template['preheader'] ?? '' ), $plain ) ) );
		$html_body = wp_kses_post( $this->replace( (string) $template['html_body'], $html_values ) );
		if ( '' !== $preheader ) { $html_body = '<div style="display:none!important;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . esc_html( $preheader ) . '</div>' . $html_body; }
		$text_body = trim( wp_strip_all_tags( $this->replace( (string) $template['text_body'], $plain ) ) );

		if ( preg_match( '/\{\{[a-z0-9_.:-]+\}\}/i', $subject . "\n" . $html_body . "\n" . $text_body ) ) {
			return new \WP_Error( 'certloom_email_template_token_unknown', __( 'The credential email template contains a merge token that is not present in this issued credential snapshot.', 'certloom' ) );
		}
		if ( '' === $subject || '' === trim( $html_body ) || '' === $text_body ) {
			return new \WP_Error( 'certloom_email_template_empty', __( 'The credential email template must produce a subject, HTML body, and plain-text fallback.', 'certloom' ) );
		}

		$attachments = $this->resolve_static_attachments( $template['attachments'] ?? array() );
		if ( is_wp_error( $attachments ) ) { return $attachments; }

		try {
			$fingerprint = $this->json->hash( array(
				'template_schema' => self::TEMPLATE_SCHEMA,
				'template_version' => self::TEMPLATE_VERSION,
				'subject' => $subject,
				'html_body' => $html_body,
				'text_body' => $text_body,
				'credential_version' => absint( $credential['version_id'] ?? 0 ),
				'static_attachments' => $attachments['evidence'],
			) );
		} catch ( \Throwable $error ) {
			return new \WP_Error( 'certloom_email_fingerprint_failed', __( 'Certloom could not fingerprint the composed credential email.', 'certloom' ) );
		}

		return array(
			'subject' => $subject,
			'preheader' => $preheader,
			'html_body' => $html_body,
			'text_body' => $text_body,
			'subject_sha256' => hash( 'sha256', $subject ),
			'html_sha256' => hash( 'sha256', $html_body ),
			'text_sha256' => hash( 'sha256', $text_body ),
			'composition_sha256' => $fingerprint,
			'verification_authoritative' => (bool) $verification_authoritative,
			'verification_url' => $url,
			'attachment_status' => count( $attachments['paths'] ) ? ( $this->renderer_authoritative() ? 'static_ready_pdf_available_later_contract' : 'static_ready_renderer_authority_gated' ) : ( $this->renderer_authoritative() ? 'pdf_available_later_contract' : 'renderer_authority_gated' ),
			'attachments' => $attachments['paths'],
			'static_attachment_count' => count( $attachments['paths'] ),
			'static_attachment_sha256' => hash( 'sha256', wp_json_encode( $attachments['evidence'] ) ?: '[]' ),
		);
	}

	/** @return array<string,mixed>|\WP_Error */
	public function normalize_template( array $template ) {
		$template = array_merge( self::defaults(), $template );
		$subject = trim( sanitize_text_field( (string) $template['subject'] ) );
		$preheader = trim( sanitize_text_field( (string) ( $template['preheader'] ?? '' ) ) );
		$html = wp_kses_post( (string) $template['html_body'] );
		$text = sanitize_textarea_field( (string) ( $template['text_body'] ?? '' ) );
		if ( '' === trim( $text ) && '' !== trim( $html ) ) {
			$plain_html = preg_replace_callback(
				'/<a\b[^>]*href=(?:"([^"]+)"|\'([^\']+)\')[^>]*>(.*?)<\/a>/is',
				static function ( $match ) { $url=html_entity_decode((string)($match[1]?:$match[2]),ENT_QUOTES|ENT_HTML5,get_bloginfo('charset')?:'UTF-8');$label=trim(wp_strip_all_tags((string)$match[3]));return ''!==$label?$label.' — '.$url:$url; },
				$html
			);
			$plain_html = str_ireplace( array( '<br>', '<br/>', '<br />', '</p>', '</div>', '</tr>' ), "\n", (string) $plain_html );
			$text = html_entity_decode( wp_strip_all_tags( $plain_html, true ), ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
			$text = preg_replace( '/[ \t]+/', ' ', (string) $text );
			$text = trim( (string) preg_replace( '/\n{3,}/', "\n\n", (string) $text ) );
		}
		if ( '' === $subject || $this->text_length( $subject ) > 200 ) { return new \WP_Error( 'certloom_email_subject_invalid', __( 'Email subject must contain between 1 and 200 characters.', 'certloom' ) ); }
		if ( '' === trim( $html ) || strlen( $html ) > 100000 ) { return new \WP_Error( 'certloom_email_html_invalid', __( 'Email HTML body must contain between 1 and 100,000 bytes.', 'certloom' ) ); }
		if ( $this->text_length( $preheader ) > 180 ) { return new \WP_Error( 'certloom_email_preheader_invalid', __( 'Email preheader must be 180 characters or fewer.', 'certloom' ) ); }
		if ( '' === trim( $text ) || strlen( $text ) > 50000 ) { return new \WP_Error( 'certloom_email_text_invalid', __( 'Email plain-text body must contain between 1 and 50,000 bytes.', 'certloom' ) ); }
		return array(
			'schema'=>self::TEMPLATE_SCHEMA,'schema_version'=>self::TEMPLATE_VERSION,'subject'=>$subject,'preheader'=>$preheader,'html_body'=>$html,'text_body'=>$text,
			'attachments'=>$this->normalize_attachment_ids($template['attachments']??array()),
		);
	}

	/** @return true|\WP_Error */
	private function validate_snapshot( array $snapshot ) {
		if ( 'certloom-credential-snapshot' !== (string) ( $snapshot['_meta']['schema'] ?? '' ) || empty( $snapshot['recipient'] ) || empty( $snapshot['program'] ) || empty( $snapshot['credential'] ) ) { return new \WP_Error( 'certloom_email_snapshot_invalid', __( 'Email Delivery requires an immutable Certloom credential snapshot.', 'certloom' ) ); }
		if ( 'issued' !== (string) ( $snapshot['credential']['issuance_state'] ?? '' ) ) { return new \WP_Error( 'certloom_email_snapshot_not_issued', __( 'Only an issued credential Version can be delivered by email.', 'certloom' ) ); }
		return true;
	}

	/** @return array<string,string> */
	private function scalar_merge_values( array $snapshot ) {
		$out=array(); $values=is_array($snapshot['merge']['values']??null)?$snapshot['merge']['values']:array();
		foreach($values as $key=>$value){if(!is_string($key)||''===$key||is_array($value)||is_object($value)||is_resource($value)){continue;}$out[$key]=null===$value?'':(is_bool($value)?($value?'1':'0'):(string)$value);}
		return $out;
	}

	/** @return array<string,string> */
	private function merge_legacy_fallbacks( array $plain, array $snapshot ) {
		$recipient=(array)($snapshot['recipient']??array());$program=(array)($snapshot['program']??array());$issuer=is_array($snapshot['issuer']??null)?$snapshot['issuer']:array();$signer=is_array($snapshot['signer']??null)?$snapshot['signer']:array();$batch=is_array($snapshot['batch']??null)?$snapshot['batch']:array();$credential=(array)($snapshot['credential']??array());
		$fallbacks=array(
			'recipient.name'=>(string)($recipient['display_name']??''),
			'recipient.first_name'=>(string)($recipient['first_name']??''),
			'recipient.middle_name'=>(string)($recipient['middle_name']??''),
			'recipient.last_name'=>(string)($recipient['last_name']??''),
			'recipient.email'=>(string)($recipient['email']??''),
			'recipient.external_id'=>(string)($recipient['external_id']??''),
			'program.title'=>(string)($program['title']??$program['name']??''),
			'program.name'=>(string)($program['title']??$program['name']??''),
			'program.code'=>(string)($program['code']??''),
			'batch.name'=>(string)($batch['name']??''),
			'batch.code'=>(string)($batch['code']??''),
			'issuer.name'=>(string)($issuer['name']??(function_exists('get_bloginfo')?get_bloginfo('name'):'')),
			'issuer.title'=>(string)($issuer['title']??''),
			'signer.name'=>(string)($signer['display_name']??$signer['name']??''),
			'signer.title'=>(string)($signer['title']??''),
			'signer.email'=>(string)($signer['email']??''),
			'credential.display_number'=>(string)($credential['display_number']??''),
			'credential.title'=>(string)($credential['title']??''),
			'credential.public_id'=>(string)($credential['public_id']??''),
			'credential.issue_date'=>(string)($credential['valid_from']??$credential['issued_at']??''),
			'credential.expiry_date'=>(string)($credential['expires_at']??''),
			'credential.status'=>(string)($credential['status']??'issued'),
			'credential.grade'=>(string)($credential['grade']??''),
			'score'=>(string)($credential['score']??''),
			'site_name'=>function_exists('get_bloginfo')?(string)get_bloginfo('name'):'',
			'verification.url'=>(string)($credential['verification_url']??''),
		);
		foreach($fallbacks as $key=>$value){if(!array_key_exists($key,$plain)){$plain[$key]=$value;}}
		$aliases=array(
			'recipient_name'=>'recipient.name','first_name'=>'recipient.first_name','middle_name'=>'recipient.middle_name','last_name'=>'recipient.last_name',
			'credential_number'=>'credential.display_number','certificate_number'=>'credential.display_number','credential_title'=>'credential.title','certificate_status'=>'credential.status',
			'program_title'=>'program.title','program_name'=>'program.name','course_name'=>'program.name',
			'batch_name'=>'batch.name','batch_code'=>'batch.code','cohort_name'=>'batch.name',
			'issuer_name'=>'issuer.name','issuer_title'=>'issuer.title','organization'=>'issuer.name',
			'signer_name'=>'signer.name','signer_title'=>'signer.title',
			'issue_date'=>'credential.issue_date','expiry_date'=>'credential.expiry_date','grade'=>'credential.grade','verification_url'=>'verification.url',
		);
		foreach($aliases as $alias=>$canonical){if(!array_key_exists($alias,$plain)){$plain[$alias]=(string)($plain[$canonical]??'');}}
		return $plain;
	}

	/** @param array<string,string> $values */
	private function replace( $template, array $values ) {
		return preg_replace_callback('/\{\{([a-z0-9_.:-]+)\}\}/i',static function($match)use($values){$key=(string)$match[1];return array_key_exists($key,$values)?(string)$values[$key]:(string)$match[0];},(string)$template);
	}

	/** @return array<int,int> */
	private function normalize_attachment_ids( $ids ) {
		if(is_string($ids)){ $decoded=json_decode($ids,true); $ids=is_array($decoded)?$decoded:array(); }
		$ids=is_array($ids)?$ids:array();$out=array();foreach($ids as $id){$id=absint($id);if($id&&!in_array($id,$out,true)){$out[]=$id;}if(count($out)>=self::MAX_STATIC_ATTACHMENTS){break;}}return $out;
	}

	/** @return array<int,int>|\WP_Error */
	public function validate_static_attachment_ids( $ids ) {
		$normalized = $this->normalize_attachment_ids( $ids );
		if ( ! $normalized ) { return array(); }
		$resolved = $this->resolve_static_attachments( $normalized );
		return is_wp_error( $resolved ) ? $resolved : $normalized;
	}

	/** @return array{paths:array<int,string>,evidence:array<int,array<string,mixed>>}|\WP_Error */
	private function resolve_static_attachments( $ids ) {
		$ids=$this->normalize_attachment_ids($ids); if(!$ids){return array('paths'=>array(),'evidence'=>array());}
		if(!function_exists('get_attached_file')){return new \WP_Error('certloom_email_attachment_runtime_missing',__( 'Certloom cannot resolve static email attachments on this runtime.', 'certloom' ));}
		$blocked=array('php','php3','php4','php5','phtml','phar','exe','com','bat','cmd','sh','bash','js','jar','msi','scr','vbs','ps1');$paths=array();$evidence=array();$total=0;
		foreach($ids as $id){$path=(string)get_attached_file($id,true);if(''===$path||!is_file($path)||!is_readable($path)||is_link($path)){return new \WP_Error('certloom_email_attachment_unavailable',__( 'One of this email design’s static attachments is unavailable or unsafe. Re-select it in Email Studio.', 'certloom' ));}$ext=strtolower((string)pathinfo($path,PATHINFO_EXTENSION));if(''===$ext||in_array($ext,$blocked,true)){return new \WP_Error('certloom_email_attachment_type_blocked',__( 'One of this email design’s static attachments uses a blocked file type.', 'certloom' ));}$size=(int)filesize($path);if($size<1||$size>self::MAX_ATTACHMENT_BYTES){return new \WP_Error('certloom_email_attachment_size',__( 'Each static email attachment must be no larger than 10 MB.', 'certloom' ));}$total+=$size;if($total>self::MAX_ATTACHMENTS_TOTAL_BYTES){return new \WP_Error('certloom_email_attachments_total_size',__( 'Static email attachments must total no more than 20 MB.', 'certloom' ));}$sha=hash_file('sha256',$path);if(false===$sha){return new \WP_Error('certloom_email_attachment_hash_failed',__( 'Certloom could not fingerprint a static email attachment.', 'certloom' ));}$paths[]=$path;$evidence[]=array('attachment_id'=>$id,'bytes'=>$size,'sha256'=>$sha,'basename'=>sanitize_file_name(basename($path)));}
		return array('paths'=>$paths,'evidence'=>$evidence);
	}

	private function renderer_authoritative() { return defined('CERTLOOM_PATH')&&is_file(rtrim(CERTLOOM_PATH,'/\\').'/resources/renderer/accepted.json'); }
	private function text_length( $value ) { return function_exists('mb_strlen')?mb_strlen((string)$value):strlen((string)$value); }
}
