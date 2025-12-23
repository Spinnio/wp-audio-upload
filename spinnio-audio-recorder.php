<?php
/**
 * Plugin Name: Spinnio Audio Recorder
 * Description: Minimal logged-in-only in-browser audio recorder (MediaRecorder) that uploads to WordPress Media Library (or custom storage via hook).
 * Version: 0.3.0
 * Author: Spinnio Ventures
 * License: GPLv2 or later
 *
 * Notes:
 * - Chrome-first. iOS browsers use WebKit and may be unreliable for MediaRecorder.
 * - Upload endpoint is authenticated; no anonymous uploads.
 */

if (!defined('ABSPATH')) {
  exit;
}

final class Spinnio_Audio_Recorder {
  const VERSION = '0.3.0';
  const NONCE_ACTION = 'spinnio_audio_recorder_upload';
  const REST_NAMESPACE = 'spinnio-recorder/v1';
  const REST_ROUTE = '/upload';
  const DEFAULT_MAX_BYTES = 25 * 1024 * 1024; // 25 MB
  const DEFAULT_HINT_TEXT = 'Chrome recommended. Keep recordings reasonably short.';

  public function __construct() {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_filter('upload_mimes', [$this, 'allow_additional_mimes']);
    add_action('admin_menu', [$this, 'register_settings_page']);
    add_action('admin_init', [$this, 'register_settings']);
  }

  /**
   * Register the [spinnio_audio_recorder] shortcode.
   */
  public function register_shortcode(): void {
    add_shortcode('spinnio_audio_recorder', [$this, 'render_shortcode']);
  }

  /**
   * Register JS/CSS (enqueued only when shortcode is present).
   */
  public function register_assets(): void {
    $base_url = plugin_dir_url(__FILE__);
    wp_register_style(
      'spinnio-audio-recorder',
      $base_url . 'assets/recorder.css',
      [],
      self::VERSION
    );

    wp_register_script(
      'spinnio-audio-recorder',
      $base_url . 'assets/recorder.js',
      [],
      self::VERSION,
      true
    );
  }

  /**
   * Admin settings page: menu entry.
   */
  public function register_settings_page(): void {
    add_options_page(
      'Spinnio Audio Recorder',
      'Spinnio Recorder',
      'manage_options',
      'spinnio-audio-recorder',
      [$this, 'render_settings_page']
    );
  }

  /**
   * Register settings and fields.
   */
  public function register_settings(): void {
    register_setting(
      'spinnio_audio_recorder',
      'spinnio_audio_recorder_max_bytes',
      [
        'type' => 'integer',
        'sanitize_callback' => [$this, 'sanitize_max_bytes'],
        'default' => self::DEFAULT_MAX_BYTES,
      ]
    );

    register_setting(
      'spinnio_audio_recorder',
      'spinnio_audio_recorder_hint_text',
      [
        'type' => 'string',
        'sanitize_callback' => [$this, 'sanitize_hint_text'],
        'default' => self::DEFAULT_HINT_TEXT,
      ]
    );

    add_settings_section(
      'spinnio_audio_recorder_main',
      'Recorder Settings',
      '__return_false',
      'spinnio-audio-recorder'
    );

    add_settings_field(
      'spinnio_audio_recorder_max_bytes',
      'Max file size (MB)',
      [$this, 'render_max_bytes_field'],
      'spinnio-audio-recorder',
      'spinnio_audio_recorder_main'
    );

    add_settings_field(
      'spinnio_audio_recorder_hint_text',
      'Helper text',
      [$this, 'render_hint_text_field'],
      'spinnio-audio-recorder',
      'spinnio_audio_recorder_main'
    );
  }

  /**
   * Max file size field (MB input; stored as bytes).
   */
  public function render_max_bytes_field(): void {
    $bytes = $this->get_max_bytes();
    $mb = (int) max(1, ceil($bytes / (1024 * 1024)));
    ?>
    <input
      type="number"
      id="spinnio_audio_recorder_max_bytes"
      name="spinnio_audio_recorder_max_bytes"
      min="1"
      step="1"
      value="<?php echo esc_attr($mb); ?>"
    />
    <p class="description">Saved in megabytes. The upload endpoint also enforces this limit.</p>
    <?php
  }

