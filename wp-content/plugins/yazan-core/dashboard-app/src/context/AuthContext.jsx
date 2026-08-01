import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react'
import { boot, setNonce, AUTH_LOST_EVENT } from '../api/client.js'
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

  /**
   * Re-read the current user, including their permission map.
   *
   * Needed because permissions can change underneath an open tab — someone edits your role, or
   * your own role editor save changes what you may see. Without this the sidebar would keep
   * offering screens the server has already started refusing.
   */
  const refresh = useCallback(async () => {
    try {
      const result = await authApi.me()
      if (result?.nonce) setNonce(result.nonce)
      setUser(result.user)
      return result.user
    } catch {
      // A failure here is not fatal: the existing session data stays, and any genuinely forbidden
      // request will still be refused by the server.
      return null
    }
  }, [])

  /*
   * The API client fires this when the server reports the session is gone (401) or the account has
   * been suspended. Dropping the user returns the app to the sign-in screen immediately, rather
   * than leaving a shell that 403s on every click.
   */
  useEffect(() => {
    const onAuthLost = () => setUser(null)
    window.addEventListener(AUTH_LOST_EVENT, onAuthLost)
    return () => window.removeEventListener(AUTH_LOST_EVENT, onAuthLost)
  }, [])

  const value = useMemo(() => {
    const isSuper = Boolean(user?.isSuper)
    const permissions = user?.permissions || {}

    // A super admin bypasses every check, mirroring Yazan_Permissions::can() on the server.
    const can = (permission) => isSuper || Boolean(permissions[permission])

    return {
      user,
      busy,
      login,
      logout,
      refresh,
      isSuper,
      permissions,
      can,
      canAny: (...list) => isSuper || list.some((p) => Boolean(permissions[p])),
      canAll: (...list) => isSuper || list.every((p) => Boolean(permissions[p])),
    }
  }, [user, busy, login, logout, refresh])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const context = useContext(AuthContext)
  if (!context) throw new Error('useAuth must be used inside <AuthProvider>')
  return context
}
