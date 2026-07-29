import { Label, RadioButton, Switch } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import parse from 'html-react-parser';
import { Header, Wrapper, NavButtons } from '../Components';
import { useOnboardingContext } from '../Context';

const ToggleRow = ( { title, description, value, onChange } ) => (
	<section className="py-6 border-0 border-b border-solid border-border-subtle last:border-b-0">
		<div className="flex items-center justify-between">
			<div>
				<Label className="flex items-center gap-0.5 text-base [&>*]:text-base [&>svg]:h-5 [&>svg]:w-5 text-field-label [&>*]:text-field-label font-medium mb-1">
					{ title }
				</Label>
				<p className="font-normal text-sm text-text-field-helper m-0 text-[#566A86]">
					{ description }
				</p>
			</div>
			<Switch
				size="md"
				value={ value }
				onChange={ onChange }
				className="[&>input]:!border-none"
			/>
		</div>
	</section>
);

const ConfigureCoupons = () => {
	const { currentScreenData, handleData } = useOnboardingContext();

	const couponStyles = window.powerCouponsSettings.coupon_templates;

	return (
		<>
			<Header
				heading={ __( 'Configure Your Coupons', 'power-coupons' ) }
				subHeading={ __(
					"Set up coupon settings to match your store's needs in just a few clicks.",
					'power-coupons'
				) }
			/>

			<Wrapper>
				<section className="py-6 border-0 border-b border-solid border-border-subtle">
					<Label className="flex items-center gap-0.5 text-base [&>*]:text-base [&>svg]:h-5 [&>svg]:w-5 text-field-label [&>*]:text-field-label font-medium mb-1">
						{ __( 'Choose Coupon Styling', 'power-coupons' ) }
					</Label>
					<p className="font-normal text-sm text-text-field-helper m-0 text-[#566A86]">
						{ __(
							'Select a visual style for how coupons will appear to your customers.',
							'power-coupons'
						) }
					</p>

					<div className="mt-[8px]">
						<RadioButton.Group
							columns={ Object.keys( couponStyles ).length }
							onChange={ ( value ) =>
								handleData( 'coupon_style', value )
							}
							size="md"
							value={
								currentScreenData.coupon_style || 'style-1'
							}
						>
							<div className="flex gap-6">
								{ Object.keys( couponStyles ).map(
									( couponStyle ) => (
										<div
											key={ couponStyle }
											className="flex gap-[26px]"
										>
											<button
												onClick={ () =>
													handleData(
														'coupon_style',
														couponStyle
													)
												}
												className="bg-transparent border-0 p-0 m-0 cursor-pointer power-coupons-coupon-style-btn"
												style={ {
													direction: 'ltr',
												} }
												type="button"
											>
												{ parse(
													couponStyles[ couponStyle ]
												) }
												<RadioButton.Button
													value={ couponStyle }
												/>
											</button>
										</div>
									)
								) }
							</div>
						</RadioButton.Group>
					</div>
				</section>

				<ToggleRow
					title={ __( 'Show Coupons on Cart', 'power-coupons' ) }
					description={ __(
						'Display available coupons on the cart page so customers can easily apply discounts.',
						'power-coupons'
					) }
					value={ currentScreenData.show_on_cart }
					onChange={ ( value ) =>
						handleData( 'show_on_cart', value )
					}
				/>

				<ToggleRow
					title={ __( 'Show Coupons on Checkout', 'power-coupons' ) }
					description={ __(
						'Display available coupons on the checkout page to boost conversions.',
						'power-coupons'
					) }
					value={ currentScreenData.show_on_checkout }
					onChange={ ( value ) =>
						handleData( 'show_on_checkout', value )
					}
				/>

				<ToggleRow
					title={ __( 'Enable for Guest Users', 'power-coupons' ) }
					description={ __(
						'Allow guest users (not logged in) to see and use available coupons.',
						'power-coupons'
					) }
					value={ currentScreenData.enable_for_guests }
					onChange={ ( value ) =>
						handleData( 'enable_for_guests', value )
					}
				/>
			</Wrapper>

			<NavButtons />
		</>
	);
};

export default ConfigureCoupons;
