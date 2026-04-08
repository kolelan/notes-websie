import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'

type AdminUser = {
  id: string
  email: string
  name: string
  role: 'user' | 'admin' | 'superadmin'
  is_active: boolean
  created_at: string
}

type UsersResponse = {
  data: AdminUser[]
  meta?: {
    page: number
    pages: number
    total: number
    limit: number
  }
}

export default function AdminUsersPage() {
  const navigate = useNavigate()
  const [users, setUsers] = useState<AdminUser[]>([])
  const [q, setQ] = useState('')
  const [roleFilter, setRoleFilter] = useState<'all' | AdminUser['role']>('all')
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'blocked'>('all')
  const [page, setPage] = useState(1)
  const pageSize = 10
  const [total, setTotal] = useState(0)
  const [pages, setPages] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function loadUsers(targetPage = page) {
    const session = readSession()
    if (!session) {
      navigate('/')
      return
    }
    setAuthToken(session.accessToken)
    setLoading(true)
    setError('')
    try {
      const params: Record<string, string | boolean> = {}
      if (q.trim()) params.q = q.trim()
      if (roleFilter !== 'all') params.role = roleFilter
      if (statusFilter !== 'all') params.is_active = statusFilter === 'active'
      params.page = String(targetPage)
      params.limit = String(pageSize)
      const res = await api.get<UsersResponse>('/admin/users', { params })
      setUsers(res.data.data)
      setPage(res.data.meta?.page ?? targetPage)
      setPages(res.data.meta?.pages ?? 1)
      setTotal(res.data.meta?.total ?? res.data.data.length)
    } catch {
      setError('Не удалось загрузить пользователей.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadUsers()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  async function changeRole(userId: string, role: AdminUser['role']) {
    if (!window.confirm('Подтвердите изменение роли пользователя.')) return
    await api.patch(`/admin/users/${userId}`, { role })
    await loadUsers()
  }

  async function toggleActive(userId: string, isActive: boolean) {
    if (!window.confirm(isActive ? 'Заблокировать пользователя?' : 'Разблокировать пользователя?')) return
    await api.patch(`/admin/users/${userId}`, { is_active: !isActive })
    await loadUsers()
  }

  async function logoutAll(userId: string) {
    if (!window.confirm('Принудительно завершить все сессии пользователя?')) return
    await api.post(`/admin/users/${userId}/logout-all`)
  }

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: Users</h1>
        <div className="row">
          <Link to="/admin/settings">Settings</Link>
          <Link to="/admin/audit">Audit</Link>
          <Link to="/dashboard">Dashboard</Link>
        </div>
      </header>
      <section className="card">
        <div className="compact-filters-row">
          <label>
            Поиск
            <input value={q} onChange={(e) => setQ(e.target.value)} />
          </label>
          <label>
            Роль
            <select value={roleFilter} onChange={(e) => setRoleFilter(e.target.value as 'all' | AdminUser['role'])}>
              <option value="all">Все роли</option>
              <option value="user">user</option>
              <option value="admin">admin</option>
              <option value="superadmin">superadmin</option>
            </select>
          </label>
          <label>
            Статус
            <select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value as 'all' | 'active' | 'blocked')}>
              <option value="all">Любой</option>
              <option value="active">Активные</option>
              <option value="blocked">Заблокированные</option>
            </select>
          </label>
        </div>
        <div className="row">
          <button onClick={() => void loadUsers(1)}>Применить</button>
          <p>Найдено: {total}</p>
        </div>
      </section>
      {error && <p className="error">{error}</p>}
      {loading ? (
        <p>Загрузка...</p>
      ) : (
        <section className="card notes-table-wrap">
          <table className="notes-table">
            <thead>
              <tr>
                <th>Email</th>
                <th>Имя</th>
                <th>Роль</th>
                <th>Статус</th>
                <th>Сессии</th>
              </tr>
            </thead>
            <tbody>
              {users.map((u) => (
                <tr key={u.id}>
                  <td>{u.email}</td>
                  <td>{u.name}</td>
                  <td>
                    <select value={u.role} onChange={(e) => void changeRole(u.id, e.target.value as AdminUser['role'])}>
                      <option value="user">user</option>
                      <option value="admin">admin</option>
                      <option value="superadmin">superadmin</option>
                    </select>
                  </td>
                  <td>
                    <button onClick={() => void toggleActive(u.id, u.is_active)}>
                      {u.is_active ? 'Активен' : 'Заблокирован'}
                    </button>
                  </td>
                  <td>
                    <button onClick={() => void logoutAll(u.id)}>Logout all</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      )}
      {!loading && pages > 1 && (
        <section className="card row">
          <button disabled={page <= 1} onClick={() => void loadUsers(page - 1)}>Назад</button>
          <span>Страница {page} из {pages}</span>
          <button disabled={page >= pages} onClick={() => void loadUsers(page + 1)}>Вперед</button>
        </section>
      )}
    </main>
  )
}
