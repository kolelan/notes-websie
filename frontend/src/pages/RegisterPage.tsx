import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input } from 'antd'
import { api, setAuthToken } from '../lib/api'
import { writeSession } from '../lib/auth'
import type { ApiEnvelope, AuthPayload } from '../types/api'

export default function RegisterPage() {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    setLoading(true)
    try {
      const res = await api.post<ApiEnvelope<AuthPayload>>('/auth/register', { name, email, password })
      const session = {
        accessToken: res.data.data.access_token,
        refreshToken: res.data.data.refresh_token,
      }
      writeSession(session)
      setAuthToken(session.accessToken)
      navigate('/dashboard')
    } catch {
      setError('Не удалось зарегистрироваться. Проверьте данные.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="page">
      <h1>Регистрация</h1>
      <form onSubmit={onSubmit}>
        <Card className="card">
        <label>
          Имя
          <Input value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label>
          Email
          <Input value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label>
          Пароль
          <Input.Password value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        {error && <Alert type="error" message={error} showIcon />}
        <Button type="primary" htmlType="submit" loading={loading}>
          {loading ? 'Создаем...' : 'Зарегистрироваться'}
        </Button>
        </Card>
      </form>
      <p>
        Уже есть аккаунт? <Link to="/login">Войти</Link>
      </p>
    </main>
  )
}

