create function kls_ids_by_kqcode(p_kls_codes text, p_kls_qual_codes text DEFAULT NULL::text) returns bigint[]
    immutable
    language sql
as
$$
SELECT
    array_agg(kls_id)::_int8
	FROM kls.qual kq
    JOIN kls.kls k USING(qual_id)
WHERE NOT kq.qual_is_del
  AND kq.qual_code = regexp_replace(p_kls_codes, '[\n\r\s]+', '', 'gn')
  AND (
    CASE WHEN p_kls_qual_codes IS NOT NULL
    THEN k.kls_code = ANY( regexp_split_to_array(p_kls_qual_codes, '(\W+)') )
    ELSE TRUE
    END
    )
    LIMIT 1
    $$;

comment on function kls_ids_by_kqcode(text, text) is '
Выборка массива идентификаторов классификатора по коду раздела
(с возможностью фильтрации по кодам классификатора указанным через пробельный или пунктуационный символ)

  Пример использования:

  1) По коду раздела с фильтрацией по кодам классификатора:
	SELECT
		*
	FROM kls.kls_ids_by_kqcode(
		  $code_qual$
		  	KLS_OKATO
		  $code_qual$::text
		, $code_okato$
			40263561
			40286550
			11Г0000000
			33|22|44
		  $code_okato$::text
	) ids
	JOIN kls.kls k ON (NOT k.kls_is_del AND k.kls_id =ANY(ids));

  2) Только по коду раздела:
	SELECT
		kls_namef
	FROM kls.kls_ids_by_kqcode(
		$code_qual$
			KLS_OKATO
		$code_qual$::text
	) ids
	JOIN kls.kls k ON (NOT k.kls_is_del AND k.kls_id =ANY(ids));
';

alter function kls_ids_by_kqcode(text, text) owner to postgres;

