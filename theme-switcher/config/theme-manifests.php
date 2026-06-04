<?php

/**
 * Deactivation rules for installed theme plugins.
 *
 * This file only contains `deactivate` rules — CSS link selectors to zero out
 * and DOM elements to hide when the corresponding theme is NOT active.
 * Colors, fonts, and viteTheme paths are auto-discovered from each plugin's
 * register() method at runtime via PanelColorCapture.
 *
 * Add an entry here only when a theme injects CSS or DOM elements through a
 * side-channel (e.g. its own render hook) that ThemeSwitcherPlugin cannot
 * suppress by resetting $panel->viteTheme() alone.
 */
return [

    // ── Starry Night: custom CSS link + star/meteor DOM elements ─────────────
    'starrynight' => [
        'deactivate' => [
            'clearHref' => ['#starrynight-css'],
            'hide'      => ['#starrynight-stars', '.starrynight-meteors'],
        ],
    ],

    // ── Neobrutalism: thick borders/shadows defined in CSS rules, not vars ────
    // The plugin only sets viteTheme — it does not call $panel->colors(), so its
    // amber primary is provided here for the PanelColorCapture fallback.
    // Its Vite CSS clearHref selector is auto-generated from the built asset filename.
    'neobrutalism-theme' => [
        'colors' => [
            'primary' => \Filament\Support\Colors\Color::Amber,
        ],
    ],

    // Nord, AlienHost, Fluffy — fully auto-discovered (colors/font/viteTheme come from
    // the plugin; viteTheme clearHref selectors are auto-generated). No entry needed.

];
