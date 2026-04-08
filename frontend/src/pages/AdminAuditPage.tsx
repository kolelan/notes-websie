import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
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
  const [pages, setPages] = useState(1)
  const [action, setAction] = useState('')
  const [targetType, setTargetType] = useState('')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')
  const [selectedDetails, setSelectedDetails] = useState<string | null>(null)
  const [error, setError] = useState('')

  async function loadAudit(targetPage: number) {
    const session = readSession()
    if (!session) {
      navigate('/')
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
      setPages(res.data.meta?.pages ?? 1)
    } catch {
      setError('Не удалось загрузить audit log.')
    }
  }

  useEffect(() => {
    void loadAudit(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: Audit</h1>
        <div className="row">
          <Link to="/admin/users">Users</Link>
          <Link to="/admin/settings">Settings</Link>
          <Link to="/dashboard">Dashboard</Link>
        </div>
      </header>
      {error && <p className="error">{error}</p>}
      <section className="card">
        <div className="compact-filters-row">
          <label>
            Action
            <input value={action} onChange={(e) => setAction(e.target.value)} placeholder="admin.user.update" />
          </label>
          <label>
            Target type
            <input value={targetType} onChange={(e) => setTargetType(e.target.value)} placeholder="user/system_setting" />
          </label>
          <label>
            Date from (ISO)
            <input value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} placeholder="2026-04-08T00:00:00Z" />
          </label>
          <label>
            Date to (ISO)
            <input value={dateTo} onChange={(e) => setDateTo(e.target.value)} placeholder="2026-04-09T00:00:00Z" />
          </label>
        </div>
        <button onClick={() => void loadAudit(1)}>Применить фильтры</button>
      </section>
      <section className="card notes-table-wrap">
        <table className="notes-table">
          <thead>
            <tr>
              <th>Когда</th>
              <th>Action</th>
              <th>Target</th>
              <th>Actor</th>
              <th>Details</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.id}>
                <td>{row.created_at}</td>
                <td>{row.action}</td>
                <td>{row.target_type}:{row.target_id ?? '-'}</td>
                <td>{row.actor_user_id ?? '-'}</td>
                <td>
                  <button onClick={() => setSelectedDetails(JSON.stringify(row.details, null, 2))}>Открыть</button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </section>
      <section className="card row">
        <button disabled={page <= 1} onClick={() => void loadAudit(page - 1)}>Назад</button>
        <span>Страница {page} из {pages}</span>
        <button disabled={page >= pages} onClick={() => void loadAudit(page + 1)}>Вперед</button>
      </section>
      {selectedDetails !== null && (
        <section className="card">
          <div className="row">
            <h2>Details</h2>
            <button onClick={() => setSelectedDetails(null)}>Закрыть</button>
          </div>
          <pre className="note-content">{selectedDetails}</pre>
        </section>
      )}
    </main>
  )
}
