<?php
/**
 * Plugin Name: Spinnio Audio Recorder
 * Description: Minimal logged-in-only in-browser audio recorder (MediaRecorder) that uploads to WordPress Media Library.
 * Version: 0.1.0
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
  const VERSION = '0.1.0';
  const NONCE_ACTION = 'spinnio_audio_recorder_upload';
  const REST_NAMESPACE = 'spinnio-recorder/v1';
  const REST_ROUTE = '/upload';

  public function __construct() {
    add_action('init', [$this, 'register_shortcode']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
    add_action('rest_api_init', [$this, 'register_rest_routes']);
    add_filter('upload_mimes', [$this, 'allow_additional_mimes']);
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
   * Allow WebM/OGG audio uploads if needed.
   *
   * WordPress typically allows ogg/wav/mp3/m4a. WebM may not be allowed in some setups
   * or may be treated as video. This filter makes it acceptable in the Media Library.
   *
   * If you prefer to avoid changing global allowed mimes, you can remove this filter and
   * record to OGG on browsers that support it or handle conversion later.
   */
  public function allow_additional_mimes(array $mimes): array {
    // WebM (often Opus audio in a WebM container from Chrome)
    $mimes['webm'] = 'audio/webm';
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
    $config = [
      'restUrl' => esc_url_raw(rest_url(self::REST_NAMESPACE . self::REST_ROUTE)),
      'nonce' => wp_create_nonce('wp_rest'),
      'uploadNonce' => wp_create_nonce(self::NONCE_ACTION),
      'maxSeconds' => (int) apply_filters('spinnio_audio_recorder_max_seconds', 300), // default 5 minutes
      'maxBytes' => (int) apply_filters('spinnio_audio_recorder_max_bytes', 25 * 1024 * 1024), // default 25MB
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
        <span class="sar-hint">Chrome recommended. Keep recordings reasonably short.</span>
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
    $max_bytes = (int) apply_filters('spinnio_audio_recorder_max_bytes', 25 * 1024 * 1024);

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
