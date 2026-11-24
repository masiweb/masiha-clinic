"""Pure identity matching rules. No I/O, browser access or patient database access."""
import re
import unicodedata
from dataclasses import dataclass

DIGITS = str.maketrans('۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩', '01234567890123456789')
def name_key(value):
    value = unicodedata.normalize('NFKC', value or '').translate(str.maketrans('يك','یک'))
    return ' '.join(value.replace('\u200c',' ').split()).casefold()

def national_key(value):
    n = (value or '').strip().translate(DIGITS)
    if not re.fullmatch(r'[0-9]{10}',n) or len(set(n)) == 1:
        return ''
    r=sum(int(n[i])*(10-i) for i in range(9))%11
    return n if int(n[-1]) == (r if r<2 else 11-r) else ''

def mobile_key(value):
    n=re.sub(r'[\s()-]','',(value or '').translate(DIGITS))
    if n.startswith('+98'): n='0'+n[3:]
    elif n.startswith('0098'): n='0'+n[4:]
    return n if re.fullmatch(r'09[0-9]{9}',n) else ''

@dataclass(frozen=True)
class Identity:
    name: str
    national: str=''
    mobile: str=''
    registered: str=''

def resolve(incoming, existing):
    """Return (decision, candidate index). Never merge uncertain identities."""
    n=national_key(incoming.national)
    m=mobile_key(incoming.mobile)
    name=name_key(incoming.name)
    if not name: return 'review',None
    nat=[i for i,e in enumerate(existing) if n and national_key(e.national)==n]
    contact=[i for i,e in enumerate(existing) if m and mobile_key(e.mobile)==m and name_key(e.name)==name]
    if len(nat)>1 or len(contact)>1: return 'review',None
    if nat:
        i=nat[0]
        if name_key(existing[i].name)!=name or (contact and contact[0]!=i):
            return 'review',None
        return 'match',i
    if contact:
        i=contact[0]
        e=existing[i]
        if n and national_key(e.national) and n!=national_key(e.national):
            return 'review',None
        if not incoming.registered or not e.registered or incoming.registered!=e.registered:
            return 'review',None
        return 'match',i
    # A shared phone or name alone is not proof of identity.
    if any((m and mobile_key(e.mobile)==m) or name_key(e.name)==name for e in existing):
        return 'review',None
    if n or (m and incoming.registered): return 'new',None
    return 'review',None
