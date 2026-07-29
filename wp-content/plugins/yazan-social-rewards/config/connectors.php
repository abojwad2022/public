<?php
/**
 * Social connector registry source.
 *
 * The list of network connector classes the Social engine loads. Each must
 * implement {@see \Yazan\Rewards\Core\Contracts\ConnectorInterface} (extend
 * {@see \Yazan\Rewards\Modules\Social\Connectors\AbstractConnector}). Nothing in
 * the engine hardcodes a network — add or remove a line here (or filter
 * `yazan_rewards/social/connectors`) to change the supported set. Each connector
 * self-declares its `network()` id, so ordering is irrelevant.
 *
 * @package Yazan\Rewards
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	\Yazan\Rewards\Modules\Social\Connectors\InstagramConnector::class,
	\Yazan\Rewards\Modules\Social\Connectors\TikTokConnector::class,
	\Yazan\Rewards\Modules\Social\Connectors\FacebookConnector::class,
	\Yazan\Rewards\Modules\Social\Connectors\YouTubeConnector::class,
	\Yazan\Rewards\Modules\Social\Connectors\PinterestConnector::class,
	\Yazan\Rewards\Modules\Social\Connectors\SnapchatConnector::class,
	\Yazan\Rewards\Modules\Social\Connectors\LinkedInConnector::class,
);
