# Спецификация: Система заметок с иерархией, тегами и дискреционным доступом

## 1. Цели
Создать веб-приложение для хранения заметок с гибкой навигацией (дерево групп + теги) и детальной моделью прав доступа между пользователями.

## 2. Архитектура
- **Backend**: PHP 8.2+, Slim Framework 4, Doctrine ORM (или Eloquent), PostgreSQL 15+
- **Frontend**: React 18, React Router, Axios, TailwindCSS / Material UI
- **Auth**: OAuth 2.0 клиенты + JWT (access/refresh)
- **WAF**: middleware для лимитов, санитизации, CSRF, логирования

## 3. Модели данных (ключевые сущности)

### user
- id (UUID)
- email
- name
- auth_providers (json: {provider: "google", provider_id: "..."})
- created_at

### group (папка)
- id (UUID)
- name
- description (text)
- image_url (опционально)
- parent_id (self-ref, nullable)
- owner_id → user.id
- created_at, updated_at

### note
- id (UUID)
- title
- description
- content (text)
- image_preview_url (опционально)
- owner_id → user.id
- created_at, updated_at

### note_group (many-to-many, для множественных путей)
- note_id
- group_id
- is_copy (boolean) — если true, это копия с отдельными правами

### tag
- id
- name (уникальный на пользователя? глобальный?)

### note_tag
- note_id
- tag_id

### permission (ACL)
- id
- target_type ('note' | 'group')
- target_id
- grantee_type ('user' | 'group_of_users' | 'public')
- grantee_id (UUID user_id или group_of_users_id, NULL для public)
- can_read (bool)
- can_edit (bool)
- can_manage (bool) — менять ACL
- can_transfer (bool) — передать владение
- inherited_from (опционально, ссылка на permission родительской группы)

### user_group (группа пользователей для удобного назначения прав)
- id
- name
- owner_id

### user_group_member
- user_group_id
- user_id

### invitation
- id
- target_group_id (группа заметок, куда приглашают)
- inviter_id
- invitee_email
- role ('reader', 'editor', 'manager')
- token
- expires_at

## 4. Бизнес-правила

### 4.1. Иерархия и множественные пути
- Заметка может быть добавлена в несколько групп (разные пути).
- Если `is_copy = false` — это та же заметка (общий контент, права суммируются).
- Если `is_copy = true` — создаётся новая запись note с новым id, копируется содержимое, права независимы.

### 4.2. Права доступа (разрешения)
- Владелец заметки/группы имеет все права (can_read, edit, manage, transfer).
- Публичная заметка: создаётся permission с grantee_type='public' и can_read=true.
- При добавлении пользователя в группу заметок — можно выдать права на всю группу (наследуются вниз).
- Переопределение: если на конкретную заметку выставлено право явно, оно имеет приоритет над унаследованным от группы.
- Передача владения: только пользователь с can_transfer может назначить нового owner_id (владельцем становится другой пользователь, старый теряет владение, но может сохранить права через ACL).

### 4.3. Приглашения
- Владелец группы заметок создаёт приглашение по email.
- Приглашённый регистрируется/логинится, принимает приглашение → становится членом user_group и получает соответствующие права.

## 5. API (основные endpoint-ы)

