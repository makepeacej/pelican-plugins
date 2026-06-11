<?php

namespace ThemeSwitcher;

use App\Contracts\Plugins\HasPluginSettings;
use App\Traits\EnvironmentWriterTrait;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\Support\Colors\ColorManager;
use Filament\Support\Facades\FilamentColor;
use Illuminate\Foundation\Vite;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use ReflectionClass;
use ThemeSwitcher\Http\Controllers\ThemePreferenceController;
use ThemeSwitcher\Models\ThemePreference;
use ThemeSwitcher\PanelColorCapture;
use ThemeSwitcher\Providers\ThemeSwitcherServiceProvider;
use ThemeSwitcher\Services\ThemeDiscoveryService;

class ThemeSwitcherPlugin implements HasPluginSettings, Plugin
{
    use EnvironmentWriterTrait;

    /**
     * Pelican's baseline ColorManager::$colors, captured the first time boot() runs.
     *
     * Captured in boot() — NOT register() — because Pelican registers its palette (primary => Blue)
     * in FilamentServiceProvider::boot(), which runs AFTER this plugin's register(). Snapshotting at
     * register() captured an EMPTY array, so restoring to it wiped Pelican's Blue and let Filament's
     * DEFAULT_COLORS amber primary win. Null until the first boot() captures the real baseline.
     */
    private ?array $cmBaseColors = null;

    public function getId(): string
    {
        return 'theme-switcher';
    }

    public function register(Panel $panel): void
    {
        app()->register(ThemeSwitcherServiceProvider::class);

        $panel->authenticatedRoutes(function () {
            Route::post('theme-switcher/preference', [ThemePreferenceController::class, 'store'])
                ->name('theme-switcher.preference.store');
        });

        $panel->renderHook('panels::head.end', function () use ($panel) {
            // Never let a render failure 500 the whole panel — this hook runs on every authenticated
            // page. Degrade to injecting nothing and log once-ish instead.
            try {
                return $this->renderHeadScripts($panel);
            } catch (\Throwable $e) {
                Log::warning('[theme-switcher] head render hook failed; injecting nothing. Error: '
                    . $e->getMessage());

                return '';
            }
        });
    }

