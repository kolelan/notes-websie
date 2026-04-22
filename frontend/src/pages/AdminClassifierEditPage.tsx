import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu } from 'antd'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { formatHstoreOutput, parseHstoreInput, validateHstoreInput } from '../lib/hstore'

export default function AdminClassifierEditPage() {
  const navigate = useNavigate()
  const { id = '' } = useParams()
  const [qualNamef, setQualNamef] = useState('')
  const [qualNames, setQualNames] = useState('')
  const [qualCode, setQualCode] = useState('')
  const [qualNote, setQualNote] = useState('')
  const [qualTag, setQualTag] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const [loading, setLoading] = useState(true)
  const tagErrors = validateHstoreInput(qualTag)
  const hasTagErrors = tagErrors.length > 0

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
            qual_namef: string
            qual_names: string | null
            qual_code: string | null
            qual_note: string | null
            tag: unknown
          }>
        }>('/admin/classifiers', {
          params: { qual_id: id, page: 1, limit: 1 },
        })
        const row = res.data.data[0]
        if (!row) {
          setError('Классификатор не найден.')
          return
        }
        setQualNamef(row.qual_namef ?? '')
        setQualNames(row.qual_names ?? '')
        setQualCode(row.qual_code ?? '')
        setQualNote(row.qual_note ?? '')
        setQualTag(formatHstoreOutput(row.tag))
      } catch {
        setError('Не удалось загрузить классификатор.')
      } finally {
        setLoading(false)
      }
    }
    void load()
  }, [id, navigate])

  async function save() {
    if (hasTagErrors) {
      setError(tagErrors[0])
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
      await api.patch(`/admin/classifiers/${id}`, {
        qual_namef: qualNamef,
        qual_names: qualNames,
        qual_code: qualCode,
        qual_note: qualNote,
        tag: parseHstoreInput(qualTag),
      })
      setMessage('Классификатор обновлен.')
    } catch {
      setError('Не удалось сохранить классификатор.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <main className="page">
      <header className="row">
        <h1>Редактирование классификатора</h1>
        <Menu
          mode="horizontal"
          selectedKeys={['classifiers']}
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
          Полное название
          <Input value={qualNamef} onChange={(e) => setQualNamef(e.target.value)} disabled={loading} />
        </label>
        <label>
          Краткое название
          <Input value={qualNames} onChange={(e) => setQualNames(e.target.value)} disabled={loading} />
        </label>
        <label>
          Код
          <Input value={qualCode} onChange={(e) => setQualCode(e.target.value)} disabled={loading} />
        </label>
        <label>
          Описание
          <Input.TextArea value={qualNote} onChange={(e) => setQualNote(e.target.value)} rows={3} disabled={loading} />
        </label>
        <label>
          tag (hstore, key=value)
          <Input.TextArea value={qualTag} onChange={(e) => setQualTag(e.target.value)} rows={4} disabled={loading} status={hasTagErrors ? 'error' : undefined} />
        </label>
        {hasTagErrors && <Alert type="warning" showIcon message={tagErrors[0]} style={{ marginBottom: 12 }} />}
        <div className="row">
          <Button type="primary" onClick={() => void save()} disabled={saving || loading || hasTagErrors}>
            Сохранить
          </Button>
          <Button onClick={() => navigate('/admin/classifiers')}>Назад</Button>
        </div>
      </Card>
    </main>
  )
}
