---
paths:
  - postcss.config.js
  - composer.json
---

# General

## Keep a project-local empty postcss.config.js to block parent-directory lookups
This repo lives one level under Desktop, which has a stray `postcss.config.js` loading Tailwind v3. Vite's PostCSS config lookup walks UP the tree and finds it, which breaks Tailwind v4 builds with "`@layer components` is used but no matching `@tailwind components` directive". The local `postcss.config.js` with `{ plugins: {} }` stops that upward search. Do not delete it, and do not add PostCSS plugins here — Tailwind v4 runs through `@tailwindcss/vite`.

## NEVER run composer require/update without --no-scripts (it destroys the chisel feature scaffolding)
`post-update-cmd` runs `php artisan install:features`, which CONSUMES the chisel system: it strips every `/* @chisel-* */` marker and deletes chisel.php, chisel-paths.php and app/Console/Commands/InstallFeaturesCommand.php, then removes its own composer script entries. No app code is lost and tests still pass, but the feature-toggling tooling is gone. ALWAYS run `composer require`/`update` with `--no-scripts` here, then handle scripts deliberately. Recover with `git checkout -- <files>`.
