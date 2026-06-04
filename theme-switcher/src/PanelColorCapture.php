<?php

namespace ThemeSwitcher;

use Filament\Panel;
use ReflectionClass;

/**
 * A bare Panel subclass used to capture what a theme plugin sets in its
 * register() method without affecting the live panel state.
 *
 * PHP 8.4 enforces the Panel $panel type hint on Plugin::register(), so we
 * must extend Panel rather than using a plain stub. All side-effect calls
 * (renderHook, authenticatedRoutes, etc.) are absorbed by Panel's own
 * implementation on this throw-away instance.
 */
class PanelColorCapture extends Panel
{
    /** Colors as-passed by the plugin (raw format — hex or OKLCH). */
    public function getCapturedColors(): array
    {
        return $this->getColors();
    }

    /** Font family name set via $panel->font(). */
    public function getCapturedFont(): ?string
    {
        foreach (['fontFamily', 'font'] as $prop) {
            try {
                $r = (new ReflectionClass(Panel::class))->getProperty($prop);
                $r->setAccessible(true);
                $value = $r->getValue($this);
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            } catch (\Throwable) {}
        }
        return null;
    }

    /** Vite theme CSS path set via $panel->viteTheme(). */
    public function getCapturedViteTheme(): ?string
    {
        try {
            $r = (new ReflectionClass(Panel::class))->getProperty('viteTheme');
            $r->setAccessible(true);
            $value = $r->getValue($this);
            if (is_string($value)) return $value;
            if (is_array($value)) return $value[0] ?? null;
        } catch (\Throwable) {}
        return null;
    }
}
