-- Связи между классификатором и разделом
-- auto-generated definition
create table qual_kls
(
    qual_kls_id     bigint  default public.nextval('kls.qual_kls_qual_kls_id_seq'::text) not null
        constraint pk_kls_qual_kls__qual_kls_id
            primary key,
    qual_kls_is_del boolean default false                                                not null,
    qual_id_parent  bigint                                                               not null
        constraint fk_kls_qual_kls__qual_id_parent
            references qual
            deferrable initially deferred,
    kls_id_child    bigint                                                               not null,
    qual_kls_vers   integer default 1                                                    not null
)
    with (fillfactor = 95, autovacuum_enabled = true);

comment on table qual_kls is 'Связи между классификатором и разделом';

comment on column qual_kls.qual_kls_id is 'Идентификатор связи раздела с классификатором';

comment on column qual_kls.qual_kls_is_del is 'Признак удаления';

comment on column qual_kls.qual_id_parent is 'Классификатор';

comment on column qual_kls.kls_id_child is 'Раздел классификатора';

comment on column qual_kls.qual_kls_vers is 'Версия';

alter table qual_kls
    owner to postgres;

create index idx_kls_qual_kls__kls_id_child
    on qual_kls (kls_id_child);

create unique index idx_kls_qual_kls__partial_qual_kls_id
    on qual_kls (qual_kls_id)
    where (qual_kls_is_del = false);

create index idx_kls_qual_kls__qual_id_parent
    on qual_kls (qual_id_parent);

grant select on qual_kls to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on qual_kls to "USER_KLS_SUID";
