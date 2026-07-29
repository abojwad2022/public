import { useStateValue } from '../Data';
import ColorField from '../fields/ColorField';
import DropdownField from '../fields/DropdownField';
import NumberField from '../fields/NumberField';
import TextField from '../fields/TextField';
import ToggleField from '../fields/ToggleField';
import CouponTemplatePicker from '../fields/CouponTemplatePicker';
import ProductSearchField from '../fields/ProductSearchField';
import CategorySearchField from '../fields/CategorySearchField';
import PageSearchField from '../fields/PageSearchField';

const componentMap = {
	toggle: ToggleField,
	dropdown: DropdownField,
	text: TextField,
	number: NumberField,
	color: ColorField,
	coupon_template_picker: CouponTemplatePicker,
	product_search: ProductSearchField,
	category_search: CategorySearchField,
	page_search: PageSearchField,
};

const getValueFromName = ( name, data ) => {
	const parts = name.split( /[\[\]]/ ).filter( Boolean );
	if ( parts.length === 2 ) {
		return data[ parts[ 0 ] ][ parts[ 1 ] ];
	}
	return data[ name ];
};

const evaluateConditions = ( conditions, data ) => {
	if ( ! conditions ) {
		return true;
	}

	return conditions.fields.every( ( cond ) => {
		const val = getValueFromName( cond.name, data );

		if ( undefined === val ) {
			// Most probably Pro plugin is deactivated.
			return true;
		}

		switch ( cond.operator ) {
			case '!==':
				return val !== cond.value;
			case '===':
			default:
				return val === cond.value;
		}
	} );
};

function FieldRenderer( { field, disabled = false } ) {
	const stateValue = useStateValue();
	const [ data ] = stateValue;
	const FieldComponent = componentMap[ field.type ];

	if ( ! FieldComponent ) {
		return null;
	}

	if ( ! evaluateConditions( field.conditions, data ) ) {
		return null;
	}

	// Merge explicit disabled prop with field-level disabled_conditions.
	const isConditionDisabled = field.disabled_conditions
		? ! evaluateConditions( field.disabled_conditions, data )
		: false;

	const props = {
		title: field.label,
		description: field.description,
		name: field.name,
		badge: field.badge,
		min: field.min,
		max: field.max,
		disabled: disabled || isConditionDisabled,
	};

	props.value = getValueFromName( field.name, data );

	if ( field.options ) {
		props.optionsArray = field.options;
	}
	if ( field.type_attr ) {
		props.type = field.type_attr;
	}
	if ( field.default_var && field.type === 'color' ) {
		props.default = data.color_default_vars[ field.default_var ];
	}

	return <FieldComponent { ...props } />;
}

export default FieldRenderer;
