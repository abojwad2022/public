import React, { useState } from 'react';
import { __ } from '@wordpress/i18n';
import BogoEnvelop from '../../../../images/bogo-envelop.svg';

const PRO_FEATURES = [
	__( 'BOGO Offers', 'power-coupons' ),
	__( 'Advanced Discount Rules', 'power-coupons' ),
	__( 'Cart Progress Bar', 'power-coupons' ),
	__( 'Loyalty Points', 'power-coupons' ),
	__( 'Coupon Analytics', 'power-coupons' ),
	__( 'And More…', 'power-coupons' ),
];

const CheckIcon = () => (
	<svg
		width="16"
		height="16"
		viewBox="0 0 12 12"
		fill="none"
		xmlns="http://www.w3.org/2000/svg"
		className="shrink-0"
	>
		<path
			d="M10 3.00098L4.5 8.50098L2 6.00098"
			stroke="#ea580c"
			strokeWidth="1.5"
			strokeLinecap="round"
			strokeLinejoin="round"
		/>
	</svg>
);

function ProUpsell( { children } ) {
	const isProInstalled = window.powerCouponsSettings.is_pro_installed;
	const [ activating, setActivating ] = useState( false );

	const handleActivatePro = async () => {
		setActivating( true );
		try {
			const response = await fetch(
				window.powerCouponsSettings.ajax_url,
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/x-www-form-urlencoded',
					},
					body: new URLSearchParams( {
						action: 'power_coupons_activate_pro',
						security:
							window.powerCouponsSettings.activate_pro_nonce,
					} ),
				}
			);
			const result = await response.json();
			if ( result.success ) {
				window.location.reload();
			} else {
				setActivating( false );
			}
		} catch {
			setActivating( false );
		}
	};

	return (
		<div className="relative">
			{ /* Dummy background content — blurred and non-interactive */ }
			<div className="pointer-events-none select-none opacity-50">
				{ children }
			</div>

			{ /* Overlay modal */ }
			<div className="absolute inset-0 flex items-center justify-center z-[9] pt-4">
				<div className="max-w-[520px] w-full bg-white rounded-xl shadow-xl border border-solid border-gray-200 p-8 text-center">
					{ /* Illustration */ }
					<div className="flex justify-center mb-4">
						<img
							src={ BogoEnvelop }
							alt={ __( 'Power Coupons Pro', 'power-coupons' ) }
							className="h-[140px] w-auto"
						/>
					</div>

					{ /* Badge + Heading */ }
					<div className="flex items-center justify-center gap-1.5 mb-2">
						<svg
							width="16"
							height="16"
							viewBox="0 0 16 16"
							fill="none"
							xmlns="http://www.w3.org/2000/svg"
						>
							<path
								d="M8.834 1.333 2.667 9.333h5.334L7.168 14.667l6.166-8H8.001l.833-5.334Z"
								stroke="#ea580c"
								strokeWidth="1.25"
								strokeLinecap="round"
								strokeLinejoin="round"
								fill="#ea580c"
								fillOpacity="0.15"
							/>
						</svg>
						<span className="text-sm font-semibold text-orange-600">
							{ __( 'PRO Feature', 'power-coupons' ) }
						</span>
					</div>

					<h2 className="text-xl font-bold text-gray-900 m-0 mb-2">
						{ __(
							'Unlock the Full Power of Power Coupons Pro',
							'power-coupons'
						) }
					</h2>
					<p className="text-sm text-gray-500 m-0 mb-6 leading-relaxed">
						{ __(
							'Boost conversions with advanced coupon automation, smart discount rules, and powerful cart incentives designed to increase sales.',
							'power-coupons'
						) }
					</p>

					{ /* Feature checklist — 2 columns */ }
					<div className="grid grid-cols-2 gap-x-8 gap-y-2.5 text-left mb-8 px-2">
						{ PRO_FEATURES.map( ( feature ) => (
							<div
								key={ feature }
								className="flex items-center gap-2 text-sm text-gray-700"
							>
								<CheckIcon />
								{ feature }
							</div>
						) ) }
					</div>

					{ /* CTA Button — full width */ }
					{ isProInstalled ? (
						<button
							type="button"
							onClick={ handleActivatePro }
							disabled={ activating }
							className="block w-full text-center rounded-lg py-3 text-sm font-semibold text-white bg-orange-500 hover:bg-orange-600 border-0 cursor-pointer disabled:opacity-70 disabled:cursor-not-allowed"
						>
							{ activating
								? __( 'Activating…', 'power-coupons' )
								: __(
										'Activate Power Coupons Pro',
										'power-coupons'
								  ) }
						</button>
					) : (
						<a
							href="https://cartflows.com/power-coupons-for-woocommerce/pricing/?utm_source=dashboard&utm_medium=free-power-coupons&utm_campaign=go-pro"
							target="_blank"
							rel="noopener noreferrer"
							className="block w-full text-center rounded-lg py-3 text-sm font-semibold no-underline text-white bg-orange-500 hover:bg-orange-600 border-0 ring-0 cursor-pointer"
						>
							{ __( 'Upgrade to PRO', 'power-coupons' ) }
						</a>
					) }
				</div>
			</div>
		</div>
	);
}

export default ProUpsell;
