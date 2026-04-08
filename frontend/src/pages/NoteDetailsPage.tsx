import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import axios from 'axios'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import type { ApiEnvelope, Group, Note } from '../types/api'

function getJwtSubject(token: string): string | null {
  try {
    const payloadPart = token.split('.')[1]
    if (!payloadPart) return null
    const normalized = payloadPart.replace(/-/g, '+').replace(/_/g, '/')
    const padded = normalized.padEnd(Math.ceil(normalized.length / 4) * 4, '=')
    const decoded = JSON.parse(window.atob(padded)) as { sub?: string }
    return decoded.sub ?? null
  } catch {
    return null
  }
}

export default function NoteDetailsPage() {
  const { noteId = '' } = useParams()
  const navigate = useNavigate()
  const [note, setNote] = useState<Note | null>(null)
  const [currentUserId, setCurrentUserId] = useState<string | null>(null)
  const [isAuthenticated, setIsAuthenticated] = useState(false)
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [content, setContent] = useState('')
  const [groups, setGroups] = useState<Group[]>([])
  const [selectedGroupIds, setSelectedGroupIds] = useState<Record<string, boolean>>({})
  const [attachedGroupIds, setAttachedGroupIds] = useState<string[]>([])
  const [attachedTags, setAttachedTags] = useState<Array<{ id: string; name: string }>>([])
  const [tagInput, setTagInput] = useState('')
  const [expandedGroupIds, setExpandedGroupIds] = useState<Record<string, boolean>>({})
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

  const canEdit = Boolean(isAuthenticated && note && currentUserId && note.owner_id === currentUserId)

  function flattenGroups(items: Group[], level = 0): Array<{ id: string; name: string; level: number }> {
    const result: Array<{ id: string; name: string; level: number }> = []
    for (const item of items) {
      result.push({ id: item.id, name: item.name, level })
      if (item.children?.length) result.push(...flattenGroups(item.children, level + 1))
    }
    return result
  }

  function toggleGroupExpanded(groupId: string) {
    setExpandedGroupIds((prev) => ({
      ...prev,
      [groupId]: !prev[groupId],
    }))
  }

  function toggleGroupChecked(groupId: string, checked: boolean) {
    setSelectedGroupIds((prev) => ({
      ...prev,
      [groupId]: checked,
    }))
  }

  function renderGroupPickerTree(items: Group[], level = 0) {
    return (
      <ul className="group-tree">
        {items.map((group) => {
          const hasChildren = Boolean(group.children?.length)
          const isExpanded = expandedGroupIds[group.id] ?? true
          const isChecked = Boolean(selectedGroupIds[group.id])
          const wasAttached = attachedGroupIds.includes(group.id)
          return (
            <li key={group.id} style={{ marginLeft: `${level * 12}px` }}>
              <div className="group-row">
                {hasChildren ? (
                  <button
                    type="button"
                    className="tree-toggle"
                    onClick={() => toggleGroupExpanded(group.id)}
                    disabled={!canEdit}
                  >
                    {isExpanded ? '▾' : '▸'}
                  </button>
                ) : (
                  <span className="tree-toggle-placeholder">•</span>
                )}
                <label className="row" style={{ gap: 8, marginBottom: 0 }}>
                  <input
                    type="checkbox"
                    checked={isChecked}
                    onChange={(e) => toggleGroupChecked(group.id, e.target.checked)}
                    disabled={!canEdit}
                  />
                  <span>{group.name}</span>
                </label>
                {wasAttached && <span className="group-count">(привязана)</span>}
              </div>
              {hasChildren && isExpanded ? renderGroupPickerTree(group.children ?? [], level + 1) : null}
            </li>
          )
        })}
      </ul>
    )
  }

  useEffect(() => {
    const session = readSession()
    const accessToken = session?.accessToken ?? null
    setIsAuthenticated(Boolean(session))
    setCurrentUserId(accessToken ? getJwtSubject(accessToken) : null)

    async function loadNote() {
      setLoading(true)
      setError('')
      setMessage('')
      try {
        if (accessToken) {
          setAuthToken(accessToken)
        } else {
          setAuthToken(null)
        }
        const endpoint = accessToken ? `/notes/${noteId}` : `/public/notes/${noteId}`
        const res = await api.get<ApiEnvelope<Note>>(endpoint)
        const loaded = res.data.data
        setNote(loaded)
        setTitle(loaded.title ?? '')
        setDescription(loaded.description ?? '')
        setContent(loaded.content ?? '')
        if (accessToken) {
          const [groupsRes, overviewRes] = await Promise.all([
            api.get<ApiEnvelope<Group[]>>('/groups'),
            api.get<ApiEnvelope<Array<{ id: string; groups: Array<{ id: string; name: string }>; tags: Array<{ id: string; name: string }> }>>>('/notes/overview'),
          ])
          setGroups(groupsRes.data.data)
          const noteOverview = overviewRes.data.data.find((n) => n.id === noteId)
          const initialAttachedIds = (noteOverview?.groups ?? []).map((g) => g.id)
          const initialTags = noteOverview?.tags ?? []
          setAttachedGroupIds(initialAttachedIds)
          setSelectedGroupIds(Object.fromEntries(initialAttachedIds.map((id) => [id, true])))
          setAttachedTags(initialTags)
          setTagInput(initialTags.map((t) => t.name).join(' '))
        } else {
          setGroups([])
          setAttachedGroupIds([])
          setSelectedGroupIds({})
          setAttachedTags([])
          setTagInput('')
        }
      } catch {
        setError('Не удалось загрузить заметку.')
      } finally {
        setLoading(false)
      }
    }

    void loadNote()
  }, [noteId, navigate])

  async function saveNote() {
    if (!noteId) return
    if (!isAuthenticated) {
      setError('Для редактирования выполните вход.')
      setMessage('')
      return
    }
    if (note && currentUserId && note.owner_id !== currentUserId) {
      setError('Эту заметку можно только просматривать: редактирование доступно только владельцу.')
      setMessage('')
      return
    }
    setError('')
    setMessage('')
    try {
      await api.put(`/notes/${noteId}`, { title, description, content })
      setMessage('Изменения сохранены')
      setNote((prev) => (prev ? { ...prev, title, description, content } : prev))
    } catch (err) {
      if (axios.isAxiosError(err)) {
        const data = err.response?.data as { error?: string } | undefined
        if (err.response?.status === 404) {
          setError(data?.error ?? 'Заметка не найдена или у вас нет прав на редактирование.')
          return
        }
        if (err.response?.status === 422) {
          setError(data?.error ?? 'Проверьте поля формы: заголовок обязателен.')
          return
        }
        if (err.response?.status === 401) {
          setError('Сессия истекла. Выполните вход заново.')
          return
        }
        if (data?.error) {
          setError(data.error)
          return
        }
        if (err.response?.status) {
          setError(`Ошибка API: ${err.response.status}`)
          return
        }
      }
      setError('Не удалось сохранить изменения.')
    }
  }

  function parseTagNames(input: string): string[] {
    const unique = new Set(
      input
        .split(/\s+/)
        .map((v) => v.trim())
        .filter(Boolean)
    )
    return Array.from(unique)
  }

  async function applyTagSelection() {
    if (!noteId) return
    if (!canEdit) {
      setError('Изменение тегов доступно только владельцу заметки.')
      setMessage('')
      return
    }

    setError('')
    setMessage('')
    const enteredTagNames = parseTagNames(tagInput)
    const attachedByName = new Map(attachedTags.map((t) => [t.name, t]))
    const attachedNames = new Set(attachedTags.map((t) => t.name))
    const enteredNamesSet = new Set(enteredTagNames)
    const namesToAdd = enteredTagNames.filter((name) => !attachedNames.has(name))
    const tagsToDetach = attachedTags.filter((t) => !enteredNamesSet.has(t.name))

    if (namesToAdd.length === 0 && tagsToDetach.length === 0) {
      setMessage('Изменений по тегам нет')
      return
    }

    try {
      const addResponses = await Promise.all(
        namesToAdd.map((name) =>
          api.post<ApiEnvelope<{ note_id: string; tag_id: string; name: string }>>(`/notes/${noteId}/tags`, { name })
        )
      )
      await Promise.all(tagsToDetach.map((tag) => api.delete(`/notes/${noteId}/tags/${tag.id}`)))

      for (const res of addResponses) {
        attachedByName.set(res.data.data.name, { id: res.data.data.tag_id, name: res.data.data.name })
      }
      for (const tag of tagsToDetach) {
        attachedByName.delete(tag.name)
      }

      const updatedTags = enteredTagNames
        .map((name) => attachedByName.get(name))
        .filter((tag): tag is { id: string; name: string } => Boolean(tag))

      setAttachedTags(updatedTags)
      setTagInput(updatedTags.map((t) => t.name).join(' '))
      setMessage('Теги заметки обновлены')
    } catch (err) {
      if (axios.isAxiosError(err)) {
        const data = err.response?.data as { error?: string } | undefined
        if (data?.error) {
          setError(data.error)
          return
        }
      }
      setError('Не удалось обновить теги заметки.')
    }
  }

  async function applyGroupSelection() {
    if (!noteId) {
      setMessage('')
      return
    }
    if (!canEdit) {
      setError('Прикрепление к группе доступно только владельцу заметки.')
      setMessage('')
      return
    }
    setError('')
    setMessage('')
    const checkedIds = Object.entries(selectedGroupIds)
      .filter(([, checked]) => checked)
      .map(([id]) => id)
    const idsToAttach = checkedIds.filter((id) => !attachedGroupIds.includes(id))
    const idsToDetach = attachedGroupIds.filter((id) => !checkedIds.includes(id))
    if (idsToAttach.length === 0 && idsToDetach.length === 0) {
      setMessage('Изменений по группам нет')
      return
    }
    try {
      await Promise.all([
        ...idsToAttach.map((groupId) => api.post(`/notes/${noteId}/attach-to-group`, { group_id: groupId })),
        ...idsToDetach.map((groupId) => api.delete(`/notes/${noteId}/groups/${groupId}`)),
      ])
      const updatedAttached = checkedIds
      setAttachedGroupIds(updatedAttached)
      setSelectedGroupIds(Object.fromEntries(updatedAttached.map((id) => [id, true])))
      setMessage('Группы заметки обновлены')
    } catch (err) {
      if (axios.isAxiosError(err)) {
        const data = err.response?.data as { error?: string } | undefined
        if (data?.error) {
          setError(data.error)
          return
        }
      }
      setError('Не удалось прикрепить заметку к группе.')
    }
  }

  if (loading) return <main className="page">Загрузка заметки...</main>
  const checkedCount = Object.values(selectedGroupIds).filter(Boolean).length

  return (
    <main className="page">
      <header className="row">
        <h1>Заметка</h1>
        <Link to={isAuthenticated ? '/dashboard' : '/'}>{isAuthenticated ? 'Назад в dashboard' : 'Ко входу'}</Link>
      </header>

      {message && <p>{message}</p>}
      {error && <p className="error">{error}</p>}

      {!note ? (
        <section className="card">
          <p>Заметка не найдена или недоступна.</p>
        </section>
      ) : (
        <>
          <section className="card">
            <h2>Просмотр</h2>
            <p><strong>ID:</strong> {note.id}</p>
            <p><strong>Заголовок:</strong> {note.title}</p>
            <p><strong>Описание:</strong> {note.description || 'Без описания'}</p>
            <p><strong>Контент:</strong></p>
            <pre className="note-content">{note.content || 'Пусто'}</pre>
          </section>

          <section className="card">
            <h2>Редактирование</h2>
            {!isAuthenticated && (
              <p className="error">Публичный режим: для редактирования требуется авторизация.</p>
            )}
            {isAuthenticated && note && currentUserId && note.owner_id !== currentUserId && (
              <p className="error">Режим только для чтения: вы не владелец заметки.</p>
            )}
            <label>
              Заголовок
              <input
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                disabled={Boolean(!isAuthenticated || (note && currentUserId && note.owner_id !== currentUserId))}
              />
            </label>
            <label>
              Описание
              <input
                value={description}
                onChange={(e) => setDescription(e.target.value)}
                disabled={Boolean(!isAuthenticated || (note && currentUserId && note.owner_id !== currentUserId))}
              />
            </label>
            <label>
              Контент
              <textarea
                value={content}
                onChange={(e) => setContent(e.target.value)}
                rows={10}
                disabled={Boolean(!isAuthenticated || (note && currentUserId && note.owner_id !== currentUserId))}
              />
            </label>
            <button
              onClick={() => void saveNote()}
              disabled={!canEdit}
            >
              Сохранить
            </button>
            <hr />
            <label>
              Группы заметки
              {groups.length > 0 ? (
                <div>
                  {renderGroupPickerTree(groups)}
                  <p className="muted">Отмечено групп: {checkedCount}</p>
                </div>
              ) : (
                <p className="muted">Группы не найдены</p>
              )}
            </label>
            <button onClick={() => void applyGroupSelection()} disabled={!canEdit}>
              Применить выбор групп
            </button>
            <hr />
            <label>
              Теги (через пробел)
              <input
                value={tagInput}
                onChange={(e) => setTagInput(e.target.value)}
                onBlur={() => void applyTagSelection()}
                disabled={!canEdit}
                placeholder="rock live acoustic"
              />
            </label>
            <p className="muted">Добавьте слово - тег создастся и привяжется. Удалите слово - тег отвяжется.</p>
            <button onClick={() => void applyTagSelection()} disabled={!canEdit}>
              Применить теги
            </button>
          </section>
        </>
      )}
    </main>
  )
}
