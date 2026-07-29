# Administrator Guide

Everything the store team manages lives under the **Yazan Rewards** menu in wp-admin (visible to users with the
`manage_yazan_rewards` capability — `administrator` and `shop_manager` by default). Reports are additionally
readable with `view_yazan_rewards`.

## The admin menu

| Screen | What you do there |
|---|---|
| **Rules** | Build no-code earning rules: pick an **event** (order completed, first purchase, review, birthday, login, referral…), add **conditions** (tier, lifetime points, product/category, date, role), and **actions** (add/remove points, upgrade level, give badge, create coupon, send notification). |
| **Rewards** | Create the redeemable catalog — credit, coupon, free shipping, free product, and four service rewards (VIP service, ring cleaning/polishing, exclusive product) — with points cost, availability window, stock, and per-user limit. |
| **Points** | Review the pending-points queue, approve/reject, and make manual adjustments. |
| **Service Queue** | Fulfil redeemed service rewards (voucher → requested → scheduled → fulfilled). |
| **Campaigns** | Marketing/UGC campaigns: define tasks + rewards, target an audience (all/tier/role/users), review submissions, and see per-campaign analytics. |
| **Social** | Per-network connector keys (write-only), enable/points per network, verification defaults, and the UGC review queue. |
| **Fraud** | Review flagged actions by state (suspicious / manual review / approved / rejected), inspect the risk signals, approve or reject (reject claws back paid rewards). |
| **Analytics** | Store-wide KPIs — customers, VIP, ambassadors; best campaigns, participation, engagement; retention, referral revenue, CLV, reward cost, point liability — with inline charts and CSV export. |
| **Notifications** | Channel toggles (email / on-site / webhook), the daily digest hour, per-category master switches, the "campaign ending" lead time, an outbox viewer, and a **Send test** button. |

## Customer-facing surfaces

- **My Account → Rewards** (`/my-account/rewards/`) — balance, store credit, the redeemable catalog, active
  campaigns, recent activity, and a **Notifications** panel (inbox + per-category preferences: email
  immediate/digest/off, on-site on/off).
- **My Account → Membership** (`/my-account/yazan-ambassador/`) — the member/ambassador dashboard: level badge,
  ranking, progress to the next level, campaigns, achievements, referral stats.

## Notifications

- **Channels:** Email (branded HTML, RTL-aware, with a plain-text fallback — WooCommerce's own emails are
  untouched), On-site (the My-Account inbox), and Webhook (an **integration** channel — POSTs every notification
  as HMAC-signed JSON to an `https://` endpoint you configure, e.g. Slack/Zapier; fires regardless of a
  customer's preferences). **SMS / Push / WhatsApp** are wired but dormant until a provider add-on is connected.
- **Categories:** service, reward, points, referral, tier, achievement, campaign, system. *Service* and *reward*
  are required (transactional — always sent). The rest are customer-controllable; **Points** defaults to the
  daily digest so per-earn notices don't flood inboxes.
- **Digest:** non-urgent emails a customer has set to "digest" are batched into one message at the configured
  hour (a flush runs hourly).
- **Webhook secret:** set once (write-only); the receiver verifies the `X-Yazan-Signature: sha256=…` header.

## Per-engine settings (in `yazan_rewards_settings`)

Key knobs (managed from the relevant screen or programmatically):

- **Points:** earn status, points-per-currency, signup/review/birthday bonuses, expiry months (0 = never),
  pre-expiry reminder days, rounding.
- **Wallet:** redeem mode (wallet / coupon / fallback), points-to-currency ratio, min redeem, max cart %.
- **Tiers:** auto-apply the member level's discount at checkout.
- **Ambassador:** auto-approve, commission rate, payout mode (store credit in v1).
- **Referral:** cookie days, levels (up to 2), first-order trigger, per-beneficiary reward (points/credit,
  fixed/percent), abuse action (hold/block/flag).
- **Anti-fraud:** daily earn cap, hold-suspicious, review threshold, velocity + multi-account limits, per-detector toggles.
- **Social:** share/UGC points, per-network overrides, the 6 manual-verification checks.
- **Notification:** channel toggles, digest hour, category master switches, campaign-ending lead-time.

## Deployment notes (from the security audit)

- **Encrypt OAuth tokens:** ensure the PHP `sodium` extension is present and define a dedicated key in
  `wp-config.php`: `define( 'YZRW_TOKEN_KEY', '<64+ random chars>' );`. Without libsodium, customer OAuth tokens
  degrade to a recoverable at-rest encoding — install `sodium` before enabling social OAuth.
- **Proxies/CDN:** only define `YZRW_TRUST_PROXY` (true) if a trusted proxy sets `X-Forwarded-For`; otherwise
  leave it undefined so client IPs can't be spoofed.
- **Webhook:** the endpoint must be `https://`. Rotate the signing secret by re-entering a new value.
- **Background jobs:** confirm **WooCommerce → Status → Scheduled Actions** lists the `yazan_rewards` group and
  that Action Scheduler is processing (a real cron or frequent traffic is needed on low-traffic sites).

## Data safety

The plugin never deletes data on deactivation, and keeps everything on uninstall unless you explicitly enable
*delete data on uninstall*. Back up before any destructive action — see [BACKUP.md](BACKUP.md).
