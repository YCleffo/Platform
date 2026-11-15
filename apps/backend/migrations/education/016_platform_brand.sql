-- Rename public activity identifiers without rewriting applied migration history
-- or the immutable exercise/result snapshots of existing attempts.
ALTER TABLE education.homework_assignments
 ALTER CONSTRAINT homework_assignments_activity_code_fkey DEFERRABLE INITIALLY DEFERRED;
ALTER TABLE education.activity_exercise_sets
 ALTER CONSTRAINT activity_exercise_sets_activity_code_fkey DEFERRABLE INITIALLY DEFERRED;
UPDATE education.activity_definitions
 SET code=replace(code,'kiberone-','platform-'),name=replace(name,'KiberOne','Platform')
 WHERE code IN ('kiberone-typing','kiberone-logic');
UPDATE education.homework_assignments SET activity_code=replace(activity_code,'kiberone-','platform-')
 WHERE activity_code IN ('kiberone-typing','kiberone-logic');
UPDATE education.activity_exercise_sets
 SET activity_code=replace(activity_code,'kiberone-','platform-'),
 content=replace(content::text,'KiberOne','Platform')::jsonb
 WHERE activity_code IN ('kiberone-typing','kiberone-logic');
UPDATE education.achievement_events
 SET payload=jsonb_set(payload,'{activityCode}',to_jsonb(replace(payload->>'activityCode','kiberone-','platform-')))
 WHERE payload->>'activityCode' IN ('kiberone-typing','kiberone-logic');
SET CONSTRAINTS education.homework_assignments_activity_code_fkey,education.activity_exercise_sets_activity_code_fkey IMMEDIATE;
ALTER TABLE education.homework_assignments
 ALTER CONSTRAINT homework_assignments_activity_code_fkey NOT DEFERRABLE;
ALTER TABLE education.activity_exercise_sets
 ALTER CONSTRAINT activity_exercise_sets_activity_code_fkey NOT DEFERRABLE;
