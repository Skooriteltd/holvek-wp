<?php
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('OBJECT')) define('OBJECT', 'OBJECT');
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');
if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!defined('WEEK_IN_SECONDS')) define('WEEK_IN_SECONDS', 604800);
if (!defined('MONTH_IN_SECONDS')) define('MONTH_IN_SECONDS', 2592000);
if (!defined('YEAR_IN_SECONDS')) define('YEAR_IN_SECONDS', 31536000);
if (!defined('MB_IN_BYTES')) define('MB_IN_BYTES', 1048576);

require_once dirname(__DIR__) . '/vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}
if (!defined('WP_CONTENT_DIR')) {
    define('WP_CONTENT_DIR', dirname(__DIR__) . '/wp-content');
}
if (!defined('HOLVEK_VERSION')) {
    define('HOLVEK_VERSION', '1.0.1');
}
if (!defined('SECURE_AUTH_KEY')) {
    define('SECURE_AUTH_KEY', 'test_secure_auth_key_1234567890abcdef');
}

// In-memory WordPress options store for unit tests
global $mock_wp_options;
$mock_wp_options = [];

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        global $mock_wp_options;
        return $mock_wp_options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, $value, $autoload = null): bool {
        global $mock_wp_options;
        $mock_wp_options[$option] = $value;
        if ($autoload !== null) {
            if (!isset($GLOBALS['mock_option_autoload']) || !is_array($GLOBALS['mock_option_autoload'])) {
                $GLOBALS['mock_option_autoload'] = array();
            }
            $GLOBALS['mock_option_autoload'][$option] = (bool) $autoload;
        }
        return true;
    }
}

