import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu, Select, Switch } from 'antd'
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
        <Menu
          mode="horizontal"
          selectedKeys={['settings']}
          items={[
            { key: 'users', label: <Link to="/admin/users">Users</Link> },
            { key: 'settings', label: <Link to="/admin/settings">Settings</Link> },
            { key: 'audit', label: <Link to="/admin/audit">Audit</Link> },
            { key: 'dashboard', label: <Link to="/dashboard">Dashboard</Link> },
          ]}
        />
      </header>
      {message && <Alert type="success" message={message} showIcon style={{ marginBottom: 12 }} />}
      {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 12 }} />}
      <Card className="card">
        <h2>Yandex Metrika</h2>
        <label>
          Counter ID
          <Input value={counterId} onChange={(e) => setCounterId(e.target.value)} />
        </label>
        <label>
          <Switch checked={enabled} onChange={setEnabled} /> Включено
        </label>
        <Button type="primary" disabled={saving} onClick={() => void saveMetrika()}>
          {saving ? 'Сохраняем...' : 'Сохранить'}
        </Button>
      </Card>

      <Card className="card">
        <h2>WAF</h2>
        <label>
          <Switch checked={wafEnabled} onChange={setWafEnabled} /> Включено
        </label>
        <label>
          Режим
          <Select
            value={wafMode}
            options={[
              { value: 'off', label: 'off' },
              { value: 'monitor', label: 'monitor' },
              { value: 'block', label: 'block' },
            ]}
            onChange={(value) => setWafMode(value)}
          />
        </label>
        <label>
          Trusted IPs (по одному на строку)
          <Input.TextArea rows={6} value={wafTrustedIps} onChange={(e) => setWafTrustedIps(e.target.value)} />
        </label>
        <Button type="primary" disabled={saving} onClick={() => void saveWafSetting()}>
          {saving ? 'Сохраняем...' : 'Сохранить WAF'}
        </Button>
      </Card>

      <Card className="card">
        <h2>Произвольная настройка</h2>
        <label>
          Ключ
          <Input placeholder="например: public.homepage.hero" value={customKey} onChange={(e) => setCustomKey(e.target.value)} />
        </label>
        <label>
          JSON value
          <Input.TextArea rows={10} value={customJson} onChange={(e) => setCustomJson(e.target.value)} />
        </label>
        <div className="row">
          <Button type="primary" disabled={saving} onClick={() => void saveCustomSetting()}>
            {saving ? 'Сохраняем...' : 'Сохранить JSON-настройку'}
          </Button>
          <Button type="default" disabled={saving} onClick={resetCustomForm}>Сбросить форму</Button>
        </div>
      </Card>

      <Card className="card">
        <h2>Все настройки</h2>
        <pre className="note-content">{JSON.stringify(settings, null, 2)}</pre>
      </Card>
    </main>
  )
}
