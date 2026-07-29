import { RadioButton } from '@bsf/force-ui';
import parse from 'html-react-parser';
import { useStateValue } from '../Data';
import FieldWrapper from '../wrappers/FieldWrapper';

const CouponTemplatePicker = ( {
	name,
	value,
	title,
	description,
	disabled = false,
} ) => {
	const couponStyles = powerCouponsSettings.coupon_templates;

	const [ data, dispatch ] = useStateValue();

	const updateValue = ( _couponStyle ) => {
		// Create a deep copy to avoid mutation issues
		const newData = JSON.parse( JSON.stringify( data ) );
		const elements = name.split( /[\[\]]/ ).filter( ( el ) => el );

		if ( elements.length >= 2 ) {
			newData[ elements[ 0 ] ][ elements[ 1 ] ] = _couponStyle;
		}

		dispatch( {
			type: 'CHANGE',
			data: newData,
		} );
	};

	return (
		<FieldWrapper
			disabled={ disabled }
			type="block"
			title={ title }
			description={ description }
		>
			<RadioButton.Group
				columns={ Object.keys( couponStyles ).length }
				onChange={ updateValue }
				size="md"
				value={ value }
			>
				<div className="flex gap-6">
					{ Object.keys( couponStyles ).map( ( couponStyle ) => (
						<div key={ couponStyle } className="flex gap-[26px]">
							<button
								onClick={ () => updateValue( couponStyle ) }
								className="bg-transparent border-0 p-0 m-0 cursor-pointer power-coupons-coupon-style-btn"
								style={ { direction: 'ltr' } }
								type="button"
							>
								{ parse(
									powerCouponsSettings.coupon_templates[
										couponStyle
									]
								) }
								<RadioButton.Button value={ couponStyle } />
							</button>
						</div>
					) ) }
				</div>
			</RadioButton.Group>
		</FieldWrapper>
	);
};

export default CouponTemplatePicker;
