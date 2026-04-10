create function kls_id_by_kqcode(_kls_code character varying, _qual_code character varying) returns bigint
    immutable
    strict
    language sql
as
$$
SELECT kls_id FROM kls.kls WHERE kls_is_del = false and kls_code = $1 AND qual_id = (SELECT qual_id FROM kls.qual WHERE qual_is_del = false and qual_code = $2);
$$;

alter function kls_id_by_kqcode(varchar, varchar) owner to postgres;

grant execute on function kls_id_by_kqcode(varchar, varchar) to "USER_KLS_S";

grant execute on function kls_id_by_kqcode(varchar, varchar) to "USER_KLS_SUID";

