SHELL := /bin/sh

DC := docker compose

.PHONY: help init wait-db up down restart build ps logs logs-php logs-nginx logs-db install migrate rollback status superadmin shell-php shell-db clean

help:
	@echo "Доступные команды:"
	@echo "  make init          - Первый запуск: .env, build, install, migrate, superadmin"
	@echo "  make up            - Запустить контейнеры"
	@echo "  make down          - Остановить контейнеры"
	@echo "  make restart       - Перезапустить контейнеры"
	@echo "  make build         - Пересобрать контейнеры"
	@echo "  make ps            - Показать статус контейнеров"
	@echo "  make logs          - Логи всех контейнеров"
	@echo "  make logs-php      - Логи php контейнера"
	@echo "  make logs-nginx    - Логи nginx контейнера"
	@echo "  make logs-db       - Логи postgres контейнера"
	@echo "  make install       - Установить composer зависимости в php контейнере"
	@echo "  make migrate       - Применить миграции (development)"
	@echo "  make rollback      - Откатить последнюю миграцию (development)"
	@echo "  make status        - Статус миграций (development)"
	@echo "  make superadmin    - Создать/обновить superadmin"
	@echo "  make shell-php     - Открыть shell в php контейнере"
	@echo "  make shell-db      - Открыть psql в postgres контейнере"
	@echo "  make clean         - Остановить и удалить volumes"

init:
	@if [ ! -f backend/.env ]; then cp backend/.env.example backend/.env; fi
	@if grep -q '^DB_HOST=' backend/.env; then \
		sed -i 's/^DB_HOST=.*/DB_HOST=db/' backend/.env; \
	else \
		echo 'DB_HOST=db' >> backend/.env; \
	fi
	$(DC) up -d --build
	$(MAKE) wait-db
	$(DC) exec php composer install
	$(DC) exec php composer migrate
	$(DC) exec php composer superadmin:create

wait-db:
	@echo "Ожидание готовности Postgres..."
	@until $(DC) exec -T db pg_isready -U postgres -d notes_website >/dev/null 2>&1; do sleep 1; done
	@echo "Postgres готов."

up:
	$(DC) up -d --build

down:
	$(DC) down

restart: down up

build:
	$(DC) up -d --build

ps:
	$(DC) ps

logs:
	$(DC) logs -f

logs-php:
	$(DC) logs -f php

logs-nginx:
	$(DC) logs -f nginx

logs-db:
	$(DC) logs -f db

install:
	$(DC) exec php composer install

migrate:
	$(MAKE) wait-db
	$(DC) exec php composer migrate

rollback:
	$(DC) exec php composer rollback

status:
	$(DC) exec php composer migrate:status

superadmin:
	$(DC) exec php composer superadmin:create

shell-php:
	$(DC) exec php sh

shell-db:
	$(DC) exec db psql -U postgres -d notes_website

clean:
	$(DC) down -v