if (!function_exists('add_option')) {
    function add_option(string $option, $value = '', $deprecated = '', $autoload = 'yes'): bool {
        global $mock_wp_options;
        if (isset($mock_wp_options[$option])) {
            return false;
        }
        $mock_wp_options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option(string $option): bool {
        global $mock_wp_options;
        unset($mock_wp_options[$option]);
        return true;
    }
}

if (!function_exists('wp_salt')) {
    function wp_salt(string $scheme = 'auth'): string {
        return 'test_mock_salt_value_for_unit_tests_' . $scheme;
    }
}

if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4(): string {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field(string $str): string {
        return strip_tags(trim($str));
    }
}

if (!function_exists('esc_url_raw')) {
    function esc_url_raw(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('esc_sql')) {
    function esc_sql(string|array $data): string|array {
        if (is_array($data)) {
            return array_map('esc_sql', $data);
        }
        return addslashes($data);
    }
}

if (!function_exists('__')) {
    function __(string $text, string $domain = 'default'): string {
        return $text;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        public string $code;
        public string $message;
        public $data;

        public function __construct(string $code = '', string $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }

        public function get_error_code(): string {
            return $this->code;
        }

        public function get_error_message(): string {
            return $this->message;
        }

        public function get_error_data(string $code = '') {
            unset($code);
            return $this->data;
        }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool {
        return ($thing instanceof WP_Error);
    }
}

if (!function_exists('untrailingslashit')) {
    function untrailingslashit(string $string): string {
        return rtrim($string, '/\\');
    }
}

if (!function_exists('wp_parse_url')) {
    function wp_parse_url(string $url, int $component = -1) {
        return parse_url($url, $component);
    }
}

if (!function_exists('maybe_unserialize')) {
    function maybe_unserialize($original) {
        if (!is_string($original)) {
            return $original;
        }
        if (!preg_match('/^(a|O|s|b|i|d):/', $original)) {
            return $original;
        }
        $decoded = unserialize($original, array('allowed_classes' => false));
        if ($decoded === false && $original !== 'b:0;') {
            return $original;
        }
        return $decoded;
    }
}

if (!isset($GLOBALS['wpdb'])) {
    class MockWpdb {
        public string $prefix = 'wp_';
        public string $posts = 'wp_posts';
        public string $options = 'wp_options';
        public string $postmeta = 'wp_postmeta';
        public string $usermeta = 'wp_usermeta';
        public string $sitemeta = 'wp_sitemeta';
        public array $queries = [];

        public function get_results(string $query = null, $output = 'OBJECT') {
            $this->queries[] = $query;
            $callback = $GLOBALS['mock_wpdb_results'] ?? null;
            if (is_callable($callback)) {
                $rows = $callback($query);
                return is_array($rows) ? $rows : [];
            }
            return [];
        }

        public function query(string $query) {
            $this->queries[] = $query;
            return true;
        }

        public function get_var($query = null, $x = 0, $y = 0) {
            $this->queries[] = $query;
            $callback = $GLOBALS['mock_wpdb_var'] ?? null;
            if (is_callable($callback)) {
                return $callback($query);
            }
            return null;
        }

        public function get_col($query = null, $x = 0) {
            $this->queries[] = $query;
            $callback = $GLOBALS['mock_wpdb_col'] ?? null;
            if (is_callable($callback)) {
                $rows = $callback($query);
                return is_array($rows) ? $rows : [];
            }
            return [];
        }

        public function get_row($query = null, $output = 'OBJECT') {
            $this->queries[] = $query;
            $callback = $GLOBALS['mock_wpdb_row'] ?? null;
            if (is_callable($callback)) {
                return $callback($query);
            }
            return null;
        }

        public function insert($table, $data, $format = null) {
            $this->queries[] = 'INSERT ' . $table;
            $callback = $GLOBALS['mock_wpdb_insert'] ?? null;
            if (is_callable($callback)) {
                return $callback($table, $data, $format);
            }
            return 1;
        }

        public function update($table, $data, $where, $format = null, $where_format = null) {
            $this->queries[] = 'UPDATE ' . $table;
            $callback = $GLOBALS['mock_wpdb_update'] ?? null;
            if (is_callable($callback)) {
                return $callback($table, $data, $where);
            }
            return 1;
        }

        public function delete($table, $where, $where_format = null) {
            $this->queries[] = 'DELETE ' . $table;
            $callback = $GLOBALS['mock_wpdb_delete'] ?? null;
            if (is_callable($callback)) {
                return $callback($table, $where, $where_format);
            }
            return 0;
        }

        public function prepare(string $query, ...$args): string {
            return vsprintf(str_replace('%s', "'%s'", $query), $args);
        }

        public function esc_like(string $text): string {
            return addcslashes($text, '_%\\');
        }

        public function get_charset_collate(): string {
            return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        }
    }
    $GLOBALS['wpdb'] = new MockWpdb();
}

// Post & Meta store
global $mock_post_meta, $mock_posts, $mock_current_post_id;
$mock_post_meta = [];
$mock_posts = [];
$mock_current_post_id = 0;

// Conditional tags state
global $mock_is_singular, $mock_is_admin, $mock_is_front_page, $mock_is_home, $mock_is_404, $mock_is_search, $mock_is_paged, $mock_is_date;
$mock_is_singular = false;
$mock_is_admin = false;
$mock_is_front_page = false;
$mock_is_home = false;
$mock_is_404 = false;
$mock_is_search = false;
$mock_is_paged = false;
$mock_is_date = false;

if (!class_exists('WP_Post')) {
    class WP_Post {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public int $post_author = 1;
        public string $post_date = '2026-01-01 12:00:00';
        public string $post_modified = '2026-01-02 12:00:00';

        public function __construct(array $data = []) {
            foreach ($data as $key => $val) {
                $this->$key = $val;
            }
        }
    }
}

if (!class_exists('WP_REST_Server')) {
    class WP_REST_Server {
        public const READABLE = 'GET';
        public const CREATABLE = 'POST';
        public const EDITABLE = 'POST, PUT, PATCH';
        public const DELETABLE = 'DELETE';
        public const ALLMETHODS = 'GET, POST, PUT, PATCH, DELETE';
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private array $params = [];
        private array $jsonParams = [];
        private string $body = '';

        public function __construct(string $method = 'GET', string $route = '') {}

        public function set_body(string $body): void {
            $this->body = $body;
        }

        public function get_body(): string {
            return $this->body;
        }

        public function set_json_params(array $params): void {
            $this->jsonParams = $params;
        }

        public function get_json_params(): array {
            return $this->jsonParams;
        }

        public function set_param(string $key, $value): void {
            $this->params[$key] = $value;
        }

        public function get_param(string $key) {
            return $this->params[$key] ?? null;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public int $status;

        public function __construct($data = null, int $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }

        public function get_data() {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }

        public function header(string $key, string $value, bool $replace = true): void {
        }
    }
}

if (!function_exists('rest_ensure_response')) {
    function rest_ensure_response($response) {
        if ($response instanceof WP_REST_Response) {
            return $response;
        }
        return new WP_REST_Response($response);
    }
}

if (!function_exists('current_user_can')) {
    function current_user_can(string $capability, ...$args): bool {
        if (array_key_exists('mock_user_caps', $GLOBALS) && is_array($GLOBALS['mock_user_caps'])) {
            if ($capability === 'edit_post') {
                $postId = isset($args[0]) ? (int) $args[0] : 0;
                $allowed = $GLOBALS['mock_user_caps']['edit_post'] ?? true;
                if (is_array($allowed)) {
                    return in_array($postId, $allowed, true);
                }
                return (bool) $allowed;
            }
            if (array_key_exists($capability, $GLOBALS['mock_user_caps'])) {
                return (bool) $GLOBALS['mock_user_caps'][$capability];
            }
        }
        return true;
    }
}

if (!function_exists('sanitize_textarea_field')) {
    function sanitize_textarea_field(string $str): string {
        return strip_tags(trim($str));
    }
}

if (!function_exists('sanitize_file_name')) {
    function sanitize_file_name(string $filename): string {
        return preg_replace('/[^a-zA-Z0-9_\.-]/', '', $filename);
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html')) {
    function esc_html(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_html__')) {
    function esc_html__(string $text, string $domain = 'default'): string {
        return esc_html(__($text, $domain));
    }
}

if (!function_exists('esc_attr__')) {
    function esc_attr__(string $text, string $domain = 'default'): string {
        return esc_attr(__($text, $domain));
    }
}

if (!function_exists('get_locale')) {
    function get_locale(): string {
        $locale = $GLOBALS['mock_locale'] ?? 'en_US';
        return is_string($locale) ? $locale : 'en_US';
    }
}

if (!function_exists('esc_url')) {
    function esc_url(string $url): string {
        return filter_var($url, FILTER_SANITIZE_URL) ?: '';
    }
}

if (!function_exists('is_admin')) {
    function is_admin(): bool {
        global $mock_is_admin;
        return (bool) $mock_is_admin;
    }
}

if (!function_exists('is_singular')) {
    function is_singular($post_types = ''): bool {
        global $mock_is_singular;
        return (bool) $mock_is_singular;
    }
}

if (!function_exists('is_single')) {
    function is_single($post = ''): bool {
        global $mock_is_singular;
        return (bool) $mock_is_singular;
    }
}

if (!function_exists('is_front_page')) {
    function is_front_page(): bool {
        global $mock_is_front_page;
        return (bool) $mock_is_front_page;
    }
}

if (!function_exists('is_home')) {
    function is_home(): bool {
        global $mock_is_home;
        return (bool) $mock_is_home;
    }
}

if (!function_exists('is_404')) {
    function is_404(): bool {
        global $mock_is_404;
        return (bool) $mock_is_404;
    }
}

if (!function_exists('get_user_by')) {
    function get_user_by(string $field, $value) {
        $users = $GLOBALS['mock_users'] ?? array();
        if (!is_array($users)) {
            return false;
        }
        foreach ($users as $user) {
            if (!is_object($user)) {
                continue;
            }
            if ($field === 'email' && isset($user->user_email) && (string) $user->user_email === (string) $value) {
                return $user;
            }
            if (($field === 'id' || $field === 'ID') && isset($user->ID) && (int) $user->ID === (int) $value) {
                return $user;
            }
            if ($field === 'login' && isset($user->user_login) && (string) $user->user_login === (string) $value) {
                return $user;
            }
        }
        return false;
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key = '', $single = false) {
        $all = $GLOBALS['mock_user_meta'][(int) $user_id] ?? array();
        if (!is_array($all)) {
            $all = array();
        }
        if ($key === '') {
            return $all;
        }
        if (!array_key_exists($key, $all)) {
            return $single ? '' : array();
        }
        $value = $all[$key];
        if ($single) {
            return is_array($value) ? ($value[0] ?? '') : $value;
        }
        return is_array($value) ? $value : array($value);
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $meta_key, $meta_value, $prev_value = '') {
        unset($prev_value);
        $id = (int) $user_id;
        if (!isset($GLOBALS['mock_user_meta'][$id]) || !is_array($GLOBALS['mock_user_meta'][$id])) {
            $GLOBALS['mock_user_meta'][$id] = array();
        }
        $GLOBALS['mock_user_meta'][$id][$meta_key] = $meta_value;
        return true;
    }
}

if (!function_exists('wp_create_nonce')) {
    function wp_create_nonce(string $action = '-1'): string {
        return 'nonce-' . $action;
    }
}

if (!function_exists('wp_kses')) {
    function wp_kses(string $content, array $allowed_html, array $allowed_protocols = array()): string {
        unset($allowed_html, $allowed_protocols);
        return $content;
    }
}

if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action = -1, $query_arg = false, bool $stop = true) {
        unset($query_arg, $stop);
        $GLOBALS['mock_ajax_actions'][] = $action;
        return 1;
    }
}

if (!function_exists('wp_send_json_success')) {
    function wp_send_json_success($data = null, int $status_code = 200): void {
        $GLOBALS['mock_json_response'] = array(
            'success' => true,
            'data' => $data,
            'status' => $status_code,
        );
    }
}

if (!function_exists('wp_send_json_error')) {
    function wp_send_json_error($data = null, int $status_code = 400): void {
        $GLOBALS['mock_json_response'] = array(
            'success' => false,
            'data' => $data,
            'status' => $status_code,
        );
    }
}

if (!function_exists('delete_user_meta')) {
    function delete_user_meta($user_id, $key, $value = ''): bool {
        unset($value);
        $id = (int) $user_id;
        if (!isset($GLOBALS['mock_user_meta'][$id][$key])) {
            return false;
        }
        unset($GLOBALS['mock_user_meta'][$id][$key]);
        return true;
    }
}

if (!function_exists('is_email')) {
    function is_email(string $email) {
        $valid = filter_var($email, FILTER_VALIDATE_EMAIL);
        return $valid ? $email : false;
    }
}

if (!function_exists('sanitize_email')) {
    function sanitize_email(string $email): string {
        $clean = filter_var($email, FILTER_SANITIZE_EMAIL);
        return is_string($clean) ? $clean : '';
    }
}

if (!function_exists('is_search')) {
    function is_search(): bool {
        global $mock_is_search;
        return (bool) $mock_is_search;
    }
}

if (!function_exists('is_paged')) {
    function is_paged(): bool {
        global $mock_is_paged;
        return (bool) $mock_is_paged;
    }
}

if (!function_exists('is_date')) {
    function is_date(): bool {
        global $mock_is_date;
        return (bool) $mock_is_date;
    }
}

if (!function_exists('is_day')) {
    function is_day(): bool {
        return false;
    }
}

if (!function_exists('is_month')) {
    function is_month(): bool {
        return false;
    }
}

if (!function_exists('is_category')) {
    function is_category(): bool {
        return false;
    }
}

if (!function_exists('is_tag')) {
    function is_tag(): bool {
        return false;
    }
}

if (!function_exists('is_tax')) {
    function is_tax(): bool {
        return false;
    }
}

if (!function_exists('is_author')) {
    function is_author(): bool {
        return false;
    }
}

if (!function_exists('is_post_type_archive')) {
    function is_post_type_archive(): bool {
        return false;
    }
}

if (!function_exists('get_the_ID')) {
    function get_the_ID(): int {
        global $mock_current_post_id;
        return (int) $mock_current_post_id;
    }
}

if (!function_exists('wp_register_script')) {
    function wp_register_script(string $handle, $src, $deps = array(), $ver = false, bool $in_footer = false): bool {
        return true;
    }
}

if (!function_exists('wp_enqueue_script')) {
    function wp_enqueue_script(string $handle, string $src = '', array $deps = array(), $ver = false, bool $in_footer = false): void {
    }
}

if (!function_exists('wp_add_inline_script')) {
    function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool {
        $GLOBALS['mock_inline_scripts'][$handle] = $data;
        return true;
    }
}

if (!class_exists('WP_Query')) {
    class WP_Query {
        public array $posts = [];
        public int $found_posts = 0;
        public int $max_num_pages = 0;

        public function __construct(array $args = []) {
            $callback = $GLOBALS['mock_wp_query'] ?? null;
            if (!is_callable($callback)) {
                return;
            }
            $result = $callback($args);
            if (!is_array($result)) {
                return;
            }
            $posts = $result['posts'] ?? [];
            $this->posts = is_array($posts) ? $posts : [];
            $this->found_posts = (int) ($result['found_posts'] ?? count($this->posts));
            $this->max_num_pages = (int) ($result['max_num_pages'] ?? 0);
        }
    }
}

if (!function_exists('get_post')) {
    function get_post($post = null) {
        global $mock_posts, $mock_current_post_id;
        if ($post === null || $post === 0) {
            $post = $mock_current_post_id;
        }
        if (is_numeric($post)) {
            return $mock_posts[(int) $post] ?? null;
        }
        if ($post instanceof WP_Post) {
            return $post;
        }
        return null;
    }
}

if (!function_exists('get_post_status')) {
    function get_post_status($post = null): string {
        if ($post instanceof WP_Post) {
            return $post->post_status;
        }
        $found = get_post($post);
        return $found instanceof WP_Post ? $found->post_status : '';
    }
}

if (!function_exists('get_post_types')) {
    function get_post_types(array $args = array(), string $output = 'names'): array {
        $types = $GLOBALS['mock_post_types'] ?? array('post', 'page');
        return is_array($types) ? $types : array('post', 'page');
    }
}

if (!function_exists('wp_make_link_relative')) {
    function wp_make_link_relative(string $link): string {
        $path = wp_parse_url($link, PHP_URL_PATH);
        return is_string($path) ? $path : '';
    }
}

if (!function_exists('wp_http_validate_url')) {
    function wp_http_validate_url(string $url) {
        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if (!in_array($scheme, array('http', 'https'), true) || $host === '') {
            return false;
        }
        return $url;
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta(int $post_id, string $key = '', bool $single = false) {
        global $mock_post_meta;
        if (empty($key)) {
            return $mock_post_meta[$post_id] ?? [];
        }
        return $mock_post_meta[$post_id][$key] ?? ($single ? '' : []);
    }
}

if (!function_exists('update_post_meta')) {
    function update_post_meta(int $post_id, string $key, $value, $prev_value = ''): bool {
        global $mock_post_meta;
        if (!isset($mock_post_meta[$post_id])) {
            $mock_post_meta[$post_id] = [];
        }
        $mock_post_meta[$post_id][$key] = $value;
        return true;
    }
}

if (!function_exists('delete_post_meta')) {
    function delete_post_meta(int $post_id, string $key, $value = ''): bool {
        unset($GLOBALS['mock_post_meta'][$post_id][$key]);
        return true;
    }
}

if (!function_exists('get_attached_file')) {
    function get_attached_file($attachment_id, bool $unfiltered = true): string|false {
        unset($unfiltered);
        $id = (int) $attachment_id;
        $file = $GLOBALS['mock_attached_files'][$id] ?? false;
        return is_string($file) ? $file : false;
    }
}

if (!function_exists('update_attached_file')) {
    function update_attached_file(int $attachment_id, string $file): bool {
        $GLOBALS['mock_attached_files'][$attachment_id] = $file;
        return true;
    }
}

if (!function_exists('get_post_mime_type')) {
    function get_post_mime_type($post = null): string {
        $id = is_object($post) ? (int) $post->ID : (int) $post;
        return (string) ($GLOBALS['mock_post_mime'][$id] ?? '');
    }
}

if (!function_exists('wp_insert_attachment')) {
    function wp_insert_attachment(array $attachment, $file = false, int $parent_post = 0, bool $wp_error = false, bool $fire_after_hooks = true) {
        unset($parent_post, $wp_error, $fire_after_hooks);
        $id = (int) ($GLOBALS['mock_next_attachment_id'] ?? 900);
        $GLOBALS['mock_next_attachment_id'] = $id + 1;
        $GLOBALS['mock_inserted_attachments'][] = array(
            'id' => $id,
            'attachment' => $attachment,
            'file' => $file,
        );
        $GLOBALS['mock_posts'][$id] = new WP_Post(array(
            'ID' => $id,
            'post_title' => (string) ($attachment['post_title'] ?? ''),
            'post_type' => 'attachment',
            'post_status' => (string) ($attachment['post_status'] ?? 'inherit'),
        ));
        if (is_string($file) && $file !== '') {
            $GLOBALS['mock_attached_files'][$id] = $file;
        }
        return $id;
    }
}

if (!function_exists('get_the_title')) {
    function get_the_title($post = 0): string {
        $p = get_post($post);
        return $p ? $p->post_title : 'Test Title';
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $show = 'name'): string {
        if ($show === 'name') {
            $name = $GLOBALS['mock_blogname'] ?? 'Holvek Test Site';
            return is_string($name) ? $name : 'Holvek Test Site';
        }
        if ($show === 'description') {
            return 'AI Powered SEO Plugin';
        }
        return '';
    }
}

if (!function_exists('get_permalink')) {
    function get_permalink($post = 0): string {
        $id = is_numeric($post) && $post > 0 ? (int) $post : get_the_ID();
        return "https://example.com/post-{$id}/";
    }
}

if (!function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string {
        return 'https://example.com' . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}

if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags(string $string, bool $remove_breaks = false): string {
        return strip_tags($string);
    }
}

if (!function_exists('wp_kses_post')) {
    function wp_kses_post(string $data): string {
        $data = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $data) ?? $data;
        $data = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*/?>#is', '', $data) ?? $data;
        $data = preg_replace('/\s+on[a-z]+\s*=\s*(\'[^\']*\'|"[^"]*"|[^\s>]+)/i', '', $data) ?? $data;
        $data = preg_replace('/javascript\s*:/i', '', $data) ?? $data;
        return $data;
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words(string $text, int $num_words = 55, ?string $more = null): string {
        $words = preg_split('/\s+/', trim($text));
        if (count($words) <= $num_words) {
            return trim($text);
        }
        return implode(' ', array_slice($words, 0, $num_words)) . ($more ?? '…');
    }
}

if (!function_exists('get_post_field')) {
    function get_post_field(string $field, $post = 0): string {
        $p = get_post($post);
        return $p && isset($p->$field) ? (string) $p->$field : '';
    }
}

if (!function_exists('get_the_date')) {
    function get_the_date(string $format = '', $post = 0): string {
        return '2026-01-01T12:00:00+00:00';
    }
}

if (!function_exists('get_the_modified_date')) {
    function get_the_modified_date(string $format = '', $post = 0): string {
        return '2026-01-02T12:00:00+00:00';
    }
}

if (!function_exists('get_the_author_meta')) {
    function get_the_author_meta(string $field = '', $user_id = false): string {
        if ($field === 'display_name') return 'John Doe';
        if ($field === 'description') return 'Author bio';
        return '';
    }
}

if (!function_exists('get_author_posts_url')) {
    function get_author_posts_url(int $author_id): string {
        return "https://example.com/author/{$author_id}/";
    }
}

if (!function_exists('get_site_icon_url')) {
    function get_site_icon_url(int $size = 512, string $url = '', int $blog_id = 0): string {
        return 'https://example.com/icon.png';
    }
}

if (!function_exists('has_post_thumbnail')) {
    function has_post_thumbnail($post = null): bool {
        return !empty($GLOBALS['mock_has_post_thumbnail']);
    }
}

if (!function_exists('get_the_post_thumbnail_url')) {
    function get_the_post_thumbnail_url($post = null, string|array $size = 'post-thumbnail'): string {
        $url = $GLOBALS['mock_thumbnail_url'] ?? '';
        return is_string($url) ? $url : '';
    }
}

if (!function_exists('get_queried_object')) {
    function get_queried_object() {
        return $GLOBALS['mock_queried_object'] ?? null;
    }
}

if (!function_exists('get_the_category')) {
    function get_the_category($post_id = false): array {
        return [];
    }
}

if (!function_exists('get_category_link')) {
    function get_category_link($category): string {
        return 'https://example.com/category/tech/';
    }
}

if (!function_exists('wp_get_post_categories')) {
    function wp_get_post_categories(int $post_id = 0, array $args = []): array {
        return [];
    }
}

if (!function_exists('get_the_excerpt')) {
    function get_the_excerpt($post = 0): string {
        $p = get_post($post);
        return $p ? $p->post_excerpt : '';
    }
}

if (!function_exists('get_post_modified_time')) {
    function get_post_modified_time(string $format = 'U', bool $gmt = false, $post = null, bool $translate = false) {
        return '2026-01-02T12:00:00+00:00';
    }
}

if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data, int $options = 0, int $depth = 512): string {
        $json = json_encode($data, $options, $depth);
        return is_string($json) ? $json : '';
    }
}

if (!function_exists('wp_get_inline_script_tag')) {
    function wp_get_inline_script_tag(string $data, array $attributes = array()): string {
        $attrs = '';
        foreach ($attributes as $name => $value) {
            $attrs .= ' ' . $name . '="' . (string) $value . '"';
        }
        return "<script{$attrs}>\n{$data}\n</script>\n";
    }
}

$GLOBALS['mock_hooks'] = array();

if (!function_exists('add_filter')) {
    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): true {
        $GLOBALS['mock_hooks'][] = array( 'filter', $hook_name );
        if (!isset($GLOBALS['mock_filter_callbacks']) || !is_array($GLOBALS['mock_filter_callbacks'])) {
            $GLOBALS['mock_filter_callbacks'] = array();
        }
        $GLOBALS['mock_filter_callbacks'][$hook_name][$priority][] = array( $callback, $accepted_args );
        return true;
    }
}

if (!function_exists('apply_filters')) {
    function apply_filters(string $hook_name, $value, ...$args) {
        $hooks = $GLOBALS['mock_filter_callbacks'][$hook_name] ?? array();
        if (!is_array($hooks)) {
            return $value;
        }
        ksort($hooks);
        foreach ($hooks as $callbacks) {
            if (!is_array($callbacks)) {
                continue;
            }
            foreach ($callbacks as $entry) {
                $callback = $entry[0];
                $accepted = max(1, (int) ($entry[1] ?? 1));
                $callArgs = array($value);
                if ($accepted > 1) {
                    $callArgs = array_merge($callArgs, array_slice($args, 0, $accepted - 1));
                }
                $value = $callback(...$callArgs);
            }
        }
        return $value;
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type, bool $gmt = false): string {
        return '2026-10-02 12:00:00';
    }
}

if (!function_exists('mysql2date')) {
    function mysql2date(string $format, string $date, bool $translate = true): string {
        unset($translate);
        $timestamp = strtotime($date . ' UTC');
        if ($timestamp === false) {
            return '';
        }
        if ($format === 'c') {
            return gmdate('c', $timestamp);
        }
        return gmdate($format, $timestamp);
    }
}

if (!function_exists('get_query_var')) {
    function get_query_var(string $query_var, $default = '') {
        $vars = $GLOBALS['mock_query_vars'] ?? array();
        if (!is_array($vars) || !array_key_exists($query_var, $vars)) {
            return $default;
        }
        return $vars[$query_var];
    }
}

if (!function_exists('set_query_var')) {
    function set_query_var(string $query_var, $value): void {
        if (!isset($GLOBALS['mock_query_vars']) || !is_array($GLOBALS['mock_query_vars'])) {
            $GLOBALS['mock_query_vars'] = array();
        }
        $GLOBALS['mock_query_vars'][$query_var] = $value;
    }
}

if (!function_exists('get_current_screen')) {
    function get_current_screen() {
        return $GLOBALS['mock_current_screen'] ?? null;
    }
}

if (!function_exists('add_rewrite_rule')) {
    function add_rewrite_rule(string $regex, string $query, string $after = 'bottom'): void {
        if (!isset($GLOBALS['mock_rewrite_rules']) || !is_array($GLOBALS['mock_rewrite_rules'])) {
            $GLOBALS['mock_rewrite_rules'] = array();
        }
        $GLOBALS['mock_rewrite_rules'][$regex] = $query;
        if (!isset($GLOBALS['wp_rewrite']) || !is_object($GLOBALS['wp_rewrite'])) {
            $GLOBALS['wp_rewrite'] = new stdClass();
        }
        if (!isset($GLOBALS['wp_rewrite']->extra_rules_top) || !is_array($GLOBALS['wp_rewrite']->extra_rules_top)) {
            $GLOBALS['wp_rewrite']->extra_rules_top = array();
        }
        if ($after === 'top') {
            $GLOBALS['wp_rewrite']->extra_rules_top[$regex] = $query;
        }
    }
}

if (!function_exists('flush_rewrite_rules')) {
    function flush_rewrite_rules(bool $hard = true): void {
        unset($hard);
        $GLOBALS['mock_flush_count'] = (int) ($GLOBALS['mock_flush_count'] ?? 0) + 1;
    }
}

if (!function_exists('add_action')) {
    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): true {
        $GLOBALS['mock_hooks'][] = array( 'action', $hook_name );
        return true;
    }
}

if (!function_exists('do_action')) {
    function do_action(string $hook_name, mixed ...$args): void {
        unset($args);
        if (!isset($GLOBALS['mock_did_actions']) || !is_array($GLOBALS['mock_did_actions'])) {
            $GLOBALS['mock_did_actions'] = array();
        }
        $GLOBALS['mock_did_actions'][] = $hook_name;
    }
}

if (!function_exists('is_ssl')) {
    function is_ssl(): bool {
        return false;
    }
}

global $mock_rest_routes;
$mock_rest_routes = [];
if (!function_exists('register_post_meta')) {
    function register_post_meta(string $post_type, string $meta_key, array $args): bool {
        if (!isset($GLOBALS['mock_registered_post_meta']) || !is_array($GLOBALS['mock_registered_post_meta'])) {
            $GLOBALS['mock_registered_post_meta'] = array();
        }
        $GLOBALS['mock_registered_post_meta'][$post_type][$meta_key] = $args;
        return true;
    }
}

if (!function_exists('register_rest_route')) {
    function register_rest_route(string $namespace, string $route, array $args = [], bool $override = false): bool {
        global $mock_rest_routes;
        $mock_rest_routes[$namespace . $route] = $args;
        return true;
    }
}

if (!function_exists('wp_unslash')) {
    function wp_unslash($value) {
        if (is_array($value)) {
            return array_map('wp_unslash', $value);
        }
        return is_string($value) ? stripslashes($value) : $value;
    }
}

if (!function_exists('wp_next_scheduled')) {
    function wp_next_scheduled(string $hook, array $args = array()) {
        $sets = array(
            $GLOBALS['mock_cron_events'] ?? array(),
            $GLOBALS['mock_recurring_events'] ?? array(),
        );
        foreach ($sets as $events) {
            if (!is_array($events)) {
                continue;
            }
            foreach ($events as $event) {
                if (is_array($event) && ($event['hook'] ?? '') === $hook) {
                    return (int) ($event['timestamp'] ?? time());
                }
            }
        }
        return false;
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, string $hook, array $args = array(), bool $wp_error = false) {
        if (!isset($GLOBALS['mock_cron_events']) || !is_array($GLOBALS['mock_cron_events'])) {
            $GLOBALS['mock_cron_events'] = array();
        }
        $GLOBALS['mock_cron_events'][] = array(
            'timestamp' => $timestamp,
            'hook' => $hook,
            'args' => $args,
        );
        return true;
    }
}

if (!function_exists('_get_cron_array')) {
    function _get_cron_array() {
        $crons = $GLOBALS['mock_cron_array'] ?? array();
        return is_array($crons) ? $crons : array();
    }
}

if (!function_exists('wp_clear_scheduled_hook')) {
    function wp_clear_scheduled_hook(string $hook, array $args = array()) {
        if (!isset($GLOBALS['mock_cleared_hooks']) || !is_array($GLOBALS['mock_cleared_hooks'])) {
            $GLOBALS['mock_cleared_hooks'] = array();
        }
        $GLOBALS['mock_cleared_hooks'][] = $hook;
        if (isset($GLOBALS['mock_recurring_events']) && is_array($GLOBALS['mock_recurring_events'])) {
            $GLOBALS['mock_recurring_events'] = array_values(array_filter(
                $GLOBALS['mock_recurring_events'],
                static function ($event) use ($hook): bool {
                    return !is_array($event) || ($event['hook'] ?? '') !== $hook;
                }
            ));
        }
        return 1;
    }
}

if (!function_exists('get_post_type_object')) {
    function get_post_type_object(string $post_type) {
        $public = in_array($post_type, array('post', 'page'), true);
        if (isset($GLOBALS['mock_post_type_public']) && is_array($GLOBALS['mock_post_type_public']) && array_key_exists($post_type, $GLOBALS['mock_post_type_public'])) {
            $public = (bool) $GLOBALS['mock_post_type_public'][$post_type];
        }
        return (object) array(
            'name' => $post_type,
            'public' => $public,
        );
    }
}

if (!function_exists('wp_schedule_event')) {
    function wp_schedule_event($timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false) {
        if (!isset($GLOBALS['mock_recurring_events']) || !is_array($GLOBALS['mock_recurring_events'])) {
            $GLOBALS['mock_recurring_events'] = array();
        }
        $GLOBALS['mock_recurring_events'][] = array(
            'timestamp' => $timestamp,
            'recurrence' => $recurrence,
            'hook' => $hook,
            'args' => $args,
        );
        return true;
    }
}

if (!function_exists('wp_unschedule_event')) {
    function wp_unschedule_event($timestamp, string $hook, array $args = array(), bool $wp_error = false) {
        $events = $GLOBALS['mock_recurring_events'] ?? array();
        if (!is_array($events)) {
            return false;
        }
        $kept = array();
        $removed = false;
        foreach ($events as $event) {
            $same = is_array($event)
                && ($event['hook'] ?? '') === $hook
                && (int) ($event['timestamp'] ?? 0) === (int) $timestamp;
            if (!$removed && $same) {
                $removed = true;
                continue;
            }
            $kept[] = $event;
        }
        $GLOBALS['mock_recurring_events'] = $kept;
        return $removed;
    }
}

if (!function_exists('add_shortcode')) {
    function add_shortcode(string $tag, callable $callback): void {
        if (!isset($GLOBALS['mock_shortcodes']) || !is_array($GLOBALS['mock_shortcodes'])) {
            $GLOBALS['mock_shortcodes'] = array();
        }
        $GLOBALS['mock_shortcodes'][] = $tag;
    }
}

if (!function_exists('shortcode_atts')) {
    function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array {
        $out = $pairs;
        foreach ($pairs as $name => $default) {
            if (array_key_exists($name, $atts)) {
                $out[$name] = $atts[$name];
            }
        }
        return $out;
    }
}

if (!function_exists('get_posts')) {
    function get_posts(array $args = array()): array {
        $GLOBALS['mock_get_posts_args'] = $args;
        $callback = $GLOBALS['mock_get_posts'] ?? null;
        if (is_callable($callback)) {
            $posts = $callback($args);
            return is_array($posts) ? $posts : array();
        }
        return array();
    }
}

if (!function_exists('update_post_caches')) {
    function update_post_caches($posts, string $post_type = 'post', bool $update_term_cache = true, bool $update_meta_cache = true): void {
    }
}

if (!function_exists('get_post_type')) {
    function get_post_type($post = null) {
        if (is_object($post) && isset($post->post_type)) {
            return (string) $post->post_type;
        }
        return 'post';
    }
}

if (!function_exists('get_edit_post_link')) {
    function get_edit_post_link($post = 0, string $context = 'display'): string {
        return '';
    }
}

if (!function_exists('wp_mail')) {
    function wp_mail($to, string $subject, string $message, $headers = '', $attachments = array()): bool {
        if (!isset($GLOBALS['mock_mail']) || !is_array($GLOBALS['mock_mail'])) {
            $GLOBALS['mock_mail'] = array();
        }
        $GLOBALS['mock_mail'][] = array(
            'to' => $to,
            'subject' => $subject,
            'message' => $message,
        );
        return true;
    }
}

if (!function_exists('wp_cache_get')) {
    function wp_cache_get($key, string $group = '', bool $force = false, &$found = null) {
        $group = (string) $group;
        $found = isset($GLOBALS['mock_object_cache'][$group][$key]);
        if (!$found) {
            return false;
        }
        return $GLOBALS['mock_object_cache'][$group][$key];
    }
}

if (!function_exists('wp_cache_set')) {
    function wp_cache_set($key, $data, string $group = '', int $expire = 0): bool {
        if (!isset($GLOBALS['mock_object_cache']) || !is_array($GLOBALS['mock_object_cache'])) {
            $GLOBALS['mock_object_cache'] = array();
        }
        $GLOBALS['mock_object_cache'][(string) $group][$key] = $data;
        return true;
    }
}

if (!function_exists('wp_cache_delete')) {
    function wp_cache_delete($key, string $group = ''): bool {
        unset($GLOBALS['mock_object_cache'][(string) $group][$key]);
        return true;
    }
}

if (!function_exists('get_transient')) {
    function get_transient(string $transient) {
        global $mock_transients;
        if (!is_array($mock_transients) || !array_key_exists($transient, $mock_transients)) {
            return false;
        }
        return $mock_transients[$transient];
    }
}

if (!function_exists('set_transient')) {
    function set_transient(string $transient, $value, int $expiration = 0): bool {
        global $mock_transients;
        if (!is_array($mock_transients)) {
            $mock_transients = [];
        }
        $mock_transients[$transient] = $value;
        return true;
    }
}

if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return !empty($GLOBALS['mock_is_multisite']);
    }
}

