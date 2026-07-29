import Text from '../fields/Text';
import FieldWrapper from '../wrappers/FieldWrapper';

function TextField( props ) {
	const { title, description, disabled = false } = props;

	return (
		<FieldWrapper
			title={ title }
			description={ description }
			type="block"
			disabled={ disabled }
		>
			<div className="flex-grow">
				<Text
					name={ props.name }
					val={ props.value }
					max={ props.max }
				/>
			</div>
		</FieldWrapper>
	);
}

export default TextField;
