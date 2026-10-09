<?php
/**
 * End-to-end integration test for PR-005 Privacy and Retention features.
 * Designed to be executed via: wp eval-file tests/e2e-privacy.php
 */

if (!defined('ABSPATH')) {
    die("Must be run within WordPress.\n");
}

echo "Starting Privacy & Retention E2E Tests...\n";

// 1. Ensure plugin is active and tables are created
$installer = new \Holvek\WordPress\Core\Database\Installer();
$installer->install();

global $wpdb;
$usageTable = $wpdb->prefix . 'holvek_ai_usage';
$logsTable = $wpdb->prefix . 'holvek_404_logs';

// Create a dummy user
$email = 'privacy_test_' . time() . '@example.com';
$user_id = wp_insert_user([
    'user_login' => 'privacy_test_user_' . time(),
    'user_pass'  => wp_generate_password(),
    'user_email' => $email,
    'role'       => 'subscriber'
]);

if (is_wp_error($user_id)) {
    die("ERROR: Failed to create user: " . $user_id->get_error_message() . "\n");
}

// Insert dummy data into holvek_ai_usage
$wpdb->insert($usageTable, [
    'user_id' => $user_id,
    'provider' => 'openai',
    'feature' => 'test_feature',
    'tokens_used' => 150,
    'created_at' => current_time('mysql')
]);

// Insert old data to test retention (older than 365 days)
$wpdb->insert($usageTable, [
    'user_id' => $user_id,
    'provider' => 'openai',
    'feature' => 'old_feature',
    'tokens_used' => 50,
    'created_at' => gmdate('Y-m-d H:i:s', strtotime('-400 days'))
]);

// Insert dummy data into holvek_404_logs (older than 90 days)
$wpdb->insert($logsTable, [
    'uri' => '/old-404-url',
    'hits' => 5,
    'last_hit' => gmdate('Y-m-d H:i:s', strtotime('-100 days'))
]);
$wpdb->insert($logsTable, [
    'uri' => '/new-404-url',
    'hits' => 1,
    'last_hit' => current_time('mysql')
]);

// Add user meta
update_user_meta($user_id, 'holvek_test_meta', 'test_value');

$tools = new \Holvek\WordPress\Modules\Compliance\CcpaPrivacyTools();

// ----------------------------------------------------------------------------
// TEST: Exporter
// ----------------------------------------------------------------------------
echo "Testing Exporter...\n";
$export = $tools->wpPrivacyExporter($email, 1);
if (empty($export['data'])) {
    die("ERROR: Export failed: no data found.\n");
}
$foundUsage = false;
$foundMeta = false;
foreach ($export['data'] as $item) {
    if ($item['group_id'] === 'holvek_ai_usage') $foundUsage = true;
    if ($item['group_id'] === 'holvek_user_meta') $foundMeta = true;
}
if (!$foundUsage || !$foundMeta) {
    die("ERROR: Export failed: missing expected groups.\n");
}
echo "Exporter OK.\n";

// ----------------------------------------------------------------------------
// TEST: Eraser
// ----------------------------------------------------------------------------
echo "Testing Eraser...\n";
$erase = $tools->wpPrivacyEraser($email, 1);
if (empty($erase['items_removed']) || $erase['items_removed'] !== true) {
    die("ERROR: Eraser failed: items_removed not true.\n");
}

// Verify deletion
$count = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$usageTable} WHERE user_id = %d", $user_id));
if ($count > 0) {
    die("ERROR: Eraser failed: DB records still exist.\n");
}
$meta = get_user_meta($user_id, 'holvek_test_meta', true);
if (!empty($meta)) {
    die("ERROR: Eraser failed: User meta still exists.\n");
}
echo "Eraser OK.\n";

// ----------------------------------------------------------------------------
// TEST: Retention Purge
// ----------------------------------------------------------------------------
echo "Testing Retention Purge...\n";
// Set options explicitly
update_option('holvek_retention_settings', ['keep_404_logs_days' => 90, 'keep_usage_logs_days' => 365]);

// Repopulate a 404 old log since we didn't erase it, it should still be there
$tools->applyRetentionPolicy();

// Verify 404 logs
$old404 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logsTable} WHERE url = %s", '/old-404-url'));
$new404 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$logsTable} WHERE url = %s", '/new-404-url'));

if ($old404 > 0) {
    die("ERROR: Retention failed to delete old 404 log.\n");
}
if ($new404 !== 1) {
    die("ERROR: Retention wrongly deleted new 404 log.\n");
}

echo "Retention Purge OK.\n";
echo "SUCCESS: All Privacy & Retention integration tests passed!\n";
