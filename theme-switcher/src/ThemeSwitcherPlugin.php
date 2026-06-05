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

    /** ColorManager::$colors snapshot taken before Panel::boot() appends any theme entries. */
    private array $cmColorsAtRegister = [];

    public function getId(): string
    {
        return 'theme-switcher';
    }

    public function register(Panel $panel): void
    {
        app()->register(ThemeSwitcherServiceProvider::class);

        // Snapshot ColorManager entries now — only Pelican's FilamentServiceProvider
        // entries exist at this point. Panel::boot() hasn't run yet so no theme plugin
        // colors have been appended. We'll restore to this in boot() to undo whatever
        // Panel::boot() and getColors() add.
        $this->cmColorsAtRegister = \Closure::bind(
            fn (ColorManager $cm) => $cm->colors,
            null,
            ColorManager::class
        )(app(ColorManager::class));

        $panel->authenticatedRoutes(function () {
            Route::post('theme-switcher/preference', [ThemePreferenceController::class, 'store'])
                ->name('theme-switcher.preference.store');
        });

        $panel->renderHook('panels::head.end', function () use ($panel) {
            return $this->renderHeadScripts($panel);
        });
    }

    public function boot(Panel $panel): void
    {
        // StartSession runs before SetUpPanel, so auth()->guard() is available here.
        $user = auth()->guard($panel->getAuthGuard())->user();

        if ($user) {
            $pref = $this->readThemePreference($user->id);
            $active = match(true) {
                $pref === 'default' => 'none',
                $pref !== null      => $pref,
                default             => $this->resolveGlobalDefault(),
            };
        } else {
            $active = $this->resolveGlobalDefault();
        }

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

        // Restore ColorManager to its pre-Panel::boot() state (snapshotted in register()),
        // then add back only the active theme's colors. array_pop is unreliable because
        // FilamentColor::getColors() permanently prepends DEFAULT_COLORS to $cm->colors on
        // each cache miss — making pop remove DEFAULT instead of the theme entry.
        $correctColors = $panel->getColors();
        $baseColors    = $this->cmColorsAtRegister;

        \Closure::bind(function (ColorManager $cm) use ($baseColors, $correctColors) {
            $cm->colors = $baseColors;
            if (! empty($correctColors)) {
                $cm->colors[] = $correctColors;
            }
            unset($cm->cachedColors);
        }, null, ColorManager::class)(app(ColorManager::class));
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
                ->default(fn () => config('theme-switcher.default_theme', 'none')),
        ];
    }

    public function saveSettings(array $data): void
    {
        $this->writeToEnvironment([
            'PANEL_DEFAULT_THEME' => $data['default_theme'] ?? '',
        ]);

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

        $capture = new PanelColorCapture();
        $plugin->register($capture);

        // Prefer dynamic values from the plugin; fall back to static config
        // for themes (like Neobrutalism) whose plugin skips $panel->colors().
        $dynamicColors    = $capture->getCapturedColors();
        $dynamicFont      = $capture->getCapturedFont();
        $dynamicViteTheme = $capture->getCapturedViteTheme();

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
        // Always write --font-family. Without this, a font set by another theme plugin's boot()
        // (which may run after our reset) bleeds into the compiled Filament theme CSS and persists.
        $css .= $font !== null ? "--font-family:'{$font}';" : '--font-family:initial;';
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

        $cssOverride    = '';
        $fontLinkHtml   = '';
        $viteThemeHtml  = '';
        if ($activeTheme !== null && $activeTheme !== 'none') {
            $manifest = $this->loadThemeManifest($activeTheme);
            // Always build the CSS override so --font-family is injected via ts-active-style and
            // overrides any font set by other theme plugins' boot() in the compiled Filament CSS.
            $cssOverride = $this->buildCssOverride($manifest['colors'] ?? [], $manifest['font'] ?? null);
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
            // 'none' — user chose Pelican's default. @filamentStyles may still contain
            // a merged theme's colors (ColorManager::scoped() resets make it unreliable
            // to clear at boot-time). ts-active-style appears after @filamentStyles in
            // <head> so it wins the cascade. We inject Pelican's actual defaults
            // directly from their Color constants (already resolved to OKLCH arrays).
            $cssOverride = $this->buildCssOverride($this->pelicanDefaultColors(), null);
        }

        $activeThemeScript = '';
        if ($activeTheme !== null) {
            $json = json_encode($activeTheme, JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT);
            $activeThemeScript = "window.__activeTheme = {$json};";
        }

        $suppressionScript = $this->buildSuppressionScript($activeTheme);

        $pickerScript = '';
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
                    $manifestsForJs[$themeId] = [
                        'colors' => $m['colors'] ?? [],
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

                $pickerScript = $this->buildPickerScript($configJson);
            }
        }

        return "{$fontLinkHtml}{$viteThemeHtml}{$cssOverride}<script>\n{$activeThemeScript}\n{$suppressionScript}\n{$pickerScript}\n</script>";
    }

    private function buildSuppressionScript(?string $activeTheme): string
    {
        if ($activeTheme === null) {
            return '';
        }

        // Collect deactivate rules from all discovered themes dynamically.
        $rules = [];
        foreach (app(ThemeDiscoveryService::class)->discover() as $themeId => $name) {
            $m = $this->loadThemeManifest($themeId);
            if ($m && ! empty($m['deactivate'])) {
                $rules[$themeId] = $m['deactivate'];
            }
        }

        if (empty($rules)) {
            return '';
        }

        $rulesJson = json_encode($rules, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        return <<<JS
(function () {
    var active = typeof window.__activeTheme !== 'undefined' ? window.__activeTheme : null;
    if (active === null) return;
    var rules = {$rulesJson};

    function applyRule(rule) {
        (rule.clearHref || []).forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                var href = el.getAttribute('href');
                // Remove the href entirely — a <link> with no href is ignored by the browser.
                // Setting href='' would resolve to '/' and load the page as a stylesheet.
                if (href) { el.setAttribute('data-ts-href', href); el.removeAttribute('href'); }
            });
        });
        (rule.hide || []).forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                el.style.display = 'none';
            });
        });
    }

    function suppress() {
        Object.keys(rules).forEach(function (id) {
            if (id !== active) applyRule(rules[id]);
        });
    }

    suppress();
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', suppress);
    }
    window.addEventListener('turbo:load', suppress);
    window.addEventListener('turbo:render', suppress);

    // Intercept href changes on inactive theme CSS links (e.g. starrynight re-applying
    // on dark/light mode toggle). Setting href to '' triggers this observer, but
    // getAttribute('href') is then falsy so we skip — no infinite loop.
    var hrefObs = new MutationObserver(function () {
        Object.keys(rules).forEach(function (id) {
            if (id === active) return;
            (rules[id].clearHref || []).forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (el) {
                    var href = el.getAttribute('href');
                    if (href) { el.setAttribute('data-ts-href', href); el.removeAttribute('href'); }
                });
            });
        });
    });
    if (document.head) {
        hrefObs.observe(document.head, { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
    }

    // Hide any inactive-theme DOM elements added after initial load.
    // Only watches childList — style mutations don't re-trigger.
    new MutationObserver(suppress).observe(document.documentElement, { childList: true, subtree: true });
})();
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
        // Always write --font-family: use theme font, or reset to initial so Filament's
        // own injected --font-family (from the active server-side theme) doesn't bleed through.
        css += m && m.font ? "--font-family:'" + m.font + "';" : '--font-family:initial;';
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
        Object.keys(rules).forEach(function (id) {
            if (id === newId) return;
            var rule = rules[id];
            (rule.clearHref || []).forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (el) {
                    var href = el.getAttribute('href');
                    if (href) { el.setAttribute('data-ts-href', href); el.removeAttribute('href'); }
                });
            });
            (rule.hide || []).forEach(function (sel) {
                document.querySelectorAll(sel).forEach(function (el) {
                    el.style.display = 'none';
                });
            });
        });
    }

    function activateTheme(id) {
        var rule = (cfg.rules || {})[id];
        if (!rule) return;
        (rule.clearHref || []).forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                var orig = el.getAttribute('data-ts-href');
                if (orig) el.setAttribute('href', orig);
            });
        });
        (rule.hide || []).forEach(function (sel) {
            document.querySelectorAll(sel).forEach(function (el) {
                el.style.removeProperty('display');
            });
        });
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
        if (id === 'default') {
            ['ts-active-style', 'ts-instant-style', 'ts-instant-font'].forEach(function (eid) {
                var el = document.getElementById(eid);
                if (el) el.remove();
            });
            // Reset --font-family so Filament's server-side font injection doesn't persist.
            var resetEl = document.createElement('style');
            resetEl.id = 'ts-instant-style';
            resetEl.textContent = ':root{--font-family:initial;}';
            document.head.appendChild(resetEl);
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
