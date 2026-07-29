import React from 'react';
import { __ } from '@wordpress/i18n';
import { Title } from '@bsf/force-ui';
import FieldRenderer from '../common/FieldRenderer';
import { useStateValue } from '../Data';

function GeneralSettings() {
	const fields = powerCouponsSettings.settings_fields?.general || [];
	const [ data ] = useStateValue();
	const isPluginEnabled = data?.general?.enable_plugin ?? true;

	return (
		<>
			<Title
				description=""
				icon={ null }
				size="md"
				tag="h2"
				title={ __( 'General', 'power-coupons' ) }
				className="mb-6 [&_h2]:text-gray-900 text-xl"
			/>
			<div className="h-auto px-6 bg-background-primary rounded-xl shadow-sm">
				{ fields.map( ( field ) => (
					<FieldRenderer
						key={ field.name }
						field={ field }
						disabled={
							field.name !== 'general[enable_plugin]' &&
							! isPluginEnabled
						}
					/>
				) ) }
			</div>
		</>
	);
}

export default GeneralSettings;
