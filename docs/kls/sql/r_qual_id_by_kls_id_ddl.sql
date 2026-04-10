create function qual_id_by_kls_id(_kls_id bigint) returns bigint
    immutable
    language sql
as
$$
SELECT qual_id FROM kls.kls WHERE kls_id=$1;
$$;

alter function qual_id_by_kls_id(bigint) owner to postgres;

grant execute on function qual_id_by_kls_id(bigint) to "USER_KLS_S";

grant execute on function qual_id_by_kls_id(bigint) to "USER_KLS_SUID";

