import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu } from 'antd'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import type { ApiEnvelope } from '../types/api'

type MePayload = {
  id: string
  email: string
  name: string
  role: string
  is_active: boolean
  created_at: string
}

export default function ProfilePage() {
  const navigate = useNavigate()
  const [me, setMe] = useState<MePayload | null>(null)
  const [name, setName] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  async function loadProfile() {
    const session = readSession()
    if (!session) {
      navigate('/login')
      return
    }
    setAuthToken(session.accessToken)
    setLoading(true)
    setError('')
    try {
      const res = await api.get<ApiEnvelope<MePayload>>('/me')
      setMe(res.data.data)
      setName(res.data.data.name ?? '')
    } catch {
      setError('Не удалось загрузить профиль.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadProfile()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function saveName() {
    setError('')
    setMessage('')
    if (name.trim() === '') {
      setError('Имя не может быть пустым.')
      return
    }
    setSaving(true)
    try {
      await api.patch('/me', { name: name.trim() })
      setMessage('Профиль обновлен')
      await loadProfile()
      window.setTimeout(() => setMessage(''), 2500)
    } catch {
      setError('Не удалось обновить профиль.')
    } finally {
      setSaving(false)
    }
  }

  function resetForm() {
    setError('')
    setMessage('')
    setName(me?.name ?? '')
  }

  if (loading) return <main className="page">Загрузка...</main>

  return (
    <main className="page">
      <header className="row">
        <h1>Профиль</h1>
        <Menu
          mode="horizontal"
          selectable={false}
          items={[
            { key: 'home', label: <Link to="/">Главная</Link> },
            { key: 'dashboard', label: <Link to="/dashboard">Dashboard</Link> },
          ]}
        />
      </header>
      {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 12 }} />}
      {message && <Alert type="success" message={message} showIcon style={{ marginBottom: 12 }} />}
      {me && (
        <Card className="card">
          <p><strong>Email:</strong> {me.email}</p>
          <p><strong>Role:</strong> {me.role}</p>
          <p><strong>Создан:</strong> {me.created_at}</p>
          <label>
            Имя
            <Input value={name} onChange={(e) => setName(e.target.value)} />
          </label>
          <div className="row">
            <Button type="primary" onClick={() => void saveName()} loading={saving}>
              {saving ? 'Сохраняем...' : 'Сохранить'}
            </Button>
            <Button onClick={resetForm} type="default">Отменить изменения</Button>
          </div>
        </Card>
      )}
    </main>
  )
}
