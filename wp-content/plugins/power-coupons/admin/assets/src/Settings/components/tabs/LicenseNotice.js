import React from 'react';
import { __ } from '@wordpress/i18n';
import { useStateValue } from '../Data';
import { Container, Title, Button } from '@bsf/force-ui';

import ToggleField from '../fields/ToggleField';

function LicenseNotice( props ) {
	const { navigate } = props;

	const [ data ] = useStateValue();

	if ( 'Activated' === data.license_status ) {
		return;
	}

	return (
		<div className="relative">
			<Container
				align="center"
				containerType="flex"
				direction="row"
				justify="center"
				className="absolute top-0 left-0 w-full h-full bg-opacity-50 backdrop-blur-sm bg-white/30 z-[9]"
			>
				<Container.Item className="absolute p-6 bg-white shadow-md rounded-lg text-center">
					<Container
						containerType="flex"
						direction="row"
						justify="center"
						className="mb-4"
					>
						<svg
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
							className="h-8 w-8 text-red-500 stroke-1"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"
							/>
						</svg>
					</Container>
					<Title
						size="md"
						tag="h2"
						title={ __( 'Activate Your License', 'power-coupons' ) }
						description={ __(
							'Please activate your license to get access to all features.',
							'power-coupons'
						) }
						className="mb-6 text-center [&_h2]:text-gray-900 [&_p]:!text-text-field-helper text-xl"
					/>
					<Button
						variant="primary"
						onClick={ () => {
							navigate( 'power_coupons_license' );
						} }
						className="inline-flex justify-center items-center gap-1.5 rounded px-3 py-2 text-sm font-normal shadow-sm text-wpcolor hover:text-white border border-solid border-wpcolor bg-white hover:bg-wpcolor outline-0 hover:outline-0 focus:ring-0"
					>
						{ __( 'Activate License', 'power-coupons' ) }
					</Button>
				</Container.Item>
			</Container>
			<div className="pointer-events-none select-none px-6">
				<ToggleField
					title={ __( 'Enable Plugin', 'power-coupons' ) }
					description={ __(
						'Enable or disable Power Coupons functionality globally',
						'power-coupons'
					) }
					name={ 'general[enable_plugin]' }
					value={ data?.general?.enable_plugin }
				/>
				<ToggleField
					title={ __( 'Show on Cart', 'power-coupons' ) }
					description={ __(
						'Display available coupons on cart page',
						'power-coupons'
					) }
					name={ 'general[show_on_cart]' }
					value={ data?.general?.show_on_cart }
				/>
				<ToggleField
					title={ __( 'Show on Checkout', 'power-coupons' ) }
					description={ __(
						'Display available coupons on checkout page',
						'power-coupons'
					) }
					name={ 'general[show_on_checkout]' }
					value={ data?.general?.show_on_checkout }
				/>
			</div>
		</div>
	);
}

export default LicenseNotice;
