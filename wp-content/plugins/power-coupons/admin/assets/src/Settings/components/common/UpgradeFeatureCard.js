import { __ } from '@wordpress/i18n';
import { Container } from '@bsf/force-ui';

const DEFAULT_PRICING_URL =
	'https://cartflows.com/power-coupons-for-woocommerce/pricing/?utm_source=dashboard&utm_medium=free-power-coupons&utm_campaign=go-pro';

function buildPricingUrl( utmMedium ) {
	if ( ! utmMedium ) {
		return DEFAULT_PRICING_URL;
	}
	return `https://cartflows.com/power-coupons-for-woocommerce/pricing/?utm_source=dashboard&utm_medium=${ encodeURIComponent(
		utmMedium
	) }&utm_campaign=go-pro`;
}

function UpgradeFeatureCard( {
	title,
	subtitle,
	description = [],
	visual = null,
	buttonLabel,
	utmMedium,
	className = '',
} ) {
	const resolvedButton = buttonLabel || __( 'Upgrade Now', 'power-coupons' );

	return (
		<div
			role="article"
			className={ `flex flex-col gap-2 p-4 bg-background-primary border border-solid border-border-subtle rounded-xl shadow-sm ${ className }` }
		>
			<Container
				containerType="flex"
				direction="row"
				wrap="wrap"
				className="gap-2 p-2 bg-background-secondary rounded-lg"
			>
				<div className="flex flex-col sm:flex-row gap-6 p-6 bg-background-primary rounded-md shadow-sm w-full">
					{ visual && (
						<div className="flex flex-col gap-2 p-2 shrink-0">
							<div className="w-56 h-48 rounded flex items-center justify-center bg-wpcolorfaded">
								{ visual }
							</div>
						</div>
					) }

					<div className="flex flex-col justify-center gap-3 flex-1">
						<div className="flex flex-col gap-2">
							<h2 className="m-0 text-xl font-semibold text-text-primary leading-[1.4] tracking-tight">
								{ title }
							</h2>
							{ subtitle && (
								<p className="m-0 text-base font-normal text-text-secondary leading-6">
									{ subtitle }
								</p>
							) }
						</div>

						{ Array.isArray( description ) &&
							description.length > 0 && (
								<ul className="list-disc list-outside ml-5 my-0 space-y-1.5 text-text-secondary">
									{ description.map( ( item, i ) => (
										<li
											key={ i }
											className="text-base font-normal leading-7"
										>
											{ item }
										</li>
									) ) }
								</ul>
							) }

						<div className="flex flex-row items-center gap-3 pt-2">
							<a
								href={ buildPricingUrl( utmMedium ) }
								target="_blank"
								rel="noopener noreferrer"
								className="inline-flex items-center justify-center px-4 py-2 rounded-md text-sm font-semibold text-white bg-wpcolor hover:bg-wphovercolor focus:bg-wphovercolor no-underline border-0 ring-0 focus:ring-0 focus-visible:ring-0 focus:outline-0"
							>
								{ resolvedButton }
							</a>
						</div>
					</div>
				</div>
			</Container>
		</div>
	);
}

export default UpgradeFeatureCard;
