create view v_kls_icons(kls_id, kls_icon, kls_icons_is_del, kls_icons_vers, kls_code) as
SELECT kl.kls_id,
       ki.kls_icon,
       ki.kls_icons_is_del,
       ki.kls_icons_vers,
       kl.kls_code
FROM kls.kls kl
         LEFT JOIN kls.kls_icons ki USING (kls_id)
WHERE kl.kls_is_del = false;

comment on view v_kls_icons is 'Иконки классификатора';

comment on column v_kls_icons.kls_id is 'Код классификатора';

comment on column v_kls_icons.kls_icon is 'Иконка';

comment on column v_kls_icons.kls_icons_is_del is 'Признак удаления';

comment on column v_kls_icons.kls_icons_vers is 'Версия';

comment on column v_kls_icons.kls_code is 'Код классификатора';

alter table v_kls_icons
    owner to postgres;

grant select on v_kls_icons to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on v_kls_icons to "USER_KLS_SUID";

