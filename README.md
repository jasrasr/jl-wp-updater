# JL WP Updater

Updates JL WP Updater and JL WP Plugins Pack from stable GitHub releases through WordPress. Install the ZIP once as a regular plugin and activate it. Enable automatic updates for JL WP Updater on the Installed Plugins page if you want WordPress to install its future releases in the background.

Open Tools → JL WP Updater to compare versions, refresh release data, or reinstall the same version. Reinstall requires a matching published release. WordPress needs permission to write its plugin directory.

## GitHub releases

Each repository publishes a stable release tagged with the version in its main plugin file, such as v1.0.0. Attach a ZIP named after the repository, with exactly one top-level directory named after the plugin. The main plugin PHP file must be directly inside that directory.

- jasrasr/jl-wp-updater → jl-wp-updater.zip
- jasrasr/jl-wp-plugins-pack → jl-wp-plugins-pack.zip

Both plugin headers must use their respective GitHub repository URLs as the Update URI. Public release lookups use GitHub without authentication. Release data is cached for up to 15 minutes, and Check now bypasses that cache. Drafts, prereleases, and releases without the expected asset are skipped.

Use one update provider per managed plugin. If you use Git Updater for other extensions, make sure it does not also manage these two packages.

## Build

From the updater repository root, run the following PowerShell command. The ZIP should contain jl-wp-updater/jl-wp-updater.php.

```powershell
git archive --format=zip --prefix=jl-wp-updater/ -o ../jl-wp-updater.zip HEAD jl-wp-updater.php README.md
```

If a release fails to load, replace the plugin files with a previous known-good ZIP through WordPress or FileZilla.
