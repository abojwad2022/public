import FieldWrapper from '../wrappers/FieldWrapper';
import ProductSelector from '../bogo/ProductSelector';
import { useStateValue } from '../Data';
import { parseFieldName, setNestedValue, getNestedValue } from './fieldUtils';

function ProductSearchField( props ) {
	const { title, description, name, disabled = false } = props;
	const [ data, dispatch ] = useStateValue();
	const parts = parseFieldName( name );
	const value = getNestedValue( data, parts ) || [];

	const handleChange = ( ids ) => {
		const newData = setNestedValue( data, parts, ids );
		dispatch( { type: 'CHANGE', data: newData } );
	};

	return (
		<FieldWrapper
			title={ title }
			description={ description }
			type="block"
			disabled={ disabled }
		>
			<div className="flex-grow">
				<ProductSelector
					label=""
					placeholder="Search products…"
					value={ value }
					onChange={ handleChange }
					portalId="power-coupons-settings"
				/>
			</div>
		</FieldWrapper>
	);
}

export default ProductSearchField;
