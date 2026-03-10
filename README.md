# jobiqo/composer-patches-install

A Composer plugin that detects `patches.lock.json` changes and automatically triggers re-patching of affected packages. See https://github.com/cweagans/composer-patches/issues/583

## What it does

1. On a **fresh install** (no `vendor/` directory), pre-initialises the `patches.lock.json` cache so the first `composer install` does not trigger unnecessary reinstalls.
2. On subsequent **composer install**, compares `patches.lock.json` against the cached copy in `vendor/composer/patches.lock.json`.
3. Re-installs only the packages whose patch hashes have changed, so patches are always applied consistently.
4. Supports `--no-dev` mode: dev-only packages are skipped when running without dev dependencies.

## Requirements

- PHP 8.1+
- Composer 2.x
- [`cweagans/composer-patches`](https://github.com/cweagans/composer-patches) ^2.0

## Installation

```bash
composer require jobiqo/composer-patches-install
```

Because this is a Composer plugin, the `pre-install-cmd`, `post-install-cmd`, and `post-update-cmd` hooks are **registered automatically** — no manual entries in your project's `scripts` section are required.

## Usage

Generate a `patches.lock.json` file with composer-patches in the root of your project (next to `composer.json`). The plugin reads the `patches` key and its `sha256` hashes (as written by `cweagans/composer-patches` v2) to decide which packages need to be reinstalled.

### Migrating from the script-handler approach

If you previously called the static methods directly in your `composer.json` scripts, remove those entries:

```json
// Remove these:
"scripts": {
    "pre-install-cmd": ["DrupalProject\\composer\\PatchesLockPlugin::preInstall"],
    "post-install-cmd": ["DrupalProject\\composer\\PatchesLockPlugin::checkPatchChanges"],
    "post-update-cmd":  ["DrupalProject\\composer\\PatchesLockPlugin::checkPatchChanges"]
}
```

Also remove the `autoload.classmap` entry for the old `PatchesLockPlugin.php` script file.
