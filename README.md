# jobiqo/composer-patches-install

A Composer plugin that detects `patches.lock.json` changes and automatically triggers re-patching of affected packages. See https://github.com/cweagans/composer-patches/issues/583

This is relevant for sites that use a `git pull && composer install` workflow. With composer-patches 2.x this is not sufficient anymore to get any `patches.lock.json` changes installed. The workaround is `git pull && composer install && composer patches-repatch`, but has downsides:
* Local development: developers need to remember a much longer command when they update their checkout, `composer install` is not enough anymore.
* Longer production downtimes than necessary. A full `composer patches-repatch` triggers *all* patched dependencies to be patched again, which is slow.

## What it does internally

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

## Usage

Generate a `patches.lock.json` file with composer-patches in the root of your project (next to `composer.json`). The plugin reads the `patches` key and its `sha256` hashes (as written by `cweagans/composer-patches` v2) to decide which packages need to be reinstalled.

Run `composer install` and patch changes will be picked up automatically.
