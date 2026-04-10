import { Navigate, Route, Routes } from 'react-router-dom'
import type { ReactElement } from 'react'
import { readSession } from './lib/auth'
import { parseJwt } from './lib/jwt'
import HomePage from './pages/HomePage'
import LoginPage from './pages/LoginPage'
import RegisterPage from './pages/RegisterPage'
import DashboardPage from './pages/DashboardPage'
import NoteDetailsPage from './pages/NoteDetailsPage'
import ProfilePage from './pages/ProfilePage'
import AdminUsersPage from './pages/AdminUsersPage'
import AdminSettingsPage from './pages/AdminSettingsPage'
import AdminAuditPage from './pages/AdminAuditPage'
import LegalPage from './pages/LegalPage'
import './App.css'

function PrivateRoute({ children }: { children: ReactElement }) {
  return readSession() ? children : <Navigate to="/login" replace />
}

function AdminRoute({ children }: { children: ReactElement }) {
  const session = readSession()
  if (!session) return <Navigate to="/login" replace />
  const role = parseJwt(session.accessToken)?.role ?? 'user'
  if (!['admin', 'superadmin'].includes(role)) return <Navigate to="/dashboard" replace />
  return children
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<HomePage />} />
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register" element={<RegisterPage />} />
      <Route path="/legal" element={<LegalPage />} />
      <Route
        path="/dashboard"
        element={
          <PrivateRoute>
            <DashboardPage />
          </PrivateRoute>
        }
      />
      <Route
        path="/profile"
        element={
          <PrivateRoute>
            <ProfilePage />
          </PrivateRoute>
        }
      />
      <Route
        path="/notes/:noteId"
        element={<NoteDetailsPage />}
      />
      <Route
        path="/admin/users"
        element={
          <AdminRoute>
            <AdminUsersPage />
          </AdminRoute>
        }
      />
      <Route
        path="/admin/settings"
        element={
          <AdminRoute>
            <AdminSettingsPage />
          </AdminRoute>
        }
      />
      <Route
        path="/admin/audit"
        element={
          <AdminRoute>
            <AdminAuditPage />
          </AdminRoute>
        }
      />
    </Routes>
  )
}