if (!function_exists('get_current_blog_id')) {
    function get_current_blog_id(): int {
        return (int) ($GLOBALS['mock_current_blog_id'] ?? 1);
    }
}

if (!function_exists('switch_to_blog')) {
    function switch_to_blog(int $blogId): bool {
        if (!isset($GLOBALS['mock_switched_blogs']) || !is_array($GLOBALS['mock_switched_blogs'])) {
            $GLOBALS['mock_switched_blogs'] = array();
        }
        $GLOBALS['mock_switched_blogs'][] = $blogId;
        if (!isset($GLOBALS['mock_blog_stack']) || !is_array($GLOBALS['mock_blog_stack'])) {
            $GLOBALS['mock_blog_stack'] = array();
        }
        $GLOBALS['mock_blog_stack'][] = get_current_blog_id();
        $GLOBALS['mock_current_blog_id'] = $blogId;
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']->prefix = $blogId <= 1 ? 'wp_' : 'wp_' . $blogId . '_';
        }
        return true;
    }
}

if (!function_exists('restore_current_blog')) {
    function restore_current_blog(): bool {
        $stack = $GLOBALS['mock_blog_stack'] ?? array();
        if (!is_array($stack)) {
            $stack = array();
        }
        $previous = array_pop($stack);
        $GLOBALS['mock_blog_stack'] = $stack;
        $blogId = (int) ($previous ?? 1);
        $GLOBALS['mock_current_blog_id'] = $blogId;
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $GLOBALS['wpdb']->prefix = $blogId <= 1 ? 'wp_' : 'wp_' . $blogId . '_';
        }
        return true;
    }
}

