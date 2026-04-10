-- Связи между разделами классификаторов
-- auto-generated definition
create table kls_kls
(
    kls_kls_id     bigint  default public.nextval('kls.kls_kls_kls_kls_id_seq'::text) not null
        constraint pk_kls_kls_kls__kls_kls_id
            primary key,
    kls_kls_is_del boolean default false                                              not null,
    kls_id_parent  bigint                                                             not null,
    kls_id_child   bigint                                                             not null,
    kls_kls_vers   integer default 1                                                  not null,
    constraint ck_kls_kls_kls__check
        check (kls_id_parent <> kls_id_child)
)
    with (fillfactor = 95, autovacuum_enabled = true);

comment on table kls_kls is 'Связи между разделами классификаторов';

comment on column kls_kls.kls_kls_id is 'Ключ';

comment on column kls_kls.kls_kls_is_del is 'Признак удаления';

comment on column kls_kls.kls_id_parent is 'Зависимый раздел';

comment on column kls_kls.kls_id_child is 'Зависимый раздел';

comment on column kls_kls.kls_kls_vers is 'Версия';

alter table kls_kls
    owner to postgres;

create index idx_kls_kls_kls__kls_id_child
    on kls_kls (kls_id_child);

create index idx_kls_kls_kls__kls_id_parent
    on kls_kls (kls_id_parent);

create unique index idx_kls_kls_kls__partial_kls_kls_id
    on kls_kls (kls_kls_id)
    where (kls_kls_is_del = false);

create unique index uidx_kls_kls_kls__parent_child
    on kls_kls (kls_id_parent, kls_id_child);

grant select on kls_kls to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on kls_kls to "USER_KLS_SUID";
