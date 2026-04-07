# Notes Website

Веб-приложение для хранения заметок с иерархией групп, тегами и гибкой моделью доступа (ACL).

## О проекте

`notes-website` - система заметок с акцентом на:
- удобную навигацию по дереву групп;
- альтернативную навигацию по тегам;
- совместную работу через приглашения;
- детальное разграничение прав доступа к группам и заметкам.

Проект задуман как fullstack-решение:
- Backend: `PHP 8.2+`, `Slim Framework 4`, `PostgreSQL`, ORM (`Doctrine` или `Eloquent`);
- Frontend: `React 18 + TypeScript`, `React Router`, `Axios`;
- Авторизация: `OAuth 2.0` + `JWT` (access/refresh);
- Безопасность: WAF-подход (rate limit, валидация, CSRF/XSS-контроли, логирование).

## Ключевые возможности

- OAuth-авторизация: Yandex, Mail.ru, Google, VK, ESIA.
- CRUD заметок с полями: заголовок, описание, контент, превью-изображение.
- Иерархия групп (папок) с вложенностью.
- Прикрепление заметки к нескольким группам (many-to-many).
- Копирование заметки как отдельного объекта (независимые права).
- Теги для заметок и фильтрация по тегам.
- ACL-права на заметки/группы:
  - `read` (чтение),
  - `edit` (редактирование),
  - `manage` (управление доступом),
  - `transfer` (передача владения).
- Публичный доступ к заметкам (read-only).
- Приглашения пользователей в рабочие группы.
- Наследование прав от группы к вложенным сущностям с возможностью явного переопределения.

## Архитектура

### Backend
- REST API на `Slim 4`.
- JWT-аутентификация (короткий access + refresh).
- Слой middleware:
  - проверка JWT;
  - ограничение частоты запросов;
  - валидация входных данных;
  - защита stateful-операций (CSRF);
  - аудит подозрительных действий.

### Frontend
- SPA на `React + TypeScript` (ожидаемый setup: Vite).
- Основные экраны:
  - Login;
  - Dashboard (дерево групп, список заметок, теги);
  - NoteEditor;
  - GroupManager;
  - AccessManager.

## Модель данных (укрупненно)

Сущности:
- `user`
- `group`
- `note`
- `note_group`
- `tag`
- `note_tag`
- `permission`
- `user_group`
- `user_group_member`
- `invitation`

Базовые принципы:
- заметка может иметь несколько путей через `note_group`;
- при `is_copy = true` создается отдельная заметка;
- права владельца максимальные;
- наследованные права могут быть переопределены на уровне конкретной заметки.

## Основные API-эндпоинты (черновой набор)

### Auth
- `POST /auth/login` (email/password, выдает access/refresh)
- `POST /auth/refresh` (обновление пары токенов)
- `POST /auth/logout` (отзыв refresh token)

### Groups
- `GET /groups`
- `POST /groups`
- `GET /groups/{id}`
- `PUT /groups/{id}`
- `DELETE /groups/{id}`
- `POST /groups/{id}/invite`
- `POST /groups/{id}/accept-invite`

### Notes
- `GET /notes?group_id=&tag_id=`
- `POST /notes`
- `GET /notes/{id}`
- `PUT /notes/{id}`
- `DELETE /notes/{id}`
- `POST /notes/{id}/attach-to-group`
- `POST /notes/{id}/copy-to-group`

### Permissions
- `GET /permissions/target/{type}/{id}`
- `POST /permissions`
- `PUT /permissions/{id}`
- `DELETE /permissions/{id}`
- `POST /transfer-ownership`

## Безопасность

- Rate limiting:
  - до `100` запросов/мин на пользователя;
  - до `20` запросов/мин на создание заметок.
- CSRF-защита для stateful-операций.
- XSS-митигации (экранирование + CSP).
- SQL-инъекции предотвращаются параметризованными запросами/ORM.
- Валидация DTO/JSON.
- Контроль загрузок изображений (MIME, размер, переименование, проверка).
- Логирование подозрительных операций.

## План MVP

1. Базовая авторизация (один OAuth-провайдер) и профиль.
2. CRUD заметок (плоская модель).
3. Группы и базовая иерархия.
4. Теги.
5. Многократная принадлежность заметок к группам.
6. ACL для пользователей.
7. Группы пользователей и приглашения.
8. Публичные заметки.
9. Передача владения.
10. Усиление WAF и аудит.

## Быстрый старт (backend)

1. Перейдите в `backend` и установите зависимости:
   - `composer install`
2. Создайте `.env`:
   - скопируйте `backend/.env.example` в `backend/.env`
3. Убедитесь, что PostgreSQL запущен и база существует.
4. Примените миграции:
   - `composer migrate`
5. Создайте суперпользователя:
   - `composer superadmin:create`
6. Запустите API:
   - `composer serve`

Проверка:
- `GET http://localhost:8080/health`
- `GET http://localhost:8080/notes`

## Локальная разработка через Docker

1. Создайте `backend/.env` из `backend/.env.example`.
2. Для контейнеров выставьте в `backend/.env`:
   - `DB_HOST=db`
3. Запустите контейнеры:
   - `docker compose up -d --build`
4. Установите зависимости внутри PHP-контейнера:
   - `docker compose exec php composer install`
5. Примените миграции:
   - `docker compose exec php composer migrate`
6. Создайте superadmin:
   - `docker compose exec php composer superadmin:create`

Проверка:
- `GET http://localhost:8080/health`

Можно использовать `Makefile`:
- `make init` (первый запуск: подготовка `.env`, сборка, миграции, superadmin)
- `make up`
- `make install`
- `make migrate`
- `make superadmin`

## Структура проекта (текущий инкремент)

- `backend/public/index.php` - точка входа Slim.
- `backend/src/Auth/JwtService.php` - выпуск и проверка JWT.
- `backend/src/Database/PdoFactory.php` - создание PDO-подключения к PostgreSQL.
- `backend/src/Http/Controller/AuthController.php` - auth endpoints.
- `backend/src/Http/Controller/NoteController.php` - базовый CRUD заметок.
- `backend/src/Http/Middleware/AuthMiddleware.php` - защита приватных маршрутов.
- `backend/phinx.php` - конфигурация Phinx.
- `backend/database/migrations` - миграции БД.
- `backend/bin/console` - CLI-команды проекта (в т.ч. `create:superadmin`).
- `docker-compose.yml` - локальный стек `nginx + php-fpm + postgres`.
- `scripts/deploy.sh` и `scripts/deploy.ps1` - MVP-скрипты деплоя на сервер по SSH.

## Production (MVP)

- На сервере: `nginx + php-fpm + postgres`.
- Публичный путь: `/var/www/site-name.ru/web`.
- Деплой скриптом:
  - Bash: `./scripts/deploy.sh <host> <user> [port]`
  - PowerShell: `.\scripts\deploy.ps1 -SshHost <host> -SshUser <user> -SshPort 22`
- В рамках деплоя выполняются:
  - `composer install --no-dev --optimize-autoloader`;
  - `phinx migrate -e production`;
  - `create:superadmin`.

Дополнительно по миграциям: см. `README-phinx.md`.

## Текущее состояние

Собран первый технический инкремент backend:
- добавлен каркас Slim API;
- подключен инструмент миграций `Phinx`;
- создана начальная миграция PostgreSQL по ключевым сущностям;
- добавлен auth-базис: `login/refresh/logout`, JWT и таблица `refresh_token`;
- `notes` маршруты защищены bearer access token.

## Лицензия

Лицензия не указана. При необходимости добавьте файл `LICENSE`.
