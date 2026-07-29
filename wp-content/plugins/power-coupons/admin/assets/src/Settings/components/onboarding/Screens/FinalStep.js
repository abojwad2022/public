import { __ } from '@wordpress/i18n';
import { Header, Wrapper } from '../Components';
import { RedirectToDashboard, RenderIcon } from '../Utils';
import { Button } from '@bsf/force-ui';

const FinalStep = () => {
	return (
		<>
			<Header
				heading={ __(
					"You're Good to Go! \uD83D\uDE80",
					'power-coupons'
				) }
				subHeading={ __(
					'Great job! \uD83C\uDF89 With Power Coupons active, your store is ready to deliver smart discounts that keep customers happy and drive more revenue.',
					'power-coupons'
				) }
			/>

			<Wrapper className="flex flex-col gap-[24px]">
				<strong>
					{ __(
						"Here's What Power Coupons Will Do for You Now:",
						'power-coupons'
					) }
				</strong>

				<ul className="list-none pl-0 m-0 space-y-3">
					<li className="flex items-start gap-2 text-field-label text-sm font-medium leading-6">
						<span className="shrink-0 mt-1.5">
							{ RenderIcon( 'check' ) }
						</span>
						<span>
							<strong>
								{ __(
									'Effortless discounts:',
									'power-coupons'
								) }
							</strong>{ ' ' }
							{ __(
								'Customers see and apply coupons with a single click.',
								'power-coupons'
							) }
						</span>
					</li>
					<li className="flex items-start gap-2 text-field-label text-sm font-medium leading-6">
						<span className="shrink-0 mt-1.5">
							{ RenderIcon( 'check' ) }
						</span>
						<span>
							<strong>
								{ __( 'Higher conversions:', 'power-coupons' ) }
							</strong>{ ' ' }
							{ __(
								'Smart coupon display encourages customers to complete their purchase.',
								'power-coupons'
							) }
						</span>
					</li>
					<li className="flex items-start gap-2 text-field-label text-sm font-medium leading-6">
						<span className="shrink-0 mt-1.5">
							{ RenderIcon( 'check' ) }
						</span>
						<span>
							<strong>
								{ __( 'Peace of mind:', 'power-coupons' ) }
							</strong>{ ' ' }
							{ __(
								'Automated coupon rules work in the background so you can focus on growing your business.',
								'power-coupons'
							) }
						</span>
					</li>
				</ul>
			</Wrapper>

			<div className="flex flex-col gap-[12px]">
				<Button
					className="px-4 bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-black"
					onClick={ RedirectToDashboard }
					variant="primary"
				>
					{ __( 'Go To Dashboard', 'power-coupons' ) }
				</Button>
				<Button
					className="text-text-tertiary hover:text-text-primary"
					onClick={ () =>
						( window.location.href =
							'admin.php?page=power_coupons_settings&path=settings' )
					}
					variant="ghost"
				>
					{ __( 'View coupon settings', 'power-coupons' ) }
				</Button>
			</div>
		</>
	);
};

export default FinalStep;
