-- 014: immutable exercise/result snapshots and reproducible activity achievements.
CREATE TABLE education.activity_exercise_sets (
 code varchar(80) NOT NULL, version integer NOT NULL CHECK(version>0),activity_code varchar(60) NOT NULL REFERENCES education.activity_definitions(code),
 language varchar(2) NOT NULL CHECK(language IN ('ru','en')),mode varchar(20) NOT NULL,difficulty integer NOT NULL CHECK(difficulty BETWEEN 1 AND 3),
 content jsonb NOT NULL CHECK(jsonb_typeof(content)='array'),created_at timestamptz NOT NULL DEFAULT now(),
 PRIMARY KEY(code,version),UNIQUE(activity_code,version,language,mode,difficulty)
);
ALTER TABLE education.activity_attempts ADD COLUMN exercise_payload jsonb NOT NULL DEFAULT '{}';
ALTER TABLE education.activity_attempts ADD COLUMN client_state jsonb NOT NULL DEFAULT '{}';
ALTER TABLE education.activity_attempts ADD COLUMN state_version integer NOT NULL DEFAULT 0 CHECK(state_version>=0);
ALTER TABLE education.activity_attempts ADD COLUMN revoked_at timestamptz;
ALTER TABLE education.activity_attempts ADD CONSTRAINT activity_attempt_tenant UNIQUE(id,organization_id,student_id);
CREATE TABLE education.activity_results (
 attempt_id uuid PRIMARY KEY,organization_id uuid NOT NULL,student_id uuid NOT NULL,
 type varchar(20) NOT NULL CHECK(type IN ('typing','logic')),version integer NOT NULL CHECK(version>0),
 request_hash char(64) NOT NULL,metrics jsonb NOT NULL CHECK(jsonb_typeof(metrics)='object'),passed boolean NOT NULL,
 created_at timestamptz NOT NULL DEFAULT now(),
 FOREIGN KEY(attempt_id,organization_id,student_id) REFERENCES education.activity_attempts(id,organization_id,student_id)
);
CREATE TABLE education.logic_results (
 attempt_id uuid PRIMARY KEY REFERENCES education.activity_results(attempt_id),organization_id uuid NOT NULL,student_id uuid NOT NULL,
 correct_answers integer NOT NULL CHECK(correct_answers>=0),total_rounds integer NOT NULL CHECK(total_rounds IN (5,10)),
 score integer NOT NULL CHECK(score BETWEEN 0 AND 1000),accuracy numeric(5,2) NOT NULL CHECK(accuracy BETWEEN 0 AND 100),
 created_at timestamptz NOT NULL DEFAULT now(),CHECK(correct_answers<=total_rounds)
);
CREATE INDEX activity_results_student ON education.activity_results(organization_id,student_id,created_at DESC,attempt_id);
CREATE INDEX logic_results_student ON education.logic_results(organization_id,student_id,created_at DESC,attempt_id);
UPDATE education.activity_definitions SET contract_version=2,
 config='{"durations":[15,30,60],"languages":["ru","en"],"modes":["words","sentences","numbers","code","accuracy"],"difficulties":[1,2,3],"exerciseVersion":2}',
 metrics='[{"code":"wpm","name":"Words per minute","kind":"number"},{"code":"accuracy","name":"Final text accuracy","kind":"number"},{"code":"errors","name":"Final text errors","kind":"number"},{"code":"typedCharacters","name":"Final typed characters","kind":"number"}]'
 WHERE code='kiberone-typing';
INSERT INTO education.activity_definitions(code,name,kind,contract_version,config,metrics,required_permission) VALUES
 ('kiberone-logic','KiberOne Logic','web-game',1,
 '{"languages":["ru","en"],"difficulties":[1,2,3],"roundCounts":[5,10],"modes":["sequence","pattern","logic","order","algorithm"]}',
 '[{"code":"score","name":"Logic score","kind":"number"},{"code":"correctAnswers","name":"Correct answers","kind":"number"},{"code":"accuracy","name":"Accuracy","kind":"number"}]','education.activities.use');
