<?php
/**
 * End-to-end integration test for PR-004 Media Optimization.
 * Executed via: wp eval-file tests/e2e-image-optimization.php
 */

if (!defined('ABSPATH')) {
    die("Must be run within WordPress.\n");
}

echo "Starting Image Optimization E2E Tests...\n";

// Require admin privileges to modify attachments
wp_set_current_user(1);

$upload_dir = wp_upload_dir();
$fixtures_dir = $upload_dir['path'];

// 1. Generate Image Fixtures
echo "Generating test fixtures (JPEG, PNG, GIF, and Corrupt)...\n";

$jpeg_path = $fixtures_dir . '/test-image.jpg';
$im = imagecreatetruecolor(100, 100);
imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 255, 0, 0));
imagejpeg($im, $jpeg_path, 90);
imagedestroy($im);

$png_path = $fixtures_dir . '/test-image.png';
$im = imagecreatetruecolor(100, 100);
imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 0, 255, 0));
imagepng($im, $png_path);
imagedestroy($im);

$gif_path = $fixtures_dir . '/test-image.gif';
$im = imagecreatetruecolor(100, 100);
imagefilledrectangle($im, 0, 0, 99, 99, imagecolorallocate($im, 0, 0, 255));
imagegif($im, $gif_path);
imagedestroy($im);

$corrupt_path = $fixtures_dir . '/corrupt.jpg';
file_put_contents($corrupt_path, 'This is not an image');

function insert_fixture($path, $mime) {
    $attachment = [
        'post_mime_type' => $mime,
        'post_title'     => basename($path),
        'post_content'   => '',
        'post_status'    => 'inherit'
    ];
    $attach_id = wp_insert_attachment($attachment, $path);
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    if ($mime !== 'text/plain') {
        $attach_data = wp_generate_attachment_metadata($attach_id, $path);
        wp_update_attachment_metadata($attach_id, $attach_data);
    }
    return $attach_id;
}

$jpeg_id = insert_fixture($jpeg_path, 'image/jpeg');
$png_id = insert_fixture($png_path, 'image/png');
$gif_id = insert_fixture($gif_path, 'image/gif');
$corrupt_id = insert_fixture($corrupt_path, 'image/jpeg'); // Claims JPEG but is corrupt

$compressor = new \Holvek\WordPress\Modules\ImageSeo\ImageCompressor();

// 2. Test Configuration Constraints
echo "Testing configuration limits (Acknowledgement)...\n";
$req = new \WP_REST_Request('PUT', '/holvek/v1/image-seo/compression');
$req->set_param('enabled', true);
$res = $compressor->saveSettings($req);
if (!is_wp_error($res) && isset($res->get_data()['success'])) {
    die("ERROR: Compressor allowed enabling without 'acknowledge_reversible_processing'.\n");
}

$req->set_param('acknowledge_reversible_processing', true);
$req->set_param('quality', 80);
$res = $compressor->saveSettings($req);
if (is_wp_error($res)) {
    die("ERROR: Failed to save valid configuration: " . $res->get_error_message() . "\n");
}

// 3. Test Bulk Processing
echo "Testing bulk compression processing...\n";
$req = new \WP_REST_Request('POST', '/holvek/v1/image-seo/bulk-compress');
$req->set_param('batch_size', 10);
$res = $compressor->bulkCompress($req);

if (is_wp_error($res)) {
    die("ERROR: Bulk compression threw an error: " . $res->get_error_message() . "\n");
}

$data = $res->get_data();
if (empty($data['success'])) {
    die("ERROR: Bulk compression returned false success.\n");
}

// 4. Verify Successes, Skips, and Failures
echo "Verifying processed states and backups...\n";

// JPEG should be processed and backed up
$jpeg_status = get_post_meta($jpeg_id, '_holvek_compressed', true);
$jpeg_backup = get_post_meta($jpeg_id, '_holvek_original_file', true);
if ($jpeg_status !== 'yes' || !file_exists($jpeg_backup)) {
    die("ERROR: JPEG was not processed successfully or backup is missing.\n");
}

// PNG should be processed and backed up
$png_status = get_post_meta($png_id, '_holvek_compressed', true);
$png_backup = get_post_meta($png_id, '_holvek_original_file', true);
if ($png_status !== 'yes' || !file_exists($png_backup)) {
    die("ERROR: PNG was not processed successfully or backup is missing.\n");
}

// GIF should be skipped
$gif_status = get_post_meta($gif_id, '_holvek_compressed', true);
if ($gif_status !== '') {
    die("ERROR: GIF was incorrectly processed ($gif_status).\n");
}

// Corrupt JPEG should FAIL, backup shouldn't be left around, and original should be intact
$corrupt_status = get_post_meta($corrupt_id, '_holvek_compressed', true);
if ($corrupt_status !== 'failed') {
    die("ERROR: Corrupt image did not register as 'failed'. Status: $corrupt_status\n");
}
if (file_get_contents($corrupt_path) !== 'This is not an image') {
    die("ERROR: Corrupt image payload was mutated despite engine failure.\n");
}

// 5. Test Restoration
echo "Testing restoration of original images...\n";
$req = new \WP_REST_Request('POST', '/holvek/v1/image-seo/restore');
$req->set_param('attachment_id', $jpeg_id);
$res = $compressor->restoreAttachment($req);
if (is_wp_error($res)) {
    die("ERROR: Restoration failed: " . $res->get_error_message() . "\n");
}

// Verify backup is deleted
if (file_exists($jpeg_backup)) {
    die("ERROR: Backup file was not deleted after restoration.\n");
}
if (get_post_meta($jpeg_id, '_holvek_original_file', true)) {
    die("ERROR: Backup meta key was not deleted after restoration.\n");
}
if (get_post_meta($jpeg_id, '_holvek_compressed', true)) {
    die("ERROR: Processed meta key was not deleted after restoration.\n");
}

echo "SUCCESS: All Image Optimization integration tests passed!\n";
