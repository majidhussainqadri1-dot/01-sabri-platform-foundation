from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]

def replace(path, old, new):
    p=ROOT/path
    s=p.read_text(encoding='utf-8')
    if old not in s:
        raise SystemExit(f'Expected fixture context missing: {path}')
    p.write_text(s.replace(old,new,1),encoding='utf-8')

# Make the negative fixture deterministically wrong regardless of WP_ENVIRONMENT_TYPE.
replace(
    'qa/wp-eleventh-ten-round-smoke.php',
    "'module'=>(string)($c['module']??''),'from'=>(string)($c['from']??''),'to'=>(string)($c['to']??''),'environment'=>'production'",
    "'module'=>'file-99','from'=>(string)($c['from']??''),'to'=>(string)($c['to']??''),'environment'=>(string)($c['environment']??'')"
)
replace(
    'qa/wp-runtime-smoke.php',
    "'module'=>(string)($context['module']??''),'from'=>(string)($context['from']??''),'to'=>(string)($context['to']??''),'environment'=>'production'",
    "'module'=>'file-99','from'=>(string)($context['from']??''),'to'=>(string)($context['to']??''),'environment'=>(string)($context['environment']??'')"
)
replace(
    'ELEVENTH-TEN-ROUND-REVIEW-2026-08-11.md',
    '**F01-R11-Q001:** an inherited Fresh-80 assertion still encoded the obsolete rule `staged = Staging-Accepted`; **F01-R11-Q002:** the first meta-regression guard then falsely treated the old text inside its own negative assertion as an active rule. Both QA contracts were corrected to verify semantics, not mere substring absence; product code was not weakened.',
    '**F01-R11-Q001:** an inherited Fresh-80 assertion still encoded the obsolete rule `staged = Staging-Accepted`; **F01-R11-Q002:** the first meta-regression guard falsely treated the old text inside its own negative assertion as an active rule; **F01-R11-Q003:** the first migration negative fixture used `production` as its intended wrong environment, but CI itself resolved to `production`, so the fixture was not actually mismatched. The negative probe now uses impossible `module=file-99`, making the mismatch deterministic. These were QA-contract/fixture defects only; product code was not weakened.',
)
print('Deterministic migration negative fixture correction prepared.')
