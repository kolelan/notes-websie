import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu, Select } from 'antd'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { parseHstoreInput, validateHstoreInput } from '../lib/hstore'

export default function AdminClassifierItemCreatePage() {
  const navigate = useNavigate()
  const [qualId, setQualId] = useState('')
  const [classifierSearch, setClassifierSearch] = useState('')
  const [classifierOptions, setClassifierOptions] = useState<Array<{ value: string; label: string }>>([])
  const [parentId, setParentId] = useState('')
  const [namef, setNamef] = useState('')
  const [names, setNames] = useState('')
  const [code, setCode] = useState('')
  const [rubrika, setRubrika] = useState('')
  const [note, setNote] = useState('')
  const [tags, setTags] = useState('')
  const [error, setError] = useState('')
  const [message, setMessage] = useState('')
  const [saving, setSaving] = useState(false)
  const tagErrors = validateHstoreInput(tags)
  const hasTagErrors = tagErrors.length > 0
  const rubrikaError = rubrika.trim() !== '' && !/^\d+(?:\.\d+)*$/.test(rubrika.trim())
    ? 'kls_rubrika: используйте формат "число.число", например 1.2.3'
    : ''

  async function loadClassifierOptions(query = '') {
    const session = readSession()
    if (!session) {
      navigate('/login')
      return
    }
    setAuthToken(session.accessToken)
    try {
      const res = await api.get<{ data: Array<{ qual_id: string; qual_namef: string; qual_code: string | null }> }>('/admin/classifiers', {
        params: {
          page: 1,
          limit: 50,
          ...(query.trim() ? { qual_namef: query.trim() } : {}),
        },
      })
      const options = res.data.data.map((c) => ({
        value: c.qual_id,
        label: `${c.qual_namef}${c.qual_code ? ` (${c.qual_code})` : ''}`,
      }))
      setClassifierOptions(options)
    } catch {
      // ignore option loading errors in selector
    }
  }

  async function createItem() {
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
      await api.post('/admin/classifier-items', {
        qual_id: qualId,
        parent_kls_id: parentId || undefined,
        kls_namef: namef,
        kls_names: names,
        kls_code: code,
        kls_rubrika: rubrika.trim() || undefined,
        kls_note: note,
        tags: parseHstoreInput(tags),
      })
      setMessage('Пункт классификатора создан')
      setTimeout(() => navigate(`/admin/classifier-items?qual_id=${encodeURIComponent(qualId)}`), 500)
    } catch {
      setError('Не удалось создать пункт классификатора.')
    } finally {
      setSaving(false)
    }
  }

  useEffect(() => {
    void loadClassifierOptions('')
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: New Classifier Item</h1>
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
          Классификатор (поиск по названию/коду)
          <Select
            value={qualId || undefined}
            showSearch
            allowClear
            placeholder="Начните вводить название или код классификатора"
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
          />
        </label>
        <label>ID классификатора (автоподстановка)<Input value={qualId} readOnly /></label>
        <label>ID родительского раздела (опционально)<Input value={parentId} onChange={(e) => setParentId(e.target.value)} /></label>
        <label>Полное название<Input value={namef} onChange={(e) => setNamef(e.target.value)} /></label>
        <label>Краткое название<Input value={names} onChange={(e) => setNames(e.target.value)} /></label>
        <label>Код пункта<Input value={code} onChange={(e) => setCode(e.target.value)} /></label>
        <label>kls_rubrika (опционально)<Input value={rubrika} onChange={(e) => setRubrika(e.target.value)} status={rubrikaError ? 'error' : undefined} placeholder="Например: 1.2.3" /></label>
        <label>Описание<Input.TextArea rows={3} value={note} onChange={(e) => setNote(e.target.value)} /></label>
        {rubrikaError && <Alert type="warning" showIcon message={rubrikaError} style={{ marginBottom: 12 }} />}
        <label>tags (key=value, по одному на строку)<Input.TextArea rows={4} value={tags} onChange={(e) => setTags(e.target.value)} status={hasTagErrors ? 'error' : undefined} /></label>
        {hasTagErrors && <Alert type="warning" showIcon message={tagErrors[0]} style={{ marginBottom: 12 }} />}
        <div className="row">
          <Button type="primary" onClick={() => void createItem()} loading={saving} disabled={hasTagErrors || Boolean(rubrikaError)}>Создать</Button>
          <Button onClick={() => navigate('/admin/classifier-items')}>Отмена</Button>
        </div>
      </Card>
    </main>
  )
}

