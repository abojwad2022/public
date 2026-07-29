import React from 'react';
import { __ } from '@wordpress/i18n';
import { Title } from '@bsf/force-ui';
import FieldRenderer from '../common/FieldRenderer';

function LabelSettings() {
	const fields = powerCouponsSettings.settings_fields?.label || [];
	return (
		<>
			<Title
				description=""
				icon={ null }
				size="md"
				tag="h2"
				title={ __( 'Text Label', 'power-coupons' ) }
				className="mb-6 [&_h2]:text-gray-900 text-xl"
			/>
			<div className="h-auto px-6 bg-background-primary rounded-xl shadow-sm">
				{ fields.map( ( field ) => (
					<FieldRenderer key={ field.name } field={ field } />
				) ) }
			</div>
		</>
	);
}

export default LabelSettings;
