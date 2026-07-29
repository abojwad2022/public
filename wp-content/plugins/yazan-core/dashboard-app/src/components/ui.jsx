/**
 * Compatibility shim.
 *
 * The design system now lives in ./ui/ as several focused modules. This file
 * stays so the ~27 screens that import from '../components/ui.jsx' keep working
 * untouched while the redesign lands wave by wave — every one of them uses named
 * imports, so a plain re-export is a complete substitute.
 *
 * The final wave rewrites those imports to '../components/ui' and deletes this
 * file. Until then, do not add anything here: components belong in ./ui/.
 */
export * from './ui/index.js'
