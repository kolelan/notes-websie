-- Иконки классификатора
-- auto-generated definition
create table kls_icons
(
    kls_id           bigint                 not null
        constraint pk_kls_kls_icons__kls_id
            primary key
        constraint fk_kls_kls_icons__kls_id
            references kls
            on update cascade on delete cascade
            deferrable initially deferred,
    kls_icons_is_del boolean  default false not null,
    kls_icons_vers   smallint default 1     not null,
    kls_icon         text
)
    with (fillfactor = 90, autovacuum_enabled = true);

comment on table kls_icons is 'Иконки классификатора';

comment on column kls_icons.kls_id is 'Код классификатора';

comment on column kls_icons.kls_icons_is_del is 'Флаг удаления';

comment on column kls_icons.kls_icons_vers is 'Версия';

comment on column kls_icons.kls_icon is 'Иконка';

alter table kls_icons
    owner to postgres;

create unique index idx_kls_kls_icons__partial_kls_id
    on kls_icons (kls_id)
    where (kls_icons_is_del = false);

grant select on kls_icons to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on kls_icons to "USER_KLS_SUID";