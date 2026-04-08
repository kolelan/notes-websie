import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import axios from 'axios'
import { api, setAuthToken } from '../lib/api'
import { readSession, writeSession } from '../lib/auth'
import { parseJwt } from '../lib/jwt'
import type { ApiEnvelope, DashboardNote, Group, Note } from '../types/api'

function flattenGroups(groups: Group[], level = 0): Array<{ id: string; name: string; level: number }> {
  const result: Array<{ id: string; name: string; level: number }> = []
  for (const g of groups) {
    result.push({ id: g.id, name: g.name, level })
    if (g.children?.length) result.push(...flattenGroups(g.children, level + 1))
  }
  return result
}

function normalizeNameList(value: unknown): Array<{ id: string; name: string }> {
  if (Array.isArray(value)) {
    return value
      .map((item) => ({
        id: String((item as { id?: string }).id ?? ''),
        name: String((item as { name?: string }).name ?? ''),
      }))
      .filter((item) => item.id !== '' && item.name !== '')
  }
  if (typeof value === 'string') {
    try {
      const parsed = JSON.parse(value)
      return normalizeNameList(parsed)
    } catch {
      return []
    }
  }
  return []
}

function normalizeDashboardNote(note: DashboardNote): DashboardNote {
  return {
    ...note,
    groups: normalizeNameList((note as unknown as { groups: unknown }).groups),
    tags: normalizeNameList((note as unknown as { tags: unknown }).tags),
  }
}