  /**
   * Helper text field.
   */
  public function render_hint_text_field(): void {
    $text = $this->get_hint_text();
    ?>
    <input
      type="text"
      id="spinnio_audio_recorder_hint_text"
      name="spinnio_audio_recorder_hint_text"
      class="regular-text"
      value="<?php echo esc_attr($text); ?>"
    />
    <p class="description">Displayed under the timer on the recorder UI.</p>
    <?php
  }

  /**
   * Settings page markup.
   */
  public function render_settings_page(): void {
    ?>
    <div class="wrap">
      <h1>Spinnio Audio Recorder</h1>
      <form method="post" action="options.php">
        <?php
        settings_fields('spinnio_audio_recorder');
        do_settings_sections('spinnio-audio-recorder');
        submit_button();
        ?>
      </form>
    </div>
    <?php
  }

  private function sanitize_max_bytes($value): int {
    $mb = absint($value);
    if ($mb <= 0) {
      return self::DEFAULT_MAX_BYTES;
    }
    return $mb * 1024 * 1024;
  }

  private function sanitize_hint_text($value): string {
    $value = is_string($value) ? $value : '';
    $value = trim($value);
    if ($value === '') {
      return self::DEFAULT_HINT_TEXT;
    }
    return sanitize_text_field($value);
  }

  private function get_max_bytes(): int {
    $stored = get_option('spinnio_audio_recorder_max_bytes', self::DEFAULT_MAX_BYTES);
    $bytes = (int) $stored;
    if ($bytes <= 0) {
      return self::DEFAULT_MAX_BYTES;
    }
    return $bytes;
  }

  private function get_hint_text(): string {
    $stored = get_option('spinnio_audio_recorder_hint_text', self::DEFAULT_HINT_TEXT);
    if (!is_string($stored) || trim($stored) === '') {
      return self::DEFAULT_HINT_TEXT;
    }
    return $stored;
  }

  /**
   * Allow WebM/OGG audio uploads if needed.
   */
  public function allow_additional_mimes(array $mimes): array {
    $mimes['webm'] = 'video/webm';
    $mimes['weba'] = 'audio/webm';
    $mimes['ogg']  = 'audio/ogg';
    return $mimes;
  }

  /**
   * Public render API for other plugins/themes.
   *
   * @param array $args Optional context args:
   *  - consumer (string)
   *  - reference_id (string|int)
   *  - requested_storage (string) e.g., "wordpress" or "bunny"
   *  - folder (string)
   */
  public function render_recorder(array $args = []): string {
    if (!is_user_logged_in()) {
      return '<div class="sar-wrap"><p>You must be logged in to record audio.</p></div>';
    }

    // Enqueue assets only when UI renders.
    wp_enqueue_style('spinnio-audio-recorder');
    wp_enqueue_script('spinnio-audio-recorder');

    // Config passed to JS.
    $max_bytes = $this->get_max_bytes();
    $config = [
      'restUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . self::REST_ROUTE)),
      'nonce' => wp_create_nonce('wp_rest'),
      'uploadNonce' => wp_create_nonce(self::NONCE_ACTION),
      'maxSeconds' => (int) apply_filters('spinnio_audio_recorder_max_seconds', 300), // default 5 minutes
      'maxBytes' => (int) apply_filters('spinnio_audio_recorder_max_bytes', $max_bytes),
    ];
    wp_localize_script('spinnio-audio-recorder', 'SpinnioAudioRecorder', $config);

    $defaults = [
      'consumer' => '',
      'reference_id' => '',
      'requested_storage' => '',
      'folder' => '',
    ];
    $args = wp_parse_args($args, $defaults);

    $data = [
      'data-sar' => '1',
    ];

    // Context data-* attributes (forwarded to server via JS -> FormData).
    if (!empty($args['consumer'])) $data['data-sar-consumer'] = (string) $args['consumer'];
    if (!empty($args['reference_id'])) $data['data-sar-reference-id'] = (string) $args['reference_id'];
    if (!empty($args['requested_storage'])) $data['data-sar-requested-storage'] = (string) $args['requested_storage'];
    if (!empty($args['folder'])) $data['data-sar-folder'] = (string) $args['folder'];

    $attr = '';
    foreach ($data as $k => $v) {
      $attr .= ' ' . esc_attr($k) . '="' . esc_attr($v) . '"';
    }

    ob_start();
    ?>
    <div class="sar-wrap"<?php echo $attr; ?>>
      <div class="sar-status" data-sar-status>Ready.</div>

      <div class="sar-controls">
        <button
          type="button"
          class="sar-btn"
          data-sar-start
          aria-label="Start recording"
          title="Start a new recording"
        >
          <span aria-hidden="true" class="sar-btn-icon">●</span>
          <span class="sar-btn-text">Record</span>
        </button>
        <button
          type="button"
          class="sar-btn"
          data-sar-stop
          aria-label="Stop recording"
          title="Stop the current recording"
          disabled
        >
          <span aria-hidden="true" class="sar-btn-icon">■</span>
          <span class="sar-btn-text">Stop</span>
        </button>
        <button
          type="button"
          class="sar-btn"
          data-sar-upload
          aria-label="Upload recording"
          title="Upload the recorded audio"
          disabled
        >
          <span aria-hidden="true" class="sar-btn-icon">⬆</span>
          <span class="sar-btn-text">Upload</span>
        </button>
        <button
          type="button"
          class="sar-btn sar-btn-secondary"
          data-sar-reset
          aria-label="Reset recorder"
          title="Clear the current recording and start over"
          disabled
        >
          <span aria-hidden="true" class="sar-btn-icon">↺</span>
          <span class="sar-btn-text">Reset</span>
        </button>
      </div>

      <div class="sar-meta">
        <span class="sar-timer" data-sar-timer>00:00</span>
        <span class="sar-hint"><?php echo esc_html($this->get_hint_text()); ?></span>
      </div>

      <div class="sar-preview">
        <audio controls data-sar-audio style="display:none;"></audio>
      </div>

      <div class="sar-result" data-sar-result style="display:none;">
        <div><strong>Saved:</strong> <a data-sar-url href="#" target="_blank" rel="noopener noreferrer">Open audio</a></div>
        <div><strong>Attachment ID:</strong> <span data-sar-id></span></div>
      </div>
    </div>
    <?php
    return (string) ob_get_clean();
  }

