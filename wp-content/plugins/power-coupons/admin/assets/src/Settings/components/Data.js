import React, { useReducer, useContext } from 'react';

export const StateContext = React.createContext();

// Wrap our app and provide the Data layer
export const StateProvider = ( { reducer, initialState, children } ) => (
	<StateContext.Provider value={ useReducer( reducer, initialState ) }>
		{ children }
	</StateContext.Provider>
);

// Get information from the data layer
export const useStateValue = () => useContext( StateContext );
