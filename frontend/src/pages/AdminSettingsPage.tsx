import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import type { ApiEnvelope } from '../types/api'

type Setting = {
  key: string
  value: unknown
  updated_by: string | null
  updated_at: string
}

export default function AdminSettingsPage() {
  const navigate = useNavigate()
  const [settings, setSettings] = useState<Setting[]>([])
  const [counterId, setCounterId] = useState('')
  const [enabled, setEnabled] = useState(false)
  const [customKey, setCustomKey] = useState('')
  const [customJson, setCustomJson] = useState('{\n  "enabled": true\n}')
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  async function loadSettings() {
    const session = readSession()
    if (!session) {
      navigate('/')
      return
    }
    setAuthToken(session.accessToken)
    try {
      const res = await api.get<ApiEnvelope<Setting[]>>('/admin/settings')
      setSettings(res.data.data)
      const metrika = res.data.data.find((s) => s.key === 'yandex_metrika')
      if (metrika && typeof metrika.value === 'object' && metrika.value !== null) {
        const v = metrika.value as { counter_id?: string; enabled?: boolean }
        setCounterId(v.counter_id ?? '')
        setEnabled(Boolean(v.enabled))
      }
    } catch {
      setError('Не удалось загрузить настройки.')
    }
  }

  useEffect(() => {
    void loadSettings()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function saveMetrika() {
    setError('')
    setMessage('')
    try {
      await api.put('/admin/settings/yandex_metrika', {
        value: {
          enabled,
          counter_id: counterId.trim(),
        },
      })
      setMessage('Настройка сохранена')
      await loadSettings()
    } catch {
      setError('Не удалось сохранить настройку.')
    }
  }

  async function saveCustomSetting() {
    setError('')
    setMessage('')
    const key = customKey.trim()
    if (!key) {
      setError('Укажите ключ настройки.')
      return
    }
    let parsed: unknown
    try {
      parsed = JSON.parse(customJson)
    } catch {
      setError('Невалидный JSON в поле значения.')
      return
    }
    try {
      await api.put(`/admin/settings/${encodeURIComponent(key)}`, { value: parsed })
      setMessage('Произвольная настройка сохранена')
      await loadSettings()
    } catch {
      setError('Не удалось сохранить произвольную настройку.')
    }
  }

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: Settings</h1>
        <div className="row">
          <Link to="/admin/users">Users</Link>
          <Link to="/admin/audit">Audit</Link>
          <Link to="/dashboard">Dashboard</Link>
        </div>
      </header>
      {message && <p>{message}</p>}
      {error && <p className="error">{error}</p>}
      <section className="card">
        <h2>Yandex Metrika</h2>
        <label>
          Counter ID
          <input value={counterId} onChange={(e) => setCounterId(e.target.value)} />
        </label>
        <label>
          <input type="checkbox" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} /> Включено
        </label>
        <button onClick={() => void saveMetrika()}>Сохранить</button>
      </section>

      <section className="card">
        <h2>Произвольная настройка</h2>
        <label>
          Ключ
          <input placeholder="например: public.homepage.hero" value={customKey} onChange={(e) => setCustomKey(e.target.value)} />
        </label>
        <label>
          JSON value
          <textarea rows={10} value={customJson} onChange={(e) => setCustomJson(e.target.value)} />
        </label>
        <button onClick={() => void saveCustomSetting()}>Сохранить JSON-настройку</button>
      </section>

      <section className="card">
        <h2>Все настройки</h2>
        <pre className="note-content">{JSON.stringify(settings, null, 2)}</pre>
      </section>
    </main>
  )
}
