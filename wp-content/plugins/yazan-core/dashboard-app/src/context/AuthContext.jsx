import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { boot, setNonce } from '../api/client.js'
import { authApi } from '../api/endpoints.js'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(boot.loggedIn ? boot.user : null)
  const [busy, setBusy] = useState(false)

  const login = useCallback(async (username, password, remember) => {
    setBusy(true)
    try {
      const result = await authApi.login(username, password, remember)
      // The server mints a nonce bound to the now-logged-in user — adopt it before any other call.
      setNonce(result.nonce)
      setUser(result.user)
      return result.user
    } finally {
      setBusy(false)
    }
  }, [])

  const logout = useCallback(async () => {
    setBusy(true)
    try {
      await authApi.logout()
    } catch {
      // Even if the call fails, drop the local session so the UI returns to the login screen.
    } finally {
      setUser(null)
      setBusy(false)
    }
  }, [])

  const value = useMemo(
    () => ({
      user,
      busy,
      login,
      logout,
      can: (capability) => Boolean(user?.capabilities?.[capability]),
    }),
    [user, busy, login, logout],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
