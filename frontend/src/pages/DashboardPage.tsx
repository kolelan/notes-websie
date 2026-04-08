import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import axios from 'axios'
import { Input, Pagination, Select, Table, Tree } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import type { TreeDataNode } from 'antd'
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
  const [groupId, setGroupId] = useState('')
  const [tagId, setTagId] = useState('')
  const [sortBy, setSortBy] = useState<'updated_at' | 'title' | 'author'>('updated_at')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')
  const [exactDate, setExactDate] = useState('')
  const [titleLike, setTitleLike] = useState('')
  const [descriptionLike, setDescriptionLike] = useState('')
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
  const [inviteEmail, setInviteEmail] = useState('')
  const [inviteRole, setInviteRole] = useState<'reader' | 'editor' | 'manager'>('reader')
  const [acceptGroupId, setAcceptGroupId] = useState('')
  const [acceptToken, setAcceptToken] = useState('')
  const [bulkTagName, setBulkTagName] = useState('')
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')
  const [canOpenAdmin, setCanOpenAdmin] = useState(false)
  const [notesPage, setNotesPage] = useState(1)
  const [notesPageSize, setNotesPageSize] = useState(25)
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
    setNotesPage(1)
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
        await api.post('/auth/logout', { refresh_token: session.refreshToken })
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

  function selectGroupAndScroll(groupIdValue: string) {
    setGroupId(groupIdValue)
    setTimeout(() => {
      const element = document.getElementById('notes-list')
      if (element) {
        element.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }
    }, 50)
  }

  function buildGroupTree(items: Group[]): TreeDataNode[] {
    return items.map((g) => ({
      key: g.id,
      title: `${g.name} (${groupNoteCounts[g.id] ?? 0})`,
      children: g.children?.length ? buildGroupTree(g.children) : undefined,
    }))
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

  function getSelectedNoteIds(): string[] {
    return Object.entries(selectedForBulk)
      .filter(([, checked]) => checked)
      .map(([id]) => id)
  }

  async function deleteNote(noteId: string) {
    if (!window.confirm('Удалить заметку? Это действие необратимо.')) return
    await withAction(async () => {
      await api.delete(`/notes/${noteId}`)
      setSelectedForBulk((prev) => {
        const next = { ...prev }
        delete next[noteId]
        return next
      })
      if (selectedNoteId === noteId) setSelectedNoteId('')
    }, 'Заметка удалена')
  }

  async function bulkAttachGroup() {
    const noteIds = getSelectedNoteIds()
    if (noteIds.length === 0) {
      setError('Выберите заметки для групповой операции.')
      return
    }
    if (!selectedGroupId) {
      setError('Выберите группу для назначения.')
      return
    }
    if (!window.confirm(`Назначить выбранную группу для ${noteIds.length} заметок?`)) return
    await withAction(async () => {
      await Promise.all(noteIds.map((id) => api.post(`/notes/${id}/attach-to-group`, { group_id: selectedGroupId })))
    }, 'Группа назначена выбранным заметкам')
  }

  async function bulkAddTag() {
    const noteIds = getSelectedNoteIds()
    if (noteIds.length === 0) {
      setError('Выберите заметки для групповой операции.')
      return
    }
    const tagName = bulkTagName.trim()
    if (!tagName) {
      setError('Укажите тег для назначения.')
      return
    }
    if (!window.confirm(`Назначить тег "${tagName}" для ${noteIds.length} заметок?`)) return
    await withAction(async () => {
      await Promise.all(noteIds.map((id) => api.post(`/notes/${id}/tags`, { name: tagName })))
      setBulkTagName('')
    }, 'Тег назначен выбранным заметкам')
  }

  async function bulkDeleteNotes() {
    const noteIds = getSelectedNoteIds()
    if (noteIds.length === 0) {
      setError('Выберите заметки для удаления.')
      return
    }
    if (!window.confirm(`Удалить ${noteIds.length} заметок? Это действие необратимо.`)) return
    await withAction(async () => {
      await Promise.all(noteIds.map((id) => api.delete(`/notes/${id}`)))
      setSelectedForBulk({})
      if (selectedNoteId && noteIds.includes(selectedNoteId)) setSelectedNoteId('')
    }, 'Выбранные заметки удалены')
  }

  if (loading) return <main className="page">Загрузка...</main>

  const groupItems = flattenGroups(groups)
  const filteredByText = dashboardNotes.filter((n) => {
    const titleOk = titleLike.trim() === '' || n.title.toLowerCase().includes(titleLike.trim().toLowerCase())
    const descOk = descriptionLike.trim() === '' || (n.description ?? '').toLowerCase().includes(descriptionLike.trim().toLowerCase())
    const dateOk = exactDate === '' || String(n.updated_at).slice(0, 10) === exactDate
    return titleOk && descOk && dateOk
  })
  const sortedDashboardNotes = [...filteredByText].sort((a, b) => {
    const dir = sortDir === 'asc' ? 1 : -1
    if (sortBy === 'title') return a.title.localeCompare(b.title) * dir
    if (sortBy === 'author') return (a.author_name ?? a.owner_id).localeCompare(b.author_name ?? b.owner_id) * dir
    return (new Date(a.updated_at).getTime() - new Date(b.updated_at).getTime()) * dir
  })
  const groupTreeData = buildGroupTree(groups)
  const notesStart = (notesPage - 1) * notesPageSize
  const pagedDashboardNotes = sortedDashboardNotes.slice(notesStart, notesStart + notesPageSize)
  const notesColumns: ColumnsType<DashboardNote> = [
    {
      title: '',
      key: 'bulk',
      width: 44,
      render: (_, n) => (
        <input
          type="checkbox"
          checked={Boolean(selectedForBulk[n.id])}
          onChange={() => toggleBulkSelection(n.id)}
          onClick={(e) => e.stopPropagation()}
        />
      ),
    },
    { title: 'Название', dataIndex: 'title', key: 'title' },
    {
      title: 'Описание',
      key: 'description',
      render: (_, n) => n.description || 'Без описания',
    },
    {
      title: 'Переход',
      key: 'link',
      render: (_, n) => (
        <Link to={`/notes/${n.id}`} onClick={(e) => e.stopPropagation()} title="Редактировать" aria-label="Редактировать">
          <i className="fa-solid fa-pen-to-square" aria-hidden="true" />
        </Link>
      ),
    },
    {
      title: 'Публикация',
      key: 'public',
      render: (_, n) => {
        const hint = n.is_public ? 'Сделать приватной' : 'Опубликовать'
        return (
          <button
            type="button"
            title={hint}
            aria-label={hint}
            className={n.is_public ? 'publish-btn publish-btn-private' : 'publish-btn publish-btn-public'}
            onClick={(e) => { e.stopPropagation(); void toggleNotePublic(n.id, n.is_public) }}
          >
            <i className={n.is_public ? 'fa-solid fa-lock' : 'fa-solid fa-globe'} aria-hidden="true" />
          </button>
        )
      },
    },
    { title: 'Группы', key: 'groups', render: (_, n) => n.groups.map((g) => g.name).join(', ') || '-' },
    { title: 'Теги', key: 'tags', render: (_, n) => n.tags.map((t) => t.name).join(', ') || '-' },
    { title: 'Автор', key: 'author', render: (_, n) => n.author_name || n.owner_id },
    {
      title: '',
      key: 'delete',
      width: 44,
      render: (_, n) => (
        <button
          type="button"
          title="Удалить"
          aria-label="Удалить"
          onClick={(e) => {
            e.stopPropagation()
            void deleteNote(n.id)
          }}
        >
          <i className="fa-solid fa-trash" aria-hidden="true" />
        </button>
      ),
    },
  ]

  return (
    <main className="page">
      <header className="row">
        <h1>Dashboard</h1>
        <div style={{ display: 'flex', alignItems: 'center', gap: 8, marginLeft: 'auto' }}>
          <button type="button">
            <Link to="/dashboard">Dashboard</Link>
          </button>
          <button type="button">
            <Link to="/profile">Профиль</Link>
          </button>
          {canOpenAdmin && (
            <button type="button">
              <Link to="/admin/users">Настройки</Link>
            </button>
          )}
          <button type="button" onClick={onLogout}>Выйти</button>
        </div>
      </header>

      {message && <p>{message}</p>}
      {error && <p className="error">{error}</p>}

      <section className="card">
        <div className="home-filter-block">
          <div className="home-filter-row">
            <span className="home-filter-icon" title="Фильтрация">
              <i className="fa-solid fa-filter" aria-hidden="true" />
            </span>
            <Select
              value={groupId || undefined}
              allowClear
              showSearch
              optionFilterProp="label"
              placeholder="Группа"
              onChange={(value) => setGroupId(value ?? '')}
              options={groupItems.map((g) => ({
                value: g.id,
                label: `${'  '.repeat(g.level)}${g.name}`,
              }))}
            />
            <Select
              value={tagId || undefined}
              allowClear
              showSearch
              optionFilterProp="label"
              placeholder="Тег"
              onChange={(value) => setTagId(value ?? '')}
              options={tags.map((t) => ({ value: t.id, label: t.name }))}
            />
            <div />
          </div>
          <div className="home-filter-row">
            <span className="home-filter-icon" title="Сортировка">
              <i className="fa-solid fa-sort" aria-hidden="true" />
            </span>
            <Select
              value={sortBy}
              onChange={(value) => setSortBy(value)}
              options={[
                { value: 'updated_at', label: 'По обновлению' },
                { value: 'title', label: 'По названию' },
                { value: 'author', label: 'По автору' },
              ]}
            />
            <button type="button" title="По возрастанию" aria-label="По возрастанию" onClick={() => setSortDir('asc')}>
              <i className="fa-solid fa-arrow-up" aria-hidden="true" />
            </button>
            <button type="button" title="По убыванию" aria-label="По убыванию" onClick={() => setSortDir('desc')}>
              <i className="fa-solid fa-arrow-down" aria-hidden="true" />
            </button>
          </div>
          <div className="home-filter-row">
            <span className="home-filter-icon" title="Поиск">
              <i className="fa-solid fa-magnifying-glass" aria-hidden="true" />
            </span>
            <Input
              value={titleLike}
              onChange={(e) => setTitleLike(e.target.value)}
              placeholder="Название"
            />
            <Input
              value={descriptionLike}
              onChange={(e) => setDescriptionLike(e.target.value)}
              placeholder="Описание"
            />
            <Input
              type="date"
              value={exactDate}
              onChange={(e) => setExactDate(e.target.value)}
              placeholder="Дата"
            />
          </div>
        </div>
      </section>

      <section className="card">
        <h2>Группы заметок</h2>
        <p>Сначала выберите группу пользователя, затем откроется список ее заметок.</p>
        {groups.length > 0 ? (
          <Tree
            treeData={groupTreeData}
            defaultExpandAll
            selectedKeys={groupId ? [groupId] : []}
            onSelect={(keys) => {
              const selected = String(keys[0] ?? '')
              if (selected) selectGroupAndScroll(selected)
            }}
          />
        ) : (
          <p>Группы пока не созданы</p>
        )}
      </section>

      <section className="card">
        <h2 id="notes-list">Заметки</h2>
        <div className="row">
          <p>Выбрано для групповых операций: {Object.values(selectedForBulk).filter(Boolean).length}</p>
          <div className="row" style={{ gap: 8 }}>
            <button type="button" onClick={() => setNoteViewMode('table')} disabled={noteViewMode === 'table'}>Таблица</button>
            <button type="button" onClick={() => setNoteViewMode('tile')} disabled={noteViewMode === 'tile'}>Плитка</button>
          </div>
        </div>
        <div className="row" style={{ gap: 10, flexWrap: 'wrap', justifyContent: 'flex-start' }}>
          <Select
            value={selectedGroupId || undefined}
            showSearch
            allowClear
            placeholder="Группа"
            optionFilterProp="label"
            style={{ width: 260 }}
            onChange={(value) => setSelectedGroupId(value ?? '')}
            options={groupItems.map((g) => ({
              value: g.id,
              label: `${'  '.repeat(g.level)}${g.name}`,
            }))}
          />
          <button
            type="button"
            className="bulk-action-btn"
            onClick={() => void bulkAttachGroup()}
            title="Назначить группу выбранным заметкам"
          >
            <i className="fa-solid fa-folder-plus" aria-hidden="true" />
            Применить
          </button>
          <Select
            value={bulkTagName || undefined}
            showSearch
            allowClear
            placeholder="Тег"
            optionFilterProp="label"
            style={{ width: 260 }}
            onChange={(value) => setBulkTagName(value ?? '')}
            onSearch={(value) => setBulkTagName(value)}
            options={tags.map((t) => ({ value: t.name, label: t.name }))}
          />
          <button
            type="button"
            className="bulk-action-btn"
            onClick={() => void bulkAddTag()}
            title="Назначить тег выбранным заметкам"
          >
            <i className="fa-solid fa-tags" aria-hidden="true" />
            Применить
          </button>
          <button
            type="button"
            className="bulk-action-btn bulk-delete-btn"
            onClick={() => void bulkDeleteNotes()}
            title="Удалить выбранные заметки"
          >
            <i className="fa-solid fa-trash" aria-hidden="true" />
          </button>
        </div>
        {noteViewMode === 'table' ? (
          <Table
            rowKey="id"
            dataSource={pagedDashboardNotes}
            columns={notesColumns}
            pagination={false}
            size="middle"
            onRow={(record) => ({
              onClick: () => setSelectedNoteId(record.id),
            })}
            rowClassName={(record) => (record.id === selectedNoteId ? 'selected-row' : '')}
          />
        ) : (
          <div className="tile-grid">
            {pagedDashboardNotes.map((n) => (
              <article key={n.id} className="note-tile" onClick={() => setSelectedNoteId(n.id)}>
                <h3 className="note-tile-title">{n.title}</h3>
                <p>{n.description || 'Без описания'}</p>
                <p><strong>Группы:</strong> {n.groups.map((g) => g.name).join(', ') || '-'}</p>
                <p><strong>Теги:</strong> {n.tags.map((t) => t.name).join(', ') || '-'}</p>
                <p><strong>Автор:</strong> {n.author_name || n.owner_id}</p>
                <div className="tile-actions">
                  <button
                    type="button"
                    className="tile-action-btn"
                    title="Выбрать"
                    aria-label="Выбрать"
                    onClick={(e) => {
                      e.stopPropagation()
                      toggleBulkSelection(n.id)
                    }}
                  >
                    <input
                      type="checkbox"
                      checked={Boolean(selectedForBulk[n.id])}
                      onChange={() => toggleBulkSelection(n.id)}
                      onClick={(e) => e.stopPropagation()}
                    />
                  </button>
                  <Link
                    className="tile-action-btn"
                    to={`/notes/${n.id}`}
                    onClick={(e) => e.stopPropagation()}
                    title="Редактировать"
                    aria-label="Редактировать"
                  >
                    <i className="fa-solid fa-pen-to-square" aria-hidden="true" />
                  </Link>
                  {(() => {
                    const hint = n.is_public ? 'Сделать приватной' : 'Опубликовать'
                    return (
                      <button
                        type="button"
                        title={hint}
                        aria-label={hint}
                        className={`tile-action-btn ${n.is_public ? 'publish-btn publish-btn-private' : 'publish-btn publish-btn-public'}`}
                        onClick={(e) => { e.stopPropagation(); void toggleNotePublic(n.id, n.is_public) }}
                      >
                        <i className={n.is_public ? 'fa-solid fa-lock' : 'fa-solid fa-globe'} aria-hidden="true" />
                      </button>
                    )
                  })()}
                  <button
                    type="button"
                    className="tile-action-btn"
                    title="Удалить"
                    aria-label="Удалить"
                    onClick={(e) => {
                      e.stopPropagation()
                      void deleteNote(n.id)
                    }}
                  >
                    <i className="fa-solid fa-trash" aria-hidden="true" />
                  </button>
                </div>
              </article>
            ))}
          </div>
        )}
        <div className="row" style={{ marginTop: 12 }}>
          <Select
            value={notesPageSize}
            style={{ width: 160 }}
            options={[
              { value: 25, label: '25 заметок' },
              { value: 100, label: '100 заметок' },
              { value: 500, label: '500 заметок' },
            ]}
            onChange={(value) => {
              setNotesPageSize(value)
              setNotesPage(1)
            }}
          />
          <Pagination
            current={notesPage}
            total={sortedDashboardNotes.length}
            pageSize={notesPageSize}
            showSizeChanger={false}
            onChange={(page) => setNotesPage(page)}
          />
        </div>
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
              {tags.map((t) => (
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

    </main>
  )
}

