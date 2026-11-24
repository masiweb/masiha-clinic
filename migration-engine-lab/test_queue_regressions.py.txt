"""Synthetic state-machine regression tests; no external systems or real data."""
import unittest
from migration_queue_core import Queue, State
from predecessor_check import first_missing, select

class QueueRegressionTests(unittest.TestCase):
    def make_queue(self):
        q = Queue()
        q.start_pass()
        q.observe_page(1, ["TEST-A", "TEST-B"])
        q.end_pass(True)
        return q

    def test_repeated_verification_does_not_replace_missing_output(self):
        q = self.make_queue()
        g = q.claim("TEST-A", ["TEST-A"], ["alpha", "beta"])
        q.verify("TEST-A", g, "alpha")
        q.verify("TEST-A", g, "alpha")
        self.assertEqual(q.finish("TEST-A", g), State.PARTIAL)
        self.assertEqual(q.entries["TEST-A"].verified, {"alpha"})

    def test_empty_expected_manifest_rejected(self):
        q = self.make_queue()
        with self.assertRaises(ValueError):
            q.claim("TEST-A", ["TEST-A"], [])
        self.assertEqual(q.entries["TEST-A"].state, State.PENDING)
        self.assertEqual(q.entries["TEST-A"].attempts, 0)

    def test_interrupted_generation_cannot_verify(self):
        q = self.make_queue()
        g = q.claim("TEST-A", ["TEST-A"], ["alpha", "beta"])
        q.verify("TEST-A", g, "alpha")
        q.recover_interrupted()
        new_g = q.claim("TEST-A", ["TEST-A"], ["alpha", "beta"])
        with self.assertRaises(RuntimeError):
            q.verify("TEST-A", g, "beta")
        self.assertEqual(q.entries["TEST-A"].verified, {"alpha"})
        self.assertEqual(q.finish("TEST-A", new_g), State.PARTIAL)

    def test_state_counts_conserve_unique_entries(self):
        q = self.make_queue()
        g = q.claim("TEST-A", ["TEST-A"], ["alpha"])
        q.verify("TEST-A", g, "alpha")
        q.finish("TEST-A", g)
        q.claim("TEST-B", ["TEST-B", "TEST-B"], ["alpha"])
        c = q.counters()
        self.assertEqual(sum(c.values()), 2)
        self.assertEqual(c["complete"], 1)
        self.assertEqual(c["review"], 1)

    def test_new_key_invalidates_previous_stable_pass(self):
        q = self.make_queue()
        q.start_pass()
        q.observe_page(1, ["TEST-A", "TEST-B"])
        self.assertTrue(q.end_pass(True))
        q.start_pass()
        q.observe_page(1, ["TEST-C", "TEST-A", "TEST-B"])
        self.assertFalse(q.end_pass(True))

    def test_adjacent_check_is_not_global_completeness(self):
        keys = ["TEST-A", "TEST-B", "TEST-C", "TEST-D"]
        completed = {"TEST-B", "TEST-C"}
        self.assertEqual(select(keys, 3, completed).key, "TEST-D")
        self.assertEqual(first_missing(keys, completed).key, "TEST-A")

if __name__ == "__main__":
    unittest.main()
