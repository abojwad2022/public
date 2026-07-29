import { Button, Loader, ProgressSteps, Topbar } from '@bsf/force-ui';
import { useEffect, useState } from 'react';
import { __ } from '@wordpress/i18n';
import Logo from '../../../../images/logo.svg';
import { RedirectToDashboard, RenderIcon, Screens } from './Utils';
import { OnboardingContextProvider } from './Context';
import apiFetch from '@wordpress/api-fetch';

const Onboarding = () => {
	const [ data, setData ] = useState(
		window.powerCouponsSettings.onboarding.defaults
	);

	const [ currentStepIndex, setCurrentStepIndex ] = useState( 0 );

	const [ isSaving, setIsSaving ] = useState( false );

	const StepScreen = Screens[ currentStepIndex ];

	const currentScreenData = data?.[ currentStepIndex ] || {};

	const handleStepCount = () => {
		return {
			increaseStep: () => setCurrentStepIndex( currentStepIndex + 1 ),
			decreaseStep: () => setCurrentStepIndex( currentStepIndex - 1 ),
			skipStep: () => {
				handleData( 'hasSkipped', true );
				handleStepCount().increaseStep();
			},
		};
	};

	const handleData = ( key, value ) => {
		setData( ( prevData ) => ( {
			...prevData,
			[ currentStepIndex ]: {
				...( prevData[ currentStepIndex ] || {} ),
				[ key ]: value,
			},
		} ) );
	};

	useEffect( () => {
		if ( currentStepIndex === Screens.length - 1 ) {
			setIsSaving( true );

			apiFetch( {
				url: window.powerCouponsSettings.onboarding.ajaxUrl,
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
				},
				body: JSON.stringify( data ),
			} )
				.then( () => {
					setIsSaving( false );
				} )
				.catch( () => {
					setIsSaving( false );
				} );
		}
	}, [ currentStepIndex, data ] );

	const handleExitSetup = () => {
		// Track onboarding skip with current step name.
		const stepNames = [
			'welcome',
			'configure',
			'user-details',
			'recommend-plugins',
			'final',
		];
		const skipUrl = window.powerCouponsSettings.onboarding.skipUrl;
		if ( skipUrl ) {
			apiFetch( {
				url: skipUrl,
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					exit_step: stepNames[ currentStepIndex ] || 'unknown',
				} ),
			} ).finally( () => {
				RedirectToDashboard();
			} );
		} else {
			RedirectToDashboard();
		}
	};

	return (
		<OnboardingContextProvider
			value={ {
				handleData,
				handleStepCount,
				currentScreenData,
			} }
		>
			<div className="bg-background-secondary w-full pb-10">
				<Topbar className="bg-background-secondary">
					<Topbar.Left>
						<Topbar.Item>
							<img
								alt={ __(
									'Power Coupons Logo',
									'power-coupons'
								) }
								className="w-[30px]"
								src={ Logo }
							/>
						</Topbar.Item>
					</Topbar.Left>
					<Topbar.Middle>
						<Topbar.Item className="w-[12rem]">
							<ProgressSteps
								size="md"
								variant="number"
								currentStep={ currentStepIndex + 1 }
								completedVariant="number"
							>
								{ Screens.slice( 0, -1 ).map(
									( Screen, index ) => (
										<ProgressSteps.Step key={ index } />
									)
								) }
							</ProgressSteps>
						</Topbar.Item>
					</Topbar.Middle>
					<Topbar.Right>
						<Topbar.Item>
							<Button
								icon={ RenderIcon( 'close' ) }
								iconPosition="right"
								size="xs"
								onClick={ handleExitSetup }
								variant="ghost"
							>
								{ __( 'Exit Guided Setup', 'power-coupons' ) }
							</Button>
						</Topbar.Item>
					</Topbar.Right>
				</Topbar>

				<div className="md:w-[718px] box-border mx-auto p-[24px] mt-[24px] border border-solid border-border-subtle bg-background-primary rounded-xl shadow-sm space-y-6">
					{ isSaving && (
						<div className="absolute inset-0 bg-white opacity-50 z-50 flex items-center justify-center">
							<Loader
								className=""
								icon={ null }
								size="lg"
								variant="primary"
							/>
						</div>
					) }
					<StepScreen />
				</div>
			</div>
		</OnboardingContextProvider>
	);
};

export default Onboarding;
