"""Safety checks for the destructive destination boundary; no Docker/DB needed."""
import importlib.util
import json
from pathlib import Path
from types import SimpleNamespace
import unittest

spec=importlib.util.spec_from_file_location('rehearsal',Path(__file__).with_name('upgrade-rehearsal.py'))
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class UpgradeBoundaryTest(unittest.TestCase):
    def fake(self,labels,name='/isolated-copy'):
        rehearsal=module.Rehearsal.__new__(module.Rehearsal)
        rehearsal.id='unit-run';rehearsal.project='platform-upgrade-unit-run'
        rehearsal.compose=lambda *args,**kwargs:SimpleNamespace(stdout='destination-id')
        rehearsal.command=lambda *args,**kwargs:SimpleNamespace(stdout=json.dumps([{'Config':{'Labels':labels},'Name':name}]))
        return rehearsal

    def labels(self):
        return {'com.docker.compose.project':'platform-upgrade-unit-run','platform.rehearsal.run':'unit-run','platform.rehearsal':'true'}

    def test_destination_rejects_other_project_or_run_even_with_rehearsal_label(self):
        for key,value in [('com.docker.compose.project','platform'),('platform.rehearsal.run','other'),('platform.rehearsal','false')]:
            labels=self.labels();labels[key]=value
            with self.subTest(key=key),self.assertRaises(RuntimeError):self.fake(labels).target('core')

    def test_original_names_rejected_even_if_ownership_labels_are_forged(self):
        for name in module.SOURCES.values():
            with self.subTest(name=name),self.assertRaises(RuntimeError):self.fake(self.labels(),'/'+name).target('core')

    def test_correct_labels_cannot_bypass_database_marker(self):
        rehearsal=self.fake(self.labels())
        rehearsal.target_sql=lambda *args,**kwargs:SimpleNamespace(stdout='platform:ordinary-cluster')
        with self.assertRaises(RuntimeError):rehearsal.assert_target_marker('core')

    def test_original_read_uses_repeatable_read_read_only_and_fixed_container(self):
        rehearsal=self.fake(self.labels());calls=[]
        def capture(args,**kwargs):calls.append((args,kwargs));return SimpleNamespace(stdout='[]')
        rehearsal.command=capture
        rehearsal.source_sql('commercial','SELECT 1','00000003-00000011-1')
        self.assertIn('platform-control-postgres-1',calls[0][0])
        self.assertTrue(calls[0][1]['data'].startswith('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY; SET TRANSACTION SNAPSHOT'))
        self.assertTrue(calls[0][1]['data'].endswith('; COMMIT;'))
        with self.assertRaises(RuntimeError):rehearsal.source_sql('core','SELECT 1',"x'; COMMIT; SELECT 'x")
        self.assertEqual(len(calls),1)

    def test_normalization_only_allows_equivalent_varchar_literal_arrays(self):
        old="CHECK (s::text = ANY (ARRAY['a'::character varying::text, 'b'::character varying::text]))"
        new="CHECK (s::text = ANY (ARRAY['a'::character varying, 'b'::character varying]::text[]))"
        self.assertEqual(module.normalize_check_array_casts(old),module.normalize_check_array_casts(new))
        self.assertNotEqual(module.normalize_check_array_casts(old),module.normalize_check_array_casts(new.replace("'b'","'c'")))
        old_index="WHERE ((status)::text = ANY (ARRAY[('issued'::character varying)::text, ('paid'::character varying)::text]))"
        new_index="WHERE ((status)::text = ANY ((ARRAY['issued'::character varying, 'paid'::character varying])::text[]))"
        self.assertEqual(module.normalize_check_array_casts(old_index),module.normalize_check_array_casts(new_index))

    def test_schema_comparison_keeps_missing_checks_and_weaker_predicates(self):
        actual={'tables':[{'schema':'core','name':'x','constraints':[{'name':'safe','definition':'CHECK (n > 0)'}]}]}
        expected={'tables':[{'schema':'core','name':'x','constraints':[{'name':'safe','definition':'CHECK (n > 1)'},{'name':'extra','definition':'CHECK (n < 9)'}]}]}
        changes=module.schema_differences(actual,expected)
        self.assertEqual(len(changes),2)
        self.assertTrue(any('/extra' in c['path'] for c in changes))


if __name__=='__main__':unittest.main()
