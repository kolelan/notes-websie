create view v_kls_subject_okato (kls_id, kls_vers, qual_id, qual_code, qual_tag, kls_namef, kls_names, kls_code, kls_rubrika) as
SELECT ko.kls_id,
       ko.kls_vers,
       ko.qual_id,
       q.qual_code,
       q.tag AS qual_tag,
       ko.kls_namef,
       ko.kls_names,
       ko.kls_code,
       ko.kls_rubrika
FROM kls.kls ko
         JOIN kls.qual q ON q.qual_id = ko.qual_id AND NOT q.qual_is_del
WHERE true
  AND NOT ko.kls_is_del
  AND nlevel(ko.kls_rubrika) = 2
  AND q.qual_code = 'KLS_OKATO'::text
ORDER BY (ko.kls_rubrika::bigint[]);

comment on view v_kls_subject_okato is 'Классификатор Субъектов РФ на базе ОКАТО';

comment on column v_kls_subject_okato.kls_id is 'Раздел классификатора';

comment on column v_kls_subject_okato.kls_vers is 'Версия';

comment on column v_kls_subject_okato.qual_id is 'Классификатор ОКАТО';

comment on column v_kls_subject_okato.qual_code is 'Код классификатора ОКАТО';

comment on column v_kls_subject_okato.qual_tag is 'Теги классификатора (hstore, необязательное поле)';

comment on column v_kls_subject_okato.kls_namef is 'Полное наименование субъекта РФ';

comment on column v_kls_subject_okato.kls_names is 'Краткое наименование субъекта РФ';

comment on column v_kls_subject_okato.kls_code is 'Код раздела классификатора';

comment on column v_kls_subject_okato.kls_rubrika is 'Рубрика';

alter table v_kls_subject_okato
    owner to postgres;

grant select on v_kls_subject_okato to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on v_kls_subject_okato to "USER_KLS_SUID";

