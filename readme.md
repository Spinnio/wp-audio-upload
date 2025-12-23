# Spinnio Audio Recorder

Minimal WordPress plugin that lets logged-in users record audio in-browser via MediaRecorder and uploads it to the WordPress Media Library. Can be referenced by another plugin to store data on third party servers. Currently configured to work with bunny.net

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
Use the **Settings → Spinnio Recorder** page in wp-admin to set:
- Maximum upload size (MB)
- The helper text displayed under the timer on the recorder UI

You can also change limits via filters (in a small must-use plugin or theme functions.php):

```php
add_filter('spinnio_audio_recorder_max_seconds', fn() => 600); // 10 minutes
add_filter('spinnio_audio_recorder_max_bytes', fn() => 50 * 1024 * 1024); // 50MB
```
## Reuse from other plugins (PHP API)

Render the recorder UI from PHP:

```php
echo spinnio_audio_recorder_render([
  'consumer' => 'my-plugin',
  'reference_id' => 123,
  'requested_storage' => 'bunny',
  'folder' => 'voice-memos',
]);

## Shortcode context attributes

[spinnio_audio_recorder consumer="my-plugin" reference_id="123" requested_storage="bunny" folder="voice-memos"]

## External storage interception (for Bunny or anything else)
Hook the filter below from another plugin. If you return an array, this plugin will NOT save to WP Media Library.

add_filter('spinnio_audio_recorder_handle_storage', function($result, $tmp_path, $context, $file_meta) {
  // Upload $tmp_path to Bunny here...
  // Return an array describing where it was stored.
  return [
    'provider' => 'bunny',
    'url' => 'https://your-bunny-url.example/recordings/xyz.webm',
    // 'attachment_id' => optional
  ];
}, 10, 4);

## Post-save action hook
Fires after any successful storage (WP or external):

add_action('spinnio_audio_recorder_saved', function($storage_result, $context) {
  // Kick off transcription, create a post, etc.
}, 10, 2);


## How to test fast

1) Zip the plugin folder `wp-audio-upload-main/`  
2) Upload/replace in WordPress  
3) Put this on a logged-in-only page:
[spinnio_audio_recorder consumer=“test” reference_id=“42” requested_storage=“wordpress” folder=“demo”]
4) Record → Upload  
5) Confirm:
- Media Library has the file
- UI shows URL + Attachment ID