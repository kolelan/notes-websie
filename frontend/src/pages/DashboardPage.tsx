import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, setAuthToken } from '../lib/api'
import { readSession, writeSession } from '../lib/auth'
import type { ApiEnvelope, Group, Note } from '../types/api'

function flattenGroups(groups: Group[], level = 0): Array<{ id: string; name: string; level: number }> {
  const result: Array<{ id: string; name: string; level: number }> = []
  for (const g of groups) {
    result.push({ id: g.id, name: g.name, level })
    if (g.children?.length) result.push(...flattenGroups(g.children, level + 1))
  }
  return result
}

export default function DashboardPage() {
  const [groups, setGroups] = useState<Group[]>([])
  const [notes, setNotes] = useState<Note[]>([])
  const [groupId, setGroupId] = useState('')
  const [loading, setLoading] = useState(true)
  const navigate = useNavigate()

  async function loadData() {
    const session = readSession()
    if (!session) {
      navigate('/')
      return
    }

    setAuthToken(session.accessToken)
    try {
      const [gRes, nRes] = await Promise.all([
        api.get<ApiEnvelope<Group[]>>('/groups'),
        api.get<ApiEnvelope<Note[]>>('/notes', { params: groupId ? { group_id: groupId } : {} }),
      ])
      setGroups(gRes.data.data)
      setNotes(nRes.data.data)
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    setLoading(true)
    void loadData()
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groupId])

  async function onLogout() {
    const session = readSession()
    if (session) {
      setAuthToken(session.accessToken)
      try {
        await api.post('/auth/logout-all')
      } catch {
        // ignore logout API errors, local logout still required
      }
    }
    writeSession(null)
    setAuthToken(null)
    navigate('/')
  }

  if (loading) return <main className="page">Загрузка...</main>

  const groupItems = flattenGroups(groups)

  return (
    <main className="page">
      <header className="row">
        <h1>Dashboard</h1>
        <button onClick={onLogout}>Выйти</button>
      </header>

      <section className="card">
        <label>
          Фильтр по группе
          <select value={groupId} onChange={(e) => setGroupId(e.target.value)}>
            <option value="">Все группы</option>
            {groupItems.map((g) => (
              <option key={g.id} value={g.id}>
                {'  '.repeat(g.level)}
                {g.name}
              </option>
            ))}
          </select>
        </label>
      </section>

      <section className="grid">
        <div className="card">
          <h2>Группы</h2>
          <ul>
            {groupItems.map((g) => (
              <li key={g.id}>
                {'- '.repeat(g.level)}
                {g.name}
              </li>
            ))}
          </ul>
        </div>
        <div className="card">
          <h2>Заметки</h2>
          <ul>
            {notes.map((n) => (
              <li key={n.id}>
                <strong>{n.title}</strong>
                <div>{n.description || 'Без описания'}</div>
              </li>
            ))}
          </ul>
        </div>
      </section>
    </main>
  )
}

