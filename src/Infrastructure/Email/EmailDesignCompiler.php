<?php
namespace Certloom\Infrastructure\Email;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Server-side compiler for Certloom's structured email builder.
 *
 * The builder stores semantic blocks and bounded style values. Delivery always
 * uses server-compiled HTML/text, never browser-authored executable markup.
 */
final class EmailDesignCompiler {
	const MAX_BLOCKS = 40;

	/** @return array<int,string> */
	private static function allowed_fonts() {
		return array(
			'Arial, Helvetica, sans-serif',
			'Georgia, Times New Roman, serif',
			'Verdana, Geneva, sans-serif',
			'Trebuchet MS, Arial, sans-serif',
		);
	}

	/** @return array<string,mixed> */
	public static function default_theme() {
		return array(
			'background'              => '#f4f7f6',
			'background_image'        => '',
			'background_size'         => 'cover',
			'background_position'     => 'center',
			'background_repeat'       => 'no-repeat',
			'content_background'      => '#ffffff',
			'content_background_image'=> '',
			'content_background_size' => 'cover',
			'content_background_position' => 'center',
			'content_background_repeat'   => 'no-repeat',
			'text_color'              => '#24322e',
			'heading_color'           => '#173f34',
			'button_background'       => '#0f6e56',
			'button_text'             => '#ffffff',
			'link_color'              => '#0f6e56',
			'font_family'             => 'Arial, Helvetica, sans-serif',
			'email_width'             => 640,
			'border_radius'           => 12,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public static function default_blocks() {
		return array(
			array( 'type' => 'heading', 'text' => __( 'Your credential is ready', 'certloom' ), 'level' => 1 ),
			array( 'type' => 'text', 'html' => '<p>' . esc_html__( 'Hello {{recipient_name}},', 'certloom' ) . '</p><p>' . esc_html__( 'Your credential for {{program_title}} has been issued.', 'certloom' ) . '</p>' ),
			array( 'type' => 'button', 'text' => __( 'View credential', 'certloom' ), 'url' => '{{verification_url}}', 'align' => 'left' ),
			array( 'type' => 'divider' ),
			array(
				'type' => 'columns',
				'count' => 2,
				'ratio' => '50-50',
				'gap' => 20,
				'columns' => array(
					array( 'kind' => 'text', 'html' => '<p><strong>' . esc_html__( 'Credential', 'certloom' ) . '</strong><br>{{credential_number}}</p>' ),
					array( 'kind' => 'text', 'html' => '<p><strong>' . esc_html__( 'Issuer', 'certloom' ) . '</strong><br>{{issuer_name}}</p>' ),
				),
			),
		);
	}

	/** @return array<string,mixed> */
	public function normalize_theme( $theme ) {
		$theme = is_array( $theme ) ? $theme : array();
		$out   = self::default_theme();
		foreach ( array( 'background', 'content_background', 'text_color', 'heading_color', 'button_background', 'button_text', 'link_color' ) as $key ) {
			if ( isset( $theme[ $key ] ) ) {
				$hex = sanitize_hex_color( (string) $theme[ $key ] );
				if ( $hex ) { $out[ $key ] = $hex; }
			}
		}
		foreach ( array( 'background_image', 'content_background_image' ) as $key ) {
			if ( isset( $theme[ $key ] ) ) { $out[ $key ] = esc_url_raw( (string) $theme[ $key ] ); }
		}
		foreach ( array( 'background_size', 'content_background_size' ) as $key ) {
			$value = sanitize_key( (string) ( $theme[ $key ] ?? $out[ $key ] ) );
			if ( in_array( $value, array( 'auto', 'cover', 'contain' ), true ) ) { $out[ $key ] = $value; }
		}
		foreach ( array( 'background_position', 'content_background_position' ) as $key ) {
			$value = sanitize_key( (string) ( $theme[ $key ] ?? $out[ $key ] ) );
			if ( in_array( $value, array( 'center', 'top', 'bottom', 'left', 'right' ), true ) ) { $out[ $key ] = $value; }
		}
		foreach ( array( 'background_repeat', 'content_background_repeat' ) as $key ) {
			$value = sanitize_key( (string) ( $theme[ $key ] ?? $out[ $key ] ) );
			if ( in_array( $value, array( 'no-repeat', 'repeat', 'repeat-x', 'repeat-y' ), true ) ) { $out[ $key ] = $value; }
		}
		$font = isset( $theme['font_family'] ) ? (string) $theme['font_family'] : '';
		if ( in_array( $font, self::allowed_fonts(), true ) ) { $out['font_family'] = $font; }
		$out['email_width']   = max( 480, min( 760, absint( $theme['email_width'] ?? $out['email_width'] ) ) );
		$out['border_radius'] = max( 0, min( 32, absint( $theme['border_radius'] ?? $out['border_radius'] ) ) );
		return $out;
	}

	/** @return array<string,mixed> */
	private function sanitize_block_style( $block, $type ) {
		$style = is_array( $block['style'] ?? null ) ? $block['style'] : array();
		$out   = array();
		$font = isset( $style['font_family'] ) ? (string) $style['font_family'] : '';
		if ( in_array( $font, self::allowed_fonts(), true ) ) { $out['font_family'] = $font; }
		$size_bounds = 'heading' === $type ? array( 12, 72 ) : ( 'button' === $type ? array( 10, 28 ) : array( 10, 32 ) );
		if ( isset( $style['font_size'] ) && '' !== (string) $style['font_size'] ) { $out['font_size'] = max( $size_bounds[0], min( $size_bounds[1], absint( $style['font_size'] ) ) ); }
		if ( isset( $style['font_weight'] ) && '' !== (string) $style['font_weight'] ) {
			$weight = absint( $style['font_weight'] );
			if ( in_array( $weight, array( 400, 500, 600, 700, 800, 900 ), true ) ) { $out['font_weight'] = $weight; }
		}
		if ( ! empty( $style['italic'] ) ) { $out['italic'] = true; }
		if ( ! empty( $style['underline'] ) ) { $out['underline'] = true; }
		foreach ( array( 'color', 'background_color' ) as $key ) {
			if ( isset( $style[ $key ] ) ) { $hex = sanitize_hex_color( (string) $style[ $key ] ); if ( $hex ) { $out[ $key ] = $hex; } }
		}
		if ( in_array( $type, array( 'heading', 'text' ), true ) ) {
			$align = (string) ( $style['align'] ?? '' );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) { $out['align'] = $align; }
			if ( isset( $style['line_height'] ) && is_numeric( $style['line_height'] ) ) { $out['line_height'] = max( 1.0, min( 2.5, round( (float) $style['line_height'], 2 ) ) ); }
		}
		if ( 'button' === $type ) {
			if ( isset( $style['border_radius'] ) ) { $out['border_radius'] = max( 0, min( 32, absint( $style['border_radius'] ) ) ); }
			if ( isset( $style['padding_x'] ) ) { $out['padding_x'] = max( 8, min( 40, absint( $style['padding_x'] ) ) ); }
			if ( isset( $style['padding_y'] ) ) { $out['padding_y'] = max( 6, min( 24, absint( $style['padding_y'] ) ) ); }
		}
		if ( 'image' === $type ) {
			$align = (string) ( $style['align'] ?? '' );
			if ( in_array( $align, array( 'left', 'center', 'right' ), true ) ) { $out['align'] = $align; }
			if ( isset( $style['width'] ) ) { $out['width'] = max( 10, min( 100, absint( $style['width'] ) ) ); }
			if ( isset( $style['border_radius'] ) ) { $out['border_radius'] = max( 0, min( 32, absint( $style['border_radius'] ) ) ); }
		}
		if ( 'divider' === $type ) {
			if ( isset( $style['color'] ) ) { $hex = sanitize_hex_color( (string) $style['color'] ); if ( $hex ) { $out['color'] = $hex; } }
			if ( isset( $style['width'] ) ) { $out['width'] = max( 10, min( 100, absint( $style['width'] ) ) ); }
			if ( isset( $style['thickness'] ) ) { $out['thickness'] = max( 1, min( 8, absint( $style['thickness'] ) ) ); }
			if ( isset( $style['margin_y'] ) ) { $out['margin_y'] = max( 4, min( 60, absint( $style['margin_y'] ) ) ); }
		}
		return $out;
	}

	/** @return string */
	public function sanitize_custom_html( $html ) {
		$html = (string) $html;
		if ( function_exists( 'wp_kses' ) ) {
			$common = array( 'style' => true, 'align' => true, 'valign' => true, 'width' => true, 'height' => true, 'title' => true );
			$allowed = array(
				'table' => $common + array( 'role' => true, 'cellspacing' => true, 'cellpadding' => true, 'border' => true, 'bgcolor' => true ),
				'thead' => $common, 'tbody' => $common, 'tfoot' => $common, 'tr' => $common,
				'td' => $common + array( 'colspan' => true, 'rowspan' => true, 'bgcolor' => true ), 'th' => $common + array( 'colspan' => true, 'rowspan' => true, 'scope' => true, 'bgcolor' => true ),
				'div' => $common, 'span' => $common, 'p' => $common,
				'h1' => $common, 'h2' => $common, 'h3' => $common, 'h4' => $common,
				'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 's' => array(),
				'br' => array(), 'hr' => $common,
				'ul' => $common, 'ol' => $common, 'li' => $common,
				'a' => array( 'href' => true, 'target' => true, 'rel' => true, 'title' => true, 'style' => true ),
				'img' => array( 'src' => true, 'alt' => true, 'title' => true, 'width' => true, 'height' => true, 'style' => true, 'align' => true, 'border' => true ),
			);
			return wp_kses( $html, $allowed );
		}
		return wp_kses_post( $html );
	}

	/** @return array<string,mixed> */
	private function normalize_column( $column ) {
		$column = is_array( $column ) ? $column : array();
		$kind = sanitize_key( (string) ( $column['kind'] ?? 'text' ) );
		if ( ! in_array( $kind, array( 'text', 'image', 'html' ), true ) ) { $kind = 'text'; }
		if ( 'image' === $kind ) {
			return array(
				'kind' => 'image',
				'url' => esc_url_raw( (string) ( $column['url'] ?? '' ) ),
				'alt' => sanitize_text_field( (string) ( $column['alt'] ?? '' ) ),
				'link' => $this->sanitize_url_or_token( $column['link'] ?? '' ),
				'width' => max( 10, min( 100, absint( $column['width'] ?? 100 ) ) ),
				'align' => in_array( (string) ( $column['align'] ?? '' ), array( 'left', 'center', 'right' ), true ) ? (string) $column['align'] : 'center',
			);
		}
		return array( 'kind' => $kind, 'html' => 'html' === $kind ? $this->sanitize_custom_html( $column['html'] ?? '' ) : wp_kses_post( (string) ( $column['html'] ?? '' ) ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function sanitize_blocks( $blocks ) {
		$blocks = is_array( $blocks ) ? array_slice( $blocks, 0, self::MAX_BLOCKS ) : array();
		$out = array();
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) { continue; }
			$type = sanitize_key( (string) ( $block['type'] ?? '' ) );
			switch ( $type ) {
				case 'heading':
					$text = sanitize_text_field( (string) ( $block['text'] ?? '' ) );
					if ( '' !== $text ) { $row = array( 'type'=>'heading','text'=>$text,'level'=>max(1,min(3,absint($block['level']??2))) ); $style=$this->sanitize_block_style($block,$type); if($style){$row['style']=$style;} $out[]=$row; }
					break;
				case 'text':
					$html = wp_kses_post( (string) ( $block['html'] ?? '' ) );
					if ( '' !== trim( wp_strip_all_tags( $html ) ) || false !== strpos( $html, '{{' ) ) { $row=array('type'=>'text','html'=>$html); $style=$this->sanitize_block_style($block,$type); if($style){$row['style']=$style;} $out[]=$row; }
					break;
				case 'html':
					$html = $this->sanitize_custom_html( $block['html'] ?? '' );
					if ( '' !== trim( $html ) ) { $out[] = array( 'type' => 'html', 'html' => $html ); }
					break;
				case 'button':
					$text=sanitize_text_field((string)($block['text']??'')); $url=$this->sanitize_url_or_token($block['url']??'');
					if(''!==$text&&''!==$url){$row=array('type'=>'button','text'=>$text,'url'=>$url,'align'=>in_array((string)($block['align']??''),array('left','center','right'),true)?(string)$block['align']:'left');$style=$this->sanitize_block_style($block,$type);if($style){$row['style']=$style;}$out[]=$row;}
					break;
				case 'image':
					$url=esc_url_raw((string)($block['url']??'')); if(''!==$url){$row=array('type'=>'image','url'=>$url,'alt'=>sanitize_text_field((string)($block['alt']??'')),'link'=>$this->sanitize_url_or_token($block['link']??''));$style=$this->sanitize_block_style($block,$type);if($style){$row['style']=$style;}$out[]=$row;}
					break;
				case 'divider': $row=array('type'=>'divider');$style=$this->sanitize_block_style($block,$type);if($style){$row['style']=$style;}$out[]=$row;break;
				case 'spacer': $out[]=array('type'=>'spacer','height'=>max(8,min(80,absint($block['height']??24))));break;
				case 'columns':
					$count = in_array( absint( $block['count'] ?? 2 ), array( 2, 3 ), true ) ? absint( $block['count'] ?? 2 ) : 2;
					$ratio = sanitize_key( (string) ( $block['ratio'] ?? ( 3 === $count ? '33-33-33' : '50-50' ) ) );
					$valid_ratios = 3 === $count ? array( '33-33-33', '25-50-25' ) : array( '50-50', '33-67', '67-33', '40-60', '60-40' );
					if ( ! in_array( $ratio, $valid_ratios, true ) ) { $ratio = 3 === $count ? '33-33-33' : '50-50'; }
					$columns = array();
					if ( is_array( $block['columns'] ?? null ) ) { foreach ( array_slice( $block['columns'], 0, $count ) as $column ) { $columns[] = $this->normalize_column( $column ); } }
					if ( ! $columns && ( isset( $block['left_html'] ) || isset( $block['right_html'] ) ) ) { $columns=array($this->normalize_column(array('kind'=>'text','html'=>$block['left_html']??'')),$this->normalize_column(array('kind'=>'text','html'=>$block['right_html']??''))); $count=2; $ratio='50-50'; }
					while ( count( $columns ) < $count ) { $columns[] = $this->normalize_column( array( 'kind' => 'text', 'html' => '<p></p>' ) ); }
					$out[] = array( 'type'=>'columns','count'=>$count,'ratio'=>$ratio,'gap'=>max(0,min(40,absint($block['gap']??20))),'columns'=>$columns );
					break;
			}
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	public function normalize_blocks( $blocks ) { $normalized=$this->sanitize_blocks($blocks); return $normalized ?: self::default_blocks(); }
	private function style_value( array $style, $key, $fallback ) { return isset($style[$key])&&''!==(string)$style[$key]?(string)$style[$key]:(string)$fallback; }

	/** @return string */
	private function text_style( array $style, array $theme, $kind ) {
		$default_size='heading'===$kind?24:16; $default_weight='heading'===$kind?700:400; $default_color='heading'===$kind?$theme['heading_color']:$theme['text_color'];
		$family=$this->style_value($style,'font_family',$theme['font_family']); $size=absint($this->style_value($style,'font_size',$default_size)); $weight=absint($this->style_value($style,'font_weight',$default_weight)); $color=$this->style_value($style,'color',$default_color); $align=$this->style_value($style,'align','left'); $line_height=$this->style_value($style,'line_height','heading'===$kind?1.25:1.65); $font_style=!empty($style['italic'])?'italic':'normal'; $text_decoration=!empty($style['underline'])?'underline':'none';
		return 'color:'.esc_attr($color).';font-family:'.esc_attr($family).';font-size:'.$size.'px;font-weight:'.$weight.';font-style:'.$font_style.';text-decoration:'.$text_decoration.';text-align:'.esc_attr($align).';line-height:'.esc_attr($line_height).';';
	}

	/** @return array<int,int> */
	private function ratio_widths( $ratio, $count ) {
		$map=array('50-50'=>array(50,50),'33-67'=>array(33,67),'67-33'=>array(67,33),'40-60'=>array(40,60),'60-40'=>array(60,40),'33-33-33'=>array(33,34,33),'25-50-25'=>array(25,50,25));
		return $map[$ratio]??(3===$count?array(33,34,33):array(50,50));
	}

	/** @return string */
	private function compile_column( array $column, array $theme, &$plain_text ) {
		if ( 'image' === $column['kind'] ) {
			if ( '' === (string) $column['url'] ) { return ''; }
			$img='<img src="'.esc_url($column['url']).'" alt="'.esc_attr($column['alt']).'" style="display:inline-block;width:'.absint($column['width']).'%;max-width:100%;height:auto;border:0;">';
			if ( '' !== (string) $column['link'] ) { $img='<a href="'.esc_attr($column['link']).'" style="text-decoration:none;">'.$img.'</a>'; }
			if ( '' !== (string) $column['alt'] ) { $plain_text[]=(string)$column['alt']; }
			return '<div style="text-align:'.esc_attr($column['align']).';">'.$img.'</div>';
		}
		$html='html'===$column['kind']?$this->sanitize_custom_html($column['html']??''):wp_kses_post((string)($column['html']??''));
		$plain=trim(wp_strip_all_tags($html)); if(''!==$plain){$plain_text[]=$plain;}
		return $html;
	}

	/** @return array{html_body:string,text_body:string,theme:array<string,mixed>,blocks:array<int,array<string,mixed>>} */
	public function compile( $blocks, $theme ) {
		$theme=$this->normalize_theme($theme); $blocks=$this->normalize_blocks($blocks); $body=''; $text=array();
		foreach($blocks as $block){$style=is_array($block['style']??null)?$block['style']:array();switch($block['type']){
			case 'heading':$default_size=1===$block['level']?30:(2===$block['level']?24:19);if(!isset($style['font_size'])){$style['font_size']=$default_size;}$body.='<h'.absint($block['level']).' style="margin:0 0 18px;'.$this->text_style($style,$theme,'heading').'">'.esc_html($block['text']).'</h'.absint($block['level']).'>';$text[]=(string)$block['text'];break;
			case 'text':$body.='<div style="margin:0 0 18px;'.$this->text_style($style,$theme,'text').'">'.wp_kses_post($block['html']).'</div>';$text[]=trim(wp_strip_all_tags(str_replace(array('</p>','<br>','<br/>','<br />'),"\n",$block['html'])));break;
			case 'html':$safe=$this->sanitize_custom_html($block['html']);$body.='<div style="margin:0 0 18px;">'.$safe.'</div>';$text[]=trim(wp_strip_all_tags($safe));break;
			case 'button':$family=$this->style_value($style,'font_family',$theme['font_family']);$size=absint($this->style_value($style,'font_size',16));$weight=absint($this->style_value($style,'font_weight',700));$color=$this->style_value($style,'color',$theme['button_text']);$background=$this->style_value($style,'background_color',$theme['button_background']);$radius=absint($this->style_value($style,'border_radius',$theme['border_radius']));$padding_x=absint($this->style_value($style,'padding_x',20));$padding_y=absint($this->style_value($style,'padding_y',13));$font_style=!empty($style['italic'])?'italic':'normal';$text_decoration=!empty($style['underline'])?'underline':'none';$body.='<div style="margin:4px 0 22px;text-align:'.esc_attr($block['align']).';"><a href="'.esc_attr($block['url']).'" style="display:inline-block;background:'.esc_attr($background).';color:'.esc_attr($color).';font-family:'.esc_attr($family).';font-size:'.$size.'px;font-weight:'.$weight.';font-style:'.$font_style.';line-height:1.2;text-decoration:'.$text_decoration.';padding:'.$padding_y.'px '.$padding_x.'px;border-radius:'.$radius.'px;">'.esc_html($block['text']).'</a></div>';$text[]=(string)$block['text'].': '.(string)$block['url'];break;
			case 'image':$align=$this->style_value($style,'align','left');$width=absint($this->style_value($style,'width',100));$radius=absint($this->style_value($style,'border_radius',0));$img='<img src="'.esc_url($block['url']).'" alt="'.esc_attr($block['alt']).'" style="display:inline-block;width:'.$width.'%;max-width:100%;height:auto;border:0;border-radius:'.$radius.'px;">';if(!empty($block['link'])){$img='<a href="'.esc_attr($block['link']).'" style="text-decoration:none;">'.$img.'</a>';}$body.='<div style="margin:0 0 20px;text-align:'.esc_attr($align).';">'.$img.'</div>';if(!empty($block['alt'])){$text[]=(string)$block['alt'];}break;
			case 'divider':$divider_color=$this->style_value($style,'color','#dce6e1');$divider_width=absint($this->style_value($style,'width',100));$divider_thickness=absint($this->style_value($style,'thickness',1));$divider_margin=absint($this->style_value($style,'margin_y',24));$body.='<div style="border-top:'.$divider_thickness.'px solid '.esc_attr($divider_color).';width:'.$divider_width.'%;margin:'.$divider_margin.'px auto;"></div>';break;
			case 'spacer':$body.='<div style="height:'.absint($block['height']).'px;line-height:'.absint($block['height']).'px;font-size:1px;">&nbsp;</div>';break;
			case 'columns':$widths=$this->ratio_widths($block['ratio'],$block['count']);$gap=absint($block['gap']);$cells='';foreach($block['columns'] as $i=>$column){$cell=$this->compile_column($column,$theme,$text);$left=$i>0?intval($gap/2):0;$right=$i<count($block['columns'])-1?intval(($gap+1)/2):0;$cells.='<td valign="top" width="'.absint($widths[$i]??(100/$block['count'])).'%" style="padding:0 '.$right.'px 0 '.$left.'px;color:'.esc_attr($theme['text_color']).';font-family:'.esc_attr($theme['font_family']).';font-size:15px;line-height:1.6;">'.$cell.'</td>';}$body.='<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width:100%;margin:0 0 20px;"><tr>'.$cells.'</tr></table>';break;
		}}
		$page_bg='background-color:'.esc_attr($theme['background']).';';$page_attr='';if(''!==$theme['background_image']){$page_attr=' background="'.esc_url($theme['background_image']).'"';$page_bg.='background-image:url(\''.esc_url($theme['background_image']).'\');background-size:'.esc_attr($theme['background_size']).';background-position:'.esc_attr($theme['background_position']).';background-repeat:'.esc_attr($theme['background_repeat']).';';}
		$content_bg='background-color:'.esc_attr($theme['content_background']).';';$content_attr='';if(''!==$theme['content_background_image']){$content_attr=' background="'.esc_url($theme['content_background_image']).'"';$content_bg.='background-image:url(\''.esc_url($theme['content_background_image']).'\');background-size:'.esc_attr($theme['content_background_size']).';background-position:'.esc_attr($theme['content_background_position']).';background-repeat:'.esc_attr($theme['content_background_repeat']).';';}
		$html='<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"'.$page_attr.' style="width:100%;'.$page_bg.'margin:0;padding:0;"><tr><td align="center" style="padding:28px 14px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"'.$content_attr.' style="width:100%;max-width:'.absint($theme['email_width']).'px;'.$content_bg.'border-radius:'.absint($theme['border_radius']).'px;"><tr><td style="padding:36px;">'.$body.'</td></tr></table></td></tr></table>';
		return array('html_body'=>$html,'text_body'=>trim(implode("\n\n",array_filter($text))),'theme'=>$theme,'blocks'=>$blocks);
	}

	/** @return string */
	private function sanitize_url_or_token( $value ) {
		$value=trim((string)$value);
		if(preg_match('/^\{\{[a-z0-9_.:-]+\}\}$/i',$value)){return $value;}
		return esc_url_raw($value);
	}
}
