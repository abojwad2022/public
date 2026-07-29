/**
 * YAZAN AI Core — image input normalization (mirrors PHP Yazan_AI_Image).
 *
 * OpenAI-schema providers accept any URL (incl. data:) as-is; Anthropic and Gemini need raw base64 +
 * mime. This turns a `data:` URI or an http(s) URL into { mime, data }, fetching a remote URL only
 * when a provider cannot take a URL directly.
 */

const MAX_BYTES = 8 * 1024 * 1024; // 8 MB
const FETCH_TIMEOUT_MS = 20_000; // bound the remote image fetch so it can't hang the provider call

export async function toBase64(src: string): Promise<{ mime: string; data: string } | null> {
  const s = String(src || '');

  if (s.startsWith('data:')) {
    const m = /^data:([^;,]+)?(;base64)?,(.*)$/is.exec(s);
    if (!m) return null;
    const mime = m[1] ? m[1].toLowerCase() : 'image/jpeg';
    const data = m[2] === ';base64' ? m[3] : Buffer.from(decodeURIComponent(m[3])).toString('base64');
    return { mime, data };
  }

  if (/^https?:\/\//i.test(s)) {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), FETCH_TIMEOUT_MS);
    try {
      const res = await fetch(s, { signal: controller.signal });
      if (!res.ok) return null;
      const buf = Buffer.from(await res.arrayBuffer());
      if (buf.length === 0 || buf.length > MAX_BYTES) return null;
      const mime = (res.headers.get('content-type') || 'image/jpeg').split(';')[0];
      return { mime, data: buf.toString('base64') };
    } catch {
      return null; // network error, abort/timeout, or oversize
    } finally {
      clearTimeout(timer);
    }
  }

  return null;
}
