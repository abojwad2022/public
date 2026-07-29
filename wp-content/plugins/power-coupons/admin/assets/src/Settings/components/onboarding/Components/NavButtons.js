import { Button } from '@bsf/force-ui';
import { __ } from '@wordpress/i18n';
import { RenderIcon } from '../Utils';
import { useOnboardingContext } from '../Context';

export default ( { labels } ) => {
	const { handleStepCount } = useOnboardingContext();

	const { increaseStep, decreaseStep, skipStep } = handleStepCount();

	return (
		<div className="flex justify-between">
			{ null !== labels?.back && (
				<Button
					icon={ RenderIcon( 'chevronLeft' ) }
					onClick={ decreaseStep }
					variant="outline"
				>
					{ labels?.back || __( 'Back', 'power-coupons' ) }
				</Button>
			) }

			<div className="flex gap-[12px]">
				{ null !== labels?.skip && (
					<Button
						onClick={ skipStep }
						variant="ghost"
						className="text-text-tertiary hover:text-text-primary"
					>
						{ labels?.skip || __( 'Skip', 'power-coupons' ) }
					</Button>
				) }

				{ null !== labels?.next && (
					<Button
						icon={ RenderIcon( 'chevronRight' ) }
						iconPosition="right"
						onClick={ increaseStep }
						variant="primary"
						className="p-[10px] w-max bg-wpcolor hover:bg-wphovercolor rounded-md box-content outline-0 hover:outline-0 focus:ring-0 focus-visible:ring-1 ring-black"
					>
						{ labels?.next || __( 'Next', 'power-coupons' ) }
					</Button>
				) }
			</div>
		</div>
	);
};
