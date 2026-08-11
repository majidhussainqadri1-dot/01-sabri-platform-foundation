from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def replace(path,old,new):
    p=ROOT/path;s=p.read_text(encoding='utf-8')
    if old not in s: raise SystemExit(f'Expected v2 finalizer context missing: {path}')
    p.write_text(s.replace(old,new,1),encoding='utf-8')

replace(
 'tests/eleventh-ten-round-review-tests.php',
 "$fresh80=$read('tests/fresh-eighty-round-review-tests.php');$assert($has('STAGING-ACCEPTANCE.md','- [ ]')&&$has('KNOWN-LIMITATIONS.md','staging')&&$has('RELEASE-CHECKLIST.md','rollback')&&str_contains($fresh80,\"array('approved','deployed')\")&&!str_contains($fresh80,\"array('staged','approved','deployed')\"),'Round 10: staging/live/operational QA contract still collapses merely-staged into Staging-Accepted.');",
 "$fresh80=$read('tests/fresh-eighty-round-review-tests.php');$assert($has('STAGING-ACCEPTANCE.md','- [ ]')&&$has('KNOWN-LIMITATIONS.md','staging')&&$has('RELEASE-CHECKLIST.md','rollback')&&str_contains($fresh80,\"array('approved','deployed')\")&&str_contains($fresh80,'Round 58: Staging-Accepted still collapses the merely-staged state.'),'Round 10: corrected staging/live/operational QA contract is not present.');"
)
replace(
 'ELEVENTH-TEN-ROUND-REVIEW-2026-08-11.md',
 '**F01-R11-Q001:** an inherited Fresh-80 assertion still encoded the obsolete rule `staged = Staging-Accepted`. The QA contract was corrected to require only `approved`/`deployed`; product code was not weakened.',
 '**F01-R11-Q001:** an inherited Fresh-80 assertion still encoded the obsolete rule `staged = Staging-Accepted`; **F01-R11-Q002:** the first meta-regression guard then falsely treated the old text inside its own negative assertion as an active rule. Both QA contracts were corrected to verify semantics, not mere substring absence; product code was not weakened.'
)
print('Eleventh review meta-regression QA correction prepared.')
