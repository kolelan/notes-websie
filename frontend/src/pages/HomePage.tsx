import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../lib/api'
import type { ApiEnvelope } from '../types/api'

type PublicNote = {
  id: string
  title: string
  description: string
  content: string
  owner_id: string
  published_at: string
  author_name: string
  groups: Array<{ id: string; name: string }>
  tags: Array<{ id: string; name: string }>
}

type PublicListResponse = {
  data: PublicNote[]
  meta?: { page: number; pages: number; total: number; limit: number }
}

type AuthorOption = { id: string; name: string; notes_count: number }
type TagOption = { id: string; name: string; notes_count: number }
type GroupOption = { id: string; name: string; notes_count: number }

export default function HomePage() {
  const [notes, setNotes] = useState<PublicNote[]>([])
  const [authors, setAuthors] = useState<AuthorOption[]>([])
  const [tags, setTags] = useState<TagOption[]>([])
  const [groups, setGroups] = useState<GroupOption[]>([])
  const [authorId, setAuthorId] = useState('')
  const [tagId, setTagId] = useState('')
  const [groupId, setGroupId] = useState('')
  const [titleLike, setTitleLike] = useState('')
  const [descriptionLike, setDescriptionLike] = useState('')
  const [sortBy, setSortBy] = useState<'published_at' | 'title' | 'author'>('published_at')
  const [sortDir, setSortDir] = useState<'asc' | 'desc'>('desc')
  const [page, setPage] = useState(1)
  const [pages, setPages] = useState(1)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const filterParams = useMemo(
    () => ({
      ...(authorId ? { author_id: authorId } : {}),
      ...(tagId ? { tag_id: tagId } : {}),
      ...(groupId ? { group_id: groupId } : {}),
      ...(titleLike.trim() ? { title_like: titleLike.trim() } : {}),
      ...(descriptionLike.trim() ? { description_like: descriptionLike.trim() } : {}),
      sort_by: sortBy,
      sort_dir: sortDir,
    }),
    [authorId, tagId, groupId, titleLike, descriptionLike, sortBy, sortDir]
  )

  async function loadFilters(currentAuthorId: string) {
    const [authorsRes, tagsRes, groupsRes] = await Promise.all([
      api.get<ApiEnvelope<AuthorOption[]>>('/public/notes/filters/authors'),
      api.get<ApiEnvelope<TagOption[]>>('/public/notes/filters/tags'),
      api.get<ApiEnvelope<GroupOption[]>>('/public/notes/filters/groups', {
        params: currentAuthorId ? { author_id: currentAuthorId } : {},
      }),
    ])
    setAuthors(authorsRes.data.data)
    setTags(tagsRes.data.data)
    setGroups(groupsRes.data.data)
  }

  async function loadNotes(targetPage = 1) {
    setLoading(true)
    setError('')
    try {
      const res = await api.get<PublicListResponse>('/public/notes', {
        params: {
          ...filterParams,
          page: targetPage,
          limit: 12,
        },
      })
      setNotes(res.data.data)
      setPage(res.data.meta?.page ?? targetPage)
      setPages(res.data.meta?.pages ?? 1)
    } catch {
      setError('Не удалось загрузить опубликованные заметки.')
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    void loadFilters(authorId)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [authorId])

  useEffect(() => {
    void loadNotes(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [filterParams])

  return (
    <main className="page">
      <header className="row home-header">
        <div>
          <h1>Notes Website</h1>
          <p className="muted">Ваши заметки, группы и совместная работа</p>
        </div>
        <div className="row">
          <Link to="/login">Войти</Link>
          <Link to="/register">Регистрация</Link>
          <Link to="/profile">Профиль</Link>
        </div>
      </header>

      <section className="card hero">
        <h2>Организуйте знания в одном месте</h2>
        <p>Иерархия групп, теги, публикация заметок и удобный поиск по публичной базе.</p>
      </section>

      <section className="card">
        <h2>Опубликованные заметки</h2>
        <div className="compact-filters-row">
          <label>
            Автор
            <select value={authorId} onChange={(e) => setAuthorId(e.target.value)}>
              <option value="">Все авторы</option>
              {authors.map((a) => (
                <option key={a.id} value={a.id}>
                  {a.name} ({a.notes_count})
                </option>
              ))}
            </select>
          </label>
          <label>
            Группа автора
            <select value={groupId} onChange={(e) => setGroupId(e.target.value)}>
              <option value="">Все группы</option>
              {groups.map((g) => (
                <option key={g.id} value={g.id}>
                  {g.name} ({g.notes_count})
                </option>
              ))}
            </select>
          </label>
          <label>
            Тег
            <select value={tagId} onChange={(e) => setTagId(e.target.value)}>
              <option value="">Все теги</option>
              {tags.map((t) => (
                <option key={t.id} value={t.id}>
                  {t.name} ({t.notes_count})
                </option>
              ))}
            </select>
          </label>
          <label>
            Название содержит
            <input value={titleLike} onChange={(e) => setTitleLike(e.target.value)} />
          </label>
          <label>
            Описание содержит
            <input value={descriptionLike} onChange={(e) => setDescriptionLike(e.target.value)} />
          </label>
          <label>
            Сортировка
            <select value={sortBy} onChange={(e) => setSortBy(e.target.value as 'published_at' | 'title' | 'author')}>
              <option value="published_at">Дата публикации</option>
              <option value="title">Название</option>
              <option value="author">Автор</option>
            </select>
          </label>
        </div>
        <div className="row">
          <button onClick={() => setSortDir((prev) => (prev === 'asc' ? 'desc' : 'asc'))}>
            Направление: {sortDir.toUpperCase()}
          </button>
          <button onClick={() => void loadNotes(1)}>Обновить</button>
        </div>
      </section>

      {error && <p className="error">{error}</p>}
      {loading ? (
        <p>Загрузка...</p>
      ) : (
        <section className="tile-grid">
          {notes.map((note) => (
            <article key={note.id} className="note-tile">
              <h3>{note.title}</h3>
              <p>{note.description || 'Без описания'}</p>
              <p className="muted">Автор: {note.author_name}</p>
              <p className="muted">Опубликовано: {note.published_at}</p>
              <p><strong>Группы:</strong> {note.groups.map((g) => g.name).join(', ') || '-'}</p>
              <p><strong>Теги:</strong> {note.tags.map((t) => t.name).join(', ') || '-'}</p>
              <Link to={`/notes/${note.id}`}>Открыть заметку</Link>
            </article>
          ))}
        </section>
      )}

      <section className="card row">
        <button disabled={page <= 1} onClick={() => void loadNotes(page - 1)}>Назад</button>
        <span>Страница {page} из {pages}</span>
        <button disabled={page >= pages} onClick={() => void loadNotes(page + 1)}>Вперед</button>
      </section>

      <footer className="card muted">
        Notes Website MVP. Публичные заметки, группировка и поиск.
      </footer>
    </main>
  )
}
