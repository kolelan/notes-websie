<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class SeedMenuClassifiers extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
            INSERT INTO kls.qual (qual_is_del, qual_vers, qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag)
            SELECT FALSE, 1,
                   COALESCE((SELECT qual_type_id FROM kls.qual_type WHERE qual_type_is_del = FALSE ORDER BY qual_type_id LIMIT 1), 1),
                   'Главное меню', 'Main Menu', 'MENU_MAIN',
                   'Основное меню сайта',
                   '"authorized"=>"true"'
            WHERE NOT EXISTS (SELECT 1 FROM kls.qual WHERE qual_code = 'MENU_MAIN');

            INSERT INTO kls.qual (qual_is_del, qual_vers, qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag)
            SELECT FALSE, 1,
                   COALESCE((SELECT qual_type_id FROM kls.qual_type WHERE qual_type_is_del = FALSE ORDER BY qual_type_id LIMIT 1), 1),
                   'Меню дашборда', 'Dashboard Menu', 'MENU_DASHBOARD',
                   'Меню для авторизованных пользователей',
                   '"authorized"=>"false"'
            WHERE NOT EXISTS (SELECT 1 FROM kls.qual WHERE qual_code = 'MENU_DASHBOARD');

            INSERT INTO kls.qual (qual_is_del, qual_vers, qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag)
            SELECT FALSE, 1,
                   COALESCE((SELECT qual_type_id FROM kls.qual_type WHERE qual_type_is_del = FALSE ORDER BY qual_type_id LIMIT 1), 1),
                   'Меню администрирования', 'Admin Menu', 'MENU_ADMIN',
                   'Меню администраторов',
                   '"role"=>"admin"'
            WHERE NOT EXISTS (SELECT 1 FROM kls.qual WHERE qual_code = 'MENU_ADMIN');
        SQL);

        $this->execute(<<<SQL
            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Главная', 'Главная', 'Переход на главную', '"url"=>"/"', 'MENU_MAIN_HOME', 1, '1'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_MAIN'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_MAIN_HOME');

            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Войти', 'Войти', 'Вход', '"url"=>"/login","authorized"=>"true"', 'MENU_MAIN_LOGIN', 1, '2'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_MAIN'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_MAIN_LOGIN');

            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Регистрация', 'Регистрация', 'Регистрация', '"url"=>"/register","authorized"=>"true"', 'MENU_MAIN_REGISTER', 1, '3'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_MAIN'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_MAIN_REGISTER');

            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Dashboard', 'Dashboard', 'Пользовательский дашборд', '"url"=>"/dashboard","authorized"=>"false"', 'MENU_DASHBOARD_HOME', 1, '1'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_DASHBOARD'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_DASHBOARD_HOME');

            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Профиль', 'Профиль', 'Профиль пользователя', '"url"=>"/profile","authorized"=>"false"', 'MENU_DASHBOARD_PROFILE', 1, '2'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_DASHBOARD'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_DASHBOARD_PROFILE');

            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Пользователи', 'Users', 'Пользователи', '"url"=>"/admin/users","role"=>"admin"', 'MENU_ADMIN_USERS', 1, '1'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_ADMIN'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_ADMIN_USERS');

            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Настройки', 'Settings', 'Настройки', '"url"=>"/admin/settings","role"=>"admin"', 'MENU_ADMIN_SETTINGS', 1, '2'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_ADMIN'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_ADMIN_SETTINGS');

            INSERT INTO kls.kls (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_vers, kls_rubrika)
            SELECT nextval('kls.kls_kls_id_seq'::regclass), FALSE, q.qual_id,
                   'Классификаторы', 'Classifiers', 'Классификаторы', '"url"=>"/admin/classifiers","role"=>"admin"', 'MENU_ADMIN_CLASSIFIERS', 1, '3'::ltree
            FROM kls.qual q
            WHERE q.qual_code = 'MENU_ADMIN'
              AND NOT EXISTS (SELECT 1 FROM kls.kls k WHERE k.qual_id = q.qual_id AND k.kls_code = 'MENU_ADMIN_CLASSIFIERS');
        SQL);
    }

    public function down(): void
    {
        $this->execute(<<<SQL
            DELETE FROM kls.kls
            WHERE kls_code IN (
                'MENU_MAIN_HOME', 'MENU_MAIN_LOGIN', 'MENU_MAIN_REGISTER',
                'MENU_DASHBOARD_HOME', 'MENU_DASHBOARD_PROFILE',
                'MENU_ADMIN_USERS', 'MENU_ADMIN_SETTINGS', 'MENU_ADMIN_CLASSIFIERS'
            );

            DELETE FROM kls.qual
            WHERE qual_code IN ('MENU_MAIN', 'MENU_DASHBOARD', 'MENU_ADMIN');
        SQL);
    }
}

