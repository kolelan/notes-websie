import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import axios from 'axios'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'
import type { ApiEnvelope, Note } from '../types/api'

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
  const [loading, setLoading] = useState(true)
  const [message, setMessage] = useState('')
  const [error, setError] = useState('')

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

  if (loading) return <main className="page">Загрузка заметки...</main>

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
              disabled={Boolean(!isAuthenticated || (note && currentUserId && note.owner_id !== currentUserId))}
            >
              Сохранить
            </button>
          </section>
        </>
      )}
    </main>
  )
}
