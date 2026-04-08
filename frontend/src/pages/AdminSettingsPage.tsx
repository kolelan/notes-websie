import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { useActionStatus } from '../hooks/useActionStatus'
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
  const [wafEnabled, setWafEnabled] = useState(false)
  const [wafMode, setWafMode] = useState<'off' | 'monitor' | 'block'>('monitor')
  const [wafTrustedIps, setWafTrustedIps] = useState('')
  const [customKey, setCustomKey] = useState('')
  const [customJson, setCustomJson] = useState('{\n  "enabled": true\n}')
  const [saving, setSaving] = useState(false)
  const { error, message, clear, fail, ok } = useActionStatus()

  async function loadSettings() {
    const session = readSession()
    if (!session) {
      navigate('/login')
      return
    }
    setAuthToken(session.accessToken)
    clear()
    try {
      const res = await api.get<ApiEnvelope<Setting[]>>('/admin/settings')
      setSettings(res.data.data)
      const metrika = res.data.data.find((s) => s.key === 'yandex_metrika')
      if (metrika && typeof metrika.value === 'object' && metrika.value !== null) {
        const v = metrika.value as { counter_id?: string; enabled?: boolean }
        setCounterId(v.counter_id ?? '')
        setEnabled(Boolean(v.enabled))
      }
      const waf = res.data.data.find((s) => s.key === 'security.waf')
      if (waf && typeof waf.value === 'object' && waf.value !== null) {
        const v = waf.value as { enabled?: boolean; mode?: 'off' | 'monitor' | 'block'; trusted_ips?: string[] }
        setWafEnabled(Boolean(v.enabled))
        setWafMode(v.mode ?? 'monitor')
        setWafTrustedIps(Array.isArray(v.trusted_ips) ? v.trusted_ips.join('\n') : '')
      }
    } catch {
      fail('Не удалось загрузить настройки.')
    }
  }

  useEffect(() => {
    void loadSettings()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function saveMetrika() {
    clear()
    setSaving(true)
    try {
      await api.put('/admin/settings/yandex_metrika', {
        value: {
          enabled,
          counter_id: counterId.trim(),
        },
      })
      ok('Настройка сохранена')
      await loadSettings()
    } catch {
      fail('Не удалось сохранить настройку.')
    } finally {
      setSaving(false)
    }
  }

  async function saveWafSetting() {
    clear()
    const trustedIps = wafTrustedIps
      .split('\n')
      .map((v) => v.trim())
      .filter(Boolean)
    setSaving(true)
    try {
      await api.put('/admin/settings/security.waf', {
        value: {
          enabled: wafEnabled,
          mode: wafMode,
          trusted_ips: trustedIps,
        },
      })
      ok('Настройка WAF сохранена')
      await loadSettings()
    } catch {
      fail('Не удалось сохранить настройку WAF.')
    } finally {
      setSaving(false)
    }
  }

  async function saveCustomSetting() {
    clear()
    const key = customKey.trim()
    if (!key) {
      fail('Укажите ключ настройки.')
      return
    }
    let parsed: unknown
    try {
      parsed = JSON.parse(customJson)
    } catch {
      fail('Невалидный JSON в поле значения.')
      return
    }
    setSaving(true)
    try {
      await api.put(`/admin/settings/${encodeURIComponent(key)}`, { value: parsed })
      ok('Произвольная настройка сохранена')
      await loadSettings()
    } catch {
      fail('Не удалось сохранить произвольную настройку.')
    } finally {
      setSaving(false)
    }
  }

  function resetCustomForm() {
    clear()
    setCustomKey('')
    setCustomJson('{\n  "enabled": true\n}')
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
        <button disabled={saving} onClick={() => void saveMetrika()}>
          {saving ? 'Сохраняем...' : 'Сохранить'}
        </button>
      </section>

      <section className="card">
        <h2>WAF</h2>
        <label>
          <input type="checkbox" checked={wafEnabled} onChange={(e) => setWafEnabled(e.target.checked)} /> Включено
        </label>
        <label>
          Режим
          <select value={wafMode} onChange={(e) => setWafMode(e.target.value as 'off' | 'monitor' | 'block')}>
            <option value="off">off</option>
            <option value="monitor">monitor</option>
            <option value="block">block</option>
          </select>
        </label>
        <label>
          Trusted IPs (по одному на строку)
          <textarea rows={6} value={wafTrustedIps} onChange={(e) => setWafTrustedIps(e.target.value)} />
        </label>
        <button disabled={saving} onClick={() => void saveWafSetting()}>
          {saving ? 'Сохраняем...' : 'Сохранить WAF'}
        </button>
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
        <div className="row">
          <button disabled={saving} onClick={() => void saveCustomSetting()}>
            {saving ? 'Сохраняем...' : 'Сохранить JSON-настройку'}
          </button>
          <button type="button" disabled={saving} onClick={resetCustomForm}>Сбросить форму</button>
        </div>
      </section>

      <section className="card">
        <h2>Все настройки</h2>
        <pre className="note-content">{JSON.stringify(settings, null, 2)}</pre>
      </section>
    </main>
  )
}
