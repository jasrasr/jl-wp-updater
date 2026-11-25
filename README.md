# JL WP Updater

Manage updates for selected plugins and themes from public GitHub releases through WordPress.

1. Install and activate the updater ZIP as a regular WordPress plugin.
2. Open **Tools → JL WP Updater**. On multisite, use **Network Admin → Settings → JL WP Updater**.
3. Select the installed plugins and themes you want to manage, then click **Save managed updates** and **Check now**.

An item appears when its main plugin file or theme stylesheet declares an HTTPS GitHub repository in its `Update URI` header. The updater stays selected so it can receive its own updates. JL WP Plugins Pack starts selected when installed and can be deselected. Use a single update provider for each managed item.

New versions appear in the normal WordPress plugin or theme update screen. Enable automatic updates there if desired. **Reinstall current version** appears when the installed version matches the published release.

## Package format

Publish a stable release tagged with the version in the main plugin or theme file, such as `v1.1.0`. Attach a ZIP named after the **installed directory**. The ZIP must contain one top-level directory with that same name and the main PHP file or theme stylesheet inside. The version in that file must match the release.

| Item | Asset | Required entry |
| --- | --- | --- |
| JL WP Updater | `jl-wp-updater.zip` | `jl-wp-updater/jl-wp-updater.php` |
| JL WP Plugins Pack | `jl-wp-plugins-pack.zip` | `jl-wp-plugins-pack/jl-wp-plugins-pack.php` |
| JL WP Theme Dark | `jl-wp-theme-dark.zip` | `jl-wp-theme-dark/style.css` |

Public release lookups use GitHub without authentication. Successful lookups are cached for 15 minutes. **Check now** bypasses that cache. Drafts, prereleases, and releases without the expected asset are skipped.

## Build the updater ZIP

From the updater repository, run:

```powershell
git archive --format=zip --prefix=jl-wp-updater/ -o ../jl-wp-updater.zip HEAD jl-wp-updater.php README.md
```

If an update fails to load, reinstall the previous known-good ZIP through WordPress or FileZilla.
