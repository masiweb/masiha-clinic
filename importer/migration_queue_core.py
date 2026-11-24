"""Standalone migration queue reference implementation, using synthetic opaque keys.
No browser, network, clinic database or external-system adapters.
"""
from dataclasses import dataclass, field
from enum import Enum

class State(str, Enum):
    PENDING='pending'
    PROCESSING='processing'
    PARTIAL='partial'
    COMPLETE='complete'
    REVIEW='review'

@dataclass
class Entry:
    key: str
    state: State=State.PENDING
    page: int=0
    row: int=0
    attempts: int=0
    generation: int=0
    expected: frozenset=field(default_factory=frozenset)
    verified: set=field(default_factory=set)

class Queue:
    def __init__(self, retry_limit=3):
        self.entries={}
        self.retry_limit=retry_limit
        self.paused=False
        self.pass_open=False
        self.new_in_pass=0
        self.seen_in_pass=set()
        self.previous_complete_pass=None
        self.duplicates=0

    def start_pass(self):
        if self.pass_open: raise RuntimeError('pass already open')
        self.pass_open=True
        self.new_in_pass=0
        self.seen_in_pass=set()

    def observe_page(self, page, keys):
        if not self.pass_open: raise RuntimeError('no open pass')
        if page<1: raise ValueError('invalid page')
        keys=list(keys)
        if any(not isinstance(k,str) or not k.strip() for k in keys):
            raise ValueError('invalid key')
        if len(set(keys))!=len(keys):
            raise ValueError('ambiguous page')
        for row,key in enumerate(keys,1):
            if key not in self.entries:
                self.entries[key]=Entry(key)
                self.new_in_pass+=1
            else:
                self.duplicates+=1
            entry=self.entries[key]
            entry.page,entry.row=page,row
            self.seen_in_pass.add(key)

    def end_pass(self, reached_end):
        if not self.pass_open: raise RuntimeError('no open pass')
        self.pass_open=False
        if not reached_end:
            self.previous_complete_pass=None
            return False
        current=frozenset(self.seen_in_pass)
        stable=(self.new_in_pass==0 and current==self.previous_complete_pass)
        self.previous_complete_pass=current
        return stable

    def claim(self,key,visible_keys,expected):
        if self.paused: return None
        e=self.entries[key]
        if e.state not in (State.PENDING,State.PARTIAL): return None
        if list(visible_keys).count(key)!=1:
            e.state=State.REVIEW
            return None
        if e.attempts>=self.retry_limit:
            e.state=State.REVIEW
            return None
        expected=frozenset(expected)
        if not expected:
            raise ValueError('explicit required output manifest needed')
        if e.attempts and e.expected!=expected:
            e.state=State.REVIEW
            return None
        e.expected=expected
        e.attempts+=1
        e.generation+=1
        e.state=State.PROCESSING
        return e.generation

    def verify(self,key,generation,output):
        e=self.entries[key]
        if e.state!=State.PROCESSING or e.generation!=generation:
            raise RuntimeError('stale claim')
        if output not in e.expected: raise ValueError('unexpected output')
        e.verified.add(output)

    def finish(self,key,generation):
        e=self.entries[key]
        if e.state!=State.PROCESSING or e.generation!=generation:
            raise RuntimeError('stale claim')
        e.state=State.COMPLETE if e.expected==e.verified else State.PARTIAL
        return e.state

    def recover_interrupted(self):
        for e in self.entries.values():
            if e.state==State.PROCESSING:
                e.state=State.PARTIAL
                e.generation+=1

    def pause(self):
        self.paused=True

    def resume(self):
        self.paused=False

    def counters(self):
        return {state.value:sum(e.state==state for e in self.entries.values()) for state in State}
