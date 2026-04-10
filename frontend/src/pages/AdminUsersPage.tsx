import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Button, Menu, Table } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import { useActionStatus } from '../hooks/useActionStatus'

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
  const [selectedUserIds, setSelectedUserIds] = useState<string[]>([])
  const [q, setQ] = useState('')
  const [roleFilter, setRoleFilter] = useState<'all' | AdminUser['role']>('all')
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'blocked'>('all')
  const [page, setPage] = useState(1)
  const pageSize = 10
  const [total, setTotal] = useState(0)
  const [pages, setPages] = useState(1)
  const [loading, setLoading] = useState(true)
  const [savingAction, setSavingAction] = useState('')
  const { error, message, clear, fail, ok } = useActionStatus()

  async function loadUsers(targetPage = page) {
    const session = readSession()
    if (!session) {
      navigate('/login')
      return
    }
    setAuthToken(session.accessToken)
    setLoading(true)
    clear()
    try {
      const params: Record<string, string | boolean> = {}
      if (q.trim()) params.q = q.trim()
      if (roleFilter !== 'all') params.role = roleFilter
      if (statusFilter !== 'all') params.is_active = statusFilter === 'active'
      params.page = String(targetPage)
      params.limit = String(pageSize)
      const res = await api.get<UsersResponse>('/admin/users', { params })
      setUsers(res.data.data)
      setSelectedUserIds([])
      setPage(res.data.meta?.page ?? targetPage)
      setPages(res.data.meta?.pages ?? 1)
      setTotal(res.data.meta?.total ?? res.data.data.length)
    } catch {
      fail('Не удалось загрузить пользователей.')
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
    setSavingAction(`role:${userId}`)
    clear()
    try {
      await api.patch(`/admin/users/${userId}`, { role })
      ok('Роль пользователя обновлена')
      await loadUsers()
    } catch {
      fail('Не удалось обновить роль пользователя.')
    } finally {
      setSavingAction('')
    }
  }

  async function toggleActive(userId: string, isActive: boolean) {
    if (!window.confirm(isActive ? 'Заблокировать пользователя?' : 'Разблокировать пользователя?')) return
    setSavingAction(`active:${userId}`)
    clear()
    try {
      await api.patch(`/admin/users/${userId}`, { is_active: !isActive })
      ok(isActive ? 'Пользователь заблокирован' : 'Пользователь разблокирован')
      await loadUsers()
    } catch {
      fail('Не удалось изменить статус пользователя.')
    } finally {
      setSavingAction('')
    }
  }

  async function logoutAll(userId: string) {
    if (!window.confirm('Принудительно завершить все сессии пользователя?')) return
    setSavingAction(`logout:${userId}`)
    clear()
    try {
      await api.post(`/admin/users/${userId}/logout-all`)
      ok('Все сессии пользователя завершены')
    } catch {
      fail('Не удалось завершить сессии пользователя.')
    } finally {
      setSavingAction('')
    }
  }

  async function bulkLogoutAll(userIds: string[]) {
    if (userIds.length === 0) {
      fail('Выберите пользователей для отзыва сессий.')
      return
    }
    if (!window.confirm(`Принудительно завершить все сессии у ${userIds.length} пользователей?`)) return
    setSavingAction('logout:bulk')
    clear()
    try {
      await Promise.all(userIds.map((id) => api.post(`/admin/users/${id}/logout-all`)))
      ok('Все сессии выбранных пользователей завершены')
    } catch {
      fail('Не удалось завершить сессии выбранных пользователей.')
    } finally {
      setSavingAction('')
    }
  }

  async function deleteUsers(userIds: string[]) {
    if (userIds.length === 0) {
      fail('Выберите пользователей для удаления.')
      return
    }
    if (!window.confirm(`Удалить выбранных пользователей: ${userIds.length}? Это действие необратимо и удалит связанные данные (заметки, группы, токены).`)) {
      return
    }
    setSavingAction('delete:bulk')
    clear()
    try {
      await Promise.all(userIds.map((id) => api.delete(`/admin/users/${id}`)))
      ok('Пользователи удалены')
      await loadUsers(1)
    } catch {
      fail('Не удалось удалить пользователей. Удаление доступно только superadmin.')
    } finally {
      setSavingAction('')
    }
  }

  const columns: ColumnsType<AdminUser> = [
    { title: 'Email', dataIndex: 'email', key: 'email' },
    { title: 'Имя', dataIndex: 'name', key: 'name' },
    {
      title: 'Роль',
      key: 'role',
      render: (_, u) => (
        <select
          value={u.role}
          disabled={savingAction !== ''}
          onChange={(e) => void changeRole(u.id, e.target.value as AdminUser['role'])}
        >
          <option value="user">user</option>
          <option value="admin">admin</option>
          <option value="superadmin">superadmin</option>
        </select>
      ),
    },
    {
      title: 'Статус',
      key: 'status',
      render: (_, u) => (
        <button disabled={savingAction !== ''} onClick={() => void toggleActive(u.id, u.is_active)}>
          {u.is_active ? 'Активен' : 'Заблокирован'}
        </button>
      ),
    },
    {
      title: 'Сессии',
      key: 'sessions',
      render: (_, u) => (
        <button disabled={savingAction !== ''} onClick={() => void logoutAll(u.id)}>Logout all</button>
      ),
    },
  ]

  return (
    <main className="page">
      <header className="row">
        <h1>Admin: Users</h1>
        <Menu
          mode="horizontal"
          selectedKeys={['users']}
          items={[
            { key: 'users', label: <Link to="/admin/users">Users</Link> },
            { key: 'settings', label: <Link to="/admin/settings">Settings</Link> },
            { key: 'classifiers', label: <Link to="/admin/classifiers">Classifiers</Link> },
            { key: 'audit', label: <Link to="/admin/audit">Audit</Link> },
            { key: 'dashboard', label: <Link to="/dashboard">Dashboard</Link> },
          ]}
        />
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
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <button onClick={() => void loadUsers(1)}>Применить</button>
            <Button
              type="default"
              disabled={savingAction !== '' || selectedUserIds.length === 0}
              onClick={() => void bulkLogoutAll(selectedUserIds)}
              title="Принудительно завершить все сессии выбранных пользователей"
            >
              Logout all ({selectedUserIds.length})
            </Button>
            <Button
              danger
              type="default"
              disabled={savingAction !== '' || selectedUserIds.length === 0}
              onClick={() => void deleteUsers(selectedUserIds)}
              title="Удалить выбранных пользователей (только superadmin)"
            >
              Удалить выбранных ({selectedUserIds.length})
            </Button>
          </div>
          <p>Найдено: {total}</p>
        </div>
      </section>
      {message && <p>{message}</p>}
      {error && <p className="error">{error}</p>}
      {loading ? (
        <p>Загрузка...</p>
      ) : (
        <section className="card">
          <Table
            rowKey="id"
            dataSource={users}
            columns={columns}
            pagination={false}
            size="middle"
            rowSelection={{
              selectedRowKeys: selectedUserIds,
              onChange: (keys) => setSelectedUserIds(keys.map(String)),
              getCheckboxProps: () => ({ disabled: savingAction !== '' }),
            }}
          />
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
