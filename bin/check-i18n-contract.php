<?php
/**
 * Release guard for placeholder-bearing translated sprintf/printf calls.
 *
 * Every immediate gettext call passed to sprintf()/printf() must carry a
 * translators comment that explains the placeholder values. This mirrors the
 * WordPress.org expectation and keeps the committed POT useful to translators.
 */
$root = dirname( __DIR__ );
$roots = array( 'admin', 'includes', 'public', 'src' );
$files = array( $root . '/certloom.php' );
foreach ( $roots as $relative ) {
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $root . '/' . $relative, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
}

$failures = array();
$checked  = 0;
$pattern  = '/\b(sprintf|printf)\s*\(\s*(?:(\/\*\s*translators:.*?\*\/\s*))?((?:__|_n|_x|esc_html__|esc_attr__)\s*\()/s';
foreach ( $files as $path ) {
	$source = file_get_contents( $path );
	if ( false === $source ) {
		continue;
	}
	if ( ! preg_match_all( $pattern, $source, $matches, PREG_OFFSET_CAPTURE ) ) {
		continue;
	}
	foreach ( $matches[0] as $index => $match ) {
		$offset = $match[1];
		$tail   = substr( $source, $offset, 1400 );
		if ( ! preg_match( "/(?:__|_n|_x|esc_html__|esc_attr__)\\s*\\(\\s*'((?:\\\\'|[^'])*)'/s", $tail, $message ) ) {
			continue;
		}
		if ( ! preg_match( '/%(?!%)(?:\d+\$)?[bcdeEfFgGosuxXdi]/', $message[1] ) ) {
			continue;
		}
		++$checked;
		$comment = isset( $matches[2][ $index ][0] ) ? trim( $matches[2][ $index ][0] ) : '';
		if ( '' === $comment ) {
			$line       = substr_count( substr( $source, 0, $offset ), "\n" ) + 1;
			$failures[] = str_replace( $root . '/', '', $path ) . ':' . $line . ' — ' . $message[1];
		}
	}
}

if ( $failures ) {
	foreach ( $failures as $failure ) {
		fwrite( STDERR, "FAIL  missing translators comment: {$failure}\n" );
	}
	fwrite( STDERR, sprintf( "\n%d placeholder translation calls checked, %d missing comments\n", $checked, count( $failures ) ) );
	exit( 1 );
}

printf( "PASS  %d placeholder translation calls carry translators comments\n", $checked );
