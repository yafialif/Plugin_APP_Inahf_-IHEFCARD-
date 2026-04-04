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
        add_meta_box(
            'ca_recording_video',
            'Video URL',
            [$this, 'recording_video_callback'],
            'ca_recording',
            'normal',
            'default'
        );
        add_meta_box(
        'ca_agenda_waktu',
        'Waktu Agenda',
        [$this, 'render_waktu_agenda'],
        'ca_agenda',
        'side',
        'high'
    );
    }


    function render_waktu_agenda($post) {
    $value = get_post_meta($post->ID, 'ca_agenda_waktu', true);
    ?>
    <label for="ca_agenda_waktu">Waktu:</label>
    <input type="text" name="ca_agenda_waktu" value="<?php echo esc_attr($value); ?>" style="width:100%;" />
    <?php
    }
    public function recording_video_callback($post)
    {
        wp_nonce_field('ca_recording_nonce', 'ca_recording_nonce');

        $value = get_post_meta($post->ID, 'ca_video_url', true);
        ?>

        <label>Video URL</label>
        <input type="text" name="video_url"
               value="<?php echo esc_attr($value); ?>"
               style="width:100%;">

        <?php
    }

    public function save_meta($post_id)
    {

        if (array_key_exists('ca_agenda_waktu', $_POST)) {
        update_post_meta(
            $post_id,
            'ca_agenda_waktu',
            $_POST['ca_agenda_waktu']
        );
        }

        // Stop autosave
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Pastikan post type benar
        if (get_post_type($post_id) !== 'ca_recording') {
            return;
        }

        // Cek nonce
        if (!isset($_POST['ca_recording_nonce']) ||
            !wp_verify_nonce($_POST['ca_recording_nonce'], 'ca_recording_nonce')) {
            return;
        }

        // Cek permission
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Simpan data
        if (isset($_POST['video_url'])) {

            $video_url = sanitize_text_field($_POST['video_url']);

            update_post_meta(
                $post_id,
                'ca_video_url',
                $video_url
            );
        }
    }
}