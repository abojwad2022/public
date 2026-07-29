/**
 * YAZAN AI Core — request authentication.
 *
 * Every call from WordPress is signed: X-Yazan-Signature = HMAC-SHA256( "<timestamp>.<rawBody>",
 * sharedSecret ), hex. The timestamp (X-Yazan-Timestamp, unix seconds) is bound into the signature and
 * checked against a small window to blunt replay. Comparison is constant-time. The PHP client
 * (Yazan_AI_Core_Client) produces the identical signature.
 */
import crypto from 'node:crypto';

export function sign(timestamp: string, rawBody: string, secret: string): string {
  return crypto.createHmac('sha256', secret).update(`${timestamp}.${rawBody}`).digest('hex');
}

export function verify(
  rawBody: string,
  signature: string,
  timestamp: string,
  secret: string,
  windowSeconds: number,
): boolean {
  if (!signature || !timestamp || !secret) return false;

  const ts = Number.parseInt(timestamp, 10);
  if (!Number.isFinite(ts)) return false;
  const now = Math.floor(Date.now() / 1000);
  if (Math.abs(now - ts) > windowSeconds) return false; // replay / clock-skew guard

  const expected = sign(timestamp, rawBody, secret);
  const a = Buffer.from(expected, 'utf8');
  const b = Buffer.from(signature, 'utf8');
  if (a.length !== b.length) return false;
  return crypto.timingSafeEqual(a, b);
}
