create function qual_code_by_kls_id(_kls_id bigint) returns text
    immutable
    language sql
as
$$
SELECT qual_code
FROM kls.kls
         JOIN kls.qual USING(qual_id)
WHERE kls_id=$1 and qual_is_del = false;
$$;

alter function qual_code_by_kls_id(bigint) owner to postgres;

grant execute on function qual_code_by_kls_id(bigint) to "USER_KLS_S";

grant execute on function qual_code_by_kls_id(bigint) to "USER_KLS_SUID";

