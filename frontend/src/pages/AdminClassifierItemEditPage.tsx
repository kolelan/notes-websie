import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu, Select } from 'antd'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { formatHstoreOutput, parseHstoreInput, validateHstoreInput } from '../lib/hstore'

export default function AdminClassifierItemEditPage() {
  const navigate = useNavigate()
  const { id = '' } = useParams()
  const [klsNamef, setKlsNamef] = useState('')
  const [qualId, setQualId] = useState('')
  const [classifierSearch, setClassifierSearch] = useState('')
  const [classifierOptions, setClassifierOptions] = useState<Array<{ value: string; label: string }>>([])
  const [klsNames, setKlsNames] = useState('')
  const [klsCode, setKlsCode] = useState('')
  const [klsRubrika, setKlsRubrika] = useState('')
  const [klsNote, setKlsNote] = useState('')
  const [tags, setTags] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const tagErrors = validateHstoreInput(tags)
  const hasTagErrors = tagErrors.length > 0
  const rubrikaError = klsRubrika.trim() !== '' && !/^\d+(?:\.\d+)*$/.test(klsRubrika.trim())
    ? 'kls_rubrika: используйте формат "число.число", например 1.2.3'
    : ''

  async function loadClassifierOptions(query = '') {
    try {
      const res = await api.get<{ data: Array<{ qual_id: string; qual_namef: string; qual_code: string | null }> }>('/admin/classifiers', {
        params: { page: 1, limit: 50, ...(query.trim() ? { qual_namef: query.trim() } : {}) },
      })
      setClassifierOptions(
        res.data.data.map((c) => ({
          value: c.qual_id,
          label: `${c.qual_namef}${c.qual_code ? ` (${c.qual_code})` : ''}`,
        })),
      )
    } catch {
      // ignore search option errors
    }
  }

  useEffect(() => {
    async function load() {
      const session = readSession()
      if (!session) {
        navigate('/login')
        return
      }
      setAuthToken(session.accessToken)
      setLoading(true)
      setError('')
      try {
        const res = await api.get<{
          data: Array<{
            kls_namef: string
            qual_id: string
            kls_names: string | null
            kls_code: string | null
            kls_rubrika: string | null
            kls_note: string | null
            tags: unknown
          }>
        }>('/admin/classifier-items', {
          params: { kls_id: id, page: 1, limit: 1 },
        })
        const row = res.data.data[0]
        if (!row) {
          setError('Пункт не найден.')
          return
        }
        setKlsNamef(row.kls_namef ?? '')
        setQualId(row.qual_id ?? '')
        setKlsNames(row.kls_names ?? '')
        setKlsCode(row.kls_code ?? '')
        setKlsRubrika(row.kls_rubrika ?? '')
        setKlsNote(row.kls_note ?? '')
        setTags(formatHstoreOutput(row.tags))
      } catch {
        setError('Не удалось загрузить пункт.')
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [id, navigate])

  useEffect(() => {
    void loadClassifierOptions('')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function save() {
    if (hasTagErrors) {
      setError(tagErrors[0])
      return
    }
    if (rubrikaError) {
      setError(rubrikaError)
      return
    }
    const session = readSession()
    if (!session) {
      navigate('/login')
      return
    }
    setAuthToken(session.accessToken)
    setError('')
    setMessage('')
    setSaving(true)
    try {
      await api.patch(`/admin/classifier-items/${id}`, {
        qual_id: qualId,
        kls_namef: klsNamef,
        kls_names: klsNames,
        kls_code: klsCode,
        kls_rubrika: klsRubrika.trim() || undefined,
        kls_note: klsNote,
        tags: parseHstoreInput(tags),
      })
      setMessage('Пункт обновлен.')
    } catch {
      setError('Не удалось сохранить пункт.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <main className="page">
      <header className="row">
        <h1>Редактирование пункта</h1>
        <Menu
          mode="horizontal"
          selectedKeys={['items']}
          items={[
            { key: 'classifiers', label: <Link to="/admin/classifiers">Classifiers</Link> },
            { key: 'items', label: <Link to="/admin/classifier-items">Items</Link> },
            { key: 'dashboard', label: <Link to="/dashboard">Dashboard</Link> },
          ]}
        />
      </header>

      {message && <Alert type="success" message={message} showIcon style={{ marginBottom: 12 }} />}
      {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 12 }} />}

      <Card className="card">
        <label>
          Классификатор (qual_id)
          <Select
            value={qualId || undefined}
            showSearch
            allowClear
            placeholder="Выберите классификатор"
            filterOption={false}
            onSearch={(value) => {
              setClassifierSearch(value)
              void loadClassifierOptions(value)
            }}
            onFocus={() => {
              if (classifierOptions.length === 0) void loadClassifierOptions(classifierSearch)
            }}
            onChange={(value) => setQualId(value ?? '')}
            options={classifierOptions}
            disabled={loading}
          />
        </label>
        <label>
          Полное название
          <Input value={klsNamef} onChange={(e) => setKlsNamef(e.target.value)} disabled={loading} />
        </label>
        <label>
          Краткое название
          <Input value={klsNames} onChange={(e) => setKlsNames(e.target.value)} disabled={loading} />
        </label>
        <label>
          Код
          <Input value={klsCode} onChange={(e) => setKlsCode(e.target.value)} disabled={loading} />
        </label>
        <label>
          kls_rubrika
          <Input value={klsRubrika} onChange={(e) => setKlsRubrika(e.target.value)} disabled={loading} status={rubrikaError ? 'error' : undefined} placeholder="Например: 1.2.3" />
        </label>
        <label>
          Описание
          <Input.TextArea value={klsNote} onChange={(e) => setKlsNote(e.target.value)} rows={3} disabled={loading} />
        </label>
        {rubrikaError && <Alert type="warning" showIcon message={rubrikaError} style={{ marginBottom: 12 }} />}
        <label>
          tags (hstore, key=value)
          <Input.TextArea value={tags} onChange={(e) => setTags(e.target.value)} rows={4} disabled={loading} status={hasTagErrors ? 'error' : undefined} />
        </label>
        {hasTagErrors && <Alert type="warning" showIcon message={tagErrors[0]} style={{ marginBottom: 12 }} />}
        <div className="row">
          <Button type="primary" onClick={() => void save()} disabled={saving || loading || hasTagErrors || Boolean(rubrikaError) || !qualId}>
            Сохранить
          </Button>
          <Button onClick={() => navigate('/admin/classifier-items')}>Назад</Button>
        </div>
      </Card>
    </main>
  )
}
