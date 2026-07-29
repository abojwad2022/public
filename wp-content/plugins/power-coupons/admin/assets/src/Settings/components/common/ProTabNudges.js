import { __ } from '@wordpress/i18n';

const iconClass = 'w-24 h-24 text-wpcolor';
const iconProps = {
	xmlns: 'http://www.w3.org/2000/svg',
	fill: 'none',
	viewBox: '0 0 24 24',
	strokeWidth: 1.2,
	stroke: 'currentColor',
	className: iconClass,
	'aria-hidden': true,
};

const CartProgressVisual = () => (
	<svg { ...iconProps }>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125Z"
		/>
	</svg>
);

const LoyaltyVisual = () => (
	<svg { ...iconProps }>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.562.562 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.562.562 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5Z"
		/>
	</svg>
);

const GiftCardsVisual = () => (
	<svg { ...iconProps }>
		<path
			strokeLinecap="round"
			strokeLinejoin="round"
			d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125Z"
		/>
	</svg>
);

const getProTabNudges = () => ( {
	power_coupons_cart_progress_bar: {
		title: __( 'Boost AOV with a Cart Progress Bar', 'power-coupons' ),
		subtitle: __(
			'Encourage shoppers to add more to their cart by showing a live progress indicator toward rewards like free shipping or discounts.',
			'power-coupons'
		),
		description: [
			__(
				'Set dynamic milestones tied to cart total or item count',
				'power-coupons'
			),
			__(
				'Customise bar colours, messaging, and success states to match your brand',
				'power-coupons'
			),
			__(
				'Displayed on cart and checkout pages with smooth animations',
				'power-coupons'
			),
		],
		utmMedium: 'free-power-coupons-cart-progress-bar',
		visual: <CartProgressVisual />,
	},
	power_coupons_points: {
		title: __(
			'Reward loyal customers with Points & Rewards',
			'power-coupons'
		),
		subtitle: __(
			'Give shoppers credit for every order, nudge repeat purchases, and let them redeem points for discounts — all on autopilot.',
			'power-coupons'
		),
		description: [
			__(
				'Automatically award points on purchases with configurable earn rates',
				'power-coupons'
			),
			__(
				'Let customers redeem points as cart discounts at checkout',
				'power-coupons'
			),
			__(
				'Run time-boxed campaigns, control expiry, and view each customer’s balance',
				'power-coupons'
			),
		],
		utmMedium: 'free-power-coupons-points',
		visual: <LoyaltyVisual />,
	},
	power_coupons_gift_cards: {
		title: __( 'Sell Gift Cards and grow revenue', 'power-coupons' ),
		subtitle: __(
			'Let customers buy gift cards for friends and family. Perfect for holidays, launches, and recovering lost sales.',
			'power-coupons'
		),
		description: [
			__(
				'Offer preset and custom gift card amounts with branded designs',
				'power-coupons'
			),
			__(
				'Redeem gift cards as discount credits at checkout',
				'power-coupons'
			),
			__(
				'Restrict redemption by product or category for full control',
				'power-coupons'
			),
		],
		utmMedium: 'free-power-coupons-gift-cards',
		visual: <GiftCardsVisual />,
	},
} );

export default getProTabNudges;
