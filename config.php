<?php
if (!defined('MDV')) { http_response_code(404); exit; } // settings only; never served on their own
// Edit these to suit your deployment.
return [
    // Folder holding your .md files and their images. Can live outside the web root.
    'docs_dir'      => __DIR__ . '/docs',

    // Shown in the sidebar header and browser tab.
    'site_title'    => 'Markdown Viewer',

    // auto, light, dark, sepia, solarized-light, solarized-dark, nord, dracula,
    // gruvbox-light, gruvbox-dark, one-dark, catppuccin-latte, catppuccin-mocha,
    // tokyo-night  (visitors can override; their choice is remembered)
    'default_theme' => 'auto',

    // First match is opened when no file is requested.
    'default_files' => ['README.md', 'index.md'],
];
