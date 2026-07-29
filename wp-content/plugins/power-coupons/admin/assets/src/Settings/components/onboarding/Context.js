import { createContext, useContext } from 'react';

export const OnboardingContext = createContext( null );

export const useOnboardingContext = () => {
	const context = useContext( OnboardingContext );
	if ( ! context ) {
		throw new Error(
			'useOnboardingContext must be used within an OnboardingContextProvider'
		);
	}
	return context;
};

export const OnboardingContextProvider = ( { children, value } ) => {
	return (
		<OnboardingContext.Provider value={ value }>
			{ children }
		</OnboardingContext.Provider>
	);
};
