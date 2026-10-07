<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
return array( 'dependencies' => array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), 'version' => (string) filemtime( __DIR__ . '/index.js' ) );
