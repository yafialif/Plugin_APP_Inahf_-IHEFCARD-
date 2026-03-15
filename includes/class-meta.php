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
        'recording_video',
        'Video URL',
        [$this, 'recording_video_callback'],
        'recording'
    );
       
    }


    function recording_video_callback($post) {

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

    }

}