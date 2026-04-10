-- Общероссийский классификатор объектов административно-территориального деления(ОКАТО)
-- auto-generated definition
create table kls_okato
(
    kls_id      bigint  default public.nextval('kls.kls_kls_id_seq'::text) not null
        constraint kls_okato_pkey
            primary key,
    kls_is_del  boolean default false                                      not null,
    qual_id     bigint                                                     not null
        constraint fk_kls_kls_okato__qual_id
            references qual
            on update cascade on delete cascade
            deferrable initially deferred
        constraint ck_kls_kls_okato__qual_code
            check (qual_id = kls.qual_id_by_qcode('KLS_OKATO'::character varying)),
    kls_namef   text                                                       not null,
    kls_names   text,
    kls_note    text,
    kls_code    text                                                       not null,
    kls_vers    integer default 1                                          not null,
    kls_rubrika ltree                                                      not null
)
    inherits (kls)
    with (fillfactor = 95, autovacuum_enabled = true);

comment on table kls_okato is 'Общероссийский классификатор объектов административно-территориального деления(ОКАТО)';

comment on column kls_okato.kls_id is 'Код';

comment on column kls_okato.kls_is_del is 'Флаг удаления';

comment on column kls_okato.qual_id is 'Классификатор';

comment on column kls_okato.kls_namef is 'Полное наименование страны';

comment on column kls_okato.kls_names is 'Наименование страны';

comment on column kls_okato.kls_note is 'Описание раздела';

comment on column kls_okato.kls_vers is 'Версия';

comment on column kls_okato.kls_rubrika is 'Рубрика';

alter table kls_okato
    owner to postgres;

create index idx_kls_kls_okato__code
    on kls_okato (kls_code);

create index idx_kls_kls_okato__gist_rubrika
    on kls_okato using gist (kls_rubrika);

create index idx_kls_kls_okato__namef_trgm
    on kls_okato using gin (kls_namef gin_trgm_ops);

create index idx_kls_kls_okato__names_trgm
    on kls_okato using gin (kls_names gin_trgm_ops);

create unique index idx_kls_kls_okato__partial_kls_id
    on kls_okato (kls_id)
    where (kls_is_del = false);

create index idx_kls_kls_okato__qual_id
    on kls_okato (qual_id);

create index idx_kls_kls_okato__qual_id_kls_rubrika
    on kls_okato (qual_id, subpath(kls_rubrika, 0, '-1'::integer));

create index idx_kls_kls_okato__qual_id_nlevel
    on kls_okato (qual_id, nlevel(kls_rubrika));

create index idx_kls_kls_okato__rubrika
    on kls_okato (kls_rubrika);

create index idx_kls_kls_okato__ts_kls_namef
    on kls_okato using gin (to_tsvector('russian'::regconfig, kls_namef));

create index idx_kls_kls_okato__ts_kls_names
    on kls_okato using gin (to_tsvector('russian'::regconfig, kls_names));

create unique index uidx_kls_kls_okato__qual_id_kls_code
    on kls_okato (qual_id, kls_code)
    where (kls_is_del = false);

create unique index uix_kls_kls_okato__qual_id_rubrika__int8
    on kls_okato (qual_id, (kls_rubrika::bigint[]))
    where (NOT kls_is_del);

grant select on kls_okato to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on kls_okato to "USER_KLS_SUID";