import { useRef, useState } from 'react'

export function useActionStatus(autoHideMs = 2500) {
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const timerRef = useRef<number | null>(null)

  function clear() {
    setError('')
    setMessage('')
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current)
      timerRef.current = null
    }
  }

  function fail(text: string) {
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current)
      timerRef.current = null
    }
    setMessage('')
    setError(text)
  }

  function ok(text: string) {
    if (timerRef.current !== null) {
      window.clearTimeout(timerRef.current)
    }
    setError('')
    setMessage(text)
    timerRef.current = window.setTimeout(() => {
      setMessage('')
      timerRef.current = null
    }, autoHideMs)
  }

  return { error, message, clear, fail, ok }
}
