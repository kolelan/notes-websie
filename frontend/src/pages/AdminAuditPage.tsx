import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Menu, Modal, Pagination, Table } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'

type AuditRow = {
  id: string
  actor_user_id: string | null
  action: string
  target_type: string
  target_id: string | null
  details: unknown
  created_at: string
}

type AuditResponse = {
  data: AuditRow[]
  meta?: {
    page: number
    pages: number
    total: number
    limit: number
  }
}

export default function AdminAuditPage() {
  const navigate = useNavigate()
  const [rows, setRows] = useState<AuditRow[]>([])
  const [page, setPage] = useState(1)
  const [total, setTotal] = useState(0)
  const [action, setAction] = useState('')
  const [targetType, setTargetType] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedDetails, setSelectedDetails] = useState<string | null>(null)
  const [error, setError] = useState('')

  async function loadAudit(targetPage: number) {
    const session = readSession()
    if (!session) {
      navigate('/login')
      return
    }
    setAuthToken(session.accessToken)
    setError('')
    try {
      const params: Record<string, string | number> = { page: targetPage, limit: 50 }
      if (action.trim()) params.action = action.trim()
      if (targetType.trim()) params.target_type = targetType.trim()
      if (dateFrom.trim()) params.date_from = dateFrom.trim()
      if (dateTo.trim()) params.date_to = dateTo.trim()
      const res = await api.get<AuditResponse>('/admin/audit', { params })
      setRows(res.data.data)
      setPage(res.data.meta?.page ?? targetPage)
      setTotal(res.data.meta?.total ?? res.data.data.length)
    } catch {
      setError('Не удалось загрузить audit log.')
    }
  }

  const columns: ColumnsType<AuditRow> = [
    { title: 'Когда', dataIndex: 'created_at', key: 'created_at' },
    { title: 'Action', dataIndex: 'action', key: 'action' },
    { title: 'Target', key: 'target', render: (_, row) => `${row.target_type}:${row.target_id ?? '-'}` },
    { title: 'Actor', dataIndex: 'actor_user_id', key: 'actor_user_id', render: (v) => v ?? '-' },
    {
      title: 'Details',
      key: 'details',
      render: (_, row) => (
        <Button onClick={() => setSelectedDetails(JSON.stringify(row.details, null, 2))}>Открыть</Button>
      ),
    },
  ]

  useEffect(() => {
    void loadAudit(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: Audit</h1>
        <Menu
          mode="horizontal"
          selectedKeys={['audit']}
          items={[
            { key: 'users', label: <Link to="/admin/users">Users</Link> },
            { key: 'settings', label: <Link to="/admin/settings">Settings</Link> },
            { key: 'audit', label: <Link to="/admin/audit">Audit</Link> },
            { key: 'dashboard', label: <Link to="/dashboard">Dashboard</Link> },
          ]}
        />
      </header>
      {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 12 }} />}
      <Card className="card">
        <div className="compact-filters-row">
          <label>
            Action
            <Input value={action} onChange={(e) => setAction(e.target.value)} placeholder="admin.user.update" />
          </label>
          <label>
            Target type
            <Input value={targetType} onChange={(e) => setTargetType(e.target.value)} placeholder="user/system_setting" />
          </label>
          <label>
            Date from (ISO)
            <Input value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} placeholder="2026-04-08T00:00:00Z" />
          </label>
          <label>
            Date to (ISO)
            <Input value={dateTo} onChange={(e) => setDateTo(e.target.value)} placeholder="2026-04-09T00:00:00Z" />
          </label>
        </div>
        <Button onClick={() => void loadAudit(1)}>Применить фильтры</Button>
      </Card>
      <Card className="card">
        <Table rowKey="id" dataSource={rows} columns={columns} pagination={false} />
      </Card>
      <Card className="card">
        <Pagination
          current={page}
          total={total}
          pageSize={50}
          onChange={(p) => void loadAudit(p)}
          showSizeChanger={false}
        />
      </Card>
      <Modal
        open={selectedDetails !== null}
        onCancel={() => setSelectedDetails(null)}
        footer={null}
        title="Details"
      >
        <pre className="note-content">{selectedDetails ?? ''}</pre>
      </Modal>
    </main>
  )
}
