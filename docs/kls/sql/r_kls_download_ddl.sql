create function kls_download(_fnc_var_code character varying, _json json)
    returns TABLE(data json, total bigint)
    language plpgsql
as
$fun$
DECLARE
	_offset				TEXT := '';
	_limit				TEXT := '';
	_order 				TEXT := 'ORDER BY kls_rubrika::bigint[]';
	lT_sql				TEXT;
	res_rows	int4 := 0;
BEGIN
	lT_sql := 'SELECT * FROM kls.v_kls_download';

	-- формируем ORDER BY (без внешних service/spo зависимостей)
	IF (_json ->> 'order') <> 'null' THEN
		_order := 'ORDER BY ' || string_agg(REPLACE(key,'kls_rubrika','kls_rubrika::bigint[]')||' '||value,', ') from json_each_text(_json ->'order');
END IF;

	-- формируем OFFSET LIMIT
_offset := 'OFFSET ' || COALESCE(_json ->> 'offset','null');
_limit :=  'LIMIT ' || COALESCE(_json ->> 'limit','null');

	EXECUTE 'SELECT COUNT(*) FROM ('||lT_sql||' '||_order||' '||_offset||' '||_limit||') t'
	INTO res_rows;
	SELECT NULL::json AS data, res_rows AS total INTO data, total;
	RETURN NEXT;
END;
$fun$;

alter function kls_download(varchar, json) owner to postgres;

grant execute on function kls_download(varchar, json) to "USER_KLS_S";

grant execute on function kls_download(varchar, json) to "USER_KLS_SUID";

