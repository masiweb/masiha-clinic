import unittest
from migration_queue_core import Queue,State

class QueueTests(unittest.TestCase):
    def make(self):
        q=Queue()
        q.start_pass()
        q.observe_page(1,['SYNTH-A','SYNTH-B'])
        q.end_pass(True)
        return q

    def test_shift_five_pages(self):
        q=Queue()
        rows=[f'SYNTH-{i}' for i in range(50)]
        stable=False
        for p in range(5):
            q.start_pass()
            offset=0
            while offset<len(rows):
                q.observe_page(offset//10+1,rows[offset:offset+10])
                if p==0 and offset==0: rows[:0]=['NEW-A','NEW-B','NEW-C']
                if p==0 and offset==20: rows[:0]=['NEW-D','NEW-E']
                offset+=10
            stable=q.end_pass(True)
            if stable:break
        self.assertTrue(stable)
        self.assertEqual(set(q.entries),set(rows))
        self.assertEqual(len(q.entries),55)

    def test_repeated_observation_preserves_complete(self):
        q=self.make()
        t=q.claim('SYNTH-A',['SYNTH-A'],['output'])
        q.verify('SYNTH-A',t,'output')
        q.finish('SYNTH-A',t)
        q.start_pass()
        q.observe_page(2,['SYNTH-A'])
        q.end_pass(True)
        self.assertEqual(q.entries['SYNTH-A'].state,State.COMPLETE)
        self.assertIsNone(q.claim('SYNTH-A',['SYNTH-A'],['output']))

    def test_partial_and_resume(self):
        q=self.make()
        t=q.claim('SYNTH-A',['SYNTH-A'],['a','b'])
        q.verify('SYNTH-A',t,'a')
        self.assertEqual(q.finish('SYNTH-A',t),State.PARTIAL)
        t=q.claim('SYNTH-A',['SYNTH-A'],['a','b'])
        q.verify('SYNTH-A',t,'b')
        self.assertEqual(q.finish('SYNTH-A',t),State.COMPLETE)

    def test_missing_or_ambiguous_target(self):
        q=self.make()
        self.assertIsNone(q.claim('SYNTH-A',['SYNTH-B'],['a']))
        self.assertEqual(q.entries['SYNTH-A'].state,State.REVIEW)
        self.assertIsNone(q.claim('SYNTH-B',['SYNTH-B','SYNTH-B'],['a']))

    def test_interrupt_rejects_stale_claim(self):
        q=self.make()
        t=q.claim('SYNTH-A',['SYNTH-A'],['a'])
        q.recover_interrupted()
        with self.assertRaises(RuntimeError):q.finish('SYNTH-A',t)
        self.assertEqual(q.entries['SYNTH-A'].state,State.PARTIAL)

    def test_pause(self):
        q=self.make()
        q.pause()
        self.assertIsNone(q.claim('SYNTH-A',['SYNTH-A'],['a']))
        q.resume()
        self.assertIsNotNone(q.claim('SYNTH-A',['SYNTH-A'],['a']))

    def test_retry_cap(self):
        q=self.make()
        for _ in range(3):
            t=q.claim('SYNTH-A',['SYNTH-A'],['a'])
            q.finish('SYNTH-A',t)
        self.assertIsNone(q.claim('SYNTH-A',['SYNTH-A'],['a']))
        self.assertEqual(q.entries['SYNTH-A'].state,State.REVIEW)

    def test_changed_manifest(self):
        q=self.make()
        t=q.claim('SYNTH-A',['SYNTH-A'],['a'])
        q.finish('SYNTH-A',t)
        self.assertIsNone(q.claim('SYNTH-A',['SYNTH-A'],['b']))
        self.assertEqual(q.entries['SYNTH-A'].state,State.REVIEW)

    def test_incomplete_pass_not_stable(self):
        q=self.make()
        q.start_pass()
        q.observe_page(1,['SYNTH-A','SYNTH-B'])
        self.assertFalse(q.end_pass(False))
        q.start_pass()
        q.observe_page(1,['SYNTH-A','SYNTH-B'])
        self.assertFalse(q.end_pass(True))

    def test_unexpected_output(self):
        q=self.make()
        t=q.claim('SYNTH-A',['SYNTH-A'],['a'])
        with self.assertRaises(ValueError):q.verify('SYNTH-A',t,'b')
        self.assertEqual(q.finish('SYNTH-A',t),State.PARTIAL)

    def test_ambiguous_page(self):
        q=Queue()
        q.start_pass()
        with self.assertRaises(ValueError):q.observe_page(1,['SYNTH-A','SYNTH-A'])
        self.assertEqual(len(q.entries),0)

if __name__=='__main__':unittest.main()
