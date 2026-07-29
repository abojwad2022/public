/**
 * Yazan — Tailwind theme preset (Black + Burgundy interchangeable systems).
 *
 * This WordPress store does not currently compile Tailwind, but the design system
 * is exported here so any Tailwind surface (a headless front-end, a Storybook, an
 * email builder, a marketing microsite) can consume the SAME tokens.
 *
 * Usage:
 *   // tailwind.config.js
 *   const yazan = require('./assets/tokens/tailwind.burgundy.js');
 *   module.exports = { presets: [yazan], content: [...] };
 *
 * Themes are driven by a `data-yz-theme` attribute on <html> (same contract as
 * the live site). Enable the `dark`-style variants via the plugin below, or use
 * the CSS-variable colours which auto-resolve per theme when you also ship
 * theme-tokens.css. The static palettes are provided for build-time class output.
 */

const plugin = require('tailwindcss/plugin');

/** Burgundy ramp (50→950). */
const burgundy = {
  50: '#FBEAEC', 100: '#F5D0D5', 200: '#E7A6AE', 300: '#D4737E', 400: '#B94551',
  500: '#8E2530', 600: '#6E1A24', 700: '#4A0F19', 800: '#2C0A10', 900: '#1E080C', 950: '#160709',
};

/** Ink/graphite ramp for the Black (classic) theme. */
const ink = {
  50: '#F7F4EE', 100: '#ECE7DD', 200: '#D4CEC4', 300: '#B0A99E', 400: '#857F76',
  500: '#605B53', 600: '#46423C', 700: '#322F2A', 800: '#201E1B', 900: '#141210', 950: '#0B0A09',
};

const accent = { red: '#A50606', carnelian: '#8C2F24', gold: '#C9A24B' };

module.exports = {
  theme: {
    extend: {
      colors: {
        burgundy,
        ink,
        accent,
        // Semantic colours as CSS variables — resolve per active theme when
        // theme-tokens.css is present (bg/surface/text flip automatically).
        bg:        'var(--yz-bg)',
        surface:   'var(--yz-surface)',
        'surface-2': 'var(--yz-surface-2)',
        content:   'var(--yz-text)',
        'content-2': 'var(--yz-text-2)',
        muted:     'var(--yz-text-muted)',
        line:      'var(--yz-border)',
        primary:   'var(--yz-primary)',
        'on-primary': 'var(--yz-on-primary)',
        brand:     'var(--yz-accent)',
      },
      fontFamily: {
        display: ["'Cormorant Garamond'", 'Georgia', "'Times New Roman'", 'serif'],
        body: ["'Jost'", 'system-ui', '-apple-system', "'Segoe UI'", 'sans-serif'],
      },
      fontSize: {
        eyebrow: ['12px', { letterSpacing: '0.14em' }],
        h3: 'clamp(22px, 2.2vw, 28px)',
        h2: 'clamp(30px, 3.6vw, 44px)',
        h1: 'clamp(38px, 5vw, 64px)',
        display: 'clamp(40px, 7vw, 88px)',
      },
      letterSpacing: { label: '0.14em' },
      borderRadius: { none: '0', pill: '999px' },
      boxShadow: {
        sm: '0 1px 2px rgba(0,0,0,0.45)',
        md: '0 14px 34px -14px rgba(0,0,0,0.60)',
        lg: '0 28px 60px -22px rgba(0,0,0,0.75)',
        glow: '0 0 0 1px rgba(165,6,6,0.35), 0 10px 34px -10px rgba(165,6,6,0.28)',
      },
      transitionTimingFunction: {
        'yz-out': 'cubic-bezier(0.22, 1, 0.36, 1)',
        'yz-inout': 'cubic-bezier(0.65, 0, 0.35, 1)',
      },
      transitionDuration: { theme: '400ms' },
    },
  },
  plugins: [
    // Adds `theme-black:` and `theme-burgundy:` variants keyed to <html data-yz-theme>.
    plugin(function ({ addVariant }) {
      addVariant('theme-black', 'html[data-yz-theme="black"] &');
      addVariant('theme-burgundy', 'html[data-yz-theme="burgundy"] &');
    }),
  ],
};
