<?php
define( 'ABSPATH', __DIR__ . '/' );
define( 'CERTLOOM_PATH', dirname( __DIR__ ) . '/' );
function sanitize_key($key){return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$key));}
function absint($v){return abs((int)$v);}
require_once CERTLOOM_PATH . 'src/Autoloader.php';
\Certloom\Autoloader::register();
$catalog = new \Certloom\Application\Templates\PresetCatalog();
echo json_encode( array( 'catalog_version' => \Certloom\Application\Templates\PresetCatalog::CATALOG_VERSION, 'presets' => $catalog->all() ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) . "\n";
