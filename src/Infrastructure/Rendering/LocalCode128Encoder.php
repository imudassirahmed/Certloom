<?php
namespace Certloom\Infrastructure\Rendering;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
use Certloom\Application\Contract\BarcodeEncoderInterface;
use InvalidArgumentException;

/**
 * Deterministic WordPress-local Code 128 encoder.
 *
 * The implementation supports printable ASCII through Code Set B and switches
 * to Code Set C for sufficiently long numeric runs. It returns only the exact
 * barcode module sequence; quiet zones and visual presentation belong to the
 * caller. No network service or alternate credential identifier is involved.
 */
final class LocalCode128Encoder implements BarcodeEncoderInterface {
	const ENCODER_ID      = 'certloom-local-code128';
	const ENCODER_VERSION = '1.0.0';
	const MAX_DATA_BYTES  = 100;

	/** @var array<int,string> Code 128 symbol width patterns 0..106. */
	private static $patterns = array(
		'212222','222122','222221','121223','121322','131222','122213','122312','132212','221213',
		'221312','231212','112232','122132','122231','113222','123122','123221','223211','221132',
		'221231','213212','223112','312131','311222','321122','321221','312212','322112','322211',
		'212123','212321','232121','111323','131123','131321','112313','132113','132311','211313',
		'231113','231311','112133','112331','132131','113123','113321','133121','313121','211331',
		'231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
		'314111','221411','431111','111224','111422','121124','121421','141122','141221','112214',
		'112412','122114','122411','142112','142211','241211','221114','413111','241112','134111',
		'111242','121142','121241','114212','124112','124211','411212','421112','421211','212141',
		'214121','412121','111143','111341','131141','114113','114311','411113','411311','113141',
		'114131','311141','411131','211412','211214','211232','2331112'
	);

	public function id() {
		return self::ENCODER_ID;
	}

	public function version() {
		return self::ENCODER_VERSION;
	}

	/** @return array<int,int> */
	public function encode( $data ) {
		$data = (string) $data;
		$length = strlen( $data );
		if ( 0 === $length || $length > self::MAX_DATA_BYTES ) {
			throw new InvalidArgumentException( 'Code 128 data length is outside the supported range.' );
		}
		if ( 1 !== preg_match( '/^[\x20-\x7E]+$/D', $data ) ) {
			throw new InvalidArgumentException( 'Code 128 supports printable ASCII identifiers only.' );
		}

		$codes = $this->data_codes( $data );
		$checksum = $codes[0];
		for ( $i = 1, $count = count( $codes ); $i < $count; $i++ ) {
			$checksum += $codes[ $i ] * $i;
		}
		$codes[] = $checksum % 103;
		$codes[] = 106;

		$modules = array();
		foreach ( $codes as $code ) {
			if ( ! isset( self::$patterns[ $code ] ) ) {
				throw new InvalidArgumentException( 'Code 128 symbol is outside the supported table.' );
			}
			$dark = 1;
			$pattern = self::$patterns[ $code ];
			for ( $j = 0, $width_count = strlen( $pattern ); $j < $width_count; $j++ ) {
				$width = (int) $pattern[ $j ];
				for ( $k = 0; $k < $width; $k++ ) {
					$modules[] = $dark;
				}
				$dark = $dark ? 0 : 1;
			}
		}
		if ( count( $modules ) < 46 || 1 !== $modules[0] || 1 !== $modules[ count( $modules ) - 1 ] ) {
			throw new InvalidArgumentException( 'Code 128 module sequence failed its structural self-check.' );
		}
		return $modules;
	}

	/**
	 * Produces start/data/switch codewords before checksum/stop.
	 *
	 * @return array<int,int>
	 */
	private function data_codes( $data ) {
		$length = strlen( $data );
		$start_in_c = $this->numeric_run( $data, 0 ) >= 4;
		$set = $start_in_c ? 'C' : 'B';
		$codes = array( $start_in_c ? 105 : 104 );
		$i = 0;

		while ( $i < $length ) {
			if ( 'C' === $set ) {
				$run = $this->numeric_run( $data, $i );
				if ( $run >= 2 ) {
					$pairs = $run - ( $run % 2 );
					for ( $end = $i + $pairs; $i < $end; $i += 2 ) {
						$codes[] = (int) substr( $data, $i, 2 );
					}
					if ( $i >= $length ) {
						break;
					}
				}
				$codes[] = 100; // Code B.
				$set = 'B';
				continue;
			}

			$run = $this->numeric_run( $data, $i );
			$remaining = $length - $i;
			$should_switch = $run >= 6 || ( $run >= 4 && $run === $remaining && 0 === $run % 2 );
			if ( $should_switch ) {
				if ( 1 === $run % 2 ) {
					$codes[] = ord( $data[ $i ] ) - 32;
					$i++;
				}
				$codes[] = 99; // Code C.
				$set = 'C';
				continue;
			}

			$codes[] = ord( $data[ $i ] ) - 32;
			$i++;
		}
		return $codes;
	}

	/** @return int */
	private function numeric_run( $data, $offset ) {
		$length = strlen( $data );
		$run = 0;
		while ( $offset + $run < $length ) {
			$ord = ord( $data[ $offset + $run ] );
			if ( $ord < 48 || $ord > 57 ) {
				break;
			}
			$run++;
		}
		return $run;
	}
}
