<?php
/** Regression guard: live legacy mutations must reserve their source-generation boundary before mutating durable state. */
$root=dirname(__DIR__);
$db=file_get_contents($root.'/includes/class-ecapla-certverify-database.php');
$backup=file_get_contents($root.'/includes/class-ecapla-certverify-backup.php');
$fields=file_get_contents($root.'/includes/class-ecapla-certverify-fields.php');
$settings=file_get_contents($root.'/includes/class-ecapla-certverify-settings.php');
function lgb_assert($c,$m){if(!$c)throw new RuntimeException($m);}
function lgb_between($src,$start,$end){$a=strpos($src,$start);$b=false===$a?false:strpos($src,$end,$a);return false===$a||false===$b?'':substr($src,$a,$b-$a);}
lgb_assert(false!==strpos($backup,'public static function reserve_mutation_generation()'),'Backup boundary must expose a shared pre-mutation generation reservation.');
lgb_assert(false!==strpos($backup,'if ( self::is_internal_restore_write() )'),'Atomic internal restore writes must bypass per-row generation reservation.');
foreach(array(
 array('public static function insert(', 'public static function update(', '$wpdb->insert'),
 array('public static function update(', 'public static function discover_orphaned_custom_field_keys(', '$wpdb->update'),
 array('public static function delete( $id )', 'public static function delete_many(', '$wpdb->delete'),
 array('public static function delete_many(', 'public static function get_by_id(', '$wpdb->query'),
) as $case){$chunk=lgb_between($db,$case[0],$case[1]);$reserve=strpos($chunk,'reserve_live_mutation_generation()');$write=strpos($chunk,$case[2]);lgb_assert(false!==$reserve&&false!==$write&&$reserve<$write,'Legacy CRUD must reserve generation before durable row mutation.');}
$purge=lgb_between($db,'public static function purge_custom_field_values(', 'public static function clear_custom_field_purge_state(');
lgb_assert(false!==strpos($purge,'$generation_reserved')&&strpos($purge,'reserve_live_mutation_generation()')<strpos($purge,'$wpdb->update'),'Retired-field purge must reserve generation before its first row update.');
lgb_assert(false!==strpos($settings,'reserve_mutation_generation()')&&strpos($settings,'reserve_mutation_generation()')<strpos($settings,'update_option( self::OPTION_KEY'),'Settings must reserve generation before a changed settings option is persisted.');
lgb_assert(substr_count($fields,'reserve_mutation_generation()')>=4,'Active/retired field persistence and normalization must reserve generation before changes.');
lgb_assert(1===substr_count($db,'Ecapla_CertVerify_Backup::mark_mutation()')||0===substr_count($db,'Ecapla_CertVerify_Backup::mark_mutation()'),'Database CRUD must not rely on unchecked post-write generation marking.');
echo "PASS  Live legacy mutations reserve the migration source generation before durable changes\n\n10 checks, 0 failures\n";
