<?php
/** Regression tests for consistent, font-independent admin action button icons. */
$root = dirname( __DIR__ );
$dashboard = file_get_contents( $root . '/admin/views/dashboard.php' );
$registry  = file_get_contents( $root . '/admin/views/list-certificates.php' );
$css       = file_get_contents( $root . '/admin/assets/admin.css' );
function aai_assert( $condition, $message ) { if ( ! $condition ) { throw new RuntimeException( $message ); } }
aai_assert( is_string( $dashboard ) && is_string( $registry ) && is_string( $css ), 'Admin action icon sources must be readable.' );
aai_assert( 2 === substr_count( $dashboard, 'class="certloom-button-icon"' ), 'Dashboard hero actions use exactly two shared SVG icons while Setup remains a quiet text continuation.' );
aai_assert( 2 === substr_count( $registry, 'class="certloom-button-icon"' ), 'Registry hero actions must use exactly two shared SVG icons.' );
aai_assert( false === strpos( substr( $registry, 0, strpos( $registry, '</section>' ) ), 'dashicons-database-import' ), 'Registry hero must not use the legacy database-import glyph.' );
aai_assert( false !== strpos( $dashboard, 'certloom-dashboard-setup-link' ) && false !== strpos( $dashboard, 'Setup wizard' ), 'Setup remains an explicit but visually quieter dashboard continuation.' );
aai_assert( false !== strpos( $dashboard, 'M12 5v14M5 12h14' ) && false !== strpos( $registry, 'M12 5v14M5 12h14' ), 'Add-certificate actions must share the same plus icon geometry.' );
aai_assert( false !== strpos( $dashboard, 'M12 15V5m0 0L8 9m4-4 4 4M5 14v5h14v-5' ) && false !== strpos( $registry, 'M12 15V5m0 0L8 9m4-4 4 4M5 14v5h14v-5' ), 'Import actions must share the same upload icon geometry.' );
aai_assert( 4 === substr_count( $dashboard . $registry, 'aria-hidden="true" focusable="false"' ), 'Decorative hero action icons must stay out of the accessibility tree.' );
aai_assert( false !== strpos( $css, '.certloom-button-icon {' ) && false !== strpos( $css, 'flex: 0 0 20px;' ) && false !== strpos( $css, 'stroke: currentColor;' ), 'Shared icon CSS must enforce stable size, flex alignment and inherited button color.' );
aai_assert( false !== strpos( $css, 'stroke-linecap: round;' ) && false !== strpos( $css, 'pointer-events: none;' ), 'Shared icon CSS must preserve premium geometry and button hit targets.' );
echo "PASS  Admin action buttons use consistent accessible inline SVG icons\n\n9 checks, 0 failures\n";