    public function boot(Panel $panel): void
    {
        // boot() runs in the SetUpPanel middleware, BEFORE the authentication middleware, so on a real
        // HTTP request auth()->user() is usually NULL here — we cannot read the DB preference yet.
        // That matters for more than colors: Filament computes a button's (dark-mode) label color from
        // the registered palette's WCAG contrast, so if boot() applies the wrong success/info palette,
        // the label SHADE is baked wrong and a render-time CSS override can't fix it (it's a class, not
        // a variable). The picker writes a PLAIN $_COOKIE['active_theme'] (readable before auth) for
        // exactly this — use it to apply the user's theme at boot. Validate against installed themes;
        // fall back to the DB preference (if a user happens to be resolved) or the global default.
        // renderHeadScripts() re-resolves from the DB at render time as the source of truth.
        $user = auth()->guard($panel->getAuthGuard())->user();

        $cookie    = $_COOKIE['active_theme'] ?? null;
        $installed = array_keys(app(ThemeDiscoveryService::class)->discover());
        $cookieTheme = match (true) {
            $cookie === 'default'                                    => 'none',
            $cookie !== null && in_array($cookie, $installed, true)  => $cookie,
            default                                                  => null,
        };

        if ($cookieTheme !== null) {
            $active = $cookieTheme;
        } elseif ($user) {
            $pref = $this->readThemePreference($user->id);
            $active = match(true) {
                $pref === 'default' => 'none',
                $pref !== null      => $pref,
                default             => $this->resolveGlobalDefault(),
            };
        } else {
            $active = $this->resolveGlobalDefault();
        }

        // Everything below reaches into Filament's private Panel/ColorManager internals via reflection.
        // Those names are version-specific (they shifted between Filament majors), so a mismatch here
        // would throw during panel boot and 500 every authenticated page. Guard the whole block: on
        // failure, log once and leave the panel at Pelican's defaults rather than breaking it.
        try {
        // Capture Pelican's baseline ColorManager state once, BEFORE this plugin perturbs it. At the
        // first boot() (request time) Pelican's FilamentServiceProvider::boot() has already registered
        // primary => Blue, so this captures the real palette. (register() ran too early and only ever
        // saw an empty array, which is why restoring to it wiped Blue and surfaced Filament's amber.)
        $this->cmBaseColors ??= \Closure::bind(
            fn (ColorManager $cm) => $cm->colors,
            null,
            ColorManager::class
        )(app(ColorManager::class));

        // Reset colors/font/viteTheme so multiple installed theme plugins don't merge.
        $ref = new ReflectionClass($panel);
        $colorsProp = $ref->getProperty('colors');
        $colorsProp->setAccessible(true);
        $colorsProp->setValue($panel, []);
        $panel->font(null);

        try {
            $vtProp = $ref->getProperty('viteTheme');
            $vtProp->setAccessible(true);
            $vtProp->setValue($panel, null);
        } catch (\ReflectionException) {}

        // Also clear the resolved-theme cache. getTheme() memoises the compiled Theme into
        // $panel->theme; if anything resolved it while another plugin's viteTheme was still set,
        // nulling viteTheme alone wouldn't undo it. Force a fresh resolve.
        try {
            $themeProp = $ref->getProperty('theme');
            $themeProp->setAccessible(true);
            $themeProp->setValue($panel, null);
        } catch (\ReflectionException) {}

        if ($active && $active !== 'none') {
            $manifest = $this->loadThemeManifest($active);
            if ($manifest) {
                if (! empty($manifest['colors'])) {
                    $panel->colors($manifest['colors']);
                }
                if (! empty($manifest['font'])) {
                    $panel->font($manifest['font']);
                }
                // Deliberately NOT setting $panel->viteTheme here. boot() runs in the SetUpPanel
                // middleware, which executes BEFORE StartSession — so auth()->user() is null and
                // $active is always the GLOBAL DEFAULT, not the logged-in user's theme. If we set
                // the panel's viteTheme to a default like neo/nord, getTheme() would emit that
                // theme's CSS as the page's base for every user. Leaving viteTheme null keeps the
                // base at Pelican's app.css; renderHeadScripts() (which runs at render time, with
                // the real user resolved) injects the *actual* active theme's Vite CSS on top.
            }
        }

        // Restore ColorManager to Pelican's baseline (captured above this request), then add back only
        // the active theme's colors. array_pop is unreliable because FilamentColor::getColors()
        // permanently prepends DEFAULT_COLORS to $cm->colors on each cache miss — making pop remove
        // DEFAULT instead of the theme entry.
        $correctColors = $panel->getColors();
        $baseColors    = $this->cmBaseColors ?? [];

        \Closure::bind(function (ColorManager $cm) use ($baseColors, $correctColors) {
            $cm->colors = $baseColors;
            if (! empty($correctColors)) {
                $cm->colors[] = $correctColors;
            }
            unset($cm->cachedColors);
        }, null, ColorManager::class)(app(ColorManager::class));

        // Rewriting ColorManager::$colors above is not enough on its own: FilamentColor::getColors()
        // re-resolves the REGISTRATION list (and re-prepends DEFAULT_COLORS) on each cache miss, so a
        // side-channel theme that registered its palette via $panel->colors() (Starry Night → purple)
        // re-applies it and clobbers the edit. Filament lets LATER registrations override earlier ones,
        // and theme-switcher boots after every theme plugin — so registering the resolved palette
        // through the PUBLIC color API here makes it win for real. Omitted keys fall back to Pelican.
        $resolved = ($active && $active !== 'none')
            ? array_replace($this->pelicanDefaultColors(), $this->loadThemeManifest($active)['colors'] ?? [])
            : $this->pelicanDefaultColors();
        FilamentColor::register($resolved);
        } catch (\Throwable $e) {
            Log::warning('[theme-switcher] boot() could not apply theme state (Filament internals '
                . 'may have changed); leaving panel at defaults. Error: ' . $e->getMessage());
        }
    }

    // ── HasPluginSettings ────────────────────────────────────────────────────

    public function getSettingsForm(): array
    {
        $themes = app(ThemeDiscoveryService::class)->discover();

        $options = ['none' => 'None (Pelican default)'];
        foreach ($themes as $id => $name) {
            $options[$id] = $name;
        }

        return [
            Select::make('default_theme')
                ->label('Global Default Theme')
                ->helperText('Applied for users who have not chosen a personal theme, including the login page.')
                ->options($options)
                // `?:` so an empty/unset value falls back to 'none' instead of rendering a
                // blank option ('' is not a valid choice in $options).
                ->default(fn () => config('theme-switcher.default_theme') ?: 'none'),
        ];
    }

