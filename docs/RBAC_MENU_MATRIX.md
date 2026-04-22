# Матрица прав меню (RBAC)

Документ описывает правила отображения пунктов меню из KLS (`kls.qual` + `kls.kls`) для endpoint `GET /menu/{code}`.

## Роли и иерархия

- `guest` — неавторизованный пользователь
- `user` — авторизованный пользователь
- `admin` — администратор
- `superadmin` — суперадминистратор

Иерархия (effective roles):
- `superadmin` включает: `superadmin`, `admin`, `user`
- `admin` включает: `admin`, `user`
- `user` включает: `user`
- `guest` включает: `guest`

## Теги в `tag/tags` (hstore)

- `authorized=false` — показывать только авторизованным (гостям скрыть)
- `role=...` — whitelist ролей (через запятую), например `role=admin,superadmin`
- `role=all` — разрешить всем ролям (не ограничивать по роли)
- `not_role=...` — blacklist ролей, например `not_role=user`
- `not_role=all` — скрыть всем
- `url=/path` — ссылка пункта

## Порядок проверки

1. Проверка `authorized`
2. Проверка `role`
3. Проверка `not_role`

`not_role` имеет приоритет запрета: если роль попала в `not_role`, пункт скрывается.

## Короткая матрица (примеры)

| Теги | guest | user | admin | superadmin |
|---|---:|---:|---:|---:|
| *(без тегов)* | ✓ | ✓ | ✓ | ✓ |
| `authorized=false` | ✗ | ✓ | ✓ | ✓ |
| `role=user` | ✗ | ✓ | ✓ | ✓ |
| `role=admin` | ✗ | ✗ | ✓ | ✓ |
| `role=superadmin` | ✗ | ✗ | ✗ | ✓ |
| `role=all` | ✓ | ✓ | ✓ | ✓ |
| `role=all, authorized=false` | ✗ | ✓ | ✓ | ✓ |
| `role=all, not_role=user` | ✓ | ✗ | ✓ | ✓ |
| `not_role=admin` | ✓ | ✓ | ✗ | ✗ |
| `not_role=superadmin` | ✓ | ✓ | ✓ | ✗ |
| `not_role=all` | ✗ | ✗ | ✗ | ✗ |

## Рекомендации

- Для админ-меню обычно достаточно `role=admin` — `superadmin` увидит его по иерархии.
- Для пунктов “только авторизованным” используйте `authorized=false`.
- Для явного исключения конкретной роли применяйте `not_role=...`.
