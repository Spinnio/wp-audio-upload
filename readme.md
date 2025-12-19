# Spinnio Audio Recorder (POC)

Minimal WordPress plugin that lets logged-in users record audio in-browser via MediaRecorder and uploads it to the WordPress Media Library.

## Features
- Logged-in users only
- Chrome-first (desktop + Android Chrome)
- Record / Stop / Preview / Upload
- Saves recording as a Media Library attachment
- No transcript, no AI, no extra metadata

## Installation
1. Copy this folder into:
   `wp-content/plugins/spinnio-audio-recorder/`
2. Activate the plugin in WordPress admin.
3. Add the shortcode to any page:
   `[spinnio_audio_recorder]`

## Security model
- Shortcode renders a message if user is not logged in
- Upload endpoint:
  - Requires logged-in user
  - Requires `upload_files` capability
  - Verifies a dedicated upload nonce
  - Requires WP REST nonce header (X-WP-Nonce)

## Configuration
You can change limits via filters (in a small must-use plugin or theme functions.php):

```php
add_filter('spinnio_audio_recorder_max_seconds', fn() => 600); // 10 minutes
add_filter('spinnio_audio_recorder_max_bytes', fn() => 50 * 1024 * 1024); // 50MB
