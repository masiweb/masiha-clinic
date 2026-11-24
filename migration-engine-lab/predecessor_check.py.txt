"""Pure predecessor selection on an ordered snapshot of opaque keys.

No I/O or source-specific integration. A completed key represents verified
completion, not merely existence. A full reconciliation is still required
because checking only adjacent predecessors cannot prove global coverage.
"""
from dataclasses import dataclass

@dataclass(frozen=True)
class Selection:
    index: int
    key: str
    action: str

def _snapshot(keys):
    keys = tuple(keys)
    if any(not isinstance(k, str) or not k.strip() for k in keys):
        raise ValueError("missing key")
    if len(keys) != len(set(keys)):
        raise ValueError("ambiguous snapshot")
    return keys

def select(keys, index, completed):
    """Find the first unfinished item in a contiguous unfinished predecessor run.

    index is zero-based across the entire snapshot, not within a single page.
    The first row has no predecessor. Positions are advisory only.
    """
    keys = _snapshot(keys)
    if not 0 <= index < len(keys):
        raise IndexError("index outside snapshot")
    completed = frozenset(completed)
    while index > 0 and keys[index - 1] not in completed:
        index -= 1
    key = keys[index]
    return Selection(index, key, "skip" if key in completed else "process")

def first_missing(keys, completed):
    """Find any gap, including one hidden behind a completed predecessor."""
    keys = _snapshot(keys)
    completed = frozenset(completed)
    return next((Selection(i, k, "process") for i, k in enumerate(keys)
                 if k not in completed), None)

def unchanged(before, after):
    """Detect snapshot changes, including insertions, deletions and reorder."""
    return _snapshot(before) == _snapshot(after)
