import React from 'react';
import ColorPicker from '../fields/ColorPicker';
import FieldWrapper from '../wrappers/FieldWrapper';

function ColorField( props ) {
	const { title, description } = props;

	return (
		<FieldWrapper title={ title } description={ description }>
			<div className="power_coupons-color-field">
				<ColorPicker
					name={ props.name }
					value={ props.value }
					defaultColor={ props.default }
				/>
			</div>
		</FieldWrapper>
	);
}

export default ColorField;
