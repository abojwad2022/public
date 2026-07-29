import { __ } from '@wordpress/i18n';
import { Header, NavButtons, Wrapper } from '../Components';
import { RenderIcon } from '../Utils';

const features = [
	__(
		'Display available coupons beautifully on cart and checkout pages.',
		'power-coupons'
	),
	__(
		'Let customers apply coupons with a single click — no copy-pasting needed.',
		'power-coupons'
	),
	__(
		'Auto-apply coupons based on smart rules and conditions.',
		'power-coupons'
	),
	__( 'Create BOGO offers to boost average order value.', 'power-coupons' ),
	__(
		'Customize coupon styles, colors, and text to match your brand.',
		'power-coupons'
	),
];

const Welcome = () => {
	return (
		<>
			<Header
				heading={ __( 'Welcome to Power Coupons', 'power-coupons' ) }
				subHeading={ __(
					'Supercharge your WooCommerce store with powerful, flexible discount features!',
					'power-coupons'
				) }
			/>

			<Wrapper>
				<iframe
					className="w-full aspect-video rounded-lg"
					src="https://www.youtube.com/embed/Uj6ZMyXoLug?autoplay=1&mute=1"
					title={ __(
						'Power Coupons YouTube Video',
						'power-coupons'
					) }
					frameBorder="0"
					allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
					allowFullScreen
				></iframe>

				<ul className="divide-y divide-gray-200 list-none pl-0 space-y-2">
					{ features.map( ( feature, index ) => (
						<li
							key={ feature + index }
							className="flex items-center space-x-2 text-field-label text-sm font-medium"
						>
							{ RenderIcon( 'check' ) }
							<span className="text-[#566A86]">{ feature }</span>
						</li>
					) ) }
				</ul>
			</Wrapper>

			<NavButtons
				labels={ {
					next: __( "Let's Get Started", 'power-coupons' ),
					skip: null,
					back: null,
				} }
			/>
		</>
	);
};

export default Welcome;
