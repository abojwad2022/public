import { __ } from '@wordpress/i18n';
import { Table, Switch } from '@bsf/force-ui';

const DUMMY_CAMPAIGNS = [
	{
		title: 'Order Points \u2014 1 point per $1',
		action_type: 'Order Earning',
		earn_type: 'Per Currency Unit',
		earn_value: '1',
	},
	{
		title: 'Welcome Bonus \u2014 50 points',
		action_type: 'Signup Bonus',
		earn_type: 'Fixed',
		earn_value: '50',
	},
	{
		title: 'Product Review \u2014 25 points',
		action_type: 'Product Review',
		earn_type: 'Fixed',
		earn_value: '25',
	},
	{
		title: 'Double Points Weekend',
		action_type: 'Order Earning',
		earn_type: 'Per Currency Unit',
		earn_value: '2',
	},
	{
		title: 'Referral Reward \u2014 100 points',
		action_type: 'Referral',
		earn_type: 'Fixed',
		earn_value: '100',
	},
	{
		title: 'Premium Members \u2014 5% bonus',
		action_type: 'Order Earning',
		earn_type: 'Percentage',
		earn_value: '5',
	},
];

function DummyPointsTable() {
	return (
		<div className="bg-background-primary rounded-xl border border-border-subtle p-4 flex flex-col gap-4">
			<div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-3">
				<h2 className="m-0 text-xl font-semibold text-text-primary">
					{ __( 'Reward Campaigns', 'power-coupons' ) }
				</h2>
				<button
					type="button"
					className="flex items-center gap-2 px-4 py-2 text-white bg-orange-500 rounded-md border-none cursor-default whitespace-nowrap"
					tabIndex={ -1 }
				>
					<span>
						{ __( 'Create New Campaign', 'power-coupons' ) }
					</span>
				</button>
			</div>
			<Table
				checkboxSelection
				className="whitespace-nowrap sm:whitespace-normal"
			>
				<Table.Head>
					<Table.HeadCell>
						{ __( 'Title', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						{ __( 'Action Type', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						{ __( 'Earn Type', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						{ __( 'Earn Value', 'power-coupons' ) }
					</Table.HeadCell>
					<Table.HeadCell>
						{ __( 'Status', 'power-coupons' ) }
					</Table.HeadCell>
				</Table.Head>
				<Table.Body>
					{ DUMMY_CAMPAIGNS.map( ( c, i ) => (
						<Table.Row key={ i }>
							<Table.Cell className="text-text-secondary text-sm font-normal">
								{ c.title }
							</Table.Cell>
							<Table.Cell className="text-text-secondary text-sm font-normal">
								{ c.action_type }
							</Table.Cell>
							<Table.Cell className="text-text-secondary text-sm font-normal">
								{ c.earn_type }
							</Table.Cell>
							<Table.Cell className="text-text-secondary text-sm font-normal">
								{ c.earn_value }
							</Table.Cell>
							<Table.Cell>
								<Switch
									size="sm"
									defaultValue={ i < 4 }
									className="[&>input]:!border-none"
								/>
							</Table.Cell>
						</Table.Row>
					) ) }
				</Table.Body>
			</Table>
		</div>
	);
}

export default DummyPointsTable;
