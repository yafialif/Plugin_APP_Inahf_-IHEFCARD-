<?php

namespace ContentAplikasi;

class Menu
{
    const MENU_SLUG = 'content-aplikasi';

    public function __construct()
    {
        add_action('admin_menu', [$this, 'register_menu']);
    }
     public function register_menu()
    {
                // MENU UTAMA
        add_menu_page(
            'Content Aplikasi',          // Page title
            'Content Aplikasi',          // Menu title
            'manage_options',            // Capability
            self::MENU_SLUG,              // Menu slug
            [$this, 'dashboard'],         // Callback
            'dashicons-admin-multisite', // Icon
            5                             // Position
        );
    }

    public function dashboard()
        {
    ?>
            <div class="wrap">
                <h1>Content Aplikasi</h1>
                <p>Kelola konten Event, HF Clinic, News, dan Recording melalui menu di samping.</p>
            </div>
    <?php
    }
}