Все запросы требуют JWT (кроме /auth/* и public-доступа к заметкам)

### Auth
- POST /auth/login — ссылки на OAuth провайдеров
- GET /auth/callback/{provider}
- POST /auth/refresh
- POST /auth/logout

### Groups
- GET /groups — дерево групп пользователя
- POST /groups — создать группу
- GET /groups/{id} — с содержимым (подгруппы + заметки)
- PUT /groups/{id}
- DELETE /groups/{id}
- POST /groups/{id}/invite
- POST /groups/{id}/accept-invite (по токену)

### Notes
- GET /notes?group_id=&tag_id=
- POST /notes
- GET /notes/{id}
- PUT /notes/{id}
- DELETE /notes/{id}
- POST /notes/{id}/attach-to-group
- POST /notes/{id}/copy-to-group

### Tags
- GET /tags
- POST /notes/{id}/tags

### Permissions
- GET /permissions/target/{type}/{id}
- POST /permissions (выдать право)
- PUT /permissions/{id}
- DELETE /permissions/{id}
- POST /transfer-ownership (передать владение заметкой/группой)

## 6. WAF и безопасность

- **Rate Limiting**: 100 запросов/мин на пользователя, 20/мин на создание заметки.
- **CSRF**: для stateful операций (формы) — double submit cookie или SameSite=Strict.
- **XSS**: автоматическое экранирование на фронте, Content-Security-Policy (CSP).
- **SQL Injection**: Doctrine / параметризованные запросы.
- **Input validation**: JSON schema + валидация на уровне DTO.
- **JWT**: короткий access (15 мин), refresh (7 дней), хранить refresh token в httpOnly cookie.
- **Логи**: подозрительные действия (массовое удаление, много неудачных ACL-запросов) → alert.
- **Файлы изображений**: проверка MIME-типа, сканирование, переименование, ограничение размера (2 MB).

## 7. Frontend (React)

Страницы:
- **Login** — кнопки входа через соцсети.
- **Dashboard** — дерево групп (слева), список заметок (центр), панель тегов (справа).
- **NoteEditor** — редактирование текста (Markdown), загрузка изображения, выбор групп, тегов.
- **GroupManager** — управление подгруппами, приглашения.
- **AccessManager** — таблица прав (кто и что может) с возможностью добавить пользователя/группу пользователей.

Компоненты:
- `TreeView` — рекурсивное отображение групп.
- `NoteCard` — превью заметки.
- `TagCloud`.
- `PermissionMatrix`.

Состояние: React Context + useReducer (или Redux Toolkit) для пользователя, групп, заметок.

## 8. Последовательность разработки (MVP)

1. Базовая аутентификация (одна соцсеть), JWT, профиль.
2. CRUD заметок (плоский список, один владелец).
3. Группы (одно дерево, заметка принадлежит одной группе).
4. Теги.
5. Множественная принадлежность группам (many-to-many).
6. ACL (пользователь → заметка/группа).
7. Группы пользователей + приглашения.
8. Публичный доступ.
9. Передача владения.
10. WAF + аудит.

## 9. Технические риски

- Сложность наследования прав при глубокой иерархии → использовать материализованный путь или кеширование разрешений в Redis.
- OAuth через Госуслуги (ESIA) требует подписания запросов ГОСТ — готовь отдельную библиотеку.
- Множественные пути могут запутать пользователя — на UI показывать «текущий путь» и «также находится в…».

## 10. Документация для API

Будет генерироваться OpenAPI (Swagger) через аннотации/атрибуты в Slim.

## 11. Стратегия выхода на production

### 11.1. Целевые окружения
- **Local (dev)**: разработка в Docker (`nginx + php-fpm + postgres`) для воспроизводимой среды.
- **Production**: нативный сервер (без Docker) на связке `nginx + php-fpm + postgres`.
- Стек на проде максимально повторяет локальный по версиям и конфигурации.

### 11.2. Публичная директория и раскладка
- Публичный web-root: `/var/www/site-name.ru/web` (там находится публичный `index.php`).
- Код backend размещается в каталоге проекта на сервере и подключается через `index.php`.
- Секреты хранятся только в серверном `.env` (в git не коммитятся).

Рекомендуемая структура:
- `/var/www/site-name.ru/releases/<timestamp>/` — конкретный релиз;
- `/var/www/site-name.ru/current` — симлинк на активный релиз;
- `/var/www/site-name.ru/shared/.env` — общий prod-конфиг.

### 11.3. Стратегия миграций БД
- Инструмент миграций: **Phinx**.
- Все изменения схемы вносятся только через миграции (без ручных SQL в проде).
- Миграции запускаются в рамках деплоя: `phinx migrate -e production`.
- Перед релизом выполняется резервная копия БД (`pg_dump`) для быстрого восстановления.

### 11.4. Стратегия доставки кода
- Доставка кода на сервер по `ssh` с помощью скрипта (MVP-формат).
- Передача файлов: `rsync` (или `scp` как fallback).
- На сервере в скрипте:
  1. `composer install --no-dev --optimize-autoloader`;
  2. линковка `.env` из `shared`;
  3. запуск миграций `phinx`;
  4. переключение симлинка `current` на новый релиз;
  5. health-check endpoint.

### 11.5. Управление конфигурацией
- Переменные окружения для prod:
  - `APP_ENV=production`;
  - `APP_DEBUG=false`;
  - `APP_URL=https://site-name.ru`;
  - `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` (из выданных доступов).
- Доступ к БД используется через отдельного пользователя с ограниченными правами.

### 11.6. Минимальный процесс релиза (MVP)
1. Прогон тестов и базовых проверок локально.
2. Деплой скриптом по `ssh`.
3. Применение миграций Phinx.
4. Проверка `/health` и smoke-check основных endpoint-ов.
5. При проблеме — откат на предыдущий релиз (через возврат симлинка) и восстановление БД из backup при необходимости.