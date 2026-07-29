import { useCallback } from '@wordpress/element';

const buildUrl = ( cta ) => {
	const adminBase = window?.surerank_globals?.wp_dashboard_url || '';

	if ( cta.type === 'route' ) {
		return `${ adminBase }?page=surerank#${ cta.target }`;
	}

	if ( cta.type === 'edit-screen' ) {
		return adminBase.replace( /admin\.php\/?$/, '' ) + cta.target;
	}

	return cta.target;
};

/**
 * Returns a click handler for a task CTA. Clicking marks the step complete
 * (unless it's auto-detected) and opens the target in a new tab.
 *
 * @param {Function} markStep - Hook callback to persist completion state.
 * @return {Function} CTA click handler.
 */
const useCtaHandler = ( markStep ) => {
	return useCallback(
		( chapterId, step, isAutoDetected ) => {
			if ( ! isAutoDetected && markStep ) {
				markStep( chapterId, step.id, true );
			}

			const cta = ( isAutoDetected && step.autoDetectedCta ) || step.cta;
			if ( ! cta?.target ) {
				return;
			}

			window.open( buildUrl( cta ), '_blank', 'noopener,noreferrer' );
		},
		[ markStep ]
	);
};

export default useCtaHandler;
