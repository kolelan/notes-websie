create function qual_id_by_qcode(_qual_code character varying) returns bigint
    immutable
    language sql
as
$$
SELECT qual_id FROM kls.qual WHERE qual_code=$1 and qual_is_del = false;
$$;

alter function qual_id_by_qcode(varchar) owner to postgres;

grant execute on function qual_id_by_qcode(varchar) to "USER_KLS_S";

grant execute on function qual_id_by_qcode(varchar) to "USER_KLS_SUID";