    public function saveSettings(array $data): void
    {
        $value = $data['default_theme'] ?? '';

        $this->writeToEnvironment([
            'PANEL_DEFAULT_THEME' => $value,
        ]);

        // Reflect the new value for the remainder of this request immediately.
        config(['theme-switcher.default_theme' => $value]);

        // The global default is the ONLY setting read from env via config() — personal
        // themes live in the DB and so are always fresh. Pelican runs with cached config by
        // default, which means the value baked into bootstrap/cache/config.php keeps shadowing
        // the .env line we just wrote on every subsequent request. resolveGlobalDefault() would
        // then keep reading the stale '' and fall back to "None" for every preference-less user
        // (and the login page). Rebuild the cache so the saved default actually reaches users;
        // if rebuilding fails (e.g. permissions), drop the cache so .env is read fresh instead
        // of continuing to serve the stale value. No-op when config isn't cached.
        if (app()->configurationIsCached()) {
            try {
                Artisan::call('config:cache');
            } catch (\Throwable $e) {
                try {
                    Artisan::call('config:clear');
                } catch (\Throwable) {
                }
                Log::warning('[theme-switcher] could not rebuild config cache after saving the '
                    . 'global default theme; the change may not apply until config is cleared. '
                    . 'Error: ' . $e->getMessage());
            }
        }

        Notification::make()
            ->title('Theme settings saved')
            ->success()
            ->send();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Read a user's saved theme preference, tolerating a missing table.
     *
     * During a fresh install — or immediately after a reimport, which drops the table via the
     * migration's down() before the new install re-runs it — `theme_preferences` may not exist.
     * A raw query would then throw QueryException and 500 the entire panel on every request
     * (this plugin's boot()/render hook run for all authenticated traffic). Returning null makes
     * callers fall back to the global default until the migration runs.
     */
    private function readThemePreference(int $userId): ?string
    {
        try {
            return ThemePreference::where('user_id', $userId)->value('theme_id');
        } catch (\Throwable $e) {
            // Log once per request so the two call sites (boot + render hook) don't double up,
            // and so a fresh install doesn't flood the log. Gives the user something concrete
            // to share when the picker silently falls back to the global default.
            static $logged = false;
            if (! $logged) {
                $logged = true;
                Log::warning('[theme-switcher] could not read theme_preferences; falling back to '
                    . 'global default. Run the plugin migration to fix. Error: ' . $e->getMessage());
            }

            return null;
        }
    }

    private function resolveActiveTheme(int $userId): ?string
    {
        $pref = $this->readThemePreference($userId);

        if ($pref === 'default') return 'none';
        if ($pref !== null)      return $pref;

        return $this->resolveGlobalDefault();
    }

    private function resolveGlobalDefault(): ?string
    {
        $default = config('theme-switcher.default_theme', '');
        // Empty string = not configured → null (backward compat, themes apply globally)
        // 'none' = admin explicitly chose "no theme" → return 'none' so __activeTheme is injected
        if ($default === '') return null;
        return $default;
    }

    private function manifests(): array
    {
        static $cache;
        return $cache ??= require base_path('plugins/theme-switcher/config/theme-manifests.php');
    }

    private function loadThemeManifest(string $themeId): ?array
    {
        static $cache = [];
        if (array_key_exists($themeId, $cache)) {
            return $cache[$themeId];
        }

        // Deactivate rules remain manually configured; everything else is auto-discovered.
        $staticConfig = $this->manifests()[$themeId] ?? [];
        $deactivate   = $staticConfig['deactivate'] ?? [];

        // Find the installed Filament plugin and capture what its register() sets.
        try {
            $plugin = collect(Filament::getCurrentPanel()->getPlugins())
                ->first(fn ($p) => $p->getId() === $themeId);
        } catch (\Throwable) {
            $plugin = null;
        }

        if (! $plugin) {
            return $cache[$themeId] = ($staticConfig ?: null);
        }

        // Capture what the theme plugin's register() sets on a throwaway PanelColorCapture, then
        // prefer those dynamic values, falling back to static config for themes (like Neobrutalism)
        // whose plugin skips $panel->colors().
        //
        // Guard the whole capture. $plugin->register($capture) executes a THIRD-PARTY plugin against
        // a fake Panel subclass, and this method runs in boot() AND the head render hook for every
        // authenticated request. A throw here therefore 500s the entire panel — which is exactly what
        // happened under Filament v5 (the plugin's register() type hint rejecting PanelColorCapture,
        // and stale-opcache class definitions during a re-import). On any failure, log once and fall
        // back to the theme's static manifest config so the panel keeps rendering.
        $dynamicColors    = [];
        $dynamicFont      = null;
        $dynamicViteTheme = null;
        try {
            $capture = new PanelColorCapture();
            $plugin->register($capture);
            $dynamicColors    = $capture->getCapturedColors();
            $dynamicFont      = $capture->getCapturedFont();
            $dynamicViteTheme = $capture->getCapturedViteTheme();
        } catch (\Throwable $e) {
            static $captureLogged = [];
            if (! isset($captureLogged[$themeId])) {
                $captureLogged[$themeId] = true;
                Log::warning('[theme-switcher] could not capture register() for theme "' . $themeId
                    . '"; falling back to static manifest config. Error: ' . $e->getMessage());
            }
        }

        $colors    = ! empty($dynamicColors)    ? $dynamicColors    : ($staticConfig['colors']    ?? []);
        $font      = $dynamicFont    !== null   ? $dynamicFont      : ($staticConfig['font']      ?? null);
        $viteTheme = $dynamicViteTheme !== null ? $dynamicViteTheme : ($staticConfig['viteTheme'] ?? null);

        // Vite emits a hashed filename (e.g. theme-B2theTrj.css), so a static selector like
        // link[href*="neobrutalism-theme"] never matches. Resolve the built asset and target it
        // by its real filename so the suppression script can clear it when the theme is inactive.
        if ($viteTheme) {
            $selector = $this->resolveViteThemeSelector($viteTheme);
            if ($selector) {
                $deactivate['clearHref'] = array_values(array_unique(
                    array_merge($deactivate['clearHref'] ?? [], [$selector])
                ));
            }
        }

        return $cache[$themeId] = [
            'colors'     => $colors,
            'font'       => $font,
            'viteTheme'  => $viteTheme,
            'deactivate' => $deactivate,
        ];
    }

    /**
     * Resolve a Vite theme source path to a CSS selector matching its built <link> tags,
     * e.g. 'link[href*="theme-B2theTrj.css"]'. Returns null if it can't be resolved.
     *
     * Reads the Vite manifest directly rather than invoking app(Vite::class), because invoking
     * the Vite instance has a side effect — it registers the entrypoint for preload injection,
     * which would leak inactive themes' CSS into the page.
     */
    private function resolveViteThemeSelector(string $vitePath): ?string
    {
        static $manifest;

        if ($manifest === null) {
            $candidates = [
                public_path('build/.vite/manifest.json'),
                public_path('build/manifest.json'),
            ];
            $manifest = [];
            foreach ($candidates as $path) {
                if (is_file($path)) {
                    $manifest = json_decode((string) file_get_contents($path), true) ?: [];
                    break;
                }
            }
        }

        $file = $manifest[$vitePath]['file'] ?? null;
        if (! is_string($file) || $file === '') {
            return null;
        }

        // Guard against a degenerate basename — link[href*=""] would match every stylesheet.
        $base = basename($file);
        if ($base === '') {
            return null;
        }

        return 'link[href*="' . $base . '"]';
    }

    private function buildCssOverride(array $colors, ?string $font = null): string
    {
        $css = ':root{';
        foreach ($colors as $name => $palette) {
            if (! is_array($palette)) {
                continue;
            }
            foreach ($palette as $shade => $value) {
                if (is_string($value)) {
                    $css .= "--{$name}-{$shade}:{$value};";
                }
            }
        }
        // Only override --font-family when this theme actually specifies one. Forcing 'initial'
        // for fontless themes (Nord, Starry Night) clobbered Filament's own --font-family (Inter):
        // Filament's theme CSS resolves the body font from var(--font-family), and a custom property
        // set to the keyword 'initial' is guaranteed-invalid, which poisons that var() and drops the
        // whole panel to the browser default (a serif). Leaving it untouched lets boot()'s
        // $panel->font() (or Filament's default Inter) stand.
        if ($font !== null) {
            $css .= "--font-family:'{$font}';";
        }
        $css .= '}';
        return "<style id=\"ts-active-style\">{$css}</style>";
    }

    private function pelicanDefaultColors(): array
    {
        // Pelican's defaults from FilamentServiceProvider::boot().
        // Color constants already return OKLCH shade arrays — no conversion needed.
        return [
            'danger'  => Color::Red,
            'gray'    => Color::Zinc,
            'info'    => Color::Sky,
            'primary' => Color::Blue,
            'success' => Color::Green,
            'warning' => Color::Amber,
            'blurple' => Color::hex('#5865F2'),
        ];
    }

    // ── Render hook ───────────────────────────────────────────────────────────

    private function renderHeadScripts(Panel $panel): string
    {
        $user = auth()->guard($panel->getAuthGuard())->user();

        $activeTheme = $user
            ? $this->resolveActiveTheme($user->id)
            : $this->resolveGlobalDefault();

        // An unconfigured global default ('' → null) must render as vanilla Pelican, identical to an
        // explicit "None": coerce null → 'none' so window.__activeTheme is set and the suppression
        // script runs, clearing every installed theme plugin's CSS instead of letting it leak globally.
        $activeTheme ??= 'none';

        $cssOverride    = '';
        $fontLinkHtml   = '';
        $viteThemeHtml  = '';
        // Guard the color/font/vite section independently so a failure here cannot take down the
        // suppression fragment (built below), which hides inactive themes' CSS/DOM.
        try {
        if ($activeTheme !== null && $activeTheme !== 'none') {
            $manifest = $this->loadThemeManifest($activeTheme);
            // Always build the CSS override so --font-family is injected via ts-active-style and
            // overrides any font set by other theme plugins' boot() in the compiled Filament CSS.
            //
            // Layer the theme's colors ON TOP OF Pelican's defaults rather than emitting only the
            // theme's own keys. boot() runs before the session starts, so it bakes the GLOBAL
            // DEFAULT theme's palette (e.g. Nord) into the compiled @filamentStyles for everyone.
            // A user on a different theme that omits some key (notably `gray`, which drives the
            // panel background) would otherwise inherit the global default's value through the
            // cascade. Resetting unspecified keys to Pelican defaults here neutralises that leak —
            // the same reason the 'none' branch below injects the full default palette.
            $overrideColors = array_replace($this->pelicanDefaultColors(), $manifest['colors'] ?? []);
            $cssOverride = $this->buildCssOverride($overrideColors, $manifest['font'] ?? null);
            if ($manifest) {
                if (! empty($manifest['font'])) {
                    $family  = $manifest['font'];
                    $slug    = str_replace(' ', '-', strtolower($family));
                    $fontLinkHtml = "<link rel=\"preconnect\" href=\"https://fonts.bunny.net\">" .
                                    "<link href=\"https://fonts.bunny.net/css?family={$slug}:400,500,600,700&display=swap\" rel=\"stylesheet\">";
                }
                // Inject the active theme's Vite CSS here (NOT in boot()). boot() runs before the
                // session is started, so it can't know the user's theme and leaves the panel base at
                // app.css. This render hook runs with the real user resolved, so it's the correct
                // place to layer the user's actual Vite theme on top of the app.css base.
                if (! empty($manifest['viteTheme'])) {
                    try {
                        $viteThemeHtml = (string) app(Vite::class)($manifest['viteTheme']);
                    } catch (\Exception) {
                        // Vite manifest miss — theme CSS not built; silently skip
                    }
                }
            }
        } else {
            // No active theme. The Pelican-default reset only has a job to do when boot() actually
            // baked a theme's palette into @filamentStyles. boot() runs before the session starts, so
            // it always bakes the GLOBAL DEFAULT theme's colors for everyone — a 'none' user then needs
            // ts-active-style (which sits after @filamentStyles in <head>) to override them back to
            // Pelican's defaults.
            //
            // But when the global default is ITSELF 'none'/unset, boot baked nothing, so @filamentStyles
            // already holds Pelican's own defaults. Injecting the reset in that case overrides Pelican's
            // live CSS with our reconstructed pelicanDefaultColors() — and any drift between the two
            // (shade set, format, or a key Pelican computes differently) shows up as a visible color
            // change with NO theme selected (the reported bug). So only inject when a real global default
            // theme is configured; otherwise leave Pelican's CSS untouched.
            $globalDefault = $this->resolveGlobalDefault();
            if ($globalDefault !== null && $globalDefault !== 'none') {
                $cssOverride = $this->buildCssOverride($this->pelicanDefaultColors(), null);
            }
        }
        } catch (\Throwable $e) {
            Log::warning('[theme-switcher] head color/vite section failed; skipping it. Error: '
                . $e->getMessage());
            $cssOverride = $fontLinkHtml = $viteThemeHtml = '';
        }

        // Build suppression FIRST and unconditionally — it (and window.__activeTheme) must survive
        // even if the color or picker sections throw. buildSuppressionScript returns a self-contained
        // <style>+<script> fragment that also publishes window.__activeTheme.
        $suppressionFragment = $this->buildSuppressionScript($activeTheme);

        // Guard the picker section independently for the same reason.
        $pickerFragment = '';
        try {
        if ($user) {
            $themes = app(ThemeDiscoveryService::class)->discover();

            if (! empty($themes)) {
                $currentTheme = ($activeTheme === null || $activeTheme === 'none') ? 'default' : $activeTheme;
                $postUrl      = Filament::getCurrentPanel()->route('theme-switcher.preference.store');
                $csrfToken    = csrf_token();

                $themeList = [['id' => 'default', 'name' => 'Default (Pelican)']];
                foreach ($themes as $id => $name) {
                    $themeList[] = ['id' => $id, 'name' => $name];
                }

                $manifestsForJs = [];
                $viteThemes     = [];
                $rulesForJs     = [];
                foreach ($themes as $themeId => $name) {
                    $m = $this->loadThemeManifest($themeId);
                    if (! $m) {
                        continue;
                    }
                    // Layer over Pelican defaults (see renderHeadScripts) so an instant switch to a
                    // theme that omits some color key resets it to Pelican's value instead of leaving
                    // the global default's leftover (e.g. Nord's gray) showing in the compiled CSS.
                    $manifestsForJs[$themeId] = [
                        'colors' => array_replace($this->pelicanDefaultColors(), $m['colors'] ?? []),
                        'font'   => $m['font'] ?? null,
                    ];
                    if (! empty($m['viteTheme'])) {
                        $viteThemes[] = $themeId;
                    }
                    if (! empty($m['deactivate'])) {
                        $rulesForJs[$themeId] = $m['deactivate'];
                    }
                }

                $configJson = json_encode([
                    'current'    => $currentTheme,
                    'themes'     => $themeList,
                    'postUrl'    => $postUrl,
                    'csrf'       => $csrfToken,
                    'manifests'  => $manifestsForJs,
                    'viteThemes' => $viteThemes,
                    'rules'      => $rulesForJs,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                $pickerFragment = "<script>\n" . $this->buildPickerScript($configJson) . "\n</script>";
            }
        }
        } catch (\Throwable $e) {
            Log::warning('[theme-switcher] head picker section failed; skipping it. Error: '
                . $e->getMessage());
            $pickerFragment = '';
        }

        return "{$fontLinkHtml}{$viteThemeHtml}{$cssOverride}{$suppressionFragment}{$pickerFragment}";
    }

    private function buildSuppressionScript(?string $activeTheme): string
    {
        // activeTheme is coerced to 'none' (never null) by renderHeadScripts before this call;
        // guard anyway so window.__activeTheme is always a valid value.
        $active          = $activeTheme ?? 'none';
        $activeThemeJson = json_encode($active, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);

        // Collect deactivate rules from all discovered themes dynamically.
        $rules = [];
        foreach (app(ThemeDiscoveryService::class)->discover() as $themeId => $name) {
            $m = $this->loadThemeManifest($themeId);
            if ($m && ! empty($m['deactivate'])) {
                $rules[$themeId] = $m['deactivate'];
            }
        }

        // Always publish the active theme so the picker (and any later script) can read it,
        // even when no installed theme needs side-channel suppression.
        if (empty($rules)) {
            return "<script>\nwindow.__activeTheme = {$activeThemeJson};\n</script>";
        }

        $rulesJson = json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // Build a static, gated stylesheet that hides each theme's injected DOM while that theme
        // is inactive. Gating on html[data-ts-suppress~="<id>"] means re-created nodes (Starry
        // Night re-adds #starrynight-stars via its own observer) stay hidden by the cascade with
        // no JS race, and activation lifts the gate simply by editing the attribute.
        $cssRules = [];
        foreach ($rules as $themeId => $rule) {
            if (empty($rule['hide'])) {
                continue;
            }
            $gate      = 'html[data-ts-suppress~="' . $themeId . '"]';
            $selectors = array_map(fn ($sel) => $gate . ' ' . $sel, $rule['hide']);
            $cssRules[] = implode(",\n", $selectors) . ' { display: none !important; }';
        }
        $gatedCss  = implode("\n", $cssRules);
        $styleHtml = $gatedCss !== '' ? "<style id=\"ts-suppress-style\">\n{$gatedCss}\n</style>\n" : '';

        return $styleHtml . <<<JS
<script>
window.__activeTheme = {$activeThemeJson};
(function () {
    // Read the active theme live each time rather than capturing it once. doInstantSwitch()
    // updates window.__activeTheme when the user switches without a reload; the long-lived
    // observers below must honour that new value, otherwise restoring an inactive theme's
    // CSS/DOM (e.g. starrynight) would be immediately re-suppressed against the stale value.
    function currentActive() {
        return typeof window.__activeTheme !== 'undefined' ? window.__activeTheme : null;
    }
    if (currentActive() === null) return;
    var rules = {$rulesJson};
    var root = document.documentElement;

    // Neutralise an inactive theme's stylesheet links. Setting el.disabled = true wins even if
    // the theme re-sets href afterwards (Starry Night's apply() only compares href, never the
    // disabled flag), so this ends the strip-href/re-add-href observer war. media = 'not all'
    // is a belt-and-suspenders fallback; href is still stashed/removed as well.
    function disableLinks(rule) {
        (rule.clearHref || []).forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                el.disabled = true;
                el.media = 'not all';
                var href = el.getAttribute('href');
                if (href) { el.setAttribute('data-ts-href', href); el.removeAttribute('href'); }
            });
        });
    }

    // Maintain the gate attribute: every inactive theme id that has rules. The server-emitted
    // #ts-suppress-style hides those themes' DOM via html[data-ts-suppress~="<id>"] ... .
    function updateGate() {
        var active = currentActive();
        var inactive = Object.keys(rules).filter(function (id) { return id !== active; });
        if (inactive.length) {
            root.setAttribute('data-ts-suppress', inactive.join(' '));
        } else {
            root.removeAttribute('data-ts-suppress');
        }
    }

    function suppress() {
        var active = currentActive();
        updateGate();
        Object.keys(rules).forEach(function (id) {
            if (id !== active) disableLinks(rules[id]);
        });
    }

    suppress();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', suppress);
    }
    window.addEventListener('turbo:load', suppress);
    window.addEventListener('turbo:render', suppress);

    // Re-disable inactive theme CSS links inserted or re-href'd after load (e.g. Starry Night
    // re-applying on a dark/light toggle). Acts by selector regardless of href, so the
    // initially href-less <link id="starrynight-css"> is caught the moment it is inserted.
    var linkObs = new MutationObserver(function () {
        var active = currentActive();
        Object.keys(rules).forEach(function (id) {
            if (id === active) return;
            disableLinks(rules[id]);
        });
    });
    if (document.head) {
        linkObs.observe(document.head, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
    }

    // Keep the gate correct if inactive-theme DOM is (re-)added after load.
    new MutationObserver(updateGate).observe(document.documentElement, { childList: true, subtree: true });
})();
</script>
JS;
    }

