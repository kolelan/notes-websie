import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { Alert, Button, Card, Input, Pagination, Select, Table } from 'antd'
import type { ColumnsType } from 'antd/es/table'
import { api, setAuthToken } from '../lib/api'
import { readSession, writeSession } from '../lib/auth'
import type { ApiEnvelope } from '../types/api'
import DynamicMenu from '../components/DynamicMenu'

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
  const navigate = useNavigate()
  const hasSession = Boolean(readSession())
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
  const [noteViewMode, setNoteViewMode] = useState<'tile' | 'table'>('tile')
  const [page, setPage] = useState(1)
  const [pageSize, setPageSize] = useState(10)
  const [total, setTotal] = useState(0)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  async function onLogout() {
    const session = readSession()
    if (session) {
      setAuthToken(session.accessToken)
      try {
        await api.post('/auth/logout', { refresh_token: session.refreshToken })
      } catch {
        // ignore
      }
    }
    writeSession(null)
    setAuthToken(null)
    navigate('/')
  }

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
          limit: pageSize,
        },
      })
      setNotes(res.data.data)
      setPage(res.data.meta?.page ?? targetPage)
      setTotal(res.data.meta?.total ?? res.data.data.length)
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
  }, [filterParams, pageSize])

  const columns: ColumnsType<PublicNote> = [
    { title: 'Название', dataIndex: 'title', key: 'title' },
    {
      title: 'Описание',
      key: 'description',
      render: (_, note) => note.description || 'Без описания',
    },
    { title: 'Автор', dataIndex: 'author_name', key: 'author_name' },
    { title: 'Опубликовано', dataIndex: 'published_at', key: 'published_at' },
    {
      title: 'Группы',
      key: 'groups',
      render: (_, note) => note.groups.map((g) => g.name).join(', ') || '-',
    },
    {
      title: 'Теги',
      key: 'tags',
      render: (_, note) => note.tags.map((t) => t.name).join(', ') || '-',
    },
    {
      title: '',
      key: 'open',
      render: (_, note) => <Link to={`/notes/${note.id}`}>Открыть</Link>,
    },
  ]

  return (
    <main className="page">
      <header className="row home-header">
        <div>
          <h1>Notes Website</h1>
          <p className="muted">Ваши заметки, группы и совместная работа</p>
        </div>
        <div className="header-menu-right">
          {hasSession ? (
            <DynamicMenu code="MENU_DASHBOARD" onLogout={onLogout} />
          ) : (
            <DynamicMenu code="MENU_MAIN" />
          )}
        </div>
      </header>

      <section className="card hero">
        <h2>Организуйте знания в одном месте</h2>
        <p>Иерархия групп, теги, публикация заметок и удобный поиск по публичной базе.</p>
      </section>

      <Card className="card">
        <h2>Опубликованные заметки</h2>
        <div className="home-filter-block">
          <div className="home-filter-row">
            <span className="home-filter-icon" title="Фильтрация">
              <i className="fa-solid fa-filter" aria-hidden="true" />
            </span>
            <Select
              value={authorId || undefined}
              allowClear
              placeholder="Автор"
              onChange={(value) => setAuthorId(value ?? '')}
              options={authors.map((a) => ({ value: a.id, label: `${a.name} (${a.notes_count})` }))}
              style={{ minWidth: 220 }}
            />
            <Select
              value={groupId || undefined}
              allowClear
              placeholder="Группа"
              onChange={(value) => setGroupId(value ?? '')}
              options={groups.map((g) => ({ value: g.id, label: `${g.name} (${g.notes_count})` }))}
              style={{ minWidth: 220 }}
            />
            <Select
              value={tagId || undefined}
              allowClear
              placeholder="Тег"
              onChange={(value) => setTagId(value ?? '')}
              options={tags.map((t) => ({ value: t.id, label: `${t.name} (${t.notes_count})` }))}
              style={{ minWidth: 220 }}
            />
          </div>
          <div className="home-filter-row">
            <span className="home-filter-icon" title="Сортировка">
              <i className="fa-solid fa-sort" aria-hidden="true" />
            </span>
            <Select
              value={sortBy}
              onChange={(value) => setSortBy(value)}
              options={[
                { value: 'published_at', label: 'Дата публикации' },
                { value: 'title', label: 'Название' },
                { value: 'author', label: 'Автор' },
              ]}
              style={{ minWidth: 220 }}
            />
            <Button
              title="По возрастанию"
              aria-label="По возрастанию"
              type={sortDir === 'asc' ? 'primary' : 'default'}
              onClick={() => setSortDir('asc')}
            >
              <i className="fa-solid fa-arrow-up" aria-hidden="true" />
            </Button>
            <Button
              title="По убыванию"
              aria-label="По убыванию"
              type={sortDir === 'desc' ? 'primary' : 'default'}
              onClick={() => setSortDir('desc')}
            >
              <i className="fa-solid fa-arrow-down" aria-hidden="true" />
            </Button>
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
          </div>
        </div>
      </Card>

      {error && <Alert type="error" message={error} showIcon style={{ marginBottom: 12 }} />}
      {loading ? (
        <p>Загрузка...</p>
      ) : noteViewMode === 'tile' ? (
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
      ) : (
        <Card className="card">
          <Table
            rowKey="id"
            dataSource={notes}
            columns={columns}
            pagination={false}
          />
        </Card>
      )}

      <Card className="card pagination-card">
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 8 }}>
          <Pagination
            current={page}
            total={total}
            pageSize={pageSize}
            locale={{ items_per_page: '' }}
            onChange={(p, size) => {
              if (size !== pageSize) {
                setPageSize(size)
                setPage(1)
                return
              }
              void loadNotes(p)
            }}
            showSizeChanger
            pageSizeOptions={[10, 25, 50]}
            size="small"
          />
          <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
            <Button size="small" type={noteViewMode === 'table' ? 'primary' : 'default'} onClick={() => setNoteViewMode('table')}>
              Таблица
            </Button>
            <Button size="small" type={noteViewMode === 'tile' ? 'primary' : 'default'} onClick={() => setNoteViewMode('tile')}>
              Плитка
            </Button>
          </div>
        </div>
      </Card>

      <footer className="card muted">
        Notes Website MVP. Публичные заметки, группировка и поиск.
      </footer>
    </main>
  )
}
