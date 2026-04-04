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

         add_submenu_page(
            self::MENU_SLUG,
            'Recording',
            'Recording',
            'manage_options',
            'edit.php?post_type=ca_recording'
        );
        add_submenu_page(
            self::MENU_SLUG,
            'Faculty',
            'Faculty',
            'manage_options',
            'edit.php?post_type=ca_faculty'
        );
         add_submenu_page(
            self::MENU_SLUG,
            'Agenda',
            'Agenda',
            'manage_options',
            'edit.php?post_type=ca_agenda'
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