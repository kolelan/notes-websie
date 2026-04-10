import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu, Select, Table } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { useActionStatus } from '../hooks/useActionStatus'
import type { ApiEnvelope } from '../types/api'

type Classifier = {
  qual_id: string
  qual_namef: string
  qual_names: string | null
  qual_code: string | null
  qual_note: string | null
  qual_type_id: number
  tag: unknown
}

type ClassifierSection = {
  kls_id: string
  qual_id: string
  kls_namef: string
  kls_names: string | null
  kls_note: string | null
  tags: unknown
  kls_code: string
  kls_rubrika: string
  parent_rubrika: string | null
}

export default function AdminClassifiersPage() {
  const navigate = useNavigate()
  const [classifiers, setClassifiers] = useState<Classifier[]>([])
  const [sections, setSections] = useState<ClassifierSection[]>([])
  const [selectedClassifierId, setSelectedClassifierId] = useState('')
  const [selectedSectionId, setSelectedSectionId] = useState('')
  const [classifiersPage, setClassifiersPage] = useState(1)
  const [classifiersPageSize, setClassifiersPageSize] = useState(10)
  const [sectionsPage, setSectionsPage] = useState(1)
  const [sectionsPageSize, setSectionsPageSize] = useState(10)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const [qualNamef, setQualNamef] = useState('')
  const [qualNames, setQualNames] = useState('')
  const [qualCode, setQualCode] = useState('')
  const [qualNote, setQualNote] = useState('')
  const [qualTypeId, setQualTypeId] = useState('1')
  const [qualTag, setQualTag] = useState('')

  const [sectionNamef, setSectionNamef] = useState('')
  const [sectionNames, setSectionNames] = useState('')
  const [sectionCode, setSectionCode] = useState('')
  const [sectionNote, setSectionNote] = useState('')
  const [sectionTags, setSectionTags] = useState('')
  const [parentSectionId, setParentSectionId] = useState('')

  const { error, message, clear, fail, ok } = useActionStatus()

  function parseTagTextToObject(source: string): Record<string, string> {
    const result: Record<string, string> = {}
    for (const rawLine of source.split('\n')) {
      const line = rawLine.trim()
      if (!line) continue
      const idx = line.indexOf('=')
      if (idx <= 0) continue
      const key = line.slice(0, idx).trim()
      const value = line.slice(idx + 1).trim()
      if (!key) continue
      result[key] = value
    }
    return result
  }

  function parseHstoreLikeString(value: string): Record<string, string> {
    const result: Record<string, string> = {}
    const re = /"([^"]+)"=>"(.*?)"/g
    let match = re.exec(value)
    while (match) {
      const key = match[1]
      const val = match[2]
      if (key) result[key] = val
      match = re.exec(value)
    }
    return result
  }

  function normalizeTagValue(value: unknown): Record<string, string> {
    if (!value) return {}
    if (typeof value === 'object' && !Array.isArray(value)) return value as Record<string, string>
    if (typeof value === 'string') {
      const trimmed = value.trim()
      if (!trimmed) return {}
      try {
        const parsed = JSON.parse(trimmed) as unknown
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          return parsed as Record<string, string>
        }
      } catch {
        return parseHstoreLikeString(trimmed)
      }
    }
    return {}
  }

  function formatTagValueToText(value: unknown): string {
    const obj = normalizeTagValue(value)
    return Object.entries(obj)
      .map(([k, v]) => `${k}=${String(v)}`)
      .join('\n')
  }

  async function ensureSession() {
    const session = readSession()
    if (!session) {
      navigate('/login')
      return null
    }
    setAuthToken(session.accessToken)
    return session
  }

  async function loadClassifiers() {
    if (!(await ensureSession())) return
    setLoading(true)
    clear()
    try {
      const res = await api.get<ApiEnvelope<Classifier[]>>('/admin/classifiers')
      const list = res.data.data
      setClassifiers(list)
      const activeId = selectedClassifierId || list[0]?.qual_id || ''
      setSelectedClassifierId(activeId)
      if (activeId) {
        await loadSections(activeId)
      } else {
        setSections([])
      }
    } catch {
      fail('Не удалось загрузить классификаторы.')
    } finally {
      setLoading(false)
    }
  }

  async function loadSections(qualId: string) {
    if (!qualId) {
      setSections([])
      return
    }
    try {
      const res = await api.get<ApiEnvelope<ClassifierSection[]>>(`/admin/classifiers/${qualId}/sections`)
      setSections(res.data.data)
      setSectionsPage(1)
    } catch {
      fail('Не удалось загрузить разделы классификатора.')
    }
  }

  useEffect(() => {
    void loadClassifiers()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    void loadSections(selectedClassifierId)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedClassifierId])

  async function createClassifier() {
    if (!selectedClassifierId && qualNamef.trim() === '') {
      fail('Укажите полное имя классификатора.')
      return
    }
    setSaving(true)
    clear()
    try {
      await api.post('/admin/classifiers', {
        qual_namef: qualNamef,
        qual_names: qualNames,
        qual_code: qualCode,
        qual_note: qualNote,
        qual_type_id: Number(qualTypeId) || 1,
        tag: parseTagTextToObject(qualTag),
      })
      ok('Классификатор создан')
      setQualNamef('')
      setQualNames('')
      setQualCode('')
      setQualNote('')
      setQualTag('')
      await loadClassifiers()
    } catch {
      fail('Не удалось создать классификатор.')
    } finally {
      setSaving(false)
    }
  }

  async function updateClassifier() {
    if (!selectedClassifierId) {
      fail('Выберите классификатор.')
      return
    }
    setSaving(true)
    clear()
    try {
      await api.patch(`/admin/classifiers/${selectedClassifierId}`, {
        qual_namef: qualNamef,
        qual_names: qualNames,
        qual_code: qualCode,
        qual_note: qualNote,
        tag: parseTagTextToObject(qualTag),
      })
      ok('Классификатор обновлен')
      await loadClassifiers()
    } catch {
      fail('Не удалось обновить классификатор.')
    } finally {
      setSaving(false)
    }
  }

  async function removeClassifier() {
    if (!selectedClassifierId) {
      fail('Выберите классификатор.')
      return
    }
    if (!window.confirm('Удалить классификатор и его разделы?')) return
    setSaving(true)
    clear()
    try {
      await api.delete(`/admin/classifiers/${selectedClassifierId}`)
      ok('Классификатор удален')
      setSelectedClassifierId('')
      await loadClassifiers()
    } catch {
      fail('Не удалось удалить классификатор.')
    } finally {
      setSaving(false)
    }
  }

  async function createSection() {
    if (!selectedClassifierId) {
      fail('Выберите классификатор.')
      return
    }
    setSaving(true)
    clear()
    try {
      await api.post(`/admin/classifiers/${selectedClassifierId}/sections`, {
        kls_namef: sectionNamef,
        kls_names: sectionNames,
        kls_code: sectionCode,
        kls_note: sectionNote,
        tags: parseTagTextToObject(sectionTags),
        parent_kls_id: parentSectionId || undefined,
      })
      ok('Раздел классификатора создан')
      setSectionNamef('')
      setSectionNames('')
      setSectionCode('')
      setSectionNote('')
      setSectionTags('')
      setParentSectionId('')
      await loadSections(selectedClassifierId)
    } catch {
      fail('Не удалось создать раздел.')
    } finally {
      setSaving(false)
    }
  }

  async function updateSection() {
    if (!selectedSectionId) {
      fail('Выберите раздел.')
      return
    }
    setSaving(true)
    clear()
    try {
      await api.patch(`/admin/classifier-sections/${selectedSectionId}`, {
        kls_namef: sectionNamef,
        kls_names: sectionNames,
        kls_code: sectionCode,
        kls_note: sectionNote,
        tags: parseTagTextToObject(sectionTags),
      })
      ok('Раздел классификатора обновлен')
      await loadSections(selectedClassifierId)
    } catch {
      fail('Не удалось обновить раздел.')
    } finally {
      setSaving(false)
    }
  }

  async function removeSection() {
    if (!selectedSectionId) {
      fail('Выберите раздел.')
      return
    }
    if (!window.confirm('Удалить раздел?')) return
    setSaving(true)
    clear()
    try {
      await api.delete(`/admin/classifier-sections/${selectedSectionId}`)
      ok('Раздел удален')
      setSelectedSectionId('')
      await loadSections(selectedClassifierId)
    } catch {
      fail('Не удалось удалить раздел.')
    } finally {
      setSaving(false)
    }
  }

  function onSelectClassifier(id: string) {
    const found = classifiers.find((item) => item.qual_id === id)
    setSelectedClassifierId(id)
    if (!found) return
    setQualNamef(found.qual_namef ?? '')
    setQualNames(found.qual_names ?? '')
    setQualCode(found.qual_code ?? '')
    setQualNote(found.qual_note ?? '')
    setQualTypeId(String(found.qual_type_id ?? 1))
    setQualTag(formatTagValueToText(found.tag))
  }

  function onSelectSection(id: string) {
    const found = sections.find((item) => item.kls_id === id)
    setSelectedSectionId(id)
    if (!found) return
    setSectionNamef(found.kls_namef ?? '')
    setSectionNames(found.kls_names ?? '')
    setSectionCode(found.kls_code ?? '')
    setSectionNote(found.kls_note ?? '')
    setSectionTags(formatTagValueToText(found.tags))
    setParentSectionId('')
  }

  const classifierColumns: ColumnsType<Classifier> = [
    { title: 'ID', dataIndex: 'qual_id', key: 'qual_id', width: 120 },
    { title: 'Код', dataIndex: 'qual_code', key: 'qual_code', width: 180, render: (_, row) => row.qual_code || '-' },
    { title: 'Наименование', dataIndex: 'qual_namef', key: 'qual_namef' },
  ]

  const sectionColumns: ColumnsType<ClassifierSection> = [
    { title: 'ID', dataIndex: 'kls_id', key: 'kls_id', width: 120 },
    { title: 'Код', dataIndex: 'kls_code', key: 'kls_code', width: 180 },
    { title: 'Название', dataIndex: 'kls_namef', key: 'kls_namef' },
    { title: 'Рубрика', dataIndex: 'kls_rubrika', key: 'kls_rubrika', width: 220 },
  ]

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: Classifiers</h1>
        <Menu
          mode="horizontal"
          selectedKeys={['classifiers']}
          items={[
            { key: 'users', label: <Link to="/admin/users">Users</Link> },
            { key: 'settings', label: <Link to="/admin/settings">Settings</Link> },
            { key: 'classifiers', label: <Link to="/admin/classifiers">Classifiers</Link> },
            { key: 'audit', label: <Link to="/admin/audit">Audit</Link> },
            { key: 'dashboard', label: <Link to="/dashboard">Dashboard</Link> },
          ]}
        />
      </header>
      {message && <Alert type="success" message={message} showIcon style={{ marginBottom: 12 }} />}
      {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 12 }} />}
      {loading ? (
        <p>Загрузка...</p>
      ) : (
        <>
          <Card className="card">
            <h2>Классификаторы</h2>
            <Table
              rowKey="qual_id"
              dataSource={classifiers}
              columns={classifierColumns}
              size="small"
              pagination={{
                current: classifiersPage,
                pageSize: classifiersPageSize,
                total: classifiers.length,
                showSizeChanger: true,
                pageSizeOptions: [10, 25, 50],
                onChange: (page, size) => {
                  setClassifiersPage(page)
                  setClassifiersPageSize(size)
                },
              }}
              onRow={(record) => ({ onClick: () => onSelectClassifier(record.qual_id) })}
              rowClassName={(record) => (record.qual_id === selectedClassifierId ? 'selected-row' : '')}
            />
            <div className="compact-filters-row">
              <label>
                Выбор
                <Select
                  value={selectedClassifierId || undefined}
                  onChange={onSelectClassifier}
                  options={classifiers.map((c) => ({ value: c.qual_id, label: `${c.qual_namef} (${c.qual_code ?? '-'})` }))}
                  allowClear
                />
              </label>
              <label>
                Полное наименование
                <Input value={qualNamef} onChange={(e) => setQualNamef(e.target.value)} />
              </label>
              <label>
                Краткое наименование
                <Input value={qualNames} onChange={(e) => setQualNames(e.target.value)} />
              </label>
            </div>
            <div className="compact-filters-row">
              <label>
                Код
                <Input value={qualCode} onChange={(e) => setQualCode(e.target.value)} />
              </label>
              <label>
                Тип (id)
                <Input value={qualTypeId} onChange={(e) => setQualTypeId(e.target.value)} disabled />
              </label>
              <label>
                tag (hstore)
                <Input.TextArea
                  rows={4}
                  value={qualTag}
                  onChange={(e) => setQualTag(e.target.value)}
                  placeholder={'key=value'}
                />
              </label>
            </div>
            <label>
              Описание
              <Input.TextArea rows={3} value={qualNote} onChange={(e) => setQualNote(e.target.value)} />
            </label>
            <div className="row">
              <div style={{ display: 'flex', gap: 8 }}>
                <Button type="primary" disabled={saving} onClick={() => void createClassifier()}>Создать</Button>
                <Button disabled={saving} onClick={() => void updateClassifier()}>Обновить</Button>
                <Button danger disabled={saving} onClick={() => void removeClassifier()}>Удалить</Button>
              </div>
            </div>
          </Card>

          <Card className="card">
            <h2>Разделы и пункты классификатора</h2>
            <Table
              rowKey="kls_id"
              dataSource={sections}
              columns={sectionColumns}
              pagination={{
                current: sectionsPage,
                pageSize: sectionsPageSize,
                total: sections.length,
                showSizeChanger: true,
                pageSizeOptions: [10, 25, 50],
                onChange: (page, size) => {
                  setSectionsPage(page)
                  setSectionsPageSize(size)
                },
              }}
              onRow={(record) => ({ onClick: () => onSelectSection(record.kls_id) })}
              rowClassName={(record) => (record.kls_id === selectedSectionId ? 'selected-row' : '')}
            />
            <div className="compact-filters-row" style={{ marginTop: 12 }}>
              <label>
                Родительский раздел (для создания)
                <Select
                  value={parentSectionId || undefined}
                  onChange={(value) => setParentSectionId(value ?? '')}
                  options={sections.map((s) => ({ value: s.kls_id, label: `${s.kls_rubrika} - ${s.kls_namef}` }))}
                  allowClear
                />
              </label>
              <label>
                Полное наименование
                <Input value={sectionNamef} onChange={(e) => setSectionNamef(e.target.value)} />
              </label>
              <label>
                Краткое наименование
                <Input value={sectionNames} onChange={(e) => setSectionNames(e.target.value)} />
              </label>
            </div>
            <div className="compact-filters-row">
              <label>
                Код
                <Input value={sectionCode} onChange={(e) => setSectionCode(e.target.value)} />
              </label>
              <label>
                tags (hstore)
                <Input.TextArea
                  rows={4}
                  value={sectionTags}
                  onChange={(e) => setSectionTags(e.target.value)}
                  placeholder={'key=value'}
                />
              </label>
              <label>
                Описание
                <Input value={sectionNote} onChange={(e) => setSectionNote(e.target.value)} />
              </label>
            </div>
            <div className="row">
              <div style={{ display: 'flex', gap: 8 }}>
                <Button type="primary" disabled={saving} onClick={() => void createSection()}>Создать раздел</Button>
                <Button disabled={saving} onClick={() => void updateSection()}>Обновить раздел</Button>
                <Button danger disabled={saving} onClick={() => void removeSection()}>Удалить раздел</Button>
              </div>
            </div>
          </Card>
        </>
      )}
    </main>
  )
}

