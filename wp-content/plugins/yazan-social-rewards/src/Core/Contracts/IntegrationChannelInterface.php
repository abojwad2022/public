<?php
/**
 * Integration (non-customer) notification channel marker.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

namespace Yazan\Rewards\Core\Contracts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Marks a channel as an OUTBOUND INTEGRATION (e.g. a webhook to Slack/Zapier),
 * not a customer-facing inbox. The dispatcher delivers to these regardless of a
 * customer's per-category notification preferences — they exist for the store's
 * own automation/ops, so a customer opt-out must not silence them. Customer
 * channels (email/on-site) implement only {@see ChannelInterface} and ARE gated
 * by preferences.
 */
interface IntegrationChannelInterface extends ChannelInterface {
}
