export type Session = {
  accessToken: string
  refreshToken: string
}

const STORAGE_KEY = 'notes_session'

export function readSession(): Session | null {
  const raw = localStorage.getItem(STORAGE_KEY)
  if (!raw) return null
  try {
    return JSON.parse(raw) as Session
  } catch {
    localStorage.removeItem(STORAGE_KEY)
    return null
  }
}

export function writeSession(session: Session | null) {
  if (!session) {
    localStorage.removeItem(STORAGE_KEY)
    return
  }
  localStorage.setItem(STORAGE_KEY, JSON.stringify(session))
}

