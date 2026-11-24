import unittest
from identity_match import Identity, resolve, national_key

def synthetic_national(i):
    stem=f'{i+100000000:09d}'
    r=sum(int(stem[j])*(10-j) for j in range(9))%11
    return stem+str(r if r<2 else 11-r)
class IdentityTests(unittest.TestCase):
    def test_reordered_pages(self):
        old=[Identity(f'Test {i}',synthetic_national(i)) for i in range(50)]
        new=[Identity(f'New {i}',synthetic_national(i+50)) for i in range(3)]
        snapshot=new+old
        self.assertEqual(sum(resolve(x,old)[0]=='new' for x in snapshot),3)
        self.assertEqual(sum(resolve(x,old)[0]=='match' for x in snapshot),50)
        for offset in range(0,50,10):
            self.assertEqual(len(snapshot[offset:offset+10]),10)
    def test_duplicate_national(self):
        a=Identity('Test',synthetic_national(1))
        self.assertEqual(resolve(a,[a,a])[0],'review')
    def test_conflict_name(self):
        a=Identity('Test',synthetic_national(1))
        self.assertEqual(resolve(Identity('Other',a.national),[a])[0],'review')
    def test_contact_match(self):
        a=Identity('Test',mobile='09120000001',registered='TEST-DATE')
        self.assertEqual(resolve(a,[a]),('match',0))
    def test_contact_missing_date(self):
        a=Identity('Test',mobile='09120000001')
        self.assertEqual(resolve(a,[a])[0],'review')
    def test_shared_phone(self):
        a=Identity('Test',mobile='09120000001',registered='TEST-DATE')
        b=Identity('Other',mobile=a.mobile,registered=a.registered)
        self.assertEqual(resolve(b,[a])[0],'review')
    def test_different_national_same_contact(self):
        a=Identity('Test',synthetic_national(1),'09120000001','TEST-DATE')
        b=Identity('Test',synthetic_national(2),a.mobile,a.registered)
        self.assertEqual(resolve(b,[a])[0],'review')
    def test_invalid_national(self):
        self.assertEqual(national_key('0000000000'),'')
        self.assertEqual(resolve(Identity('Test','0000000000'),[])[0],'review')
if __name__=='__main__':unittest.main(verbosity=2)
