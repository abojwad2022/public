import React from 'react';
import { __ } from '@wordpress/i18n';
import { Title } from '@bsf/force-ui';
import SectionRenderer from '../common/SectionRenderer';

function StylingSettings() {
	const fields = powerCouponsSettings.settings_fields?.styling || [];
	return (
		<>
			<Title
				description=""
				icon={ null }
				size="md"
				tag="h2"
				title={ __( 'Styling', 'power-coupons' ) }
				className="mb-6 [&_h2]:text-gray-900 text-xl"
			/>
			<SectionRenderer fields={ fields } />
		</>
	);
}

export default StylingSettings;
