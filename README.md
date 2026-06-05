# pelican-plugins

A collection of [Pelican Panel](https://pelican.dev) plugins by makepeaceJ.
Assisted by Claude CLI.
Tested in both docker and bare metal installs.

| Plugin | Description |
|--------|-------------|
| [`theme-switcher`](theme-switcher/) | Per-user theme selection with an admin-configurable global default. |

---

## theme-switcher
Theme Picker(Scrollable over a certain number of themes):

<img width="380" height="458" alt="image" src="https://github.com/user-attachments/assets/3e5a1354-398c-47c0-b3b7-fb7ddb6ed4ad" />

Global Default Theme (Default theme for users including the login screen):

<img width="1114" height="452" alt="image" src="https://github.com/user-attachments/assets/14a69103-c238-4272-9d97-2a1753bf22c1" />

Lets every panel user pick their own theme from a dropdown injected next to Filament's
built-in light/dark switcher, and lets an admin set the **global default** theme that applies
to users who haven't chosen one (including the login page).
Currently confirmed working themes (If a theme doesn't work, submit an issue and I'll take a look):
-StarryNight
-Nord Theme
-Neobrutalism
-Fluffy

### How it works

- **Theme discovery is automatic.** `ThemeDiscoveryService` scans the panel's `plugins/`
  directory and treats any enabled plugin whose `plugin.json` has `"category": "theme"` as a
  selectable theme. Install another theme plugin and it shows up in the picker — no config
  changes required. The switcher works on its own even with no other themes installed (you can
  still toggle "Default (Pelican)").
- **Colors, fonts and Vite themes are captured at runtime** from each theme plugin's
  `register()` method via `PanelColorCapture`, so the switcher applies the *exact* styling a
  theme defines without duplicating it.
- **Inactive themes are suppressed** in the browser (clearing their injected `<link>` CSS and
  hiding theme-specific DOM) so multiple installed themes don't bleed into each other.
- **Per-user preference** is stored in a `theme_preferences` table; the **global default** is
  stored in the panel `.env` as `PANEL_DEFAULT_THEME` (written from the plugin's Settings form).

Static deactivation rules — for themes that inject CSS or DOM through their own render hooks —
live in `config/theme-manifests.php`. Most themes so far need no entry there.

### Configure

- **Global default theme:** Admin → plugin **Settings** → *Global Default Theme*. Choose
  "None (Pelican default)" or any installed theme. This sets `PANEL_DEFAULT_THEME` in `.env`.
- **Per-user theme:** any logged-in user clicks the theme icon next to the light/dark toggle and
  picks a theme; their choice is saved immediately.

## License

[MIT](LICENSE)
