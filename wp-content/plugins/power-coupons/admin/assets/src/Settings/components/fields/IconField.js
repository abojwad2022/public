import FieldWrapper from '../wrappers/FieldWrapper';
import IconPicker from '../fields/IconPicker';

const IconField = ( props ) => {
	const { title, description, name, value, options } = props;
	return (
		<FieldWrapper title={ title } description={ description } type="block">
			<IconPicker name={ name } value={ value } options={ options } />
		</FieldWrapper>
	);
};

export default IconField;
