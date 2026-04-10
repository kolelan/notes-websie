-- Вывод классивикаторов с иерархией
create function kls_tree_data()
    returns TABLE(kls_id integer, qual_id integer, kls_rubrika ltree, kls_id_parent integer, kls_rubrika_parent ltree, arr_kls_id integer[], leaf boolean, toid oid, kls_vers integer, kls_code text, kls_names text, kls_namef text, kls_note text, kls_code_parent text, kls_namef_parent text, kls_names_parent text)
    language plpgsql
as
$$
BEGIN
  PERFORM kls.kls_tree_data_check();
RETURN QUERY EXECUTE $stmt$
SELECT
    mv.*
FROM kls.mv_kls_tree mv
    $stmt$;
END;
$$;

comment on function kls_tree_data() is 'Вывод классивикаторов с иерархией';

alter function kls_tree_data() owner to postgres;

grant execute on function kls_tree_data() to "USER_KLS_S";

grant execute on function kls_tree_data() to "USER_KLS_SUID";

