-- Read-only PostgreSQL catalog export. No customer rows or credentials.
SELECT jsonb_build_object(
 'server_version', current_setting('server_version'),
 'schemas', (SELECT jsonb_agg(nspname ORDER BY nspname) FROM pg_namespace WHERE nspname IN ('core','platform_runtime','commercial','education','device_management','storage','chat','realtime','notifications','activity','workspace')),
 'enums', COALESCE((SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'name',t.typname,'values',v.labels) ORDER BY n.nspname,t.typname)
   FROM pg_type t JOIN pg_namespace n ON n.oid=t.typnamespace
   CROSS JOIN LATERAL (SELECT jsonb_agg(enumlabel ORDER BY enumsortorder) labels FROM pg_enum WHERE enumtypid=t.oid) v
   WHERE n.nspname IN ('core','platform_runtime','commercial','education','device_management','storage','chat','realtime','notifications','activity','workspace') AND t.typtype='e'),'[]'::jsonb),
 'tables', COALESCE((SELECT jsonb_agg(jsonb_build_object(
   'schema',n.nspname,'name',c.relname,'comment',obj_description(c.oid,'pg_class'),
   'rls',c.relrowsecurity,'force_rls',c.relforcerowsecurity,
   'columns',(SELECT jsonb_agg(jsonb_build_object('name',a.attname,'type',format_type(a.atttypid,a.atttypmod),
      'type_schema',tn.nspname,'type_name',t.typname,'is_enum',t.typtype='e',
      'nullable',NOT a.attnotnull,'default',pg_get_expr(d.adbin,d.adrelid),
      'identity',a.attidentity,'generated',a.attgenerated,'comment',col_description(c.oid,a.attnum)) ORDER BY a.attnum)
      FROM pg_attribute a JOIN pg_type t ON t.oid=a.atttypid JOIN pg_namespace tn ON tn.oid=t.typnamespace
      LEFT JOIN pg_attrdef d ON d.adrelid=a.attrelid AND d.adnum=a.attnum
      WHERE a.attrelid=c.oid AND a.attnum>0 AND NOT a.attisdropped),
   'constraints',COALESCE((SELECT jsonb_agg(jsonb_build_object('name',co.conname,'kind',co.contype,
      'definition',pg_get_constraintdef(co.oid,true),'deferrable',co.condeferrable,'initially_deferred',co.condeferred,
      'columns',(SELECT jsonb_agg(a.attname ORDER BY k.ord) FROM unnest(co.conkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=co.conrelid AND a.attnum=k.num),
      'target_schema',fn.nspname,'target_table',fc.relname,
      'target_columns',(SELECT jsonb_agg(a.attname ORDER BY k.ord) FROM unnest(co.confkey) WITH ORDINALITY k(num,ord) JOIN pg_attribute a ON a.attrelid=co.confrelid AND a.attnum=k.num),
      'on_update',co.confupdtype,'on_delete',co.confdeltype) ORDER BY co.conname)
      FROM pg_constraint co LEFT JOIN pg_class fc ON fc.oid=co.confrelid LEFT JOIN pg_namespace fn ON fn.oid=fc.relnamespace WHERE co.conrelid=c.oid),'[]'::jsonb),
   'indexes',COALESCE((SELECT jsonb_agg(jsonb_build_object('name',ic.relname,'definition',pg_get_indexdef(i.indexrelid),
      'primary',i.indisprimary,'unique',i.indisunique,'predicate',pg_get_expr(i.indpred,i.indrelid),
      'method',am.amname,'key_count',i.indnkeyatts,'attribute_count',i.indnatts,
      'keys',(SELECT jsonb_agg(pg_get_indexdef(i.indexrelid,k,false) ORDER BY k) FROM generate_series(1,i.indnkeyatts) k)) ORDER BY ic.relname)
      FROM pg_index i JOIN pg_class ic ON ic.oid=i.indexrelid JOIN pg_am am ON am.oid=ic.relam WHERE i.indrelid=c.oid),'[]'::jsonb),
   'triggers',COALESCE((SELECT jsonb_agg(jsonb_build_object('name',tgname,'definition',pg_get_triggerdef(oid,true)) ORDER BY tgname) FROM pg_trigger WHERE tgrelid=c.oid AND NOT tgisinternal),'[]'::jsonb),
   'policies',COALESCE((SELECT jsonb_agg(jsonb_build_object('name',polname,'command',polcmd,'using',pg_get_expr(polqual,polrelid),'check',pg_get_expr(polwithcheck,polrelid)) ORDER BY polname) FROM pg_policy WHERE polrelid=c.oid),'[]'::jsonb)
 ) ORDER BY n.nspname,c.relname) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace
 WHERE n.nspname IN ('core','platform_runtime','commercial','education','device_management','storage','chat','realtime','notifications','activity','workspace') AND c.relkind IN ('r','p')),'[]'::jsonb),
 'views',COALESCE((SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'name',c.relname,'definition',pg_get_viewdef(c.oid,true)) ORDER BY n.nspname,c.relname)
   FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname IN ('core','platform_runtime','commercial','education','device_management','storage','chat','realtime','notifications','activity','workspace') AND c.relkind IN ('v','m')),'[]'::jsonb),
 'functions',COALESCE((SELECT jsonb_agg(jsonb_build_object('schema',n.nspname,'name',p.proname,'definition',pg_get_functiondef(p.oid)) ORDER BY n.nspname,p.proname,pg_get_function_identity_arguments(p.oid))
   FROM pg_proc p JOIN pg_namespace n ON n.oid=p.pronamespace WHERE n.nspname IN ('core','platform_runtime','commercial','education','device_management','storage','chat','realtime','notifications','activity','workspace') AND p.prokind IN ('f','p')),'[]'::jsonb)
);
