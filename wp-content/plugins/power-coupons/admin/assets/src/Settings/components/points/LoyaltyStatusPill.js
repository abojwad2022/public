import { __ } from '@wordpress/i18n';
import { useStateValue } from '../Data';

function LoyaltyStatusPill() {
	const [ data ] = useStateValue();
	const isEnabled = !! data?.points_settings?.enable;

	const baseClass =
		'inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium leading-none';
	const stateClass = isEnabled
		? 'bg-green-50 text-green-700 ring-1 ring-inset ring-green-200'
		: 'bg-amber-50 text-amber-800 ring-1 ring-inset ring-amber-300';

	return (
		<span
			className={ `${ baseClass } ${ stateClass }` }
			aria-label={
				isEnabled
					? __( 'Loyalty Rewards is enabled', 'power-coupons' )
					: __( 'Loyalty Rewards is disabled', 'power-coupons' )
			}
		>
			<span
				aria-hidden="true"
				className={ `inline-block h-1.5 w-1.5 rounded-full ${
					isEnabled ? 'bg-green-500' : 'bg-amber-500'
				}` }
			/>
			{ isEnabled
				? __( 'Active', 'power-coupons' )
				: __( 'Disabled', 'power-coupons' ) }
		</span>
	);
}

export default LoyaltyStatusPill;
