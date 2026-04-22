import { useState } from 'react'
import type { FormEvent } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Checkbox, Input } from 'antd'
import axios from 'axios'
import { api, setAuthToken } from '../lib/api'
import { writeSession } from '../lib/auth'
import type { ApiEnvelope, AuthPayload } from '../types/api'

export default function RegisterPage() {
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [termsAccepted, setTermsAccepted] = useState(false)
  const [error, setError] = useState('')
  const [loading, setLoading] = useState(false)
  const navigate = useNavigate()

  async function onSubmit(e: FormEvent) {
    e.preventDefault()
    setError('')
    if (!termsAccepted) {
      setError('Для регистрации необходимо принять Политику конфиденциальности и Правила сайта.')
      return
    }
    setLoading(true)
    try {
      const res = await api.post<ApiEnvelope<AuthPayload>>('/auth/register', {
        name,
        email,
        password,
        terms_accepted: true,
      })
      const session = {
        accessToken: res.data.data.access_token,
        refreshToken: res.data.data.refresh_token,
      }
      writeSession(session)
      setAuthToken(session.accessToken)
      navigate('/dashboard')
    } catch (err) {
      if (axios.isAxiosError(err)) {
        const data = err.response?.data as { error?: string } | undefined
        if (data?.error) {
          setError(data.error)
          return
        }
      }
      setError('Не удалось зарегистрироваться. Проверьте данные.')
    } finally {
      setLoading(false)
    }
  }

  return (
    <main className="page page-register">
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
        <div style={{ marginBottom: 12 }}>
          <Checkbox checked={termsAccepted} onChange={(e) => setTermsAccepted(e.target.checked)}>
            Я принимаю <Link to="/legal" target="_blank" rel="noreferrer">Политику конфиденциальности и Правила сайта</Link>
          </Checkbox>
        </div>
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

