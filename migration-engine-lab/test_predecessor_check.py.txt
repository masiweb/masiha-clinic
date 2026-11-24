import unittest
from predecessor_check import select, first_missing, unchanged, Selection

class PredecessorTests(unittest.TestCase):
    def setUp(self):
        self.rows = [f"TEST-{i}" for i in range(1,101)]

    def test_first_row(self):
        self.assertEqual(select(self.rows,0,set()),Selection(0,"TEST-1","process"))

    def test_previous_complete(self):
        self.assertEqual(select(self.rows,69,{"TEST-69"}).key,"TEST-70")

    def test_previous_missing(self):
        self.assertEqual(select(self.rows,69,{"TEST-68"}).key,"TEST-69")

    def test_multiple_missing(self):
        self.assertEqual(select(self.rows,69,{"TEST-64"}).key,"TEST-65")

    def test_cross_page(self):
        self.assertEqual(select(self.rows,70,{"TEST-68"}).key,"TEST-69")

    def test_no_completed(self):
        self.assertEqual(select(self.rows,69,set()).key,"TEST-1")

    def test_completed_target(self):
        self.assertEqual(select(self.rows,69,{"TEST-69","TEST-70"}).action,"skip")

    def test_global_gap(self):
        completed=set(self.rows)-{"TEST-12","TEST-70"}
        self.assertEqual(select(self.rows,69,completed).key,"TEST-70")
        self.assertEqual(first_missing(self.rows,completed).key,"TEST-12")

    def test_inserted_rows(self):
        changed=["NEW-A","NEW-B"]+self.rows
        self.assertFalse(unchanged(self.rows,changed))
        self.assertEqual(first_missing(changed,set(self.rows)).key,"NEW-A")

    def test_duplicate_key(self):
        with self.assertRaises(ValueError):
            select(["TEST-A","TEST-A"],1,set())

    def test_all_complete(self):
        self.assertIsNone(first_missing(self.rows,set(self.rows)))

if __name__=="__main__":unittest.main()
