<?php
/**
 * Plugin Name: Spinnio Audio Recorder
 * Description: Minimal logged-in-only in-browser audio recorder (MediaRecorder) that uploads to WordPress Media Library.
 * Version: 0.2.0
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
  const VERSION = '0.2.0';
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
   *
   * WordPress typically allows ogg/wav/mp3/m4a. WebM may not be allowed in some setups
   * or may be treated as video. This filter makes it acceptable in the Media Library.
   *
   * If you prefer to avoid changing global allowed mimes, you can remove this filter and
   * record to OGG on browsers that support it or handle conversion later.
   */
  public function allow_additional_mimes(array $mimes): array {
    // WebM can be reported as audio/webm (ideal) or video/webm (common sniff result),
    // so allow both to avoid the "not allowed to upload this file type" error.
    $mimes['webm'] = 'video/webm';
    $mimes['weba'] = 'audio/webm';
    // OGG (Opus in an Ogg container)
    $mimes['ogg']  = 'audio/ogg';

    return $mimes;
  }

  /**
   * Render recorder UI.
   *
   * Logged-in users only: if not logged in, show a simple message.
   * This is intentionally minimal for a POC.
   */
  public function render_shortcode($atts = []): string {
    if (!is_user_logged_in()) {
      return '<div class="sar-wrap"><p>You must be logged in to record audio.</p></div>';
    }

    // Enqueue assets only when shortcode renders.
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

    // UI container
    ob_start();
    ?>
    <div class="sar-wrap" data-sar="1">
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
          title="Upload the recorded audio to the Media Library"
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
   * Register REST routes.
   *
   * We create a dedicated upload endpoint instead of using /wp/v2/media to keep the
   * implementation explicit and easy to customize later.
   */
  public function register_rest_routes(): void {
    register_rest_route(self::REST_NAMESPACE, self::REST_ROUTE, [
      'methods' => 'POST',
      'callback' => [$this, 'handle_upload'],
      'permission_callback' => [$this, 'permissions_check'],
      'args' => [
        // upload_nonce is separate from wp_rest nonce; this is an extra guard you can remove if desired.
        'upload_nonce' => [
          'required' => true,
          'type' => 'string',
        ],
      ],
    ]);
  }

  /**
   * Permissions for upload:
   * - Must be logged in
   * - Must have upload_files capability (typical for Authors/Editors/Admins)
   */
  public function permissions_check(\WP_REST_Request $request): bool {
    if (!is_user_logged_in()) {
      return false;
    }
    if (!current_user_can('upload_files')) {
      return false;
    }

    // Additional nonce check for this specific action.
    $upload_nonce = (string) $request->get_param('upload_nonce');
    if (!wp_verify_nonce($upload_nonce, self::NONCE_ACTION)) {
      return false;
    }

    return true;
  }

  /**
   * Handle audio upload to Media Library.
   *
   * Expects multipart/form-data with:
   * - file (Blob)
   * - filename (optional; but recommended)
   * - upload_nonce (required)
   */
  public function handle_upload(\WP_REST_Request $request) {
    // Enforce size limits in PHP as a backstop.
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

    // WordPress media functions
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    // Optional: if client provides a filename, we set it here to help WP detect mime/ext.
    if (!empty($_POST['filename']) && is_string($_POST['filename'])) {
      $_FILES['file']['name'] = sanitize_file_name(wp_unslash($_POST['filename']));
    }

    // Use media_handle_upload to move file into uploads, create attachment, generate metadata.
    $attachment_id = media_handle_upload('file', 0, [
      'post_title' => $this->default_attachment_title(),
    ]);

    if (is_wp_error($attachment_id)) {
      return new \WP_REST_Response([
        'ok' => false,
        'error' => $attachment_id->get_error_message(),
      ], 500);
    }

    $url = wp_get_attachment_url($attachment_id);

    return new \WP_REST_Response([
      'ok' => true,
      'attachment_id' => $attachment_id,
      'url' => $url,
    ], 200);
  }

  private function default_attachment_title(): string {
    $user = wp_get_current_user();
    $name = $user && $user->exists() ? $user->display_name : 'user';
    return sprintf('Voice recording (%s) %s', $name, gmdate('Y-m-d H:i:s'));
  }
}

new Spinnio_Audio_Recorder();
