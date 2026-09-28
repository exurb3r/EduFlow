---
paths:
  - 'resources/css/**/*.css'
  - resources/css/bulletin.css
---

# Css

## Custom CSS must live in @layer components or it overrides Tailwind utilities
Custom component classes in a plain CSS file are UNLAYERED, and unlayered rules beat every layered rule regardless of specificity — so they silently override Tailwind's `@layer utilities`. Wrapping them in `:where()` does NOT help. Put custom component CSS inside `@layer components { }` (Tailwind declares `theme, base, components, utilities`, so utilities then win). resources/css/bulletin.css does this; any new partial must too.

## Scoped themes must remap --sidebar* too, and must be applied to AppShell
The app shell (sidebar, header) paints from the `--sidebar*` token set, NOT `--background` — so a scoped theme that only remaps background/foreground leaves a grey seam against themed content. `.bulletin` must also define --sidebar, --sidebar-foreground, --sidebar-primary(-foreground), --sidebar-accent(-foreground), --sidebar-border and --sidebar-ring in BOTH light and dark, and it is applied to AppShell so all pages in the app layout inherit it. Filament admin is NOT affected because its CSS does not define these tokens and its panel uses a separate layout.

## --sidebar must be the SAME value as --paper, not a darker step
Do NOT give `--sidebar` its own darker tone than `--paper`. The user wants shell and content as ONE continuous sheet: set `--sidebar: var(--paper)` exactly. A ~2.5% lightness step is technically subtle but reads as a visible band across the full width. Let the `inset` sidebar variant delineate the content panel with its radius and shadow instead of a second background colour.
