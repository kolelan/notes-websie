import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu, Select, Table } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { useActionStatus } from '../hooks/useActionStatus'

type Classifier = {
  qual_id: string
  qual_is_del: boolean
  qual_namef: string
  qual_names: string | null
  qual_code: string | null
  qual_note: string | null
}

type ListResponse<T> = {
  data: T[]
  meta?: { page: number; pages: number; total: number; limit: number }
}

export default function AdminClassifiersPage() {
  const navigate = useNavigate()
  const [rows, setRows] = useState<Classifier[]>([])
  const [selectedIds, setSelectedIds] = useState<string[]>([])
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)

  const [filterId, setFilterId] = useState('')
  const [filterCode, setFilterCode] = useState('')
  const [filterNamef, setFilterNamef] = useState('')
  const [filterNames, setFilterNames] = useState('')
  const [filterNote, setFilterNote] = useState('')
  const [isDeletedFilter, setIsDeletedFilter] = useState<'all' | 'enabled' | 'disabled'>('all')

  const { error, message, clear, fail, ok } = useActionStatus()

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
    clear()
    try {
      const res = await api.get<ListResponse<Classifier>>('/admin/classifiers', {
        params: {
          page: targetPage,
          limit: targetSize,
          ...(filterId.trim() ? { qual_id: filterId.trim() } : {}),
          ...(filterCode.trim() ? { qual_code: filterCode.trim() } : {}),
          ...(filterNamef.trim() ? { qual_namef: filterNamef.trim() } : {}),
          ...(filterNames.trim() ? { qual_names: filterNames.trim() } : {}),
          ...(filterNote.trim() ? { qual_note: filterNote.trim() } : {}),
        },
      })
      let data = res.data.data
      if (isDeletedFilter === 'enabled') data = data.filter((r) => !r.qual_is_del)
      if (isDeletedFilter === 'disabled') data = data.filter((r) => r.qual_is_del)
      setRows(data)
      setPage(res.data.meta?.page ?? targetPage)
      setPageSize(res.data.meta?.limit ?? targetSize)
      setTotal(res.data.meta?.total ?? data.length)
      setSelectedIds([])
    } catch {
      fail('Не удалось загрузить классификаторы.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadData(1, pageSize)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isDeletedFilter])

  async function runBulk(action: 'enable' | 'disable' | 'delete') {
    if (selectedIds.length === 0) {
      fail('Выберите классификаторы.')
      return
    }
    const labels: Record<typeof action, string> = {
      enable: 'включить',
      disable: 'выключить',
      delete: 'удалить',
    }
    if (!window.confirm(`Выполнить "${labels[action]}" для ${selectedIds.length} классификаторов?`)) return
    setSaving(true)
    clear()
    try {
      await api.post('/admin/classifiers/bulk', { action, ids: selectedIds })
      ok('Групповая операция выполнена')
      await loadData(1, pageSize)
    } catch {
      fail('Не удалось выполнить групповую операцию.')
    } finally {
      setSaving(false)
    }
  }

  async function copyClassifier(id: string) {
    setSaving(true)
    clear()
    try {
      await api.post(`/admin/classifiers/${id}/copy`)
      ok('Классификатор скопирован')
      await loadData(page, pageSize)
    } catch {
      fail('Не удалось скопировать классификатор.')
    } finally {
      setSaving(false)
    }
  }

  async function deleteClassifier(id: string) {
    if (!window.confirm('Удалить классификатор и связанные пункты?')) return
    setSaving(true)
    clear()
    try {
      await api.delete(`/admin/classifiers/${id}`)
      ok('Классификатор удален')
      await loadData(page, pageSize)
    } catch {
      fail('Не удалось удалить классификатор.')
    } finally {
      setSaving(false)
    }
  }

  const columns: ColumnsType<Classifier> = [
    { title: 'ID', dataIndex: 'qual_id', key: 'qual_id', width: 120 },
    { title: 'Код', dataIndex: 'qual_code', key: 'qual_code', width: 180, render: (_, r) => r.qual_code || '-' },
    { title: 'Полное название', dataIndex: 'qual_namef', key: 'qual_namef' },
    { title: 'Краткое название', dataIndex: 'qual_names', key: 'qual_names', render: (_, r) => r.qual_names || '-' },
    { title: 'Описание', dataIndex: 'qual_note', key: 'qual_note', render: (_, r) => r.qual_note || '-' },
    {
      title: 'Статус',
      key: 'status',
      width: 120,
      render: (_, r) => (r.qual_is_del ? 'Выключен' : 'Включен'),
    },
    {
      title: '',
      key: 'actions',
      width: 220,
      render: (_, r) => (
        <div style={{ display: 'flex', gap: 8 }}>
          <Button
            size="small"
            title="Открыть пункты"
            onClick={() => navigate(`/admin/classifier-items?qual_id=${r.qual_id}`)}
          >
            <i className="fa-solid fa-list-ul" />
          </Button>
          <Button size="small" title="Редактировать" onClick={() => navigate(`/admin/classifiers/${r.qual_id}/edit`)}>
            <i className="fa-solid fa-pen-to-square" />
          </Button>
          <Button size="small" title="Копировать" onClick={() => void copyClassifier(r.qual_id)}>
            <i className="fa-solid fa-copy" />
          </Button>
          <Button size="small" danger title="Удалить" onClick={() => void deleteClassifier(r.qual_id)}>
            <i className="fa-solid fa-trash" />
          </Button>
        </div>
      ),
    },
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
            { key: 'items', label: <Link to="/admin/classifier-items">Items</Link> },
            { key: 'audit', label: <Link to="/admin/audit">Audit</Link> },
            { key: 'dashboard', label: <Link to="/dashboard">Dashboard</Link> },
          ]}
        />
      </header>

      {message && <Alert type="success" message={message} showIcon style={{ marginBottom: 12 }} />}
      {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 12 }} />}

      <Card className="card">
        <div className="compact-filters-row">
          <label>Идентификатор<Input value={filterId} onChange={(e) => setFilterId(e.target.value)} /></label>
          <label>Код<Input value={filterCode} onChange={(e) => setFilterCode(e.target.value)} /></label>
          <label>Полное название<Input value={filterNamef} onChange={(e) => setFilterNamef(e.target.value)} /></label>
        </div>
        <div className="compact-filters-row">
          <label>Краткое название<Input value={filterNames} onChange={(e) => setFilterNames(e.target.value)} /></label>
          <label>Описание<Input value={filterNote} onChange={(e) => setFilterNote(e.target.value)} /></label>
          <label>Статус
            <Select
              value={isDeletedFilter}
              options={[
                { value: 'all', label: 'Все' },
                { value: 'enabled', label: 'Включенные' },
                { value: 'disabled', label: 'Выключенные' },
              ]}
              onChange={(value) => setIsDeletedFilter(value)}
            />
          </label>
        </div>
        <div className="row">
          <div style={{ display: 'flex', gap: 8 }}>
            <Button type="primary" onClick={() => void loadData(1, pageSize)}>Фильтровать</Button>
            <Button onClick={() => navigate('/admin/classifiers/new')}>Добавить классификатор</Button>
            <Button onClick={() => void runBulk('enable')} disabled={saving || selectedIds.length === 0}>Включить выбранные</Button>
            <Button onClick={() => void runBulk('disable')} disabled={saving || selectedIds.length === 0}>Выключить выбранные</Button>
            <Button danger onClick={() => void runBulk('delete')} disabled={saving || selectedIds.length === 0}>Удалить выбранные</Button>
          </div>
          <span>Выбрано: {selectedIds.length}</span>
        </div>
      </Card>

      <Card className="card">
        <Table
          rowKey="qual_id"
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

