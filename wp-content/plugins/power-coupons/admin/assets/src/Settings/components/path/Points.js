import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { Tabs } from '@bsf/force-ui';
import { Link } from 'react-router-dom';
import { ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import CampaignsList from '../points/CampaignsList';
import PointsHistory from '../points/PointsHistory';
import { useStateValue } from '../Data';

const TABS = [
	{ slug: 'campaigns', title: __( 'Reward Campaigns', 'power-coupons' ) },
	{ slug: 'history', title: __( 'Logs', 'power-coupons' ) },
];

function Points( { toast } ) {
	const [ activeTab, setActiveTab ] = useState( 'campaigns' );
	const [ data ] = useStateValue();
	const isLoyaltyEnabled = !! data?.points_settings?.enable;

	const tabSelector = (
		<Tabs activeItem={ activeTab }>
			<Tabs.Group
				className="pc-tabs-rounded"
				orientation="horizontal"
				size="md"
				variant="rounded"
				width="auto"
			>
				{ TABS.map( ( tab ) => (
					<Tabs.Tab
						key={ tab.slug }
						slug={ tab.slug }
						text={ tab.title }
						type="button"
						onClick={ () => setActiveTab( tab.slug ) }
					/>
				) ) }
			</Tabs.Group>
		</Tabs>
	);

	return (
		<div className="flex flex-col gap-4">
			{ ! isLoyaltyEnabled && (
				<div
					role="alert"
					className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 rounded-lg p-4 bg-amber-50 border border-solid border-amber-300 text-amber-900"
				>
					<div className="flex items-start gap-3">
						<ExclamationTriangleIcon
							className="h-5 w-5 flex-shrink-0 mt-0.5 text-amber-600"
							aria-hidden="true"
						/>
						<div>
							<p className="m-0 font-semibold text-sm">
								{ __(
									'Loyalty Rewards is currently disabled.',
									'power-coupons'
								) }
							</p>
							<p className="m-0 mt-1 text-sm text-amber-800">
								{ __(
									"Customers won't earn or redeem credits even when campaigns are marked Active. Turn on the master toggle to start running your program.",
									'power-coupons'
								) }
							</p>
						</div>
					</div>
					<Link
						to={ {
							pathname: 'admin.php',
							search: '?page=power_coupons_settings&path=settings&tab=power_coupons_points',
						} }
						className="inline-flex items-center justify-center gap-2 px-4 py-2 rounded-md text-sm font-medium text-white hover:text-white bg-orange-500 hover:bg-orange-600 no-underline whitespace-nowrap focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2"
					>
						{ __( 'Open settings', 'power-coupons' ) }
					</Link>
				</div>
			) }
			{ 'campaigns' === activeTab && (
				<CampaignsList toast={ toast } tabSelector={ tabSelector } />
			) }
			{ 'history' === activeTab && (
				<PointsHistory toast={ toast } tabSelector={ tabSelector } />
			) }
		</div>
	);
}

export default Points;