    private function buildPickerScript(string $configJson): string
    {
        return <<<JS
(function () {
    var cfg = {$configJson};
    var saving = false;

    function getToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : cfg.csrf;
    }

    function needsReload(id) {
        return id === 'default'
            || cfg.viteThemes.indexOf(id) >= 0
            || cfg.viteThemes.indexOf(cfg.current) >= 0;
    }

    function applyInstantCss(id) {
        var old = document.getElementById('ts-instant-style');
        if (old) old.remove();
        var m = cfg.manifests && cfg.manifests[id];
        var css = ':root{';
        if (m && m.colors) {
            Object.keys(m.colors).forEach(function (name) {
                var palette = m.colors[name];
                if (palette && typeof palette === 'object') {
                    Object.keys(palette).forEach(function (shade) {
                        css += '--' + name + '-' + shade + ':' + palette[shade] + ';';
                    });
                }
            });
        }
        // Only set --font-family when the theme specifies one. Forcing 'initial' for a fontless
        // theme is guaranteed-invalid and poisons Filament's font-family:var(--font-family) rule,
        // dropping the panel to the browser default (serif). Omitting it lets Filament's Inter stand.
        if (m && m.font) {
            css += "--font-family:'" + m.font + "';";
        }
        css += '}';
        var el = document.createElement('style');
        el.id = 'ts-instant-style';
        el.textContent = css;
        document.head.appendChild(el);
    }

    function applyInstantFont(id) {
        var old = document.getElementById('ts-instant-font');
        if (old) old.remove();
        var m = cfg.manifests && cfg.manifests[id];
        if (!m || !m.font) return;
        var slug = m.font.toLowerCase().replace(/ /g, '-');
        var link = document.createElement('link');
        link.id = 'ts-instant-font';
        link.rel = 'stylesheet';
        link.href = 'https://fonts.bunny.net/css?family=' + slug + ':400,500,600,700&display=swap';
        document.head.appendChild(link);
    }

    function applyInstantRules(newId) {
        var rules = cfg.rules || {};
        var inactive = Object.keys(rules).filter(function (id) { return id !== newId; });
        inactive.forEach(function (id) {
            (rules[id].clearHref || []).forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (el) {
                    // Disable wins even if the theme re-sets href (see suppression script).
                    el.disabled = true;
                    el.media = 'not all';
                    var href = el.getAttribute('href');
                    if (href) { el.setAttribute('data-ts-href', href); el.removeAttribute('href'); }
                });
            });
        });
        // Hide every inactive theme's DOM via the gated #ts-suppress-style stylesheet.
        if (inactive.length) {
            document.documentElement.setAttribute('data-ts-suppress', inactive.join(' '));
        } else {
            document.documentElement.removeAttribute('data-ts-suppress');
        }
    }

    function activateTheme(id) {
        var rule = (cfg.rules || {})[id];
        if (!rule) return;
        (rule.clearHref || []).forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                el.disabled = false;
                el.media = '';
                var orig = el.getAttribute('data-ts-href');
                if (orig) el.setAttribute('href', orig);
            });
        });
        // Lift the hide gate for the now-active theme so its DOM (stars/meteors) shows again.
        var root = document.documentElement;
        var cur = (root.getAttribute('data-ts-suppress') || '').split(' ').filter(Boolean);
        var next = cur.filter(function (x) { return x !== id; });
        if (next.length) { root.setAttribute('data-ts-suppress', next.join(' ')); }
        else { root.removeAttribute('data-ts-suppress'); }
    }

    function updatePickerUi(newId) {
        cfg.current = newId;
        document.querySelectorAll('[data-ts-theme]').forEach(function (b) {
            var tid = b.getAttribute('data-ts-theme');
            var dot = b.querySelector('span');
            if (tid === newId) {
                b.classList.add('bg-gray-50', 'dark:bg-white/5');
                if (dot) dot.style.background = 'var(--c-primary-500, #6366f1)';
            } else {
                b.classList.remove('bg-gray-50', 'dark:bg-white/5');
                if (dot) dot.style.background = 'rgb(209 213 219)';
            }
        });
    }

    function doInstantSwitch(id, btn) {
        // Keep window.__activeTheme in sync so the suppression script's live observers
        // treat the newly-selected theme as active and don't re-suppress its CSS/DOM.
        // 'default' maps to 'none' to match what the server injects for the Pelican default.
        window.__activeTheme = (id === 'default') ? 'none' : id;
        if (id === 'default') {
            ['ts-active-style', 'ts-instant-style', 'ts-instant-font'].forEach(function (eid) {
                var el = document.getElementById(eid);
                if (el) el.remove();
            });
            // The instant styles above are removed; Filament's own --font-family (Inter) in
            // @filamentStyles then stands, so no font reset is needed. (Forcing 'initial' here
            // would drop the panel to the browser default serif.)
            applyInstantRules('default');
        } else {
            var oldActive = document.getElementById('ts-active-style');
            if (oldActive) oldActive.remove();
            activateTheme(id);
            applyInstantCss(id);
            applyInstantFont(id);
            applyInstantRules(id);
        }
        updatePickerUi(id);
        var expires = new Date(Date.now() + 30 * 86400 * 1000).toUTCString();
        document.cookie = 'active_theme=' + encodeURIComponent(id) + ';expires=' + expires + ';path=/;samesite=Lax';
        fetch(cfg.postUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getToken(),
                'Accept': 'application/json'
            },
            body: JSON.stringify({ theme_id: id })
        }).catch(function () {});
        saving = false;
        if (btn) btn.disabled = false;
    }

    function doReloadSwitch(id, btn) {
        fetch(cfg.postUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getToken(),
                'Accept': 'application/json'
            },
            body: JSON.stringify({ theme_id: id })
        })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            if (d.ok) {
                window.location.reload();
            } else {
                saving = false;
                if (btn) btn.disabled = false;
            }
        })
        .catch(function () {
            saving = false;
            if (btn) btn.disabled = false;
        });
    }

    function saveTheme(id, btn) {
        if (saving) return;
        saving = true;
        if (btn) btn.disabled = true;
        if (needsReload(id)) {
            doReloadSwitch(id, btn);
        } else {
            doInstantSwitch(id, btn);
        }
    }

    function buildPanel() {
        var panel = document.createElement('div');
        panel.id = 'ts-picker-panel';
        var scrollCss = cfg.themes.length > 5
            ? 'max-height:calc(5 * 2.5rem);overflow-y:auto;overscroll-behavior:contain;'
            : '';
        panel.style.cssText = 'display:none;padding:2px 0 4px;' + scrollCss;

        cfg.themes.forEach(function (theme) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.setAttribute('data-ts-theme', theme.id);
            btn.className = 'fi-dropdown-list-item flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-gray-700 outline-none transition duration-75 hover:bg-gray-50 focus-visible:bg-gray-50 dark:text-gray-200 dark:hover:bg-white/5 dark:focus-visible:bg-white/5';
            if (theme.id === cfg.current) {
                btn.classList.add('bg-gray-50', 'dark:bg-white/5');
            }

            var dot = document.createElement('span');
            dot.style.cssText = 'display:inline-block;width:8px;height:8px;border-radius:50%;flex-shrink:0;';
            dot.style.background = theme.id === cfg.current ? 'var(--c-primary-500, #6366f1)' : 'rgb(209 213 219)';
            btn.appendChild(dot);

            var label = document.createElement('span');
            label.textContent = theme.name;
            btn.appendChild(label);

            btn.addEventListener('click', function () { saveTheme(theme.id, btn); });
            panel.appendChild(btn);
        });

        return panel;
    }

    function inject() {
        var switcher = document.querySelector('.fi-theme-switcher');
        if (!switcher || switcher.querySelector('#ts-picker-btn')) return;

        var btn = document.createElement('button');
        btn.id = 'ts-picker-btn';
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Change Theme');
        btn.setAttribute('x-tooltip', "{ content: 'Change Theme', theme: \$store.theme }");
        btn.className = 'fi-theme-switcher-btn';
        btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="width:1.15em;height:1.15em"><path stroke-linecap="round" stroke-linejoin="round" d="M9.53 16.122a3 3 0 0 0-5.78 1.128 2.25 2.25 0 0 1-2.4 2.245 4.5 4.5 0 0 0 8.4-2.245c0-.399-.078-.78-.22-1.128Zm0 0a15.998 15.998 0 0 0 3.388-1.62m-5.043-.025a15.994 15.994 0 0 1 1.622-3.395m3.42 3.42a15.995 15.995 0 0 0 4.764-4.648l3.876-5.814a1.151 1.151 0 0 0-1.597-1.597L14.146 6.32a15.996 15.996 0 0 0-4.649 4.763m3.42 3.42a6.776 6.776 0 0 0-3.42-3.42"/></svg>';
        switcher.appendChild(btn);

        if (window.Alpine) { try { window.Alpine.initTree(btn); } catch (e) {} }

        var panel = buildPanel();
        var listParent = switcher.closest('.fi-dropdown-list, .fi-dropdown-list-item, li') || switcher.parentElement;
        if (listParent && listParent !== switcher) {
            listParent.parentElement.insertBefore(panel, listParent.nextSibling);
        } else {
            switcher.parentElement.insertBefore(panel, switcher.nextSibling);
        }

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var shown = panel.style.display !== 'none';
            panel.style.display = shown ? 'none' : 'block';
        });

        document.addEventListener('click', function (e) {
            if (!switcher.contains(e.target) && !panel.contains(e.target)) {
                panel.style.display = 'none';
            }
        }, true);
    }

    function tryInject() {
        if (document.querySelector('.fi-theme-switcher')) {
            inject();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', tryInject);
    } else {
        tryInject();
    }

    window.addEventListener('turbo:load', function () { tryInject(); });
    window.addEventListener('turbo:render', function () {
        var existing = document.getElementById('ts-picker-btn');
        if (existing) existing.closest('.fi-theme-switcher') ? null : existing.remove();
        var ep = document.getElementById('ts-picker-panel');
        if (ep) ep.remove();
        tryInject();
    });

    var obs = new MutationObserver(function () { tryInject(); });
    obs.observe(document.documentElement, { childList: true, subtree: true });
})();
JS;
    }
}
