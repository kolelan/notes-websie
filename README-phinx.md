# README Phinx

Краткая памятка по миграциям `Phinx` для проекта `notes-website`.

## Где находится конфигурация

- Конфиг: `backend/phinx.php`
- Миграции: `backend/database/migrations`
- Composer-скрипты: `backend/composer.json`

## Подготовка

1. Перейти в backend:
   - `cd backend`
2. Установить зависимости:
   - `composer install`
3. Создать env:
   - скопировать `backend/.env.example` в `backend/.env`
4. Проверить доступ к PostgreSQL (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`).

## Основные команды Phinx

### Создать новую миграцию

```bash
vendor/bin/phinx create CreateSomethingTable
```

После этого файл появится в `backend/database/migrations`.

### Применить миграции (development)

```bash
vendor/bin/phinx migrate -e development
```

или через composer:

```bash
composer migrate
```

### Проверить статус миграций

```bash
vendor/bin/phinx status -e development
```

или:

```bash
composer migrate:status
```

### Откатить последнюю миграцию

```bash
vendor/bin/phinx rollback -e development
```

или:

```bash
composer rollback
```

## Команды для production

Если в `phinx.php` настроено окружение `production`, используйте:

```bash
vendor/bin/phinx migrate -e production
vendor/bin/phinx status -e production
vendor/bin/phinx rollback -e production
```

## Рекомендуемый порядок на проде

1. Сделать backup БД (`pg_dump`).
2. Задеплоить код.
3. Выполнить:
   - `vendor/bin/phinx migrate -e production`
4. Проверить приложение (`/health`).
5. При проблеме:
   - откатить релиз;
   - при необходимости выполнить `rollback` и/или восстановить БД из backup.

## Правила для миграций

- Одна миграция = одно логическое изменение схемы.
- Писать `up()` и `down()` (по возможности обратимые изменения).
- Не редактировать уже примененные миграции.
- Для изменений в проде создавать новую миграцию.
- В миграциях избегать бизнес-логики приложения.

## Полезно для текущего проекта

- Начальная схема: `backend/database/migrations/20260407143000_init_schema.php`
- Роли/пароль пользователя: `backend/database/migrations/20260407144500_add_user_role_and_password.php`
- Теги hstore для KLS: `backend/database/migrations/20260409120000_add_kls_hstore_tags.php`
- Создание superadmin после миграций:
  - `composer superadmin:create`
