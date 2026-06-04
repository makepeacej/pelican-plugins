<?php

namespace ThemeSwitcher\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use ThemeSwitcher\Models\ThemePreference;

class ThemePreferenceController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'theme_id' => ['required', 'string', 'max:100'],
        ]);

        $themeId = $request->input('theme_id');
        $userId  = $request->user()->id;

        // Store 'default' explicitly so we can distinguish "chose default" from
        // "never set a preference" — both cases suppress custom themes differently.
        ThemePreference::updateOrCreate(
            ['user_id' => $userId],
            ['theme_id' => $themeId]
        );

        // Set a plain (unencrypted) cookie so the theme is readable at register()
        // time — before session/auth middleware runs. Theme IDs are not sensitive.
        setcookie('active_theme', $themeId, [
            'expires'  => time() + 86400 * 30,
            'path'     => '/',
            'samesite' => 'Lax',
            'secure'   => request()->isSecure(),
        ]);

        return response()->json(['ok' => true]);
    }
}
