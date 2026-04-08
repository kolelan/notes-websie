import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, setAuthToken } from '../lib/api'
import { writeSession } from '../lib/auth'
import type { ApiEnvelope, AuthPayload } from '../types/api'

export default function LoginPage() {
  const [email, setEmail] = useState('admin@example.com')
  const [password, setPassword] = useState('change_me')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      const res = await api.post<ApiEnvelope<AuthPayload>>('/auth/login', { email, password })
      const session = {
        accessToken: res.data.data.access_token,
        refreshToken: res.data.data.refresh_token,
      }
      writeSession(session)
      setAuthToken(session.accessToken)
      navigate('/dashboard')
    } catch (err) {
      setError('Не удалось войти. Проверьте email и пароль.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="page">
      <h1>Вход</h1>
      <form className="card" onSubmit={onSubmit}>
        <label>
          Email
          <input value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label>
          Пароль
          <input type="password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        {error && <p className="error">{error}</p>}
        <button disabled={loading}>{loading ? 'Входим...' : 'Войти'}</button>
      </form>
      <p>
        Нет аккаунта? <Link to="/register">Зарегистрироваться</Link>
      </p>
    </main>
  )
}

