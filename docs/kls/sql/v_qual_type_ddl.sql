create view v_qual_type
            (qual_type_id, qual_type_is_del, qual_type_namef, qual_type_names, qual_type_code, qual_type_vers) as
SELECT qt.qual_type_id,
       qt.qual_type_is_del,
       qt.qual_type_namef,
       qt.qual_type_names,
       qt.qual_type_code,
       qt.qual_type_vers
FROM kls.qual_type qt
WHERE qt.qual_type_is_del = false;

alter table v_qual_type
    owner to postgres;

grant select on v_qual_type to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on v_qual_type to "USER_KLS_SUID";