  /**
   * Shortcode handler (wraps render_recorder).
   *
   * Supported attrs:
   * [spinnio_audio_recorder consumer="" reference_id="" requested_storage="" folder=""]
   */
  public function render_shortcode($atts = []): string {
    $atts = shortcode_atts([
      'consumer' => '',
      'reference_id' => '',
      'requested_storage' => '',
      'folder' => '',
    ], (array) $atts, 'spinnio_audio_recorder');

    return $this->render_recorder($atts);
  }

  /**
   * Register REST routes.
   */
  public function register_rest_routes(): void {
    register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
      'methods' => 'POST',
      'callback' => [$this, 'handle_upload'],
      'permission_callback' => [$this, 'permissions_check'],
      'args' => [
        'upload_nonce' => [
          'required' => true,
          'type' => 'string',
        ],
        // Optional context fields.
        'consumer' => ['required' => false, 'type' => 'string'],
        'reference_id' => ['required' => false, 'type' => 'string'],
        'requested_storage' => ['required' => false, 'type' => 'string'],
        'folder' => ['required' => false, 'type' => 'string'],
      ],
    ]);
  }

  public function permissions_check(\WP_REST_Request $request): bool {
    if (!is_user_logged_in()) return false;
    if (!current_user_can('upload_files')) return false;

    $upload_nonce = (string) $request->get_param('upload_nonce');
    if (!wp_verify_nonce($upload_nonce, self::NONCE_ACTION)) return false;

    return true;
  }

  /**
   * Handle audio upload.
   *
   * Default: save to WP Media Library.
   * Extensible: other plugins can intercept and store elsewhere (e.g., Bunny)
   * via filter: spinnio_audio_recorder_handle_storage.
   *
   * Filter signature:
   *  apply_filters('spinnio_audio_recorder_handle_storage', null, $tmp_path, $context, $file_meta)
   * Return:
   *  - null to fall back to WP Media Library
   *  - array to indicate storage was handled externally
   *
   * Action after save:
   *  do_action('spinnio_audio_recorder_saved', $storage_result, $context)
   */
  public function handle_upload(\WP_REST_Request $request) {
    $max_bytes = (int) apply_filters('spinnio_audio_recorder_max_bytes', $this->get_max_bytes());

    if (empty($_FILES['file'])) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'Missing file field. Expected multipart/form-data with "file".'
      ], 400);
    }

    if (!empty($_FILES['file']['size']) && (int) $_FILES['file']['size'] > $max_bytes) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'File too large. Please record a shorter clip.',
      ], 413);
    }

    $context = [
      'consumer' => sanitize_text_field((string) $request->get_param('consumer')),
      'reference_id' => sanitize_text_field((string) $request->get_param('reference_id')),
      'requested_storage' => sanitize_text_field((string) $request->get_param('requested_storage')),
      'folder' => sanitize_text_field((string) $request->get_param('folder')),
    ];

    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // If client provides a filename, set it to help mime/ext detection.
    if (!empty($_POST['filename']) && is_string($_POST['filename'])) {
      $_FILES['file']['name'] = sanitize_file_name(wp_unslash($_POST['filename']));
    }

    $original = $_FILES['file'];

    // Move upload to our own temp path so we can (a) hand to external storage safely, and
    // (b) use media_handle_sideload without relying on the original PHP tmp file lifecycle.
    $tmp_path = wp_tempnam($original['name'] ?? 'recording.webm');
    if (!$tmp_path || !move_uploaded_file($original['tmp_name'], $tmp_path)) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => 'Unable to persist uploaded file to a temp location.',
      ], 500);
    }

    $file_meta = [
      'name' => sanitize_file_name($original['name'] ?? 'recording.webm'),
      'type' => (string) ($original['type'] ?? ''),
      'size' => (int) ($original['size'] ?? 0),
    ];

    // Give other plugins a chance to store externally (e.g., Bunny).
    $storage_result = apply_filters('spinnio_audio_recorder_handle_storage', null, $tmp_path, $context, $file_meta);

    if (is_array($storage_result)) {
      // External storage handled it; clean up temp file.
      if (file_exists($tmp_path)) @unlink($tmp_path);

      // Fire post-save hook.
      do_action('spinnio_audio_recorder_saved', $storage_result, $context);

      return new \WP_REST_Response([
        'ok' => true,
        // Back-compat keys (may be absent for external storage)
        'attachment_id' => $storage_result['attachment_id'] ?? null,
        'url' => $storage_result['url'] ?? null,
        'storage' => $storage_result,
      ], 200);
    }

    // Default: store in WP Media Library.
    $file_array = [
      'name' => sanitize_file_name($original['name'] ?? 'recording.webm'),
      'tmp_name' => $tmp_path,
    ];

    $attachment_id = media_handle_sideload($file_array, 0, [
      'post_title' => $this->default_attachment_title(),
    ]);

    if (is_wp_error($attachment_id)) {
      if (file_exists($tmp_path)) @unlink($tmp_path);

      return new \WP_REST_Response([
        'ok' => false,
        'error' => $attachment_id->get_error_message(),
      ], 500);
    }

    $url = wp_get_attachment_url($attachment_id);

    $storage_result = [
      'provider' => 'wordpress',
      'attachment_id' => $attachment_id,
      'url' => $url,
    ];

    do_action('spinnio_audio_recorder_saved', $storage_result, $context);

    return new \WP_REST_Response([
      'ok' => true,
      'attachment_id' => $attachment_id,
      'url' => $url,
      'storage' => $storage_result,
    ], 200);
  }

  private function default_attachment_title(): string {
    $user = wp_get_current_user();
    $name = $user && $user->exists() ? $user->display_name : 'user';
    return sprintf('Voice recording (%s) %s', $name, gmdate('Y-m-d H:i:s'));
  }
}

// Instantiate and expose a global instance for the helper function.
$GLOBALS['spinnio_audio_recorder'] = new Spinnio_Audio_Recorder();

/**
 * Helper function for other plugins/themes to render the recorder UI.
 *
 * Usage:
 *   echo spinnio_audio_recorder_render([
 *     'consumer' => 'my-plugin',
 *     'reference_id' => 123,
 *     'requested_storage' => 'bunny',
 *     'folder' => 'voice-memos',
 *   ]);
 */
function spinnio_audio_recorder_render(array $args = []): string {
  if (!isset($GLOBALS['spinnio_audio_recorder']) || !($GLOBALS['spinnio_audio_recorder'] instanceof Spinnio_Audio_Recorder)) {
    return '';
  }
  return $GLOBALS['spinnio_audio_recorder']->render_recorder($args);
}