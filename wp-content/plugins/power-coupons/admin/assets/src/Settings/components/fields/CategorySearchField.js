import FieldWrapper from '../wrappers/FieldWrapper';
import CategorySelector from '../common/CategorySelector';
import { useStateValue } from '../Data';
import { parseFieldName, setNestedValue, getNestedValue } from './fieldUtils';

function CategorySearchField( props ) {
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
				<CategorySelector
					label=""
					placeholder="Search categories…"
					value={ value }
					onChange={ handleChange }
				/>
			</div>
		</FieldWrapper>
	);
}

export default CategorySearchField;
