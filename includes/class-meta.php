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

    }

     public function save_meta($post_id)
    {

    }

}