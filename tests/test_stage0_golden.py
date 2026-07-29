"""Stage 0: golden export baseline must match current Python export path."""

from scripts.verify_exports import verify_counts, verify_python


def test_stage0_entity_counts():
    assert verify_counts() is True


def test_stage0_python_matches_golden():
    assert verify_python() is True
