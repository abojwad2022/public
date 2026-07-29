import { Label } from '@bsf/force-ui';

export default ( { heading, subHeading } ) => (
	<div className="p-[8px]">
		<Label className="text-3xl font-semibold text-text-primary" size="md">
			{ heading }
		</Label>
		<Label className="text-[#566A86] mt-2">{ subHeading }</Label>
	</div>
);
