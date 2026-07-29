import { Checkbox, Input, Label } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { Header, NavButtons, Wrapper } from '../Components';
import { useOnboardingContext } from '../Context';

const UserDetailInput = ( { title, value, onChange } ) => (
	<div className="w-full">
		<Label tag={ 'p' } className="text-[#566A86] mt-2">
			{ title }
		</Label>
		<Input size="md" value={ value } onChange={ onChange } />
	</div>
);

const UserDetails = () => {
	const { currentScreenData, handleData } = useOnboardingContext();

	return (
		<>
			<Header
				heading={ __(
					'Okay, Just One Last Step\u2026',
					'power-coupons'
				) }
				subHeading={ __(
					'Subscribe to get the latest updates, tips, and features.',
					'power-coupons'
				) }
			/>

			<Wrapper className="flex flex-col gap-[24px]">
				<div className="flex justify-between gap-[16px]">
					<UserDetailInput
						title={ __( 'First Name', 'power-coupons' ) }
						value={ currentScreenData.user_detail_firstname }
						onChange={ ( value ) =>
							handleData( 'user_detail_firstname', value )
						}
					/>
					<UserDetailInput
						title={ __( 'Last Name', 'power-coupons' ) }
						value={ currentScreenData.user_detail_lastname }
						onChange={ ( value ) =>
							handleData( 'user_detail_lastname', value )
						}
					/>
				</div>

				<UserDetailInput
					title={ __( 'Your Email', 'power-coupons' ) }
					value={ currentScreenData.user_detail_email }
					onChange={ ( value ) =>
						handleData( 'user_detail_email', value )
					}
				/>

				<div className="mt-[24px]">
					<Checkbox
						size="sm"
						checked={
							currentScreenData.optin_usage_tracking
								? true
								: false
						}
						onChange={ ( value ) =>
							handleData( 'optin_usage_tracking', value )
						}
						label={ {
							heading: (
								<span>
									{ __(
										'Allow Power Coupons to track non-sensitive usage tracking data.',
										'power-coupons'
									) }{ ' ' }
									<a
										className="text-wpcolor hover:text-wphovercolor no-underline"
										target="_blank"
										href="https://store.brainstormforce.com/usage-tracking/?utm_source=dashboard&utm_medium=power-coupons&utm_campaign=docs"
										rel="noreferrer"
									>
										{ __( 'Learn More', 'power-coupons' ) }
									</a>
								</span>
							),
						} }
					/>
				</div>
			</Wrapper>

			<NavButtons />
		</>
	);
};

export default UserDetails;
