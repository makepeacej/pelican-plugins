<?php

namespace ThemeSwitcher\Services;

use Illuminate\Support\Facades\File;

class ThemeDiscoveryService
{
    /** @return array<string, string> [id => name] */
    public function discover(): array
    {
        $themes = [];

        foreach (File::directories(base_path('plugins')) as $dir) {
            $json = $dir . '/plugin.json';
            if (! file_exists($json)) {
                continue;
            }

            try {
                $data = json_decode(file_get_contents($json), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (($data['category'] ?? '') === 'theme'
                && ($data['meta']['status'] ?? '') === 'enabled'
            ) {
                $id   = strtolower($data['id'] ?? '');
                $name = $data['name'] ?? $id;
                if ($id !== '') {
                    $themes[$id] = $name;
                }
            }
        }

        return $themes;
    }
}
