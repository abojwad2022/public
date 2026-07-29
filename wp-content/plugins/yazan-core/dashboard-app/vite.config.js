import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'
import { fileURLToPath, URL } from 'node:url'

/**
 * Builds the dashboard SPA straight into the plugin's assets/dashboard/ folder with STABLE
 * filenames (app.js / app.css). The PHP shell (class-yazan-dashboard.php) references those two
 * names and cache-busts them with filemtime, so no manifest parsing is needed on the WP side.
 *
 * `base` is the public URL the built assets are served from, so any asset the bundle references
 * (fonts, images) resolves correctly when the page is served from /dashboard.
 */
export default defineConfig({
  plugins: [react(), tailwindcss()],

  base: '/wp-content/plugins/yazan-core/assets/dashboard/',

  build: {
    outDir: fileURLToPath(new URL('../assets/dashboard', import.meta.url)),
    emptyOutDir: true,
    cssCodeSplit: false,
    sourcemap: false,
    target: 'es2020',
    // The PHP shell reads this to discover the hashed entry names. Written to
    // manifest.json rather than Vite's default .vite/manifest.json because nginx
    // 404s dot-directories, which would put it out of reach of the dev preview
    // harness (PHP reads it from disk either way).
    manifest: 'manifest.json',
    rollupOptions: {
      output: {
        // Content-hashed, NOT stable. The entry must be cache-busted by FILE NAME, because
        // the chunks import it by its bare relative name — a `?v=` query on the document's
        // script tag would make `app.js?v=1` and `app.js` two different module URLs and the
        // browser would evaluate the entire bundle twice (two Reacts, two of every context).
        entryFileNames: 'app-[hash].js',
        // Chunk names must NOT be stable. Chunk URLs are embedded inside app.js and
        // carry no ?v= query, so an unhashed chunk that changed content but kept its
        // name is served stale from cache against a fresh entry — an intermittent,
        // route-specific breakage. The content hash is the cache key.
        chunkFileNames: 'app-[name]-[hash].js',
        assetFileNames: (info) => {
          const name = (info.names && info.names[0]) || info.name || ''
          return name.endsWith('.css') ? 'app-[hash].css' : 'assets/[name]-[hash][extname]'
        },
        // Rollup's default. NOTE: this does not disable splitting (that would be
        // inlineDynamicImports) — lazy routes chunk normally.
        manualChunks: undefined,
      },
    },
  },
})
