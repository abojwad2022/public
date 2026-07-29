import FieldRenderer from '../common/FieldRenderer';

function sortFieldsByPriority( fields ) {
	const fieldsArray = Object.values( fields );
	if ( Array.isArray( fieldsArray ) ) {
		return [ ...fieldsArray ].sort( ( a, b ) => {
			const aPriority = typeof a.priority === 'number' ? a.priority : 0;
			const bPriority = typeof b.priority === 'number' ? b.priority : 0;
			return aPriority - bPriority;
		} );
	}
	return fieldsArray;
}

function SectionRenderer( {
	fields,
	masterDisabled = false,
	masterFieldName = '',
} ) {
	if ( ! fields?.sections ) {
		return (
			<div className="h-auto px-6 bg-background-primary rounded-xl shadow-sm">
				{ sortFieldsByPriority( fields ).map( ( field ) => (
					<FieldRenderer
						key={ field.name }
						field={ field }
						disabled={
							masterDisabled && field.name !== masterFieldName
						}
					/>
				) ) }
			</div>
		);
	}

	const sections = fields.sections;

	// Sort sections by priority before rendering.
	const sortedSectionEntries = Object.entries( sections ).sort(
		( [ , a ], [ , b ] ) => {
			const aPriority = typeof a.priority === 'number' ? a.priority : 0;
			const bPriority = typeof b.priority === 'number' ? b.priority : 0;
			return aPriority - bPriority;
		}
	);

	return (
		<>
			{ sortedSectionEntries.map( ( sortedSectionEntry ) => {
				const section = sortedSectionEntry[ 1 ];

				if ( 0 === section.field_args.length ) {
					return null;
				}

				if (
					0 ===
					Object.values( section.field_args )
						.map( ( field ) => FieldRenderer( { field } ) )
						.filter( Boolean ).length
				) {
					return null;
				}

				return (
					<div
						key={ sortedSectionEntry[ 0 ] }
						className="h-auto px-6 bg-background-primary rounded-xl shadow-sm mb-6"
					>
						<h3 className="text-[18px] font-semibold text-[#111827] mb-0 leading-6 tracking-[-0.5%]">
							{ section.label }
						</h3>
						{ sortFieldsByPriority( section.field_args ).map(
							( field ) => (
								<FieldRenderer
									key={ field.name }
									field={ field }
									disabled={
										masterDisabled &&
										field.name !== masterFieldName
									}
								/>
							)
						) }
					</div>
				);
			} ) }
		</>
	);
}

export default SectionRenderer;
