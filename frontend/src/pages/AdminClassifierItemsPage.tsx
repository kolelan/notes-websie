import { useEffect, useState } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu, Table } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'

type ClassifierItem = {
  kls_id: string
  qual_id: string
  qual_code: string
  qual_namef: string
  kls_rubrika: string | null
  kls_code: string
  kls_namef: string
  kls_names: string | null
  kls_note: string | null
  kls_code_parent: string | null
  kls_namef_parent: string | null
}

type ListResponse<T> = { data: T[]; meta?: { page: number; pages: number; total: number; limit: number } }

export default function AdminClassifierItemsPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const [rows, setRows] = useState<ClassifierItem[]>([])
  const [selectedIds, setSelectedIds] = useState<string[]>([])
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const [qualId, setQualId] = useState(searchParams.get('qual_id') ?? '')
  const [qualCode, setQualCode] = useState('')
  const [qualNamef, setQualNamef] = useState('')
  const [klsId, setKlsId] = useState('')
  const [klsCode, setKlsCode] = useState('')
  const [klsNamef, setKlsNamef] = useState('')
  const [klsNames, setKlsNames] = useState('')
  const [klsNote, setKlsNote] = useState('')
  const [parentCode, setParentCode] = useState('')
  const [parentNamef, setParentNamef] = useState('')

  const [error, setError] = useState('')
  const [message, setMessage] = useState('')

  async function ensureSession() {
    const session = readSession()
    if (!session) {
      navigate('/login')
      return null
    }
    setAuthToken(session.accessToken)
    return session
  }

  async function loadData(targetPage = page, targetSize = pageSize) {
    if (!(await ensureSession())) return
    setLoading(true)
    setError('')
    setMessage('')
    try {
      const res = await api.get<ListResponse<ClassifierItem>>('/admin/classifier-items', {
        params: {
          page: targetPage,
          limit: targetSize,
          ...(qualId.trim() ? { qual_id: qualId.trim() } : {}),
          ...(qualCode.trim() ? { qual_code: qualCode.trim() } : {}),
          ...(qualNamef.trim() ? { qual_namef: qualNamef.trim() } : {}),
          ...(klsId.trim() ? { kls_id: klsId.trim() } : {}),
          ...(klsCode.trim() ? { kls_code: klsCode.trim() } : {}),
          ...(klsNamef.trim() ? { kls_namef: klsNamef.trim() } : {}),
          ...(klsNames.trim() ? { kls_names: klsNames.trim() } : {}),
          ...(klsNote.trim() ? { kls_note: klsNote.trim() } : {}),
          ...(parentCode.trim() ? { kls_code_parent: parentCode.trim() } : {}),
          ...(parentNamef.trim() ? { kls_namef_parent: parentNamef.trim() } : {}),
        },
      })
      setRows(res.data.data)
      setPage(res.data.meta?.page ?? targetPage)
      setPageSize(res.data.meta?.limit ?? targetSize)
      setTotal(res.data.meta?.total ?? res.data.data.length)
      setSelectedIds([])
    } catch {
      setError('Не удалось загрузить пункты классификаторов.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData(1, pageSize)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    const nextQualId = searchParams.get('qual_id') ?? ''
    setQualId(nextQualId)
    void loadData(1, pageSize)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams.toString()])

  async function runBulk(action: 'enable' | 'disable' | 'delete') {
    if (selectedIds.length === 0) {
      setError('Выберите пункты.')
      return
    }
    if (!window.confirm(`Выполнить "${action}" для ${selectedIds.length} пунктов?`)) return
    setSaving(true)
    setError('')
    setMessage('')
    try {
      await api.post('/admin/classifier-items/bulk', { action, ids: selectedIds })
      setMessage('Групповая операция выполнена')
      await loadData(1, pageSize)
    } catch {
      setError('Не удалось выполнить групповую операцию.')
    } finally {
      setSaving(false)
    }
  }

  async function copyItem(id: string) {
    setSaving(true)
    setError('')
    setMessage('')
    try {
      await api.post(`/admin/classifier-items/${id}/copy`)
      setMessage('Пункт скопирован')
      await loadData(page, pageSize)
    } catch {
      setError('Не удалось скопировать пункт.')
    } finally {
      setSaving(false)
    }
  }

  async function deleteItem(id: string) {
    if (!window.confirm('Удалить пункт классификатора?')) return
    setSaving(true)
    setError('')
    setMessage('')
    try {
      await api.delete(`/admin/classifier-items/${id}`)
      setMessage('Пункт удален')
      await loadData(page, pageSize)
    } catch {
      setError('Не удалось удалить пункт.')
    } finally {
      setSaving(false)
    }
  }

  const columns: ColumnsType<ClassifierItem> = [
    { title: 'ID', dataIndex: 'kls_id', key: 'kls_id', width: 120 },
    { title: 'ID классификатора', dataIndex: 'qual_id', key: 'qual_id', width: 140 },
    { title: 'Код классификатора', dataIndex: 'qual_code', key: 'qual_code', width: 160 },
    { title: 'Рубрика', dataIndex: 'kls_rubrika', key: 'kls_rubrika', width: 160, render: (_, r) => r.kls_rubrika || '-' },
    { title: 'Код пункта', dataIndex: 'kls_code', key: 'kls_code', width: 160 },
    { title: 'Полное название пункта', dataIndex: 'kls_namef', key: 'kls_namef' },
    { title: 'Краткое название пункта', dataIndex: 'kls_names', key: 'kls_names', render: (_, r) => r.kls_names || '-' },
    { title: 'Описание пункта', dataIndex: 'kls_note', key: 'kls_note', render: (_, r) => r.kls_note || '-' },
    { title: 'Код раздела', dataIndex: 'kls_code_parent', key: 'kls_code_parent', render: (_, r) => r.kls_code_parent || '-' },
    { title: 'Название раздела', dataIndex: 'kls_namef_parent', key: 'kls_namef_parent', render: (_, r) => r.kls_namef_parent || '-' },
    {
      title: '',
      key: 'actions',
      width: 150,
      render: (_, r) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <Button size="small" title="Редактировать" onClick={() => navigate(`/admin/classifier-items/${r.kls_id}/edit`)}>
            <i className="fa-solid fa-pen-to-square" />
          </Button>
          <Button size="small" title="Копировать" onClick={() => void copyItem(r.kls_id)}>
            <i className="fa-solid fa-copy" />
          </Button>
          <Button size="small" danger title="Удалить" onClick={() => void deleteItem(r.kls_id)}>
            <i className="fa-solid fa-trash" />
          </Button>
        </div>
      ),
    },
  ]

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: Classifier Items</h1>
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
        <div className="compact-filters-row">
          <label>ID классификатора<Input value={qualId} onChange={(e) => setQualId(e.target.value)} /></label>
          <label>Код классификатора<Input value={qualCode} onChange={(e) => setQualCode(e.target.value)} /></label>
          <label>Название классификатора<Input value={qualNamef} onChange={(e) => setQualNamef(e.target.value)} /></label>
        </div>
        <div className="compact-filters-row">
          <label>ID пункта<Input value={klsId} onChange={(e) => setKlsId(e.target.value)} /></label>
          <label>Код пункта<Input value={klsCode} onChange={(e) => setKlsCode(e.target.value)} /></label>
          <label>Полное название пункта<Input value={klsNamef} onChange={(e) => setKlsNamef(e.target.value)} /></label>
        </div>
        <div className="compact-filters-row">
          <label>Краткое название пункта<Input value={klsNames} onChange={(e) => setKlsNames(e.target.value)} /></label>
          <label>Описание пункта<Input value={klsNote} onChange={(e) => setKlsNote(e.target.value)} /></label>
          <label>Код раздела<Input value={parentCode} onChange={(e) => setParentCode(e.target.value)} /></label>
        </div>
        <div className="compact-filters-row">
          <label>Название раздела<Input value={parentNamef} onChange={(e) => setParentNamef(e.target.value)} /></label>
          <div />
          <div />
        </div>
        <div className="row">
          <div style={{ display: 'flex', gap: 8 }}>
            <Button type="primary" onClick={() => void loadData(1, pageSize)}>Фильтровать</Button>
            <Button onClick={() => navigate('/admin/classifier-items/new')}>Добавить пункт</Button>
            <Button onClick={() => void runBulk('enable')} disabled={saving || selectedIds.length === 0}>Включить выбранные</Button>
            <Button onClick={() => void runBulk('disable')} disabled={saving || selectedIds.length === 0}>Выключить выбранные</Button>
            <Button danger onClick={() => void runBulk('delete')} disabled={saving || selectedIds.length === 0}>Удалить выбранные</Button>
          </div>
          <span>Выбрано: {selectedIds.length}</span>
        </div>
      </Card>

      <Card className="card">
        <Table
          rowKey="kls_id"
          dataSource={rows}
          loading={loading}
          columns={columns}
          rowSelection={{
            selectedRowKeys: selectedIds,
            onChange: (keys) => setSelectedIds(keys.map(String)),
          }}
          pagination={{
            current: page,
            pageSize,
            total,
            showSizeChanger: true,
            pageSizeOptions: [10, 25, 50],
            onChange: (nextPage, nextSize) => void loadData(nextPage, nextSize),
          }}
        />
      </Card>
    </main>
  )
}