if (!function_exists('get_sites')) {
    function get_sites(array $args = array()): array {
        unset($args);
        $GLOBALS['mock_sites_calls'] = (int) ($GLOBALS['mock_sites_calls'] ?? 0) + 1;
        $sites = $GLOBALS['mock_sites'] ?? array();
        return is_array($sites) ? $sites : array();
    }
}

if (!function_exists('get_site_option')) {
    function get_site_option(string $option, $default = false) {
        $options = $GLOBALS['mock_site_options'] ?? array();
        if (is_array($options) && array_key_exists($option, $options)) {
            return $options[$option];
        }
        return $default;
    }
}

if (!function_exists('update_site_option')) {
    function update_site_option(string $option, $value): bool {
        if (!isset($GLOBALS['mock_site_options']) || !is_array($GLOBALS['mock_site_options'])) {
            $GLOBALS['mock_site_options'] = array();
        }
        $GLOBALS['mock_site_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('plugin_basename')) {
    function plugin_basename(string $file): string {
        return basename(dirname($file)) . '/' . basename($file);
    }
}

if (!function_exists('wp_upload_dir')) {
    function wp_upload_dir($time = null, bool $create = true): array {
        unset($time, $create);
        if (isset($GLOBALS['mock_upload_dir']) && is_array($GLOBALS['mock_upload_dir'])) {
            return $GLOBALS['mock_upload_dir'];
        }
        return array(
            'basedir' => sys_get_temp_dir(),
            'baseurl' => 'https://example.com/uploads',
            'error' => false,
        );
    }
}

if (!function_exists('wp_count_posts')) {
    function wp_count_posts(string $type = 'post', string $perm = '') {
        unset($type, $perm);
        $count = $GLOBALS['mock_published_posts'] ?? 0;
        return (object) array('publish' => (int) $count);
    }
}

if (!function_exists('dbDelta')) {
    function dbDelta($queries = '', bool $execute = true) {
        unset($execute);
        if (!isset($GLOBALS['mock_db_delta']) || !is_array($GLOBALS['mock_db_delta'])) {
            $GLOBALS['mock_db_delta'] = array();
        }
        $GLOBALS['mock_db_delta'][] = $queries;
        return array();
    }
}

if (!class_exists('MockWpRole')) {
    class MockWpRole {
        public string $name;
        public array $caps = array();
        public array $removed = array();

        public function __construct(string $name) {
            $this->name = $name;
        }

        public function add_cap(string $cap, bool $grant = true): void {
            $this->caps[$cap] = $grant;
            $GLOBALS['mock_role_events'][] = array(
                'action' => 'add',
                'role' => $this->name,
                'cap' => $cap,
                'blog' => get_current_blog_id(),
            );
        }

        public function remove_cap(string $cap): void {
            unset($this->caps[$cap]);
            $this->removed[] = $cap;
            $GLOBALS['mock_role_events'][] = array(
                'action' => 'remove',
                'role' => $this->name,
                'cap' => $cap,
                'blog' => get_current_blog_id(),
            );
        }
    }
}

if (!function_exists('get_role')) {
    function get_role(string $role) {
        if (!isset($GLOBALS['mock_roles']) || !is_array($GLOBALS['mock_roles'])) {
            $GLOBALS['mock_roles'] = array();
        }
        if (!isset($GLOBALS['mock_roles'][$role])) {
            $GLOBALS['mock_roles'][$role] = new MockWpRole($role);
        }
        return $GLOBALS['mock_roles'][$role];
    }
}

if (!function_exists('add_query_arg')) {
    function add_query_arg(...$args): string {
        if ($args === array()) {
            return '';
        }
        if (is_array($args[0])) {
            $params = $args[0];
            $url = isset($args[1]) ? (string) $args[1] : '';
        } else {
            $params = array((string) $args[0] => $args[1] ?? '');
            $url = isset($args[2]) ? (string) $args[2] : '';
        }

        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        $question = strpos($url, '?');
        if ($question === false) {
            $base = $url;
            $existing = array();
        } else {
            $base = substr($url, 0, $question);
            $existing = array();
            parse_str(substr($url, $question + 1), $existing);
            if (!is_array($existing)) {
                $existing = array();
            }
        }

        foreach ($params as $key => $value) {
            if ($value === false || $value === null) {
                unset($existing[$key]);
                continue;
            }
            $existing[$key] = $value;
        }

        $query = http_build_query($existing, '', '&', PHP_QUERY_RFC3986);
        return $base . ($query !== '' ? '?' . $query : '') . $fragment;
    }
}

if (!function_exists('admin_url')) {
    function admin_url(string $path = '', string $scheme = 'admin'): string {
        return 'https://example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (!function_exists('rest_url')) {
    function rest_url(string $path = '', string $scheme = 'json'): string {
        return 'https://example.com/wp-json/' . ltrim($path, '/');
    }
}

if (!function_exists('sanitize_key')) {
    function sanitize_key(string $key): string {
        $key = strtolower($key);
        $sanitized = preg_replace('/[^a-z0-9_\-]/', '', $key);
        return is_string($sanitized) ? $sanitized : '';
    }
}

if (!function_exists('delete_transient')) {
    function delete_transient(string $transient): bool {
        global $mock_transients;
        if (is_array($mock_transients)) {
            unset($mock_transients[$transient]);
        }
        return true;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id(): int {
        return (int) ($GLOBALS['mock_current_user_id'] ?? 1);
    }
}

if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in(): bool {
        if (array_key_exists('mock_is_user_logged_in', $GLOBALS)) {
            return (bool) $GLOBALS['mock_is_user_logged_in'];
        }
        return get_current_user_id() > 0;
    }
}

if (!function_exists('get_userdata')) {
    function get_userdata(int $user_id) {
        $users = $GLOBALS['mock_users'] ?? array();
        return $users[$user_id] ?? false;
    }
}

if (!function_exists('user_can')) {
    function user_can($user, string $capability, ...$args): bool {
        if (is_object($user) && isset($user->caps) && is_array($user->caps)) {
            return !empty($user->caps[$capability]);
        }
        return false;
    }
}

if (!function_exists('wp_generate_password')) {
    function wp_generate_password(int $length = 12, bool $special_chars = true, bool $extra_special_chars = false): string {
        if (isset($GLOBALS['mock_generated_password'])) {
            return (string) $GLOBALS['mock_generated_password'];
        }
        return bin2hex(random_bytes((int) max(1, ceil($length / 2))));
    }
}

if (!function_exists('get_categories')) {
    function get_categories(array $args = array()): array {
        $categories = $GLOBALS['mock_categories'] ?? array();
        return is_array($categories) ? $categories : array();
    }
}

if (!function_exists('tests_mock_http_response')) {
    function tests_mock_http_response(string $url) {
        if (isset($GLOBALS['mock_http_queue']) && is_array($GLOBALS['mock_http_queue']) && $GLOBALS['mock_http_queue'] !== array()) {
            return array_shift($GLOBALS['mock_http_queue']);
        }
        $map = $GLOBALS['mock_http_responses'] ?? array();
        if (is_array($map) && isset($map[$url])) {
            return $map[$url];
        }
        if (is_array($map)) {
            foreach ($map as $needle => $response) {
                if (is_string($needle) && $needle !== '' && str_contains($url, $needle)) {
                    return $response;
                }
            }
        }
        return $GLOBALS['mock_http_response'] ?? array('body' => '{}');
    }
}

if (!function_exists('wp_safe_remote_post')) {
    function wp_safe_remote_post(string $url, array $args = array()) {
        if (!isset($GLOBALS['mock_http_log']) || !is_array($GLOBALS['mock_http_log'])) {
            $GLOBALS['mock_http_log'] = array();
        }
        $GLOBALS['mock_http_log'][] = array(
            'method' => 'POST',
            'url' => $url,
            'args' => $args,
        );
        return tests_mock_http_response($url);
    }
}

if (!function_exists('wp_safe_remote_head')) {
    function wp_safe_remote_head(string $url, array $args = array()) {
        if (!isset($GLOBALS['mock_http_log']) || !is_array($GLOBALS['mock_http_log'])) {
            $GLOBALS['mock_http_log'] = array();
        }
        $GLOBALS['mock_http_log'][] = array(
            'method' => 'HEAD',
            'url' => $url,
            'args' => $args,
        );
        return tests_mock_http_response($url);
    }
}

if (!function_exists('wp_safe_remote_get')) {
    function wp_safe_remote_get(string $url, array $args = array()) {
        if (!isset($GLOBALS['mock_http_log']) || !is_array($GLOBALS['mock_http_log'])) {
            $GLOBALS['mock_http_log'] = array();
        }
        $GLOBALS['mock_http_log'][] = array(
            'method' => 'GET',
            'url' => $url,
            'args' => $args,
        );
        return tests_mock_http_response($url);
    }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response): string {
        if (is_array($response) && isset($response['body'])) {
            return (string) $response['body'];
        }
        return '';
    }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response): int {
        if (!is_array($response)) {
            return 0;
        }
        if (isset($response['response']['code'])) {
            return (int) $response['response']['code'];
        }
        return (int) ($response['code'] ?? 0);
    }
}

if (!function_exists('wp_remote_retrieve_header')) {
    function wp_remote_retrieve_header($response, string $header): string {
        if (!is_array($response) || !isset($response['headers']) || !is_array($response['headers'])) {
            return '';
        }
        $header = strtolower($header);
        foreach ($response['headers'] as $name => $value) {
            if (strtolower((string) $name) === $header) {
                return is_array($value) ? (string) reset($value) : (string) $value;
            }
        }
        return '';
    }
}

if (!function_exists('wp_safe_redirect')) {
    function wp_safe_redirect(string $location, int $status = 302, string $x_redirect_by = 'WordPress'): bool {
        if (!isset($GLOBALS['mock_redirects']) || !is_array($GLOBALS['mock_redirects'])) {
            $GLOBALS['mock_redirects'] = array();
        }
        $GLOBALS['mock_redirects'][] = $location;
        if (!empty($GLOBALS['mock_redirect_throw'])) {
            throw new \RuntimeException('redirect:' . $location);
        }
        return true;
    }
}

if (!function_exists('absint')) {
    function absint($maybeint): int {
        return abs((int) $maybeint);
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        return @mkdir($target, 0777, true);
    }
}

if (!function_exists('wp_delete_file')) {
    function wp_delete_file(string $file): bool {
        return is_file($file) && unlink($file);
    }
}

if (!function_exists('site_url')) {
    function site_url(string $path = '', ?string $scheme = null): string {
        return 'https://example.com' . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}

if (!isset($GLOBALS['wp'])) {
    $wpObj = new \stdClass();
    $wpObj->request = '';
    $GLOBALS['wp'] = $wpObj;
}

if (!function_exists('remove_accents')) {
    function remove_accents(string $string, string $locale = ''): string {
        $chars = [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ý' => 'y', 'ÿ' => 'y', 'ñ' => 'n', 'ç' => 'c',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ý' => 'Y', 'Ñ' => 'N', 'Ç' => 'C',
        ];
        return strtr($string, $chars);
    }
}

if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path(string $file): string {
        return trailingslashit(dirname($file));
    }
}

if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url(string $file): string {
        return 'https://example.com/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}

if (!function_exists('trailingslashit')) {
    function trailingslashit(string $string): string {
        return rtrim($string, '/\\') . '/';
    }
}

if (!function_exists('register_activation_hook')) {
    function register_activation_hook(string $file, callable|array $callback): void {}
}

if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook(string $file, callable|array $callback): void {}
}
