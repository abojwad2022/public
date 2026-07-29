import { Badge, Button } from '@bsf/force-ui';
import { __, sprintf } from '@wordpress/i18n';
import { ArrowUpRight, Check, ExternalLink } from 'lucide-react';

const buildLearnMoreHref = ( url, stepId ) => {
	if ( ! url ) {
		return '';
	}
	const params = new URLSearchParams( {
		utm_source: 'surerank_plugin',
		utm_medium: 'in_product',
		utm_campaign: 'learn',
		utm_content: stepId,
	} );
	return `${ url }${ url.includes( '?' ) ? '&' : '?' }${ params.toString() }`;
};

const LearnTaskCard = ( {
	chapterId,
	step,
	completed,
	autoDetected,
	onToggle,
	onCta,
} ) => {
	const handleCheckboxClick = ( e ) => {
		e.stopPropagation();
		if ( autoDetected ) {
			return;
		}
		onToggle( chapterId, step.id, ! completed );
	};

	return (
		<div className="flex items-center gap-4 p-4 bg-background-primary border border-solid border-border-subtle rounded-lg">
			<button
				type="button"
				onClick={ handleCheckboxClick }
				disabled={ autoDetected }
				aria-pressed={ completed }
				aria-label={
					completed
						? __( 'Mark step incomplete', 'surerank' )
						: __( 'Mark step complete', 'surerank' )
				}
				className={ [
					'shrink-0 self-start mt-0.5 flex items-center justify-center size-5 rounded-full border border-solid transition-colors',
					completed
						? 'bg-button-primary border-button-primary text-text-on-color'
						: 'bg-background-primary border-border-strong text-transparent hover:border-button-primary',
					autoDetected
						? 'cursor-not-allowed opacity-90'
						: 'cursor-pointer',
				].join( ' ' ) }
			>
				<Check className="size-3 shrink-0" strokeWidth={ 3 } />
			</button>
			<div className="flex-1 min-w-0 flex flex-col gap-1">
				<div className="flex items-center gap-2 flex-wrap">
					<span className="text-sm font-medium text-text-primary leading-5">
						{ step.title }
					</span>
					{ autoDetected && (
						<Badge
							size="xs"
							variant="green"
							label={ __( 'Auto-detected', 'surerank' ) }
						/>
					) }
					{ step.learnMoreUrl && (
						<a
							href={ buildLearnMoreHref(
								step.learnMoreUrl,
								step.id
							) }
							target="_blank"
							rel="noopener noreferrer"
							className="inline-flex items-center gap-0.5 text-xs font-medium text-text-secondary hover:text-text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-focus rounded no-underline"
							aria-label={ sprintf(
								// translators: %s: step title
								__( 'Learn more about %s', 'surerank' ),
								step.title
							) }
						>
							{ __( 'Learn More', 'surerank' ) }
							<ExternalLink
								aria-hidden="true"
								className="size-3"
							/>
						</a>
					) }
				</div>
				<span className="text-sm text-text-secondary leading-5">
					{ step.description }
				</span>
			</div>
			<div className="shrink-0">
				<Button
					variant="primary"
					size="sm"
					icon={ <ArrowUpRight /> }
					iconPosition="right"
					onClick={ () => onCta( chapterId, step, autoDetected ) }
				>
					{ ( autoDetected && step.autoDetectedCta?.label ) ||
						step.cta?.label }
				</Button>
			</div>
		</div>
	);
};

export default LearnTaskCard;
