import parse from 'html-react-parser';
import { useId } from '@wordpress/element';
import { Label } from '@bsf/force-ui';

function FieldWrapper( props ) {
	const {
		children,
		title,
		description,
		badge,
		content,
		type = 'inline',
		disabled = false,
	} = props;

	// Generate a stable unique ID for ARIA associations.
	const fieldId = useId();

	return (
		<section
			className={ `flex ${
				type === 'block' ? 'flex-col' : 'flex-col sm:flex-row'
			} py-6 justify-between gap-2 lg:gap-5 border-0 border-b border-solid border-border-subtle last:border-b-0${
				disabled ? ' opacity-50 pointer-events-none' : ''
			}` }
			aria-labelledby={ title ? `${ fieldId }-title` : undefined }
		>
			{ ( title || description ) && (
				<div
					className={ `${
						type === 'block' ? 'w-full' : 'w-full sm:w-[70%]'
					}` }
				>
					{ title && (
						<Label
							className="font-medium mb-1"
							htmlFor={ fieldId }
							size="md"
							id={ `${ fieldId }-title` }
							as="h3"
						>
							{ title }
						</Label>
					) }
					{ description && (
						<>
							<p
								className="font-normal text-sm text-text-field-helper m-0"
								id={ `${ fieldId }-description` }
								aria-hidden="true"
							>
								{ parse( description ) }
							</p>
							{ badge && (
								<span
									className="inline-flex items-center mt-1 px-2.5 py-1 rounded-md text-xs font-medium bg-wpcolorfaded !text-wpcolor"
									role="status"
									aria-label={ `Status: ${ badge }` }
								>
									{ badge }
								</span>
							) }
						</>
					) }
				</div>
			) }
			{ content && (
				<div
					className="pr-16 pb-8 w-full"
					role="group"
					aria-labelledby={ title ? `${ fieldId }-title` : '' }
					aria-describedby={
						description ? `${ fieldId }-description` : ''
					}
				>
					{ children }
				</div>
			) }

			{ ! content && children }
		</section>
	);
}

export default FieldWrapper;
