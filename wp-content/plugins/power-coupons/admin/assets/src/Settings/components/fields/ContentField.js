import FieldWrapper from '../wrappers/FieldWrapper';

function ContentField( props ) {
	const { children, content } = props;
	return <FieldWrapper content={ content }>{ children }</FieldWrapper>;
}

export default ContentField;
