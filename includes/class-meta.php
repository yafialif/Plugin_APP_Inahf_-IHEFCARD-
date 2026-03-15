<?php

namespace ContentAplikasi;

class Meta
{
    public function __construct()
    {
        add_action('add_meta_boxes', [$this, 'register_meta_boxes']);
        add_action('save_post', [$this, 'save_meta']);


    }

    public function register_meta_boxes()
    {
        // Meta box Recording
        add_meta_box(
        'ca_recording_video',
        'Video URL',
        [$this, 'recording_video_callback'],
        'ca_recording'
    );
       
    }


    function recording_video_callback($post) {
        wp_nonce_field('ca_recording_nonce', 'ca_recording_nonce');
    $value = get_post_meta($post->ID,'video_url',true);

    ?>
    <label>Video URL</label>
    <input type="text" name="video_url" value="<?php echo esc_attr($value); ?>" style="width:100%;">
    <?php

    }
     public function save_meta($post_id)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (isset($_POST['ca_recording_nonce']) &&
            wp_verify_nonce($_POST['ca_recording_nonce'], 'ca_recording_nonce')) {

            update_post_meta($post_id, 'ca_video_url',
                sanitize_text_field($_POST['video_url'] ?? '')
            );
        }

    }

}