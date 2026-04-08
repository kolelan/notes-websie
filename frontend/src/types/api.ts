export type ApiEnvelope<T> = { data: T }

export type AuthPayload = {
  access_token: string
  refresh_token: string
  token_type: string
}

export type Group = {
  id: string
  name: string
  description: string
  parent_id: string | null
  children?: Group[]
}

export type Note = {
  id: string
  title: string
  description: string
  content: string
  owner_id: string
}

export type DashboardNote = {
  id: string
  title: string
  description: string
  content: string
  owner_id: string
  updated_at: string
  author_name: string
  is_public: boolean
  groups: Array<{ id: string; name: string }>
  tags: Array<{ id: string; name: string }>
}