CREATE TABLE education.achievement_definitions (
 id uuid PRIMARY KEY DEFAULT uuidv7(),code varchar(80) NOT NULL,rule_version integer NOT NULL CHECK(rule_version>0),
 name varchar(160) NOT NULL,description text NOT NULL,rule jsonb NOT NULL CHECK(jsonb_typeof(rule)='object'),
 enabled boolean NOT NULL DEFAULT true,created_at timestamptz NOT NULL DEFAULT now(),UNIQUE(code,rule_version)
);
CREATE TABLE education.achievement_events (
 id uuid PRIMARY KEY DEFAULT uuidv7(),organization_id uuid NOT NULL,student_id uuid NOT NULL,
 source_type varchar(20) NOT NULL CHECK(source_type IN ('attempt','homework')),source_id uuid NOT NULL,
 occurred_at timestamptz NOT NULL,payload jsonb NOT NULL CHECK(jsonb_typeof(payload)='object'),revoked_at timestamptz,
 UNIQUE(organization_id,student_id,source_type,source_id),UNIQUE(id,organization_id,student_id),
 FOREIGN KEY(student_id,organization_id) REFERENCES education.students(id,organization_id)
);
CREATE INDEX achievement_events_student ON education.achievement_events(organization_id,student_id,occurred_at,id);
CREATE TABLE education.achievement_unlocks (
 id uuid PRIMARY KEY DEFAULT uuidv7(),organization_id uuid NOT NULL,student_id uuid NOT NULL,
 definition_id uuid NOT NULL REFERENCES education.achievement_definitions(id),source_event_id uuid NOT NULL,
 unlocked_at timestamptz NOT NULL DEFAULT now(),revoked_at timestamptz,
 UNIQUE(organization_id,student_id,definition_id),
 FOREIGN KEY(source_event_id,organization_id,student_id) REFERENCES education.achievement_events(id,organization_id,student_id),
 FOREIGN KEY(student_id,organization_id) REFERENCES education.students(id,organization_id)
);
-- Definition versions are independent. New rule versions require explicit new definitions;
-- existing unlock versions remain identified by their original definition row.
INSERT INTO education.achievement_definitions(code,rule_version,name,description,rule) VALUES
 ('first-training',1,'First training','Finish a typing training with at least one typed character.','{"type":"first-training","target":1}'),
 ('first-logic',1,'Logic explorer','Finish a Logic game.','{"type":"first-logic","target":1}'),
 ('first-homework',1,'Homework accepted','Have one homework submission accepted by a teacher.','{"type":"first-homework","target":1}'),
 ('typing-accuracy',1,'Careful typing','Reach 95% final text accuracy with at least 20 correct characters.','{"type":"typing-accuracy","target":95,"minCorrectChars":20}'),
 ('personal-best',1,'Personal best','Improve a previous typing best with the same language, mode, difficulty and duration.','{"type":"personal-best","target":1}'),
 ('successful-streak',1,'Three successful exercises','Pass three consecutive completed exercises.','{"type":"successful-streak","target":3}');