export default function DashboardPage() {
  const [groups, setGroups] = useState<Group[]>([])
  const [notes, setNotes] = useState<Note[]>([])
  const [dashboardNotes, setDashboardNotes] = useState<DashboardNote[]>([])
  const [noteViewMode, setNoteViewMode] = useState<'table' | 'tile'>('table')
  const [selectedForBulk, setSelectedForBulk] = useState<Record<string, boolean>>({})
  const [tags, setTags] = useState<Array<{ id: string; name: string }>>([])
  const [publicNoteIds, setPublicNoteIds] = useState<string[]>([])
  const [groupedNotesByGroup, setGroupedNotesByGroup] = useState<Array<{ groupId: string; groupName: string; notes: Note[] }>>([])
  const [groupNoteCounts, setGroupNoteCounts] = useState<Record<string, number>>({})
  const [expandedGroupIds, setExpandedGroupIds] = useState<Record<string, boolean>>({})
  const [groupId, setGroupId] = useState('')
  const [tagId, setTagId] = useState('')
  const [selectedNoteId, setSelectedNoteId] = useState('')
  const [selectedGroupId, setSelectedGroupId] = useState('')
  const [newGroupName, setNewGroupName] = useState('')
  const [newGroupParentId, setNewGroupParentId] = useState('')
  const [newNoteTitle, setNewNoteTitle] = useState('')
  const [newNoteDescription, setNewNoteDescription] = useState('')
  const [newNoteContent, setNewNoteContent] = useState('')
  const [editNoteTitle, setEditNoteTitle] = useState('')
  const [editNoteDescription, setEditNoteDescription] = useState('')
  const [editNoteContent, setEditNoteContent] = useState('')
  const [newTagName, setNewTagName] = useState('')
  const [existingTagName, setExistingTagName] = useState('')
  const [tagSearchQuery, setTagSearchQuery] = useState('')
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState<'reader' | 'editor' | 'manager'>('reader')
  const [acceptGroupId, setAcceptGroupId] = useState('')
  const [acceptToken, setAcceptToken] = useState('')
  const [publicNoteId, setPublicNoteId] = useState('')
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [canOpenAdmin, setCanOpenAdmin] = useState(false)
  const navigate = useNavigate()

  async function loadData() {
    const session = readSession()
    if (!session) {
      navigate('/')
      return
    }

    setAuthToken(session.accessToken)
    const role = parseJwt(session.accessToken)?.role ?? 'user'
    setCanOpenAdmin(['admin', 'superadmin'].includes(role))
    try {
      const [gRes, nRes, oRes, tRes] = await Promise.all([
        api.get<ApiEnvelope<Group[]>>('/groups'),
        api.get<ApiEnvelope<Note[]>>('/notes', {
          params: {
            ...(groupId ? { group_id: groupId } : {}),
            ...(tagId ? { tag_id: tagId } : {}),
          },
        }),
        api.get<ApiEnvelope<DashboardNote[]>>('/notes/overview', {
          params: {
            ...(groupId ? { group_id: groupId } : {}),
            ...(tagId ? { tag_id: tagId } : {}),
          },
        }),
        api.get<ApiEnvelope<Array<{ id: string; name: string }>>>('/tags'),
      ])
      setGroups(gRes.data.data)
      setNotes(nRes.data.data)
      setDashboardNotes(oRes.data.data.map(normalizeDashboardNote))
      setTags(tRes.data.data)

      const countsRes = await api.get<ApiEnvelope<Array<{ group_id: string; group_name: string; updated_at: string; notes_count: number }>>>('/groups/note-counts')
      setGroupNoteCounts(
        Object.fromEntries(
          countsRes.data.data.map((row) => [row.group_id, row.notes_count])
        )
      )

      setPublicNoteIds(oRes.data.data.map(normalizeDashboardNote).filter((n) => n.is_public).map((n) => n.id))
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    setLoading(true)
    void loadData()
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [groupId, tagId])

  useEffect(() => {
    const note = notes.find((n) => n.id === selectedNoteId)
    if (!note) {
      setEditNoteTitle('')
      setEditNoteDescription('')
      setEditNoteContent('')
      return
    }
    setEditNoteTitle(note.title ?? '')
    setEditNoteDescription(note.description ?? '')
    setEditNoteContent(note.content ?? '')
  }, [selectedNoteId, notes])

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

  function resolveApiErrorMessage(err: unknown, fallback: string): string {
    if (axios.isAxiosError(err)) {
      const status = err.response?.status
      const data = err.response?.data as { error?: string } | undefined
      if (status === 401) return 'Сессия истекла. Выполните вход заново.'
      if (status === 404) return data?.error ?? 'Ресурс не найден или нет прав доступа.'
      if (status === 422) return data?.error ?? 'Проверьте корректность заполнения полей.'
      if (data?.error) return data.error
      if (status) return `Ошибка API: ${status}`
    }
    return fallback
  }

  async function withAction(action: () => Promise<void>, success: string) {
    setError('')
    setMessage('')
    try {
      await action()
      setMessage(success)
      await loadData()
    } catch (err) {
      setError(resolveApiErrorMessage(err, 'Операция не выполнена. Проверьте поля и права доступа.'))
    }
  }

  function currentNote() {
    return notes.find((n) => n.id === selectedNoteId)
  }

  function toggleBulkSelection(noteId: string) {
    setSelectedForBulk((prev) => ({ ...prev, [noteId]: !prev[noteId] }))
  }

  function toggleGroup(groupId: string) {
    setExpandedGroupIds((prev) => ({
      ...prev,
      [groupId]: !prev[groupId],
    }))
  }

  function selectGroupAndScroll(groupIdValue: string) {
    setGroupId(groupIdValue)
    setTimeout(() => {
      const element = document.getElementById('notes-list')
      if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }
    }, 50)
  }

  function renderGroupTree(items: Group[], level = 0) {
    return (
      <ul className="group-tree">
        {items.map((g) => {
          const hasChildren = Boolean(g.children && g.children.length > 0)
          const isExpanded = expandedGroupIds[g.id] ?? true
          const count = groupNoteCounts[g.id] ?? 0
          return (
            <li key={g.id} style={{ marginLeft: `${level * 12}px` }}>
              <div className="group-row">
                {hasChildren ? (
                  <button type="button" className="tree-toggle" onClick={() => toggleGroup(g.id)}>
                    {isExpanded ? '▾' : '▸'}
                  </button>
                ) : (
                  <span className="tree-toggle-placeholder">•</span>
                )}
                <button type="button" className="group-link-btn" onClick={() => selectGroupAndScroll(g.id)}>
                  {g.name}
                </button>
                <span className="group-count">({count})</span>
              </div>
              {hasChildren && isExpanded ? renderGroupTree(g.children ?? [], level + 1) : null}
            </li>
          )
        })}
      </ul>
    )
  }

  async function createGroup() {
    await withAction(async () => {
      await api.post('/groups', {
        name: newGroupName,
        parent_id: newGroupParentId || undefined,
      })
      setNewGroupName('')
      setNewGroupParentId('')
    }, 'Группа создана')
  }

  async function createNote() {
    await withAction(async () => {
      const res = await api.post<ApiEnvelope<{ id: string }>>('/notes', {
        title: newNoteTitle,
        description: newNoteDescription,
        content: newNoteContent,
      })
      setNewNoteTitle('')
      setNewNoteDescription('')
      setNewNoteContent('')
      setSelectedNoteId(res.data.data.id)
      setPublicNoteId(res.data.data.id)
    }, 'Заметка создана')
  }

  async function addTag() {
    if (!selectedNoteId) {
      setError('Сначала выберите заметку.')
      return
    }
    await withAction(async () => {
      await api.post(`/notes/${selectedNoteId}/tags`, { name: newTagName })
      setNewTagName('')
    }, 'Тег добавлен')
  }

  async function addExistingTag() {
    if (!selectedNoteId) {
      setError('Сначала выберите заметку.')
      return
    }
    if (!existingTagName) {
      setError('Выберите существующий тег.')
      return
    }
    await withAction(async () => {
      await api.post(`/notes/${selectedNoteId}/tags`, { name: existingTagName })
    }, 'Существующий тег привязан к заметке')
  }

  async function updateNote() {
    if (!selectedNoteId) {
      setError('Сначала выберите заметку.')
      return
    }
    await withAction(async () => {
      await api.put(`/notes/${selectedNoteId}`, {
        title: editNoteTitle,
        description: editNoteDescription,
        content: editNoteContent,
      })
      if (selectedGroupId) {
        await api.post(`/notes/${selectedNoteId}/attach-to-group`, { group_id: selectedGroupId })
      }
    }, selectedGroupId ? 'Заметка обновлена и прикреплена к группе' : 'Заметка обновлена')
  }

  async function attachToGroup() {
    if (!selectedNoteId || !selectedGroupId) {
      setError('Выберите заметку и группу.')
      return
    }
    await withAction(async () => {
      await api.post(`/notes/${selectedNoteId}/attach-to-group`, { group_id: selectedGroupId })
    }, 'Заметка прикреплена к группе')
  }

  async function copyToGroup() {
    if (!selectedNoteId || !selectedGroupId) {
      setError('Выберите заметку и группу.')
      return
    }
    await withAction(async () => {
      await api.post(`/notes/${selectedNoteId}/copy-to-group`, { group_id: selectedGroupId })
    }, 'Копия заметки создана и прикреплена')
  }

  async function createInvite() {
    if (!selectedGroupId || !inviteEmail) {
      setError('Выберите группу и укажите email.')
      return
    }
    await withAction(async () => {
      const res = await api.post<ApiEnvelope<{ token: string }>>(`/groups/${selectedGroupId}/invite`, {
        invitee_email: inviteEmail,
        role: inviteRole,
        expires_in: 3600,
      })
      setAcceptGroupId(selectedGroupId)
      setAcceptToken(res.data.data.token)
      setInviteEmail('')
    }, 'Инвайт создан. Токен подставлен в форму accept.')
  }

  async function acceptInvite() {
    if (!acceptGroupId || !acceptToken) {
      setError('Укажите group id и token.')
      return
    }
    await withAction(async () => {
      await api.post(`/groups/${acceptGroupId}/accept-invite`, { token: acceptToken })
    }, 'Инвайт принят')
  }

  async function makePublicRead() {
    if (!publicNoteId) {
      setError('Укажите note id для публикации.')
      return
    }
    await withAction(async () => {
      await api.post('/permissions', {
        target_type: 'note',
        target_id: publicNoteId,
        grantee_type: 'public',
        can_read: true,
      })
    }, 'Публичный read доступ выдан')
  }

  async function loadGroupedNotes() {
    setError('')
    setMessage('')
    try {
      const flatGroups = flattenGroups(groups)
      const responses = await Promise.all(
        flatGroups.map(async (group) => {
          const res = await api.get<ApiEnvelope<Note[]>>('/notes', { params: { group_id: group.id } })
          return {
            groupId: group.id,
            groupName: group.name,
            notes: res.data.data,
          }
        })
      )
      setGroupedNotesByGroup(responses.filter((g) => g.notes.length > 0))
      setMessage('Сгруппированные заметки обновлены')
    } catch {
      setError('Не удалось загрузить сгруппированные заметки.')
    }
  }

  async function toggleNotePublic(noteId: string, isPublic: boolean) {
    await withAction(async () => {
      await api.put(`/notes/${noteId}/public`, { is_public: !isPublic })
    }, isPublic ? 'Заметка сделана приватной' : 'Заметка опубликована')
  }

  if (loading) return <main className="page">Загрузка...</main>

  const groupItems = flattenGroups(groups)
  const normalizedTagSearch = tagSearchQuery.trim().toLowerCase()
  const filteredTags = normalizedTagSearch
    ? tags.filter((t) => t.name.toLowerCase().includes(normalizedTagSearch))
    : tags

  return (
    <main className="page">
      <header className="row">
        <h1>Dashboard</h1>
        <div className="row">
          <Link to="/">Главная</Link>
          <Link to="/profile">Профиль</Link>
          {canOpenAdmin && <Link to="/admin/users">Admin</Link>}
          <button onClick={onLogout}>Выйти</button>
        </div>
      </header>

      {message && <p>{message}</p>}
      {error && <p className="error">{error}</p>}

      <section className="card compact-filters">
        <h2>Фильтры</h2>
        <div className="compact-filters-row">
          <label>
            По группе
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
          <label>
            По тегу
            <select value={tagId} onChange={(e) => setTagId(e.target.value)}>
              <option value="">Все теги</option>
              {filteredTags.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Поиск тега
            <input
              placeholder="Введите часть названия тега"
              value={tagSearchQuery}
              onChange={(e) => setTagSearchQuery(e.target.value)}
            />
          </label>
        </div>
      </section>

      <section className="card">
        <h2>Группы заметок</h2>
        <p>Сначала выберите группу пользователя, затем откроется список ее заметок.</p>
        {groups.length > 0 ? renderGroupTree(groups) : <p>Группы пока не созданы</p>}
      </section>

      <section className="card">
        <h2 id="notes-list">Заметки</h2>
        <div className="row">
          <p>Выбрано для групповых операций: {Object.values(selectedForBulk).filter(Boolean).length}</p>
          <div className="row">
            <button type="button" onClick={() => setNoteViewMode('table')} disabled={noteViewMode === 'table'}>Таблица</button>
            <button type="button" onClick={() => setNoteViewMode('tile')} disabled={noteViewMode === 'tile'}>Плитка</button>
          </div>
        </div>
        {noteViewMode === 'table' ? (
          <div className="notes-table-wrap">
            <table className="notes-table">
              <thead>
                <tr>
                  <th />
                  <th>Название</th>
                  <th>Описание</th>
                  <th>Переход</th>
                  <th>Публикация</th>
                  <th>Группы</th>
                  <th>Теги</th>
                  <th>Автор</th>
                </tr>
              </thead>
              <tbody>
                {dashboardNotes.map((n) => (
                  <tr key={n.id} className={selectedNoteId === n.id ? 'selected-row' : ''} onClick={() => setSelectedNoteId(n.id)}>
                    <td>
                      <input
                        type="checkbox"
                        checked={Boolean(selectedForBulk[n.id])}
                        onChange={() => toggleBulkSelection(n.id)}
                        onClick={(e) => e.stopPropagation()}
                      />
                    </td>
                    <td>{n.title}</td>
                    <td>{n.description || 'Без описания'}</td>
                    <td><Link to={`/notes/${n.id}`} onClick={(e) => e.stopPropagation()}>Открыть</Link></td>
                    <td>
                      <button type="button" onClick={(e) => { e.stopPropagation(); void toggleNotePublic(n.id, n.is_public) }}>
                        {n.is_public ? 'Сделать приватной' : 'Опубликовать'}
                      </button>
                    </td>
                    <td>{n.groups.map((g) => g.name).join(', ') || '-'}</td>
                    <td>{n.tags.map((t) => t.name).join(', ') || '-'}</td>
                    <td>{n.author_name || n.owner_id}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ) : (
          <div className="tile-grid">
            {dashboardNotes.map((n) => (
              <article key={n.id} className="note-tile" onClick={() => setSelectedNoteId(n.id)}>
                <div className="row">
                  <input
                    type="checkbox"
                    checked={Boolean(selectedForBulk[n.id])}
                    onChange={() => toggleBulkSelection(n.id)}
                    onClick={(e) => e.stopPropagation()}
                  />
                  <strong>{n.title}</strong>
                </div>
                <p>{n.description || 'Без описания'}</p>
                <p><strong>Группы:</strong> {n.groups.map((g) => g.name).join(', ') || '-'}</p>
                <p><strong>Теги:</strong> {n.tags.map((t) => t.name).join(', ') || '-'}</p>
                <p><strong>Автор:</strong> {n.author_name || n.owner_id}</p>
                <div className="row">
                  <Link to={`/notes/${n.id}`} onClick={(e) => e.stopPropagation()}>Открыть</Link>
                  <button type="button" onClick={(e) => { e.stopPropagation(); void toggleNotePublic(n.id, n.is_public) }}>
                    {n.is_public ? 'Приватная' : 'Опубликовать'}
                  </button>
                </div>
              </article>
            ))}
          </div>
        )}
      </section>

      <section className="grid">
        <div className="card">
          <h2>Опубликованные заметки</h2>
          <ul>
            {notes.filter((n) => publicNoteIds.includes(n.id)).map((n) => (
              <li key={n.id}>
                <strong>{n.title}</strong>
                <div>{n.description || 'Без описания'}</div>
                <Link to={`/notes/${n.id}`} className="inline-link">Открыть заметку</Link>
              </li>
            ))}
            {notes.filter((n) => publicNoteIds.includes(n.id)).length === 0 && <li>Пока нет опубликованных заметок</li>}
          </ul>
        </div>
        <div className="card">
          <h2>Сгруппированные заметки</h2>
          <button onClick={() => void loadGroupedNotes()}>Обновить список по группам</button>
          <ul>
            {groupedNotesByGroup.map((groupBlock) => (
              <li key={groupBlock.groupId}>
                <strong>{groupBlock.groupName}</strong> ({groupBlock.notes.length})
                <div>
                  {groupBlock.notes.slice(0, 5).map((n) => (
                    <div key={n.id}>
                      <Link to={`/notes/${n.id}`} className="inline-link">{n.title}</Link>
                    </div>
                  ))}
                </div>
              </li>
            ))}
            {groupedNotesByGroup.length === 0 && <li>Нажмите "Обновить список по группам"</li>}
          </ul>
        </div>
      </section>

      <section className="grid">
        <div className="card">
          <h2>Создать группу</h2>
          <label>
            Название
            <input value={newGroupName} onChange={(e) => setNewGroupName(e.target.value)} />
          </label>
          <label>
            Родительская группа
            <select value={newGroupParentId} onChange={(e) => setNewGroupParentId(e.target.value)}>
              <option value="">Без родителя</option>
              {groupItems.map((g) => (
                <option key={g.id} value={g.id}>
                  {'  '.repeat(g.level)}
                  {g.name}
                </option>
              ))}
            </select>
          </label>
          <button onClick={() => void createGroup()}>Создать группу</button>
        </div>

        <div className="card">
          <h2>Создать заметку</h2>
          <label>
            Заголовок
            <input value={newNoteTitle} onChange={(e) => setNewNoteTitle(e.target.value)} />
          </label>
          <label>
            Описание
            <input value={newNoteDescription} onChange={(e) => setNewNoteDescription(e.target.value)} />
          </label>
          <label>
            Контент
            <textarea value={newNoteContent} onChange={(e) => setNewNoteContent(e.target.value)} rows={4} />
          </label>
          <button onClick={() => void createNote()}>Создать заметку</button>
        </div>
      </section>

      <section className="grid">
        <div className="card">
          <h2>Операции с заметкой</h2>
          <p>Выбрана заметка: {currentNote()?.title ?? 'не выбрана'}</p>
          <label>
            Заголовок
            <input value={editNoteTitle} onChange={(e) => setEditNoteTitle(e.target.value)} />
          </label>
          <label>
            Описание
            <input value={editNoteDescription} onChange={(e) => setEditNoteDescription(e.target.value)} />
          </label>
          <label>
            Контент
            <textarea value={editNoteContent} onChange={(e) => setEditNoteContent(e.target.value)} rows={4} />
          </label>
          <button onClick={() => void updateNote()}>Сохранить изменения заметки</button>
          <hr />
          <label>
            Целевая группа
            <select value={selectedGroupId} onChange={(e) => setSelectedGroupId(e.target.value)}>
              <option value="">Выберите группу</option>
              {groupItems.map((g) => (
                <option key={g.id} value={g.id}>
                  {'  '.repeat(g.level)}
                  {g.name}
                </option>
              ))}
            </select>
          </label>
          <p className="muted">Если выбрать группу, при сохранении заметка будет сразу прикреплена к ней.</p>
          <div className="row">
            <button onClick={() => void attachToGroup()}>Attach to group</button>
            <button onClick={() => void copyToGroup()}>Copy to group</button>
          </div>
          <hr />
          <label>
            Создать новый тег и привязать
            <input value={newTagName} onChange={(e) => setNewTagName(e.target.value)} />
          </label>
          <button onClick={() => void addTag()}>Добавить новый тег</button>
          <label>
            Привязать существующий тег
            <select value={existingTagName} onChange={(e) => setExistingTagName(e.target.value)}>
              <option value="">Выберите тег</option>
              {filteredTags.map((t) => (
                <option key={t.id} value={t.name}>
                  {t.name}
                </option>
              ))}
            </select>
          </label>
          <button onClick={() => void addExistingTag()}>Привязать существующий тег</button>
        </div>

        <div className="card">
          <h2>Инвайты</h2>
          <label>
            Группа для инвайта
            <select value={selectedGroupId} onChange={(e) => setSelectedGroupId(e.target.value)}>
              <option value="">Выберите группу</option>
              {groupItems.map((g) => (
                <option key={g.id} value={g.id}>
                  {'  '.repeat(g.level)}
                  {g.name}
                </option>
              ))}
            </select>
          </label>
          <label>
            Email
            <input value={inviteEmail} onChange={(e) => setInviteEmail(e.target.value)} />
          </label>
          <label>
            Роль
            <select value={inviteRole} onChange={(e) => setInviteRole(e.target.value as 'reader' | 'editor' | 'manager')}>
              <option value="reader">reader</option>
              <option value="editor">editor</option>
              <option value="manager">manager</option>
            </select>
          </label>
          <button onClick={() => void createInvite()}>Создать инвайт</button>
          <hr />
          <label>
            Group ID (accept)
            <input value={acceptGroupId} onChange={(e) => setAcceptGroupId(e.target.value)} />
          </label>
          <label>
            Token
            <input value={acceptToken} onChange={(e) => setAcceptToken(e.target.value)} />
          </label>
          <button onClick={() => void acceptInvite()}>Принять инвайт</button>
        </div>
      </section>

      <section className="card">
        <h2>Публичная заметка</h2>
        <label>
          Note ID
          <input value={publicNoteId} onChange={(e) => setPublicNoteId(e.target.value)} />
        </label>
        <button onClick={() => void makePublicRead()}>Сделать заметку публичной</button>
      </section>
    </main>
  )
}

