# One-tap sign-in — Google & Apple

Adds **Continue with Google** and **Continue with Apple** to the Yazan storefront. One tap → the
provider's own account picker → signed in and back on the page they came from. No registration
form, no password, no confirmation screen.

The module ships **inert**. With no credentials defined, `Yazan_Social_Auth::providers()` returns
an empty array, no buttons render, and `/my-account/` is byte-for-byte what it was before. Nothing
below happens until you add the constants.

---

## Why the redirect flow, not Google's JavaScript SDK

Google Identity Services would give an in-page account-picker popup, but it requires loading
`accounts.google.com/gsi/client` on the login page. This site has a verified **zero external
requests** position (self-hosted fonts, local GSAP, `yazan_dequeue_foreign_fonts()`), and one
third-party script in the auth path would end that.

The server-side authorisation-code flow is still one tap — the account chooser is Google's own
page, reached by a full-page redirect. The only cost is a page navigation instead of an overlay.
Both provider marks are inline SVG, so the buttons themselves add **no** requests at all.

If you later decide the popup is worth it, the exchange/verify layer does not change — only the UI
and how the code reaches `Yazan_Social_Auth::callback()`.

---

## Setup — Google

1. [Google Cloud Console](https://console.cloud.google.com/) → create or pick a project.
2. **APIs & Services → OAuth consent screen**
   - User type **External**, then **Publish** it. While it is in *Testing*, only accounts you list
     as test users can sign in.
   - Scopes: `openid`, `email`, `profile` only. Nothing here is a sensitive scope, so **no Google
     review is required**.
3. **APIs & Services → Credentials → Create credentials → OAuth client ID**
   - Application type: **Web application**
   - **Authorised redirect URI** — must match byte for byte, trailing slash included:
     ```
     https://YOUR-DOMAIN/yazan-auth/google/callback/
     ```
4. Copy the client ID and client secret into `wp-config.php` (below).

## Setup — Apple

Requires a paid **Apple Developer Program** membership ($99/yr). There is no free tier.

1. [developer.apple.com](https://developer.apple.com/account/resources/identifiers/list) →
   **Identifiers → App IDs** → create one with **Sign in with Apple** enabled.
2. **Identifiers → Services IDs** → create one (e.g. `com.yazan.web`). This — *not* the App ID — is
   your `client_id`.
   - Enable **Sign in with Apple** → **Configure**:
     - Primary App ID: the App ID from step 1
     - Domains: `YOUR-DOMAIN` (no scheme, no path)
     - Return URLs: `https://YOUR-DOMAIN/yazan-auth/apple/callback/`
3. **Keys** → create a key with **Sign in with Apple** enabled → download the `.p8` **once**
   (Apple will not let you download it again). Note the **Key ID**.
4. Your **Team ID** is top-right in the developer portal.

Apple requires **HTTPS and a publicly resolvable domain**. It will not accept `localhost`,
`yazan.local`, an IP address, or a return URL with a query string.

---

## wp-config.php

Add above the `/* That's all, stop editing! */` line. Credentials live here rather than in the
database so they never appear in a DB export, a backup archive, or the dashboard.

```php
/* Google */
define( 'YAZAN_GOOGLE_CLIENT_ID',     '…apps.googleusercontent.com' );
define( 'YAZAN_GOOGLE_CLIENT_SECRET', 'GOCSPX-…' );

/* Apple */
define( 'YAZAN_APPLE_CLIENT_ID', 'com.yazan.web' );  // Services ID, NOT the App ID
define( 'YAZAN_APPLE_TEAM_ID',   'ABCDE12345' );
define( 'YAZAN_APPLE_KEY_ID',    'KEY9876543' );

// Either point at the .p8 (keep it OUTSIDE the webroot) …
define( 'YAZAN_APPLE_PRIVATE_KEY_PATH', 'C:/secure/AuthKey_KEY9876543.p8' );

// … or paste it inline. Note the real newlines — a single-line key will not parse.
// define( 'YAZAN_APPLE_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\nMIGT…\n-----END PRIVATE KEY-----" );
```

A provider with any required value missing is simply never offered — Google needs id + secret,
Apple needs Services ID + Team ID + Key ID + key. You can enable one and not the other.

---

## Testing locally

Neither provider will accept `yazan.local`, so a local end-to-end test needs a public HTTPS tunnel.

```bash
cloudflared tunnel --url http://yazan.local:80    # or: ngrok http 80
```

Then point the auth routes at the tunnel hostname **without** changing `WP_HOME`/`WP_SITEURL`:

```php
define( 'YAZAN_SOCIAL_AUTH_BASE_URL', 'https://your-tunnel.trycloudflare.com/' );
```

Register `https://your-tunnel.trycloudflare.com/yazan-auth/google/callback/` in the Google console
(a free tunnel hostname changes each run, so re-register each session — or pay for a stable one).

Without a tunnel you can still verify everything short of the provider round trip; see
**Verification** below.

---

## How it behaves

| Situation | Result |
|---|---|
| Provider identity already linked | Signed straight in |
| Verified email matches an existing account | Linked to that account — never duplicated |
| Nobody matches | Customer created via `wc_create_new_customer()` and signed in |
| Provider says the email is **not** verified | Refused, with a notice. Never linked, never created |
| Provider sends no email at all | Refused |
| Shopper closes the account picker | Returned quietly to where they were, no error |

**On the verified-email rule.** Linking by email is what makes this effortless and is also the one
place the module could hand someone another shopper's order history. It is allowed only when the
provider asserts `email_verified`. Google and Apple always do for genuine accounts, so nothing
legitimate is turned away.

**Data preserved.** Orders, favourites and reward balances hang off the user ID, so linking keeps
all of them. The basket is handled explicitly by `Yazan_Social_Auth_Cart`: contents are stashed
server-side before the redirect and merged back afterwards, adding only lines the cart does not
already hold.

**Existing login untouched.** Email/password sign-in, registration, lost-password and the
`form-login.php` override all work exactly as before. The buttons attach through
`woocommerce_login_form_start` / `woocommerce_register_form_start` — two of the core hooks that
template already preserved — so they also appear on CartFlows' checkout login automatically.

---

## Security

- **Algorithm pinned to RS256.** `alg: none` and HMAC-confusion forgeries are rejected outright.
- **Full OIDC claim validation**: signature against the provider's JWKS, `iss`, `aud`, `azp`,
  `exp`/`iat`/`nbf` with 60s leeway, and `nonce`.
- **Three independent bindings** must agree before anyone is signed in: the single-use `state`
  record, a per-flow secret in an HttpOnly cookie, and the `nonce` inside the signed token.
- **PKCE (S256)** on Google. Apple does not document support, so it is not sent there.
- **Login-CSRF nonce** on the start link, so a visitor cannot be silently driven into someone
  else's account. *This means `/my-account/` must not be page-cached — which WooCommerce already
  requires.*
- **Apple's client secret is minted per request** with a 1-hour expiry, so there is no six-month
  secret to rotate and no silent expiry to debug.
- **`redirect_to` is passed through `wp_validate_redirect()`** — no open redirect.
- **Rate limited** to 20 starts per IP per 15 minutes.
- Failures are logged to **WooCommerce → Status → Logs**, source `yazan-social-auth`, and the
  shopper sees a plain notice — never a stack trace or a provider error string.

---

## Extending

```php
// Supply credentials from somewhere other than wp-config.
add_filter( 'yazan_social_auth_config', function ( $config ) { … } );

// Veto automatic linking (e.g. refuse it for administrators).
add_filter( 'yazan_social_auth_allow_link', function ( $allow, $user, $identity ) {
	return user_can( $user, 'manage_options' ) ? false : $allow;
}, 10, 3 );

// React to a social sign-in.
add_action( 'yazan_social_auth_signed_in', function ( $user, $provider, $result ) { … }, 10, 3 );
add_action( 'yazan_social_auth_linked',    function ( $user, $provider, $identity ) { … }, 10, 3 );
```

`wp_login` fires on every social sign-in, so the rewards plugin's `LoginObserver` and anything else
listening keeps working.

### Adding a provider

Extend `Yazan_Social_Auth_Provider`, implement the abstract methods, and add it to
`Yazan_Social_Auth::providers()`. The token exchange, JWKS verification and user resolution are all
inherited.

### For a future native app

`Yazan_Social_Auth_JWT::verify_id_token()` and `Yazan_Social_Auth_Users::resolve()` are deliberately
free of any dependency on the web redirect flow. A REST endpoint that accepts an ID token from an
Android/iOS SDK can call both and get identical behaviour — the only new work is issuing a token
for the app instead of a cookie, since the existing session model is same-origin cookie + nonce.

---

## Verification

Run from the repo root (path per `HANDOFF.md`; re-derive the run-dir id if Local has restarted):

```bash
PHP="…/lightning-services/php-8.3.17+1/bin/win64/php.exe"
INI="…/Local/run/<id>/conf/php/php.ini"
"$PHP" -c "$INI" path/to/test-social-auth-crypto.php   # 19 assertions — RS256 + ES256
"$PHP" -c "$INI" path/to/test-social-auth-users.php    # 22 assertions — create/link/dedupe
"$PHP" -c "$INI" path/to/test-social-auth-flow.php     # 46 assertions — URLs, secret, buttons
```

The crypto suite pre-seeds the JWKS transient, so it needs no network and no credentials. The user
suite creates real customers and deletes every one it made. The flow suite injects credentials
through the filter and writes nothing.

**Not covered by them** — needs real credentials plus a tunnel:

1. The provider round trip itself (picker → callback → session).
2. Apple's cross-site `form_post` callback and the `SameSite=None` cookie surviving it.
3. Apple's first-authorisation `user` name blob (only ever sent once per Apple ID — to test again,
   revoke the app under **Apple ID → Sign in with Apple**).
4. Basket merge across a real sign-in: add items as a guest, sign in, confirm they survive.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `redirect_uri_mismatch` | The console URI differs from `Yazan_Social_Auth::redirect_uri()` — usually the trailing slash, or http vs https. |
| `invalid_client` (Apple) | Wrong Team ID / Key ID, `client_id` set to the App ID instead of the Services ID, or a mangled `.p8`. |
| "We could not confirm this sign-in came from your browser" | The state cookie did not survive. Over HTTP with Apple this is expected — `SameSite=None` requires HTTPS. |
| "That sign-in link has expired" | Stale nonce — `/my-account/` is being page-cached. Exclude it. |
| Buttons do not appear | A required constant is missing, or you are already logged in. |
| 404 on `/yazan-auth/…` | Flush permalinks (Settings → Permalinks → Save). |