ALTER TABLE education.notifications DROP CONSTRAINT education_notification_target;
ALTER TABLE education.notifications ADD CONSTRAINT education_notification_target CHECK (
 (kind='message' AND thread_id IS NOT NULL AND parent_student_id IS NULL) OR
 (kind IN ('homework_published','homework_deadline_changed','homework_submitted','homework_returned','homework_reviewed','achievement_unlocked')
   AND resource_id IS NOT NULL AND resource_path IS NOT NULL AND subject IS NOT NULL AND parent_student_id IS NULL) OR
 (kind IN ('parent_homework_published','parent_homework_reviewed','parent_homework_returned','parent_homework_deadline_changed',
   'parent_schedule_changed','parent_family_confirmed','parent_family_revoked','parent_family_invited',
   'parent_family_invitation_revoked','parent_family_relationship_changed','parent_family_added','parent_family_updated',
   'parent_activity_finished','parent_achievement_unlocked')
   AND parent_student_id IS NOT NULL AND resource_id IS NOT NULL AND resource_path IS NOT NULL AND subject IS NOT NULL AND thread_id IS NULL)
);
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-words-1',2,'kiberone-typing','ru','words',1,'["дом кот лес мир сад чай стол окно море небо утро книга лампа река школа"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-words-2',2,'kiberone-typing','ru','words',2,'["алгоритм клавиатура решение программа учитель ученик команда рисунок задача скорость"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-words-3',2,'kiberone-typing','ru','words',3,'["последовательность программирование закономерность исследование ответственность воображение"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-sentences-1',2,'kiberone-typing','ru','sentences',1,'["У нас есть план. Мы учимся каждый день.","Тихий ветер качает ветки. Кот смотрит в окно."]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-sentences-2',2,'kiberone-typing','ru','sentences',2,'["Проверь порядок действий, затем запиши результат.","На экране появилась задача: найди верный ответ!"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-sentences-3',2,'kiberone-typing','ru','sentences',3,'["Почему алгоритм завершился? Спросил ученик, проверяя условие.","В 09:30 команда проверила код, исправила ошибки и начала новый опыт."]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-numbers-1',2,'kiberone-typing','ru','numbers',1,'["12 34 56 78 90 123 456 789 102 345"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-numbers-2',2,'kiberone-typing','ru','numbers',2,'["12, 24; 36: 48. 50! 62? (74) [86] 98%"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-numbers-3',2,'kiberone-typing','ru','numbers',3,'["2026-11-14 09:30:45 +7 (343) 123-45-67 3.1415 25% #42 @home"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-code-1',2,'kiberone-typing','ru','code',1,'["let count = 5; let total = count + 2;","const name = \"KiberOne\"; print(name);"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-code-2',2,'kiberone-typing','ru','code',2,'["for (let i = 0; i < 5; i++) { total += i; }","if (score >= 10) { print(\"Ready!\"); }"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-code-3',2,'kiberone-typing','ru','code',3,'["function sum(items) { return items.reduce((a, b) => a + b, 0); }","const even = values.filter(value => value % 2 === 0);"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-accuracy-1',2,'kiberone-typing','ru','accuracy',1,'["У нас есть план. Мы учимся каждый день.","Тихий ветер качает ветки. Кот смотрит в окно."]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-accuracy-2',2,'kiberone-typing','ru','accuracy',2,'["Проверь порядок действий, затем запиши результат.","На экране появилась задача: найди верный ответ!"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-ru-accuracy-3',2,'kiberone-typing','ru','accuracy',3,'["Почему алгоритм завершился? Спросил ученик, проверяя условие.","В 09:30 команда проверила код, исправила ошибки и начала новый опыт."]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-words-1',2,'kiberone-typing','en','words',1,'["cat dog sun sky tree book door home blue green learn play river cloud"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-words-2',2,'kiberone-typing','en','words',2,'["keyboard solution program teacher student pattern practice journey accuracy"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-words-3',2,'kiberone-typing','en','words',3,'["sequence programming investigation responsibility imagination architecture"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-sentences-1',2,'kiberone-typing','en','sentences',1,'["We learn a little every day. The sun is bright.","A small robot can follow a simple plan."]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-sentences-2',2,'kiberone-typing','en','sentences',2,'["Check each step, then write down the result.","The screen shows a question: can you solve it?"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-sentences-3',2,'kiberone-typing','en','sentences',3,'["\"Why did the algorithm stop?\" asked the student.","At 09:30, the team checked its code, fixed three errors, and tried again."]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-numbers-1',2,'kiberone-typing','en','numbers',1,'["12 34 56 78 90 123 456 789 102 345"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-numbers-2',2,'kiberone-typing','en','numbers',2,'["12, 24; 36: 48. 50! 62? (74) [86] 98%"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-numbers-3',2,'kiberone-typing','en','numbers',3,'["2026-11-14 09:30:45 +7 (343) 123-45-67 3.1415 25% #42 @home"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-code-1',2,'kiberone-typing','en','code',1,'["let count = 5; let total = count + 2;","const name = \"KiberOne\"; print(name);"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-code-2',2,'kiberone-typing','en','code',2,'["for (let i = 0; i < 5; i++) { total += i; }","if (score >= 10) { print(\"Ready!\"); }"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-code-3',2,'kiberone-typing','en','code',3,'["function sum(items) { return items.reduce((a, b) => a + b, 0); }","const even = values.filter(value => value % 2 === 0);"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-accuracy-1',2,'kiberone-typing','en','accuracy',1,'["We learn a little every day. The sun is bright.","A small robot can follow a simple plan."]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-accuracy-2',2,'kiberone-typing','en','accuracy',2,'["Check each step, then write down the result.","The screen shows a question: can you solve it?"]');
INSERT INTO education.activity_exercise_sets(code,version,activity_code,language,mode,difficulty,content) VALUES ('typing-v2-en-accuracy-3',2,'kiberone-typing','en','accuracy',3,'["\"Why did the algorithm stop?\" asked the student.","At 09:30, the team checked its code, fixed three errors, and tried again."]');
