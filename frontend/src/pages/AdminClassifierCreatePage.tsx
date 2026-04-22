import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu } from 'antd'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { parseHstoreInput, validateHstoreInput } from '../lib/hstore'

export default function AdminClassifierCreatePage() {
  const navigate = useNavigate()
  const [qualNamef, setQualNamef] = useState('')
  const [qualNames, setQualNames] = useState('')
  const [qualCode, setQualCode] = useState('')
  const [qualNote, setQualNote] = useState('')
  const [qualTag, setQualTag] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const tagErrors = validateHstoreInput(qualTag)
  const hasTagErrors = tagErrors.length > 0

  async function createClassifier() {
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
      await api.post('/admin/classifiers', {
        qual_namef: qualNamef,
        qual_names: qualNames,
        qual_code: qualCode,
        qual_note: qualNote,
        tag: parseHstoreInput(qualTag),
      })
      setMessage('Классификатор создан')
      setTimeout(() => navigate('/admin/classifiers'), 500)
    } catch {
      setError('Не удалось создать классификатор.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: New Classifier</h1>
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
        <label>Полное наименование<Input value={qualNamef} onChange={(e) => setQualNamef(e.target.value)} /></label>
        <label>Краткое наименование<Input value={qualNames} onChange={(e) => setQualNames(e.target.value)} /></label>
        <label>Код<Input value={qualCode} onChange={(e) => setQualCode(e.target.value)} /></label>
        <label>Описание<Input.TextArea rows={3} value={qualNote} onChange={(e) => setQualNote(e.target.value)} /></label>
        <label>tags (key=value, по одному на строку)<Input.TextArea rows={4} value={qualTag} onChange={(e) => setQualTag(e.target.value)} status={hasTagErrors ? 'error' : undefined} /></label>
        {hasTagErrors && <Alert type="warning" showIcon message={tagErrors[0]} style={{ marginBottom: 12 }} />}
        <div className="row">
          <Button type="primary" onClick={() => void createClassifier()} loading={saving} disabled={hasTagErrors}>Создать</Button>
          <Button onClick={() => navigate('/admin/classifiers')}>Отмена</Button>
        </div>
      </Card>
    </main>
  )
}

