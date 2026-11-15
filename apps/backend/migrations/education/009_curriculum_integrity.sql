-- Preserve tenant/group binding even for direct SQL writers and future adapters.
CREATE FUNCTION education.check_lesson_curriculum_binding() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.group_program_id IS NOT NULL AND NOT EXISTS (
   SELECT 1 FROM education.group_programs gp
   CROSS JOIN LATERAL jsonb_array_elements(gp.snapshot->'modules') m
   CROSS JOIN LATERAL jsonb_array_elements(m->'lessons') l
   WHERE gp.id=NEW.group_program_id AND gp.organization_id=NEW.organization_id
     AND gp.group_id=NEW.group_id AND l->>'id'=NEW.curriculum_lesson_id::text
 ) THEN
   RAISE EXCEPTION 'Curriculum lesson is not in the group assignment snapshot' USING ERRCODE='23514';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER lesson_curriculum_binding_guard BEFORE INSERT OR UPDATE OF group_id,organization_id,group_program_id,curriculum_lesson_id
ON education.lessons FOR EACH ROW EXECUTE FUNCTION education.check_lesson_curriculum_binding();
