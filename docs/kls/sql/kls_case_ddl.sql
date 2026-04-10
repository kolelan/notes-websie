-- Падежи классификатора
-- auto-generated definition
create table kls_case
(
    kls_case_id      bigint  default public.nextval('kls.kls_case_kls_case_id_seq'::text) not null
        constraint pk_kls_kls_case__kls_case_id
            primary key,
    kls_case_is_del  boolean default false                                                not null,
    kls_id           bigint                                                               not null
        constraint fk_kls_kls_case__kls_id
            references kls
            on update cascade on delete cascade
            deferrable initially deferred,
    kls_id_case_type bigint                                                               not null
        constraint fk_kls_kls_case__kls_id_case_type
            references kls
            on update cascade on delete cascade
            deferrable initially deferred,
    kls_case_names   text,
    kls_case_namef   text                                                                 not null,
    kls_case_vers    integer default 1                                                    not null
)
    with (fillfactor = 95, autovacuum_enabled = true);

comment on table kls_case is 'Падежи классификатора';

comment on column kls_case.kls_case_id is 'Ключ';

comment on column kls_case.kls_case_is_del is 'признак удаления';

comment on column kls_case.kls_id is 'Ссылка на классификатор';

comment on column kls_case.kls_id_case_type is 'Вид падежа';

comment on column kls_case.kls_case_names is 'Краткое именование в падеже';

comment on column kls_case.kls_case_namef is 'Полное именование в падеже';

comment on column kls_case.kls_case_vers is 'Версия';

alter table kls_case
    owner to postgres;

create index idx_kls_kls_case__kls_case_namef_trgm
    on kls_case using gist (kls_case_namef gist_trgm_ops);

create index idx_kls_kls_case__kls_case_names_trgm
    on kls_case using gist (kls_case_names gist_trgm_ops);

create unique index idx_kls_kls_case__kls_id
    on kls_case (kls_id);

create unique index idx_kls_kls_case__kls_id_case_type
    on kls_case (kls_id_case_type);

create unique index idx_kls_kls_case__partial_kls_case_id
    on kls_case (kls_case_id)
    where (kls_case_is_del = false);

create unique index uidx_kls_kls_case__kls_id_kls_id_case_type
    on kls_case (kls_id, kls_id_case_type)
    where (kls_case_is_del = false);

grant select on kls_case to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on kls_case to "USER_KLS_SUID";
