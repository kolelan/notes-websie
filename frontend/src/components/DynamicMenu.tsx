import { useEffect, useState } from 'react'
import type { ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { Menu } from 'antd'
import { api, setAuthToken } from '../lib/api'
import { readSession } from '../lib/auth'

type MenuNode = {
  id: string
  title: string
  url: string
  children: MenuNode[]
}

type MenuResponse = {
  data: {
    code: string
    items: MenuNode[]
  }
}

type DynamicMenuItem = { key: string; label: ReactNode; children?: DynamicMenuItem[] }

function toAntItems(nodes: MenuNode[], onLogout?: () => Promise<void> | void): DynamicMenuItem[] {
  return nodes.map((node) => ({
    key: node.id,
    label:
      (node.url === '/logout' || node.url === 'logout') && onLogout
        ? (
            <a
              href="#"
              onClick={(e) => {
                e.preventDefault()
                void onLogout()
              }}
            >
              {node.title}
            </a>
          )
        : <Link to={node.url}>{node.title}</Link>,
    children: node.children?.length
      ? node.children.map((child) => ({
          key: child.id,
          label:
            (child.url === '/logout' || child.url === 'logout') && onLogout
              ? (
                  <a
                    href="#"
                    onClick={(e) => {
                      e.preventDefault()
                      void onLogout()
                    }}
                  >
                    {child.title}
                  </a>
                )
              : <Link to={child.url}>{child.title}</Link>,
        }))
      : undefined,
  }))
}

export default function DynamicMenu({ code, className, onLogout }: { code: string; className?: string; onLogout?: () => Promise<void> | void }) {
  const [items, setItems] = useState<DynamicMenuItem[]>([])

  useEffect(() => {
    async function load() {
      const session = readSession()
      setAuthToken(session?.accessToken ?? null)
      try {
        const res = await api.get<MenuResponse>(`/menu/${encodeURIComponent(code)}`)
        setItems(toAntItems(res.data.data.items, onLogout))
      } catch {
        setItems([])
      }
    }
    void load()
  }, [code, onLogout])

  if (items.length === 0) return null

  return (
    <div className="dynamic-menu-scroll">
      <Menu
        mode="horizontal"
        selectable={false}
        items={items}
        className={className}
        disabledOverflow
      />
    </div>
  )
}

