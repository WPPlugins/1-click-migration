<?php
/** Minimal WordPress stubs for rendering the real admin template in UI tests. */
namespace OCM {
class OCM_Backup { static function get_progress() {return array('text'=>'','value'=>'0%');} }
class One_Click_Migration { static function get_version() {return '3.1.16';} }
}
namespace {
function current_user_can($cap) {return true;}
function settings_errors($x) {}
function get_option($name, $default = false) {return $default;}
function wp_nonce_url($u,$a) {return $u;}
function admin_url($p) {return '/'.$p;}
function plugins_url($p,$base) {return $p;}
function wp_kses_post($s) {return $s;}
function esc_html($s) {return htmlspecialchars((string)$s);}
function esc_attr($s) {return htmlspecialchars((string)$s);}
function esc_url($s) {return htmlspecialchars((string)$s);}
define('OCM_DEBUG_LOG_FILE','/tmp/nonexistent-ocm-preview-log');
require __DIR__ . '/../inc/admin/class-admin-page.php';
OCM\OCM_Admin_Page::display_admin_menu();
}
